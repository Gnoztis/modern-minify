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
		'js'  => '.min.js',
		'css' => '.min.css',
	);

	/**
	 * Every file the plugin may create in the cache directory.
	 *
	 * Used for statistics, the TTL cleanup and the full delete, so a new kind of
	 * cached file cannot be left behind by forgetting to add it in one place.
	 */
	private const PATTERNS = array(
		'*.min.js',
		'*.min.css',
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

		add_action( 'upgrader_process_complete', array( __CLASS__, 'purge_on_upgrade' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'purge_on_theme_switch' ) );
		add_action( 'update_option_mmf_settings', array( __CLASS__, 'purge_on_settings_save' ) );
		add_action( 'add_option_mmf_settings', array( __CLASS__, 'purge_on_settings_save' ) );
	}

	/**
	 * Purges the cache after a plugin, theme or core update.
	 *
	 * @param mixed $upgrader Upgrader instance, not used.
	 * @param mixed $options  Update context: `action` and `type`.
	 */
	public static function purge_on_upgrade( $upgrader = null, $options = null ): void {
		unset( $upgrader );

		if ( ! is_array( $options ) ) {
			return;
		}

		$action = isset( $options['action'] ) && is_string( $options['action'] ) ? $options['action'] : '';
		$type   = isset( $options['type'] ) && is_string( $options['type'] ) ? $options['type'] : '';

		if ( ! in_array( $action, array( 'update', 'install' ), true ) ) {
			return;
		}

		if ( ! in_array( $type, array( 'plugin', 'theme', 'core', 'translation' ), true ) ) {
			return;
		}

		self::delete_all();
	}

	/**
	 * Purges the cache when the theme changes.
	 */
	public static function purge_on_theme_switch(): void {
		self::delete_all();
	}

	/**
	 * Purges the cache when the settings are saved.
	 *
	 * The settings decide what is minified and how, so every file built under
	 * the previous rules is obsolete.
	 */
	public static function purge_on_settings_save(): void {
		self::delete_all();
	}

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
			'version'  => defined( 'MMF_VERSION' ) ? (string) MMF_VERSION : '',
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
	public static function path( string $hash, string $type ): string {
		$name = self::file_name( $hash, $type );
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
	public static function url( string $hash, string $type ): string {
		$name = self::file_name( $hash, $type );
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
	public static function get( string $hash, string $type ) {
		$path = self::path( $hash, $type );

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
	public static function has( string $hash, string $type ): bool {
		$path = self::path( $hash, $type );

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
	public static function put( string $hash, string $content, string $type ) {
		$path = self::path( $hash, $type );

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
			'url'  => self::url( $hash, $type ),
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
	public static function cleanup(): int {
		$ttl = self::ttl_seconds();

		if ( $ttl <= 0 ) {
			return 0;
		}

		$cutoff  = time() - $ttl;
		$deleted = 0;

		foreach ( self::asset_files() as $file ) {
			$modified = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a file may be removed between glob() and stat().

			if ( false === $modified || $modified >= $cutoff ) {
				continue;
			}

			if ( @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- the file may be removed by a parallel request.
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Statistics for the admin screen: what is on disk right now.
	 *
	 * @return array{dir:string,url:string,exists:bool,writable:bool,files:int,size:int,updated:int}
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
