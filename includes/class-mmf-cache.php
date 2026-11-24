<?php
/**
 * File cache for minified assets.
 *
 * Files live in `wp-content/uploads/mmf/` and are named after the hash of the
 * source plus the settings that produced it: the same input always maps to the
 * same file, and a changed setting produces a new name instead of an invalid
 * one. Nothing is written unless it is read back by the browser, so the folder
 * carries an `index.php` stub and no `.htaccess` rule.
 *
 * There are no logs: every failure is reported through the return value and
 * shown on the admin screen of stage 2.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Cache' ) ) {
	return;
}

/**
 * Reads and writes the minified files.
 */
final class MMF_Cache {

	/**
	 * Folder under the uploads directory.
	 */
	public const DIR = 'mmf';

	/**
	 * Daily cleanup hook.
	 */
	public const CRON_HOOK = 'mmf_cache_cleanup';

	/**
	 * Extension per asset type.
	 */
	private const EXTENSIONS = array(
		'js'   => '.min.js',
		'css'  => '.min.css',
		'webp' => '.avatar.webp',
	);

	/**
	 * Every file the plugin may create in the cache directory.
	 *
	 * Used for statistics, the TTL cleanup and the full delete, so a new kind of
	 * cached file cannot be left behind by forgetting to add it in one place.
	 */
	public const LAST_CLEANUP_OPTION = 'mmf_last_cleanup';

	/**
	 * A file younger than this is never deleted: a page in a reverse-proxy
	 * cache may still point at it.
	 */
	private const MIN_AGE = HOUR_IN_SECONDS;

	private const PATTERNS = array(
		'*.min.js',
		'*.min.css',
		'*.avatar.webp',
		'*.fonts.css',
		'font-*.woff2',
		'font-*.woff',
		'font-*.ttf',
		'font-*.otf',
		'font-*.eot',
		'font-*.svg',
	);

	/**
	 * Directory of the current site, `null` until first use.
	 */
	private static ?string $base_dir = null;

	/**
	 * URL of the current site, `null` until first use.
	 */
	private static ?string $base_url = null;

	/**
	 * Registers the cron handler and the events that invalidate the cache.
	 *
	 * The cron handler has to be attached on every request: WP-Cron runs the
	 * hook in an ordinary request, not in the one that scheduled it.
	 *
	 * A minified copy survives an update of the file it was made from only until
	 * its name changes, and the name is a hash of the source: a theme update
	 * therefore invalidates it anyway. The purge on updates and on a settings
	 * save exists for the other case - a change that the hash cannot see, such
	 * as a different way of building the same file - and because old files must
	 * not accumulate forever.
	 */
	public static function register_hooks(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup' ) );

		// Deliberately no purge on plugin update, theme switch or settings save.
		// Cached files are content-addressed: a new version or a new setting
		// produces new names, so old files are simply unreferenced and the daily
		// cleanup removes them. Deleting them instead would break pages that are
		// still served from a reverse-proxy or browser cache with the old URLs -
		// those pages would request files that no longer exist. Manual reset and
		// uninstall are the only paths that delete files.
	}

	/*
	 * There is intentionally no purge-on-update, purge-on-theme-switch or
	 * purge-on-settings-save here. See the comment in `register_hooks()`: those
	 * purges deleted files that pages still cached elsewhere were pointing at.
	 */

	/**
	 * Activation: creates the folder and schedules the daily cleanup.
	 */
	public static function activate(): void {
		self::ensure_dir();

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation: drops the scheduled cleanup, keeps the files.
	 *
	 * The files are not removed: a deactivated plugin must not break pages that
	 * are still cached with their URLs.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Cache directory of the current site.
	 */
	public static function base_dir(): string {
		if ( null === self::$base_dir ) {
			$uploads = wp_upload_dir( null, false );

			if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
				self::$base_dir = '';

				return '';
			}

			self::$base_dir = trailingslashit( (string) $uploads['basedir'] ) . self::DIR;
		}

		return (string) self::$base_dir;
	}

	/**
	 * Public URL of the cache directory of the current site.
	 */
	public static function base_url(): string {
		if ( null === self::$base_url ) {
			$uploads = wp_upload_dir( null, false );

			if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) ) {
				self::$base_url = '';

				return '';
			}

			self::$base_url = trailingslashit( (string) $uploads['baseurl'] ) . self::DIR;
		}

		return (string) self::$base_url;
	}

	/**
	 * Forgets the resolved paths, so the next call asks WordPress again.
	 *
	 * Required after `switch_to_blog()`: uploads may live in a different place
	 * per site.
	 */
	public static function flush_paths(): void {
		self::$base_dir = null;
		self::$base_url = null;
	}

	/**
	 * Creates the cache folder and the `index.php` stub.
	 */
	public static function ensure_dir(): bool {
		$dir = self::base_dir();

		if ( '' === $dir ) {
			return false;
		}

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$index = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			// The stub only stops a directory listing; a failure to write it is
			// reported by stats() and must not break the request.
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a non-fatal write of a listing stub.
		}

		return is_dir( $dir );
	}

	/**
	 * Cache name of a source string.
	 *
	 * The settings fingerprint is part of the name: with a different setting the
	 * same source must not reuse a file produced under the old rules. The plugin
	 * version is included as well, so an update never serves a stale file.
	 *
	 * @param string               $source   Source code the file is built from.
	 * @param string               $type     `js` or `css`.
	 * @param array<string,mixed>  $settings Settings that affect the result.
	 */
	public static function hash( string $source, string $type, array $settings = array() ): string {
		$signature = array(
			'type'     => $type,
			'minifier' => defined( 'MMF_MINIFIER_VERSION' ) ? (string) MMF_MINIFIER_VERSION : '',
			'settings' => $settings,
		);

		return md5( $source . '|' . wp_json_encode( $signature ) );
	}

	/**
	 * Path of a cached file, empty when the arguments are not valid.
	 *
	 * @param string $hash Cache name.
	 * @param string $type `js` or `css`.
	 */
	public static function path( string $hash, string $type, string $label = '' ): string {
		$name = self::name_for( $hash, $type, $label );
		$dir  = self::base_dir();

		if ( '' === $name || '' === $dir ) {
			return '';
		}

		return trailingslashit( $dir ) . $name;
	}

	/**
	 * Public URL of a cached file, empty when the arguments are not valid.
	 *
	 * @param string $hash Cache name.
	 * @param string $type `js` or `css`.
	 */
	public static function url( string $hash, string $type, string $label = '' ): string {
		$name = self::name_for( $hash, $type, $label );
		$base = self::base_url();

		if ( '' === $name || '' === $base ) {
			return '';
		}

		return trailingslashit( $base ) . $name;
	}

	/**
	 * Contents of a cached file, `false` on a miss.
	 *
	 * @param string $hash Cache name.
	 * @param string $type `js` or `css`.
	 * @return string|false
	 */
	public static function get( string $hash, string $type, string $label = '' ) {
		$path = self::path( $hash, $type, $label );

		if ( '' === $path || ! is_readable( $path ) ) {
			return false;
		}

		// The path is the validated hash plus a fixed extension, never raw input.
		$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- a path built from a hex hash and a whitelisted extension.

		return is_string( $contents ) && '' !== $contents ? $contents : false;
	}

	/**
	 * Whether a cached file exists.
	 *
	 * @param string $hash Cache name.
	 * @param string $type `js` or `css`.
	 */
	public static function has( string $hash, string $type, string $label = '' ): bool {
		$path = self::path( $hash, $type, $label );

		return '' !== $path && is_readable( $path );
	}

	/**
	 * Stores a minified file.
	 *
	 * Returns `false` instead of throwing: a failed cache write only costs a
	 * repeated minification, and the caller falls back to serving the result of
	 * this request from memory.
	 *
	 * @param string $hash    Cache name.
	 * @param string $content Minified code.
	 * @param string $type    `js` or `css`.
	 * @return array{hash:string,path:string,url:string,size:int}|false
	 */
	public static function put( string $hash, string $content, string $type, string $label = '' ) {
		$path = self::path( $hash, $type, $label );

		if ( '' === $path || '' === $content || ! self::ensure_dir() ) {
			return false;
		}

		// The name IS the content hash: an existing file already holds exactly
		// this payload, so a second call only reads its size.
		$existing = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a missing file is the normal first-write path.

		if ( false === $existing || $existing !== strlen( $content ) ) {
			$existing = @file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a path built from a hex hash and a whitelisted extension.

			if ( false === $existing ) {
				return false;
			}
		}

		return array(
			'hash' => $hash,
			'path' => $path,
			'url'  => self::url( $hash, $type, $label ),
			'size' => (int) $existing,
		);
	}

	/**
	 * Deletes every cached file of the current site.
	 *
	 * The folder and the `index.php` stub stay: the cache is cleared on every
	 * settings change, and recreating the directory each time would be wasted
	 * work. `uninstall()` removes the folder itself.
	 *
	 * @return int Number of deleted files.
	 */
	public static function delete_all(): int {
		$deleted = 0;

		foreach ( self::asset_files() as $file ) {
			if ( @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a concurrent cleanup may already have removed the file.
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Drops files older than the configured TTL.
	 *
	 * Runs daily from WP-Cron. A TTL of 0 means "keep until cleared by hand",
	 * which is why the age check is skipped instead of deleting everything.
	 *
	 * @return int Number of deleted files.
	 */
	/**
	 * Drops files by age, then trims the oldest until the count and size fit.
	 *
	 * Two safety rules: a file younger than an hour is never removed (a page in
	 * a reverse-proxy cache may still point at it), and limits always apply even
	 * when the TTL is 0.
	 *
	 * @param int|null $ttl_days   Age in days, null reads the setting.
	 * @param int|null $max_files  File limit, null reads the setting.
	 * @param int|null $max_size_mb Size limit in MiB, null reads the setting.
	 * @return int Number of deleted files.
	 */
	public static function cleanup( ?int $ttl_days = null, ?int $max_files = null, ?int $max_size_mb = null ): int {
		$ttl_days    = null === $ttl_days ? (int) MMF_Config::get( 'cache_ttl', 7 ) : $ttl_days;
		$max_files   = null === $max_files ? (int) MMF_Config::get( 'cache_max_files', 1000 ) : $max_files;
		$max_size_mb = null === $max_size_mb ? (int) MMF_Config::get( 'cache_max_size', 100 ) : $max_size_mb;

		$now      = time();
		$young    = $now - self::MIN_AGE;
		$deleted  = 0;
		$cutoff   = $ttl_days > 0 ? $now - $ttl_days * DAY_IN_SECONDS : 0;

		// Age first: expired files go even when the limits are not reached.
		if ( $cutoff > 0 ) {
			foreach ( self::asset_files() as $file ) {
				$modified = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a file may be removed between glob() and stat().

				if ( false === $modified || $modified > $young || $modified >= $cutoff ) {
					continue;
				}

				if ( @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- the file may be removed by a parallel request.
					$deleted++;
				}
			}
		}

		// Then the count and size caps, oldest first, never touching a young file.
		$files = array();
		$size  = 0;

		foreach ( self::asset_files() as $file ) {
			$modified = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a file may be removed by a parallel request.
			$bytes    = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a file may be removed by a parallel request.

			if ( false === $modified || false === $bytes ) {
				continue;
			}

			$files[] = array(
				'path'  => $file,
				'mtime' => (int) $modified,
				'size'  => (int) $bytes,
			);
			$size   += (int) $bytes;
		}

		usort(
			$files,
			static function ( $left, $right ) {
				return $left['mtime'] <=> $right['mtime'];
			}
		);

		$count      = count( $files );
		$max_bytes  = max( 0, $max_size_mb ) * 1024 * 1024;

		foreach ( $files as $entry ) {
			if ( $count <= $max_files && ( $max_bytes <= 0 || $size <= $max_bytes ) ) {
				break;
			}

			if ( $entry['mtime'] > $young ) {
				continue;
			}

			if ( @unlink( $entry['path'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- the file may be removed by a parallel request.
				$deleted++;
				$count--;
				$size -= $entry['size'];
			}
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( self::LAST_CLEANUP_OPTION, $now, false );
		}

		return $deleted;
	}

	/**
	 * Statistics for the admin screen: what is on disk right now.
	 *
	 * @return array{dir:string,url:string,exists:bool,writable:bool,files:int,size:int,updated:int,oldest:int}
	 */
	public static function stats(): array {
		$dir   = self::base_dir();
		$stats = array(
			'dir'      => $dir,
			'url'      => self::base_url(),
			'exists'   => false,
			'writable' => false,
			'files'    => 0,
			'size'     => 0,
			'updated'  => 0,
			'oldest'   => 0,
		);

		if ( '' === $dir ) {
			return $stats;
		}

		$stats['exists']   = is_dir( $dir );
		$stats['writable'] = $stats['exists'] ? is_writable( $dir ) : is_writable( dirname( $dir ) );

		foreach ( self::asset_files() as $file ) {
			$size     = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- the file may be removed by a parallel request.
			$modified = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- the file may be removed by a parallel request.

			if ( false !== $size ) {
				$stats['files']++;
				$stats['size'] += (int) $size;
			}

			if ( false !== $modified && (int) $modified > $stats['updated'] ) {
				$stats['updated'] = (int) $modified;
			}

			if ( false !== $modified && ( 0 === $stats['oldest'] || (int) $modified < $stats['oldest'] ) ) {
				$stats['oldest'] = (int) $modified;
			}
		}

		return $stats;
	}

	/**
	 * Removes the cache folder with everything in it.
	 *
	 * Used by `uninstall.php` only: plugin data must not outlive the plugin.
	 */
	public static function uninstall(): void {
		self::flush_paths();

		self::delete_all();

		$dir = self::base_dir();

		if ( '' === $dir ) {
			return;
		}

		$index = trailingslashit( $dir ) . 'index.php';

		if ( file_exists( $index ) ) {
			@unlink( $index ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unreadable stub is not a reason to abort the uninstall.
		}

		if ( is_dir( $dir ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- the folder may still hold a file written by another request.
		}
	}

	/**
	 * Cache lifetime in seconds from the settings.
	 */
	public static function ttl_seconds(): int {
		if ( ! class_exists( 'MMF_Config' ) ) {
			return 0;
		}

		$days = (int) MMF_Config::get( 'cache_ttl', 0 );

		return $days > 0 ? $days * DAY_IN_SECONDS : 0;
	}

	/**
	 * Cached files of the current site, both types.
	 *
	 * @return string[]
	 */
	private static function asset_files(): array {
		$dir = self::base_dir();

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return array();
		}

		$files = array();

		foreach ( self::PATTERNS as $pattern ) {
			$found = glob( trailingslashit( $dir ) . $pattern );

			if ( is_array( $found ) ) {
				$files = array_merge( $files, $found );
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Stores a file under an explicit name.
	 *
	 * Used for the downloaded font stylesheet and font binaries, whose name is
	 * built by the caller from a hash. The name is validated here as well: it is
	 * the other entry point to the filesystem, and it must not be able to leave
	 * the cache directory.
	 *
	 * @param string $name    File name inside the cache directory.
	 * @param string $content File contents.
	 */
	public static function write_file( string $name, string $content ): bool {
		if ( ! self::safe_file_name( $name ) || '' === $content || ! self::ensure_dir() ) {
			return false;
		}

		$path = trailingslashit( self::base_dir() ) . $name;

		return false !== @file_put_contents( $path, $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a validated name inside the cache directory.
	}

	/**
	 * Reads a file stored under an explicit name.
	 *
	 * @param string $name File name inside the cache directory.
	 * @return string|false
	 */
	public static function read_file( string $name ) {
		$path = self::file_path( $name );

		if ( '' === $path || ! is_readable( $path ) ) {
			return false;
		}

		$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- a validated name inside the cache directory.

		return is_string( $contents ) && '' !== $contents ? $contents : false;
	}

	/**
	 * Whether a file stored under an explicit name exists.
	 *
	 * @param string $name File name inside the cache directory.
	 */
	public static function has_file( string $name ): bool {
		$path = self::file_path( $name );

		return '' !== $path && is_readable( $path );
	}

	/**
	 * Public URL of a file stored under an explicit name.
	 *
	 * @param string $name File name inside the cache directory.
	 */
	public static function url_of( string $name ): string {
		$base = self::base_url();

		if ( '' === $base || ! self::safe_file_name( $name ) ) {
			return '';
		}

		return trailingslashit( $base ) . $name;
	}

	/**
	 * File name of a cache entry, empty when the arguments are not valid.
	 *
	 * The hash must be a plain 32-character hex string: this is what keeps an
	 * unexpected value from reaching the filesystem.
	 *
	 * @param string $hash Cache name.
	 * @param string $type `js` or `css`.
	 */
	/**
	 * Readable file name: the original name plus a short hash.
	 *
	 * The owner asked to see what a file is: a bare hash says nothing, while
	 * cleantalk-public.min_8f3a1c.css says everything. The hash still makes the
	 * name unique per content, so the cache contract does not change.
	 *
	 * @param string $label Original file name, may be empty.
	 * @param string $hash  Content hash.
	 * @param string $type  css or js.
	 */
	public static function readable_name( string $label, string $hash, string $type ): string {
		$short = substr( (string) preg_replace( '/[^a-z0-9]/i', '', $hash ), 0, 6 );
		$short = '' !== $short ? $short : 'cache';

		$label = strtolower( basename( $label ) );
		$label = (string) preg_replace( '/[^a-z0-9._-]+/', '-', $label );
		$label = trim( $label, '.-' );

		$ext = 'css' === $type ? 'css' : 'js';

		if ( '' === $label ) {
			return 'mmf_' . $short . '.' . $ext;
		}

		$suffix = '.' . $ext;
		$base   = str_ends_with( $label, $suffix ) ? substr( $label, 0, -strlen( $suffix ) ) : $label;
		$base   = trim( $base, '.-' );

		if ( '' === $base ) {
			$base = 'mmf';
		}

		if ( strlen( $base ) > 60 ) {
			$base = substr( $base, 0, 60 );
		}

		return $base . '_' . $short . '.' . $ext;
	}

	/**
	 * File name for a hash, readable when a label is known.
	 *
	 * @param string $hash  Content hash.
	 * @param string $type  css or js.
	 * @param string $label Original file name, may be empty.
	 */
	private static function name_for( string $hash, string $type, string $label = '' ): string {
		return '' === $label ? self::file_name( $hash, $type ) : self::readable_name( $label, $hash, $type );
	}

	private static function file_name( string $hash, string $type ): string {
		if ( ! isset( self::EXTENSIONS[ $type ] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
			return '';
		}

		return $hash . self::EXTENSIONS[ $type ];
	}

	/**
	 * Path of a file stored under an explicit name.
	 *
	 * @param string $name File name inside the cache directory.
	 */
	private static function file_path( string $name ): string {
		$dir = self::base_dir();

		if ( '' === $dir || ! self::safe_file_name( $name ) ) {
			return '';
		}

		return trailingslashit( $dir ) . $name;
	}

	/**
	 * Whether a name can be used as a cache file name.
	 *
	 * Plain lower-case letters, digits, dots and dashes only: no slash, no
	 * backslash, no leading dot, so nothing can escape the directory or become
	 * a hidden file.
	 *
	 * @param string $name File name inside the cache directory.
	 */
	private static function safe_file_name( string $name ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9._-]{0,127}$/', $name );
	}
}
