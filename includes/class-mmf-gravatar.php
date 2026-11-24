<?php
/**
 * Local avatar instead of Gravatar.
 *
 * A gravatar URL sends the visitor's IP address and a hash of their e-mail to a
 * third-party server on every page that shows an avatar. The replacement is an
 * inline SVG data URI: no request, no third party, and nothing to configure.
 *
 * Three filters are covered because themes get an avatar in three ways:
 * `pre_get_avatar_data` (what `get_avatar()` and `get_avatar_url()` both go
 * through), `get_avatar` (the final markup) and `get_avatar_url` (a theme that
 * builds the URL itself). The admin screens and cron are left alone: the
 * plugin's own diagnostics and outgoing mail keep whatever WordPress does.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Gravatar' ) ) {
	return;
}

/**
 * Replaces Gravatar with an inline SVG.
 */
final class MMF_Gravatar {

	/**
	 * Avatars replaced on this page.
	 */
	private static int $count = 0;

	/**
	 * Cached data URI.
	 */
	private static string $uri = '';

	/**
	 * Registers the filters.
	 */
	public static function register_hooks(): void {
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'filter_data' ), 10, 2 );
		add_filter( 'get_avatar', array( __CLASS__, 'filter_avatar' ), 10, 5 );
		add_filter( 'get_avatar_url', array( __CLASS__, 'filter_url' ), 10, 3 );
	}

	/**
	 * Resets the per-request counter.
	 */
	public static function reset(): void {
		self::$count = 0;
	}

	/**
	 * How many avatars were replaced in this request.
	 */
	public static function count(): int {
		return self::$count;
	}

	/**
	 * Short-circuits `get_avatar_data()`.
	 *
	 * @param mixed $args        Avatar arguments.
	 * @param mixed $id_or_email User identifier.
	 * @return mixed
	 */
	public static function filter_data( $args, $id_or_email ) {
		if ( ! self::active() || ! is_array( $args ) ) {
			return $args;
		}

		self::$count++;

		$args['url']          = self::uri();
		$args['found_avatar'] = true;

		return $args;
	}

	/**
	 * Replaces the `<img>` markup.
	 *
	 * @param mixed  $avatar      Original markup.
	 * @param mixed  $id_or_email User identifier.
	 * @param mixed  $size        Requested size.
	 * @param mixed  $default     Default argument, unused.
	 * @param mixed  $alt         Alt text.
	 * @return string
	 */
	public static function filter_avatar( $avatar, $id_or_email, $size = 96, $default = '', $alt = '' ) {
		if ( ! self::active() ) {
			return $avatar;
		}

		$size = is_numeric( $size ) ? max( 1, (int) $size ) : 96;
		$alt  = is_string( $alt ) ? $alt : '';

		return sprintf(
			'<img alt="%s" src="%s" class="avatar avatar-%d photo" height="%d" width="%d" />',
			esc_attr( $alt ),
			esc_url( self::uri() ),
			$size,
			$size,
			$size
		);
	}

	/**
	 * Replaces a URL a theme asked for directly.
	 *
	 * @param mixed $url         Original URL.
	 * @param mixed $id_or_email User identifier.
	 * @param mixed $args        Avatar arguments.
	 * @return string
	 */
	public static function filter_url( $url, $id_or_email, $args = array() ) {
		if ( ! self::active() ) {
			return $url;
		}

		return self::uri();
	}

	/**
	 * Whether the replacement applies to this request.
	 */
	private static function active(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}

		return (bool) MMF_Config::get( 'disable_gravatar', true );
	}

	/**
	 * Data URI of the placeholder.
	 */
	public static function uri(): string {
		if ( '' === self::$uri ) {
			self::$uri = 'data:image/svg+xml;base64,' . base64_encode( self::svg() );
		}

		return self::$uri;
	}

	/**
	 * The SVG itself.
	 */
	private static function svg(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" width="96" height="96" role="img" aria-hidden="true">'
			. '<rect width="96" height="96" rx="48" fill="#dcdde1"/>'
			. '<circle cx="48" cy="37" r="16" fill="#8b8f98"/>'
			. '<path d="M15 89c4-19 17-28 33-28s29 9 33 28" fill="#8b8f98"/>'
			. '</svg>';
	}
}
