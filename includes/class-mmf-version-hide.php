<?php
/**
 * Version hiding.
 *
 * The WordPress version in `?ver=` and in `<meta name="generator">` is a map of
 * the known vulnerabilities that apply to a site, so it is removed from the
 * page, from the feed and from the site's own asset URLs. This is a security
 * measure first and a cache question second; the `hash` mode exists so that
 * hiding the version does not cost the browser cache.
 *
 * The admin is deliberately untouched: there the version helps with debugging
 * and cache busting, and a logged-in administrator can see it anyway.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Version_Hide' ) ) {
	return;
}

/**
 * Removes the WordPress version from URLs, the head and the feed.
 */
final class MMF_Version_Hide {

	/**
	 * Length of the content hash used instead of the version.
	 */
	private const HASH_LENGTH = 6;

	/**
	 * Hashes already computed in this request, keyed by URL.
	 *
	 * The same file is often enqueued more than once; the file does not change
	 * within a request, so one read is enough.
	 *
	 * @var array<string,string>
	 */
	private static array $hashes = array();

	/**
	 * Attaches the filters and the removals.
	 */
	public static function register_hooks(): void {
		// Late enough that other plugins have already built their URLs.
		add_filter( 'script_loader_src', array( __CLASS__, 'filter_script_src' ), 15 );
		add_filter( 'style_loader_src', array( __CLASS__, 'filter_style_src' ), 15 );

		add_action( 'init', array( __CLASS__, 'hide_in_head' ), 10 );
		add_action( 'init', array( __CLASS__, 'remove_emoji' ), 10 );
	}

	/**
	 * `script_loader_src` callback.
	 *
	 * @param mixed $src Script URL.
	 * @return mixed
	 */
	public static function filter_script_src( $src ) {
		return self::filter_src( $src, 'js' );
	}

	/**
	 * `style_loader_src` callback.
	 *
	 * @param mixed $src Style URL.
	 * @return mixed
	 */
	public static function filter_style_src( $src ) {
		return self::filter_src( $src, 'css' );
	}

	/**
	 * Replaces or removes the `ver` parameter of an asset URL.
	 *
	 * Only a URL that actually carries `ver` is touched: an asset without the
	 * parameter keeps its URL, so the plugin does not rewrite addresses that
	 * already reveal nothing.
	 *
	 * @param mixed  $src  Asset URL.
	 * @param string $type `js` or `css`.
	 * @return mixed
	 */
	public static function filter_src( $src, string $type ) {
		if ( ! is_string( $src ) || '' === $src ) {
			return $src;
		}

		if ( is_admin() ) {
			return $src;
		}

		$mode = MMF_Config::get( 'ver_mode', 'hash' );

		if ( ! is_string( $mode ) || ! in_array( $mode, array( 'hash', 'remove', 'keep' ), true ) ) {
			$mode = 'hash';
		}

		if ( 'keep' === $mode || ! self::has_version_parameter( $src ) ) {
			return $src;
		}

		// The plain URL without any of our markers: makes the filter idempotent
		// if another plugin runs it twice.
		$clean = remove_query_arg( array( 'ver', 'v' ), $src );

		if ( 'remove' === $mode ) {
			return $clean;
		}

		$hash = self::content_hash( $clean, $type );

		// An external file cannot be read, so no hash can be built: the honest
		// fallback is to remove the parameter rather than invent a value.
		if ( '' === $hash ) {
			return $clean;
		}

		return add_query_arg( 'v', $hash, $clean );
	}

	/**
	 * Removes the generator tag from the head and the feed.
	 */
	public static function hide_in_head(): void {
		if ( is_admin() || ! MMF_Config::get( 'remove_generator' ) ) {
			return;
		}

		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', array( __CLASS__, 'no_generator' ) );
	}

	/**
	 * Returns an empty generator string for feeds.
	 */
	public static function no_generator(): string {
		return '';
	}

	/**
	 * Removes the emoji detection script, its styles and the feed/mail filters.
	 *
	 * The admin is left alone: its emoji assets are not the page weight this is
	 * about, and nothing in the admin is minified.
	 */
	public static function remove_emoji(): void {
		if ( ! MMF_Config::get( 'remove_emoji' ) ) {
			return;
		}

		// Feed and mail content: not an admin screen, safe to clean everywhere.
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

		if ( is_admin() ) {
			return;
		}

		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	}

	/**
	 * Whether the URL carries the `ver` parameter.
	 *
	 * @param string $src Asset URL.
	 */
	private static function has_version_parameter( string $src ): bool {
		$query = (string) wp_parse_url( $src, PHP_URL_QUERY );

		if ( '' === $query ) {
			return false;
		}

		$args = array();
		parse_str( $query, $args );

		return array_key_exists( 'ver', $args );
	}

	/**
	 * Short content hash of the file behind a URL.
	 *
	 * @param string $url  Asset URL without query markers.
	 * @param string $type Expected extension: `js` or `css`.
	 */
	private static function content_hash( string $url, string $type ): string {
		$path = MMF_Assets::resolve_path( $url, $type );

		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}

		// The cache key carries the file state: a file replaced during the
		// request must not be answered with the previous hash.
		$stat = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a file can disappear between the check and the read.
		$key  = $url . '|' . ( is_array( $stat ) ? (int) $stat['mtime'] . ':' . (int) $stat['size'] : '' );

		if ( array_key_exists( $key, self::$hashes ) ) {
			return self::$hashes[ $key ];
		}

		$hash = @md5_file( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a file can disappear between the check and the read.

		if ( ! is_string( $hash ) || '' === $hash ) {
			self::$hashes[ $key ] = '';

			return '';
		}

		self::$hashes[ $key ] = substr( $hash, 0, self::HASH_LENGTH );

		return self::$hashes[ $key ];
	}
}
