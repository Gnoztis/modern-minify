<?php
/**
 * WebP copies of local user avatars.
 *
 * Gravatars are already replaced with an inline SVG; this covers the other
 * case: a plugin (or WordPress itself) points an avatar at an uploaded JPEG or
 * PNG on this site. The file is converted once into the cache and the URL is
 * swapped, nothing else - no `<picture>`, no markup replacement, no change to
 * the original file.
 *
 * The conversion is deliberately conservative:
 *
 *   - only local files that exist and only JPEG/PNG; data URIs, external URLs,
 *     SVG and GIF (animation) are left alone;
 *   - only when the visitor sent `Accept: image/webp`;
 *   - the cache key is the path, its mtime and the quality, so the existing TTL
 *     and limits apply and a changed file gets a new name;
 *   - any failure returns the original URL and raises the error counter.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Avatars' ) ) {
	return;
}

/**
 * Converts local avatar files to WebP.
 */
final class MMF_Avatars {

	/**
	 * Converted on this request.
	 */
	private static int $converted = 0;

	/**
	 * Bytes saved on this request.
	 */
	private static int $saved = 0;

	/**
	 * Failed conversions on this request.
	 */
	private static int $errors = 0;

	/**
	 * URLs handled on this request, so one avatar is counted once.
	 *
	 * @var array<string,bool>
	 */
	private static array $done = array();

	/**
	 * WebP support, `null` until detected.
	 */
	private static ?bool $supports = null;

	/**
	 * Registers the filters after other avatar providers.
	 */
	public static function register_hooks(): void {
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'filter_data' ), 99, 2 );
		add_filter( 'get_avatar_url', array( __CLASS__, 'filter_url' ), 99, 3 );
	}

	/**
	 * Resets the per-request counters.
	 */
	public static function reset(): void {
		self::$converted = 0;
		self::$saved     = 0;
		self::$errors    = 0;
		self::$done      = array();
	}

	/**
	 * Converted avatars in this request.
	 */
	public static function converted(): int {
		return self::$converted;
	}

	/**
	 * Bytes saved in this request.
	 */
	public static function saved(): int {
		return self::$saved;
	}

	/**
	 * Failed conversions in this request.
	 */
	public static function errors(): int {
		return self::$errors;
	}

	/**
	 * Whether this server can write WebP.
	 */
	public static function supports_webp(): bool {
		if ( null !== self::$supports ) {
			return self::$supports;
		}

		$supports = function_exists( 'imagewebp' )
			&& function_exists( 'imagetypes' )
			&& defined( 'IMG_WEBP' )
			&& ( imagetypes() & IMG_WEBP );

		if ( ! $supports && class_exists( 'Imagick' ) ) {
			try {
				$formats = \Imagick::queryFormats( 'WEBP' );

				$supports = is_array( $formats ) && array() !== $formats;
			} catch ( \Throwable $error ) {
				$supports = false;
			}
		}

		self::$supports = (bool) $supports;

		return self::$supports;
	}

	/**
	 * Replaces `$args['url']` when it points at a local JPEG/PNG.
	 *
	 * @param mixed $args        Avatar arguments.
	 * @param mixed $id_or_email User identifier.
	 * @return mixed
	 */
	public static function filter_data( $args, $id_or_email ) {
		if ( ! self::active() || ! is_array( $args ) || empty( $args['url'] ) || ! is_string( $args['url'] ) ) {
			return $args;
		}

		$webp = self::webp_url( $args['url'] );

		if ( $webp !== $args['url'] ) {
			$args['url'] = $webp;
		}

		return $args;
	}

	/**
	 * Replaces a URL a theme asked for directly.
	 *
	 * @param mixed $url         Original URL.
	 * @param mixed $id_or_email User identifier.
	 * @param mixed $args        Avatar arguments.
	 * @return mixed
	 */
	public static function filter_url( $url, $id_or_email, $args = array() ) {
		if ( ! self::active() || ! is_string( $url ) ) {
			return $url;
		}

		return self::webp_url( $url );
	}

	/**
	 * WebP URL for a local JPEG/PNG avatar, or the original URL.
	 *
	 * @param string $url Avatar URL.
	 */
	public static function webp_url( string $url ): string {
		if ( '' === $url || str_starts_with( $url, 'data:' ) ) {
			return $url;
		}

		if ( ! self::accepts_webp() ) {
			return $url;
		}

		MMF_Stats::increment( 'avatar_found' );

		if ( isset( self::$done[ $url ] ) ) {
			return self::$done[ $url ];
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			MMF_Stats::increment( 'avatar_fail_type' );

			return $url;
		}

		$file = MMF_Assets::resolve_path( $url, $ext );

		if ( '' === $file || ! is_file( $file ) ) {
			MMF_Stats::increment( MMF_Assets::is_local_url( $url ) ? 'avatar_fail_missing' : 'avatar_fail_external' );

			return $url;
		}

		$quality = max( 60, min( 95, (int) MMF_Config::get( 'avatar_quality', 82 ) ) );
		$sidecar = $file . '.webp';

		if ( ! is_file( $sidecar ) && (bool) MMF_Config::get( 'image_webp_ondemand', false ) ) {
			MMF_Images::enqueue( $file );

			return $url;
		}

		// The copy lives next to the avatar, not in the clearable cache: a cache
		// reset must not turn a cached page's avatar into a 404.
		if ( ! is_file( $sidecar ) ) {
			$ok = class_exists( 'MMF_Images' ) && MMF_Images::convert_to( $file, $sidecar, $quality, 'png' === $ext );

			if ( ! $ok || ! is_file( $sidecar ) ) {
				self::$errors++;
				MMF_Stats::increment( '' === MMF_Images::method() ? 'avatar_fail_support' : 'avatar_fail_write' );
				MMF_Stats::increment( 'avatar_missing' );
				self::$done[ $url ] = $url;

				return $url;
			}
		}

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$photo = preg_replace( '/(\?.*)?$/', '', $url );

		if ( ! is_string( $photo ) ) {
			self::$done[ $url ] = $url;

			return $url;
		}

		$webp = $photo . '.webp' . ( '' !== $query ? '?' . $query : '' );

		MMF_Stats::increment( 'avatar_sidecar' );
		delete_transient( 'mmf_avatar_file_stats' );
		self::$converted++;
		self::$saved += max( 0, (int) filesize( $file ) - (int) filesize( $sidecar ) );
		$known = (array) get_option( 'mmf_avatar_paths', array() );

		if ( ! in_array( $file, $known, true ) ) {
			$known[] = $file;
			update_option( 'mmf_avatar_paths', array_slice( $known, -500 ), false );
		}
		self::$done[ $url ] = $webp;

		return $webp;
	}

	/**
	 * Whether the visitor accepts WebP.
	 */
	private static function accepts_webp(): bool {
		if ( ! (bool) MMF_Config::get( 'webp_require_accept', false ) ) {
			return true;
		}

		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : '';

		return '' !== $accept && false !== stripos( $accept, 'image/webp' );
	}

	/**
	 * Whether conversion applies to this request.
	 */
	private static function active(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}

		return self::supports_webp() && (bool) MMF_Config::get( 'avatar_webp', true );
	}

	/**
	 * Writes one WebP file into the cache.
	 *
	 * @param string $file    Source file.
	 * @param string $target  Cache path.
	 * @param int    $quality Quality 60-95.
	 * @param string $ext     Source extension.
	 */
	private static function convert( string $file, string $target, int $quality, string $ext ): bool {
		if ( ! MMF_Cache::ensure_dir() ) {
			return false;
		}

		$tmp = $target . '.tmp';

		if ( file_exists( $tmp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a stale temporary file.
		}

		$ok = self::convert_gd( $file, $tmp, $quality, $ext );

		if ( ! $ok ) {
			$ok = self::convert_imagick( $file, $tmp, $quality );
		}

		if ( ! $ok || ! is_file( $tmp ) || 0 === (int) filesize( $tmp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- nothing to keep.

			return false;
		}

		if ( ! @rename( $tmp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a concurrent request may have written it.
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- nothing to keep.

			return false;
		}

		return true;
	}

	/**
	 * GD path, which is the common one.
	 *
	 * @param string $file    Source file.
	 * @param string $tmp     Temporary target.
	 * @param int    $quality Quality.
	 * @param string $ext     Source extension.
	 */
	private static function convert_gd( string $file, string $tmp, int $quality, string $ext ): bool {
		if ( ! function_exists( 'imagecreatefromjpeg' ) || ! function_exists( 'imagewebp' ) ) {
			return false;
		}

		if ( 'png' === $ext ) {
			if ( ! function_exists( 'imagecreatefrompng' ) ) {
				return false;
			}

			$image = @imagecreatefrompng( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a broken file falls back to Imagick.
		} else {
			$image = @imagecreatefromjpeg( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a broken file falls back to Imagick.
		}

		if ( ! $image ) {
			return false;
		}

		if ( 'png' === $ext ) {
			// Keep transparency: without these three calls the alpha channel can
			// collapse to black when the palette is expanded.
			@imagepalettetotruecolor( $image ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- truecolor PNGs are already fine.
			@imagealphablending( $image, false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- not fatal on an image without alpha.
			@imagesavealpha( $image, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- not fatal on an image without alpha.
		}

		$ok = @imagewebp( $image, $tmp, $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a failure is reported by the caller.
		@imagedestroy( $image ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- cleanup only.

		return (bool) $ok;
	}

	/**
	 * Imagick path for builds without GD WebP.
	 *
	 * @param string $file    Source file.
	 * @param string $tmp     Temporary target.
	 * @param int    $quality Quality.
	 */
	private static function convert_imagick( string $file, string $tmp, int $quality ): bool {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}

		try {
			$image = new \Imagick( $file );
			$image->setImageFormat( 'webp' );
			$image->setImageCompressionQuality( $quality );
			$ok = $image->writeImage( $tmp );
			$image->clear();

			return (bool) $ok;
		} catch ( \Throwable $error ) {
			return false;
		}
	}
}
