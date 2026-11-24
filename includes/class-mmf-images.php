<?php
/**
 * WebP sidecars for uploaded images.
 *
 * Converters are detected once, best first: Imagick, then GD, then the cwebp
 * binary. No Composer package is involved and nothing is written outside the
 * uploads tree.
 *
 * A sidecar is a plain file next to the original: `photo.jpg.webp`. It is not
 * an attachment, so the media library does not list it twice, and it is removed
 * together with the original. Originals are only read, never modified.
 *
 * Serving is minimal: only the `src` and `srcset` attribute values of `<img>`
 * tags are swapped, and only when the visitor accepts WebP and the sidecar
 * already exists. No `<picture>`, no extra tags.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Images' ) ) {
	return;
}

/**
 * Converts uploads to WebP sidecars and serves them.
 */
final class MMF_Images {

	public const MIN_BYTES = 10240;

	public const JOBS_OPTION = 'mmf_webp_jobs';
	public const VIEW_LOCK = 'mmf_webp_view_lock';
	public const CRON_HOOK = 'mmf_webp_process';
	public const LAST_QUEUE_OPTION = 'mmf_webp_last_queue';
	public const LAST_CRON_OPTION = 'mmf_webp_last_cron';
	public const LAST_CRON_COUNT = 'mmf_webp_last_cron_count';

	/**
	 * Detected converter, `null` until first use.
	 */
	private static ?string $method = null;

	/**
	 * Whether the output buffer is already started.
	 */
	private static bool $buffering = false;

	/**
	 * Served on this request, flushed once at shutdown.
	 */
	private static int $served = 0;

	/**
	 * Bytes saved on this request.
	 */
	private static int $saved = 0;

	/**
	 * URLs already counted in this request.
	 *
	 * @var array<string,bool>
	 */
	private static array $counted = array();

	/**
	 * Sidecar validity, memoised for the request: stat plus twelve bytes.
	 *
	 * @var array<string,bool>
	 */
	private static array $sidecar_ok = array();

	/**
	 * Registered the hooks.
	 */
	public static function register_hooks(): void {
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_metadata' ), 20, 2 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_buffer' ), 2 );
		add_action( 'shutdown', array( __CLASS__, 'process_queue' ) );
		add_action( 'init', array( __CLASS__, 'sync_cron' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_process' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush_stats' ) );
	}

	/**
	 * Converter name: `imagick`, `gd`, `cwebp` or an empty string.
	 */
	public static function method(): string {
		if ( null !== self::$method ) {
			return self::$method;
		}

		$method = '';

		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			try {
				$formats = \Imagick::queryFormats( 'WEBP' );
				$method  = is_array( $formats ) && array() !== $formats ? 'imagick' : '';
			} catch ( \Throwable $error ) {
				$method = '';
			}
		}

		if ( '' === $method && function_exists( 'imagewebp' ) && function_exists( 'imagetypes' ) && defined( 'IMG_WEBP' ) && ( imagetypes() & IMG_WEBP ) ) {
			$method = 'gd';
		}

		if ( '' === $method ) {
			$binary = self::find_cwebp();

			if ( '' !== $binary ) {
				$method = 'cwebp';
			}
		}

		self::$method = $method;

		return self::$method;
	}

	/**
	 * Path of a cwebp binary, empty when unavailable.
	 */
	private static function find_cwebp(): string {
		if ( ! function_exists( 'exec' ) ) {
			return '';
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		if ( in_array( 'exec', $disabled, true ) ) {
			return '';
		}

		$command = DIRECTORY_SEPARATOR === '\\' ? 'where cwebp' : 'which cwebp';
		$output  = array();
		$code    = 1;

		@exec( $command . ' 2>' . ( DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null' ), $output, $code ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions -- detection only.

		if ( 0 !== $code || ! isset( $output[0] ) ) {
			return '';
		}

		$path = trim( (string) $output[0] );

		return is_file( $path ) ? $path : '';
	}

	/**
	 * Whether conversion applies to this request/site.
	 */
	public static function enabled(): bool {
		return '' !== self::method() && (bool) MMF_Config::get( 'image_webp', true );
	}

	/**
	 * Sidecar path of a file.
	 *
	 * @param string $file Original path.
	 */
	public static function sidecar( string $file ): string {
		return $file . '.webp';
	}

	/**
	 * Absolute path of an attachment's original file.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function attachment_file( int $id ): string {
		$metadata = wp_get_attachment_metadata( $id );
		$file     = is_array( $metadata ) && isset( $metadata['file'] ) ? (string) $metadata['file'] : '';

		if ( '' === $file ) {
			return '';
		}

		$uploads = wp_upload_dir( null, false );

		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) ) {
			return '';
		}

		return trailingslashit( (string) $uploads['basedir'] ) . $file;
	}

	/**
	 * Whether an attachment still needs a sidecar.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function needs_sidecar( int $id ): bool {
		$file = self::attachment_file( $id );

		if ( '' === $file ) {
			return false;
		}

		$type = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );

		if ( ! in_array( $type, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return false;
		}

		return ! self::usable_sidecar( $file );
	}

	/**
	 * Converts the original and the generated sizes of one attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string,int> Counters by outcome.
	 */
	public static function convert_attachment( int $id ): array {
		$out = array(
			'created'      => 0,
			'recreated'    => 0,
			'reused'       => 0,
			'already-webp' => 0,
			'skipped'      => 0,
			'small'        => 0,
			'error'        => 0,
			'bigger'       => 0,
		);

		$mime = function_exists( 'get_post_mime_type' ) ? strtolower( (string) get_post_mime_type( $id ) ) : '';

		if ( 'image/webp' === $mime ) {
			MMF_Stats::increment( 'image_webp_existing' );
			$out['already-webp']++;

			return $out;
		}

		$metadata = wp_get_attachment_metadata( $id );
		$file     = is_array( $metadata ) && isset( $metadata['file'] ) ? (string) $metadata['file'] : '';

		if ( '' === $file ) {
			return $out;
		}

		$uploads = wp_upload_dir( null, false );

		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) ) {
			return $out;
		}

		$base  = trailingslashit( (string) $uploads['basedir'] );
		$dir   = trailingslashit( dirname( $file ) );
		$paths = array( $base . $file );

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) ) {
				$paths[] = $base . $dir . (string) $size['file'];
			}
		}

		foreach ( $paths as $path ) {
			$result = self::convert_file( $path );

			if ( isset( $out[ $result ] ) ) {
				$out[ $result ]++;
			}
		}

		return $out;
	}

	/**
	 * Converts the original and every generated size of an attachment.
	 *
	 * @param mixed $metadata Attachment metadata.
	 * @param mixed $id       Attachment ID.
	 * @return mixed
	 */
	public static function on_metadata( $metadata, $id ) {
		if ( ! is_array( $metadata ) || ! self::enabled() ) {
			return $metadata;
		}

		$mime = function_exists( 'get_post_mime_type' ) ? (string) get_post_mime_type( (int) $id ) : '';

		if ( 'image/webp' === strtolower( $mime ) ) {
			MMF_Stats::increment( 'image_webp_existing' );

			return $metadata;
		}

		$file = isset( $metadata['file'] ) ? (string) $metadata['file'] : '';

		if ( '' === $file ) {
			return $metadata;
		}

		$uploads = wp_upload_dir( null, false );

		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) ) {
			return $metadata;
		}

		$base  = trailingslashit( (string) $uploads['basedir'] );
		$dir   = trailingslashit( dirname( $file ) );
		$paths = array( $base . $file );

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) ) {
				$paths[] = $base . $dir . (string) $size['file'];
			}
		}

		foreach ( $paths as $path ) {
			self::convert_file( $path );
		}

		return $metadata;
	}

	/**
	 * Removes sidecars of an attachment before it is deleted.
	 *
	 * @param mixed $id Attachment ID.
	 */
	public static function on_delete( $id ): void {
		$metadata = wp_get_attachment_metadata( (int) $id );
		$file     = is_array( $metadata ) && isset( $metadata['file'] ) ? (string) $metadata['file'] : '';

		if ( '' === $file ) {
			return;
		}

		$uploads = wp_upload_dir( null, false );

		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) ) {
			return;
		}

		$base  = trailingslashit( (string) $uploads['basedir'] );
		$dir   = trailingslashit( dirname( $file ) );
		$paths = array( $base . $file );

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) ) {
				$paths[] = $base . $dir . (string) $size['file'];
			}
		}

		foreach ( $paths as $path ) {
			$sidecar = self::sidecar( $path );

			if ( is_file( $sidecar ) ) {
				@unlink( $sidecar ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a concurrent cleanup may have removed it.
			}
		}
	}

	/**
	 * Converts one file into its sidecar.
	 *
	 * @param string $path Original path.
	 * @return string `created`, `skipped`, `bigger`, `error` or `exists`.
	 */
	public static function convert_file( string $path ): string {
		$sidecar = self::sidecar( $path );

		if ( ! self::enabled() || ! is_file( $path ) || ! is_readable( $path ) ) {
			return 'skipped';
		}

		$type = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( 'webp' === $type ) {
			MMF_Stats::increment( 'image_webp_existing' );

			return 'already-webp';
		}

		if ( ! in_array( $type, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return 'skipped';
		}

		if ( (int) filesize( $path ) < self::MIN_BYTES ) {
			MMF_Stats::increment( 'image_small' );

			return 'small';
		}

		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a broken file is the expected failure.

		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			MMF_Stats::increment( 'image_errors' );

			return 'error';
		}

		$recreate = false;

		if ( is_file( $sidecar ) ) {
			if ( self::usable_sidecar( $path ) ) {
				MMF_Stats::increment( 'image_reused' );

				return 'reused';
			}

			$recreate = true;
		}

		$ok = self::write_sidecar( $path, $sidecar, $type );

		if ( ! $ok ) {
			MMF_Stats::increment( 'image_errors' );

			return 'error';
		}

		if ( (int) filesize( $sidecar ) >= (int) filesize( $path ) ) {
			@unlink( $sidecar ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- our own file.

			MMF_Stats::increment( 'image_bigger' );

			return 'bigger';
		}

		if ( $recreate ) {
			self::$sidecar_ok = array();
			MMF_Stats::increment( 'image_recreated' );

			return 'recreated';
		}

		MMF_Stats::increment( 'image_created' );

		return 'created';
	}

	/**
	 * Runs the detected converter.
	 *
	 * @param string $path    Source.
	 * @param string $target  Sidecar.
	 * @param string $type    `jpg`, `jpeg` or `png`.
	 */
	private static function write_sidecar( string $path, string $target, string $type, ?int $quality = null, ?bool $lossless = null ): bool {
		$quality  = null === $quality ? ( 'png' === $type ? (int) MMF_Config::get( 'image_quality_png', 82 ) : (int) MMF_Config::get( 'image_quality_jpeg', 82 ) ) : $quality;
		$quality  = max( 60, min( 95, $quality ) );
		$lossless = null === $lossless ? ( 'png' === $type && (bool) MMF_Config::get( 'image_png_lossless', true ) ) : $lossless;

		if ( $lossless ) {
			$quality = 100;
		}

		$tmp = $target . '.tmp';

		if ( file_exists( $tmp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- stale temporary file.
		}

		$method = self::method();
		$ok     = false;

		if ( 'imagick' === $method ) {
			$ok = self::via_imagick( $path, $tmp, $quality, 'png' === $type );
		} elseif ( 'gd' === $method ) {
			$ok = self::via_gd( $path, $tmp, $quality, $type );
		} elseif ( 'cwebp' === $method ) {
			$ok = self::via_cwebp( $path, $tmp, $quality, 'png' === $type );
		}

		if ( ! $ok || ! is_file( $tmp ) || 0 === (int) filesize( $tmp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- nothing to keep.

			return false;
		}

		return (bool) @rename( $tmp, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a concurrent request may have written it.
	}

	/**
	 * Imagick conversion.
	 *
	 * @param string $path     Source.
	 * @param string $tmp      Target.
	 * @param int    $quality  Quality.
	 * @param bool   $lossless PNG lossless mode.
	 */
	private static function via_imagick( string $path, string $tmp, int $quality, bool $lossless ): bool {
		try {
			$image = new \Imagick( $path );
			$image->setImageFormat( 'webp' );
			$image->setImageCompressionQuality( $quality );

			if ( $lossless ) {
				$image->setOption( 'webp:lossless', 'true' );
			}

			$ok = $image->writeImage( $tmp );
			$image->clear();

			return (bool) $ok;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * GD conversion.
	 *
	 * @param string $path    Source.
	 * @param string $tmp     Target.
	 * @param int    $quality Quality.
	 * @param string $type    Source type.
	 */
	private static function via_gd( string $path, string $tmp, int $quality, string $type ): bool {
		if ( 'png' === $type ) {
			$image = function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a broken file is reported by false.
		} else {
			$image = function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a broken file is reported by false.
		}

		if ( ! $image ) {
			return false;
		}

		if ( 'png' === $type ) {
			@imagepalettetotruecolor( $image ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- truecolor PNGs are fine.
			@imagealphablending( $image, false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- not fatal without alpha.
			@imagesavealpha( $image, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- not fatal without alpha.
		}

		$ok = @imagewebp( $image, $tmp, $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- reported by the return value.
		@imagedestroy( $image ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- cleanup.

		return (bool) $ok;
	}

	/**
	 * cwebp conversion.
	 *
	 * @param string $path     Source.
	 * @param string $tmp      Target.
	 * @param int    $quality  Quality.
	 * @param bool   $lossless PNG lossless mode.
	 */
	private static function via_cwebp( string $path, string $tmp, int $quality, bool $lossless ): bool {
		$binary = self::find_cwebp();

		if ( '' === $binary ) {
			return false;
		}

		$command = escapeshellarg( $binary )
			. ' -quiet'
			. ( $lossless ? ' -lossless' : ' -q ' . (int) $quality )
			. ' ' . escapeshellarg( $path )
			. ' -o ' . escapeshellarg( $tmp );

		$output = array();
		$code   = 1;

		@exec( $command . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions -- the detected binary and escaped arguments.

		return 0 === $code && is_file( $tmp );
	}

	/**
	 * Writes the per-request serving counters once.
	 */
	public static function flush_stats(): void {
		if ( self::$served > 0 ) {
			MMF_Stats::increment( 'image_served', self::$served );
		}

		if ( self::$saved > 0 ) {
			MMF_Stats::increment( 'image_saved', self::$saved );
		}

		self::$served  = 0;
		self::$saved   = 0;
		self::$counted = array();
	}

	/**
	 * Converts one file to an explicit sidecar with a given quality.
	 *
	 * Used by the avatar converter, which has its own quality setting and must
	 * write next to the avatar instead of the clearable cache.
	 *
	 * @param string $file     Source.
	 * @param string $target   Target path.
	 * @param int    $quality  60-95.
	 * @param bool   $lossless PNG lossless mode.
	 */
	public static function convert_to( string $file, string $target, int $quality, bool $lossless ): bool {
		if ( '' === self::method() || ! is_file( $file ) ) {
			return false;
		}

		$type = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );

		if ( ! in_array( $type, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return false;
		}

		return self::write_sidecar( $file, $target, $type, $quality, $lossless );
	}

	/**
	 * Queues a file for conversion on shutdown, once.
	 *
	 * @param string $file Original path.
	 */
	public static function enqueue( string $file ): void {
		if ( ! (bool) MMF_Config::get( 'image_webp_ondemand', false ) || ! is_file( $file ) ) {
			return;
		}

		$jobs = get_option( self::JOBS_OPTION, array() );
		$jobs = is_array( $jobs ) ? $jobs : array();

		if ( in_array( $file, $jobs, true ) ) {
			return;
		}

		$jobs[] = $file;
		update_option( self::JOBS_OPTION, array_values( array_unique( $jobs ) ), false );
		MMF_Stats::increment( 'convert_queued' );
	}

	/**
	 * Converts at most two queued files after the response, under a lock.
	 */
	public static function process_queue(): void {
		if ( ! (bool) MMF_Config::get( 'image_webp_ondemand', false ) ) {
			return;
		}

		$lock = (int) get_option( self::VIEW_LOCK, 0 );

		if ( $lock > 0 && ( time() - $lock ) < 60 ) {
			return;
		}

		$jobs = get_option( self::JOBS_OPTION, array() );

		if ( ! is_array( $jobs ) || array() === $jobs ) {
			return;
		}

		update_option( self::VIEW_LOCK, time(), false );

		$chunk = array_splice( $jobs, 0, 2 );

		foreach ( $chunk as $file ) {
			if ( is_string( $file ) && is_file( $file ) && ! self::usable_sidecar( $file ) ) {
				self::convert_file( $file );
			}
		}

		if ( array() === $jobs ) {
			delete_option( self::JOBS_OPTION );
		} else {
			update_option( self::JOBS_OPTION, array_values( $jobs ), false );
		}

		update_option( self::LAST_QUEUE_OPTION, time(), false );
		delete_option( self::VIEW_LOCK );
	}

	/**
	 * Whether a sidecar is valid and not older than the original.
	 *
	 * Cheap by design: one stat of each file and twelve bytes of the sidecar,
	 * memoised per request. RIFF size is checked against the real file size, so
	 * a truncated file with a plausible start is rejected.
	 *
	 * @param string $file Original path.
	 */
	public static function usable_sidecar( string $file ): bool {
		$sidecar = self::sidecar( $file );
		$key     = $sidecar . '|' . (string) @filemtime( $file ) . '|' . (string) @filemtime( $sidecar ) . '|' . (string) @filesize( $sidecar ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a missing file is a false result.

		if ( isset( self::$sidecar_ok[ $key ] ) ) {
			return self::$sidecar_ok[ $key ];
		}

		$ok = false;

		if ( is_file( $sidecar ) && is_file( $file ) ) {
			$sidecar_mtime = @filemtime( $sidecar ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- reported as not usable.
			$source_mtime  = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- reported as not usable.

			if ( false !== $sidecar_mtime && false !== $source_mtime && $sidecar_mtime >= $source_mtime ) {
				$ok = self::valid_header( $sidecar );
			}
		}

		self::$sidecar_ok[ $key ] = $ok;

		return $ok;
	}

	/**
	 * RIFF/WEBP header check.
	 *
	 * @param string $sidecar Sidecar path.
	 */
	private static function valid_header( string $sidecar ): bool {
		$size = @filesize( $sidecar ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a missing file is invalid.

		if ( false === $size || $size < 20 ) {
			return false;
		}

		$handle = @fopen( $sidecar, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unreadable file is invalid.

		if ( ! $handle ) {
			return false;
		}

		$head = (string) fread( $handle, 16 );
		fclose( $handle );

		if ( 16 !== strlen( $head ) || 'RIFF' !== substr( $head, 0, 4 ) || 'WEBP' !== substr( $head, 8, 4 ) ) {
			return false;
		}

		$declared = unpack( 'V', substr( $head, 4, 4 ) );

		if ( ! is_array( $declared ) || ! isset( $declared[1] ) ) {
			return false;
		}

		// RIFF size excludes the first eight bytes; a truncated file lies about it.
		return ( (int) $declared[1] + 8 ) <= (int) $size;
	}

	/**
	 * Starts the output buffer when serving can change something.
	 */
	public static function maybe_buffer(): void {
		if ( self::$buffering || is_admin() || wp_doing_cron() || ob_get_level() > 0 ) {
			return;
		}

		if ( ! (bool) MMF_Config::get( 'image_webp', true ) ) {
			return;
		}

		if ( (bool) MMF_Config::get( 'webp_require_accept', false ) && ! self::accepts_webp() ) {
			return;
		}

		self::$buffering = true;

		ob_start( array( __CLASS__, 'rewrite_html' ) );
	}

	/**
	 * Whether the visitor accepts WebP.
	 */
	private static function accepts_webp(): bool {
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : '';

		return '' !== $accept && false !== stripos( $accept, 'image/webp' );
	}

	/**
	 * Swaps `src` and `srcset` values of `<img>` tags.
	 *
	 * @param string $html Buffered HTML.
	 */
	public static function rewrite_html( string $html ): string {
		if ( '' === $html || false === stripos( $html, '<img' ) ) {
			return $html;
		}

		$out = '';

		foreach ( MMF_HTML::tokenize( $html ) as $token ) {
			if ( 'tag' !== $token['type'] || $token['closing'] || 'img' !== $token['name'] ) {
				$out .= (string) $token['raw'];
				continue;
			}

			$tag = (string) $token['raw'];

			if ( MMF_Assets::has_attribute( $tag, 'src' ) ) {
				$src = (string) ( MMF_Assets::attributes( $tag )['src'] ?? '' );
				$new = self::webp_url( $src );

				if ( $new !== $src ) {
					$tag = MMF_Assets::replace_attribute( $tag, 'src', $new );
				}
			}

			if ( MMF_Assets::has_attribute( $tag, 'srcset' ) ) {
				$srcset = (string) ( MMF_Assets::attributes( $tag )['srcset'] ?? '' );
				$tag    = MMF_Assets::replace_attribute( $tag, 'srcset', self::rewrite_srcset( $srcset ) );
			}

			$out .= $tag;
		}

		return $out;
	}

	/**
	 * Rewrites every candidate of a srcset consistently.
	 *
	 * @param string $srcset Value.
	 */
	public static function rewrite_srcset( string $srcset ): string {
		$parts = explode( ',', $srcset );

		foreach ( $parts as $index => $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				unset( $parts[ $index ] );
				continue;
			}

			$bits = preg_split( '/\s+/', $part );
			$url  = (string) ( $bits[0] ?? '' );

			if ( '' !== $url ) {
				$bits[0] = self::webp_url( $url );
			}

			$parts[ $index ] = implode( ' ', $bits );
		}

		return implode( ', ', $parts );
	}

	/**
	 * WebP URL of an uploads image, or the original.
	 *
	 * @param string $url Image URL.
	 */
	public static function webp_url( string $url ): string {
		if ( '' === $url || str_starts_with( $url, 'data:' ) ) {
			return $url;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return $url;
		}

		$file = MMF_Assets::resolve_path( $url, $ext );

		if ( '' === $file || ! self::usable_sidecar( $file ) ) {
			if ( '' !== $file && (bool) MMF_Config::get( 'image_webp_ondemand', false ) ) {
				self::enqueue( $file );
			} elseif ( '' !== $file ) {
				MMF_Stats::increment( 'image_missing' );
			}

			return $url;
		}

		$quote = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$webp  = preg_replace( '/(\?.*)?$/', '', $url );

		if ( ! is_string( $webp ) ) {
			return $url;
		}

		if ( ! isset( self::$counted[ $url ] ) ) {
			self::$counted[ $url ] = true;
			self::$served++;
			self::$saved += max( 0, (int) filesize( $file ) - (int) filesize( self::sidecar( $file ) ) );
		}

		return $webp . '.webp' . ( '' !== $quote ? '?' . $quote : '' );
	}

	/**
	 * Sidecars present on disk: count and bytes.
	 *
	 * @return array{files:int,bytes:int}
	 */
	public static function disk_stats(): array {
		$uploads = wp_upload_dir( null, false );

		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || ! is_dir( (string) $uploads['basedir'] ) ) {
			return array( 'files' => 0, 'bytes' => 0 );
		}

		$files = 0;
		$bytes = 0;

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( (string) $uploads['basedir'], FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && preg_match( '/\.(jpe?g|png)\.webp$/i', $file->getFilename() ) ) {
				$files++;
				$bytes += (int) $file->getSize();
			}
		}

		return array( 'files' => $files, 'bytes' => $bytes );
	}

	/**
	 * Five-minute schedule for the cron fallback.
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function cron_schedules( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();
		$schedules['mmf_five_minutes'] = array( 'interval' => 300, 'display' => __( 'Modern Minify: каждые 5 минут', 'modern-minify' ) );

		return $schedules;
	}

	/**
	 * Schedules or clears the fallback depending on the option.
	 */
	public static function sync_cron(): void {
		$on = (bool) MMF_Config::get( 'image_webp_ondemand', false );

		if ( $on && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'mmf_five_minutes', self::CRON_HOOK );
		} elseif ( ! $on && wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Cron fallback: ten queued files, then a scan of recent uploads.
	 */
	/**
	 * Rebuilds the sidecars of the library, a batch at a time.
	 *
	 * Changing the quality deletes every generated copy, and nothing brings them
	 * back until a visitor happens to load the image. This walks the library in
	 * the background instead: the flag is set once by the settings screen and
	 * cleared when no attachment is left without a fresh sidecar.
	 *
	 * @return int Files converted in this run.
	 */
	public static function rebuild_sidecars(): int {
		if ( ! (bool) get_option( 'mmf_rebuild_sidecars', false ) ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'fields'         => 'ids',
				'posts_per_page' => 40,
				'no_found_rows'  => true,
			)
		);

		$done  = 0;
		$start = microtime( true );

		foreach ( (array) $ids as $id ) {
			if ( ( microtime( true ) - $start ) > 8 ) {
				break;
			}

			if ( self::needs_sidecar( (int) $id ) ) {
				self::convert_attachment( (int) $id );
				$done++;
			}
		}

		if ( 0 === $done ) {
			delete_option( 'mmf_rebuild_sidecars' );
		}

		return $done;
	}

	public static function cron_process(): void {
		self::rebuild_sidecars();

		if ( ! (bool) MMF_Config::get( 'image_webp_ondemand', false ) ) {
			return;
		}

		$jobs = get_option( self::JOBS_OPTION, array() );
		$jobs = is_array( $jobs ) ? $jobs : array();
		$done = 0;

		if ( array() !== $jobs ) {
			$batch = min( 30, max( 10, (int) ceil( count( $jobs ) / 4 ) ) );
			$chunk = array_splice( $jobs, 0, $batch );
			$start = microtime( true );

			foreach ( $chunk as $file ) {
				if ( ( microtime( true ) - $start ) > 8 ) {
					$jobs[] = $file;
					continue;
				}

				if ( is_string( $file ) && is_file( $file ) && ! self::usable_sidecar( $file ) ) {
					self::convert_file( $file );
					$done++;
				}
			}

			if ( array() === $jobs ) {
				delete_option( self::JOBS_OPTION );
			} else {
				update_option( self::JOBS_OPTION, array_values( $jobs ), false );
			}
		} else {
			$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => array( 'image/jpeg', 'image/png' ), 'fields' => 'ids', 'posts_per_page' => 10, 'no_found_rows' => true ) );

			$scan_start = microtime( true );

			foreach ( (array) $ids as $id ) {
				if ( ( microtime( true ) - $scan_start ) > 8 ) {
					break;
				}

				if ( self::needs_sidecar( (int) $id ) ) {
					self::convert_attachment( (int) $id );
					$done++;
				}
			}
		}

		update_option( self::LAST_CRON_OPTION, time(), false );
		update_option( self::LAST_CRON_COUNT, $done, false );
		update_option( self::LAST_QUEUE_OPTION, time(), false );
	}

	/**
	 * Deletes every generated sidecar under uploads.
	 *
	 * @return int Number of deleted files.
	 */
	public static function delete_all(): int {
		$uploads = wp_upload_dir( null, false );

		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || ! is_dir( (string) $uploads['basedir'] ) ) {
			return 0;
		}

		$deleted  = 0;
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( (string) $uploads['basedir'], FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || ! str_ends_with( strtolower( $file->getFilename() ), '.webp' ) ) {
				continue;
			}

			$name = $file->getFilename();

			if ( ! preg_match( '/\.(jpe?g|png)\.webp$/i', $name ) ) {
				continue;
			}

			if ( @unlink( $file->getPathname() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a concurrent cleanup may win.
				$deleted++;
			}
		}

		return $deleted;
	}
}
