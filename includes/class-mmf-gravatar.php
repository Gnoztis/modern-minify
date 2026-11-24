<?php
/**
 * No Gravatar requests: a local placeholder, without choosing whose avatar wins.
 *
 * A gravatar URL sends the visitor's IP address and a hash of their e-mail to a
 * third-party server on every page that shows an avatar. The goal is only to
 * stop that request, not to override an avatar somebody else already chose:
 * another plugin may point `get_avatar_data()` at an uploaded file, and that
 * decision is none of our business.
 *
 * So the two filters are narrow and late:
 *
 *   - `pre_get_avatar_data` (priority 99) acts only when the URL is empty or is
 *     a gravatar URL; a local file, an uploads path, a data URI or a URL of
 *     this site is returned untouched;
 *   - `get_avatar_url` (priority 99) rewrites only gravatar hosts, including
 *     the `*.wp.com` proxy with an `/avatar/` path;
 *   - `get_avatar` is not filtered at all: replacing the final markup is the
 *     blunt instrument that used to hide an avatar another plugin had set.
 *
 * The admin screens and cron are left alone: the plugin's own diagnostics and
 * outgoing mail keep whatever WordPress does.
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
 * Replaces Gravatar requests with an inline SVG.
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
	 * Registers the filters after other avatar providers.
	 */
	public static function register_hooks(): void {
		// Priority 99: a plugin that sets its own avatar at 10 keeps it.
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'filter_data' ), 99, 2 );
		add_filter( 'get_avatar_url', array( __CLASS__, 'filter_url' ), 99, 3 );
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
	 * Replaces an empty or gravatar URL, and nothing else.
	 *
	 * @param mixed $args        Avatar arguments.
	 * @param mixed $id_or_email User identifier.
	 * @return mixed
	 */
	public static function filter_data( $args, $id_or_email ) {
		if ( ! self::active() || ! is_array( $args ) ) {
			return $args;
		}

		$url = isset( $args['url'] ) && is_string( $args['url'] ) ? trim( $args['url'] ) : '';

		// Another plugin already chose an avatar: local upload, data URI, our
		// own domain - anything that is not a gravatar stays as it is.
		if ( '' !== $url && ! self::is_gravatar_url( $url ) ) {
			return $args;
		}

		self::$count++;

		$args['url']          = self::uri();
		$args['found_avatar'] = true;

		return $args;
	}

	/**
	 * Rewrites a URL a theme asked for directly, gravatar hosts only.
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

		if ( ! self::is_gravatar_url( $url ) ) {
			return $url;
		}

		return self::uri();
	}

	/**
	 * Whether a URL points at a Gravatar avatar.
	 *
	 * Covers `gravatar.com` and its subdomains and the `*.wp.com` image proxy,
	 * but only on an `/avatar/` path - a non-avatar image on those hosts is not
	 * ours to rewrite.
	 *
	 * @param string $url Avatar URL.
	 */
	public static function is_gravatar_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		$host = strtolower( (string) $parts['host'] );
		$path = strtolower( (string) ( $parts['path'] ?? '' ) );

		if ( ! str_contains( $path, '/avatar/' ) ) {
			return false;
		}

		if ( 'gravatar.com' === $host || str_ends_with( $host, '.gravatar.com' ) ) {
			return true;
		}

		return str_ends_with( $host, '.wp.com' );
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
