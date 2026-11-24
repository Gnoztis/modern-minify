<?php
/**
 * Web fonts: Google Fonts and Font Awesome.
 *
 * Three things can happen to a font stylesheet link, and all of them are opt-in
 * because a wrong guess here is visible on every page:
 *
 *   - `off`   the tag is left exactly as the theme printed it;
 *   - `swap`  the Google Fonts URL gets `display=swap`, which is a one-line
 *             change and hides text for less time while the font loads;
 *   - `local` the stylesheet and the font files are downloaded into the cache
 *             and the link points at the local copy, so the visitor never talks
 *             to the font CDN.
 *
 * Only hosts on the allowlist are fetched, every request has a timeout and a
 * size limit, and any failure leaves the original tag in place - a page with an
 * external font is better than a page with a broken one.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Fonts' ) ) {
	return;
}

/**
 * Rewrites font stylesheet links.
 */
final class MMF_Fonts {

	/**
	 * Limits of a downloaded file. A stylesheet is small; a font file is not.
	 */
	private const MAX_CSS_BYTES  = 262144;
	private const MAX_FONT_BYTES = 1572864;

	/**
	 * Upper bound of font files per stylesheet.
	 */
	private const MAX_FONTS = 24;

	/**
	 * Network timeout, in seconds.
	 */
	private const TIMEOUT = 5;

	/**
	 * A browser user agent: the font CDN serves woff2 only when asked by one.
	 */
	private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	/**
	 * Hosts that may be fetched. Everything else is left alone.
	 */
	private const ALLOWED_HOSTS = array(
		'fonts.googleapis.com',
		'fonts.gstatic.com',
		'cdnjs.cloudflare.com',
		'use.fontawesome.com',
		'kit.fontawesome.com',
		'maxcdn.bootstrapcdn.com',
	);

	/**
	 * Font CDNs whose preconnect hint is useless once the files are local.
	 */
	private const FONT_CDN_HOSTS = array(
		'fonts.googleapis.com',
		'fonts.gstatic.com',
	);

	/**
	 * Font Awesome stylesheet hosts.
	 */
	private const FONTAWESOME_HOSTS = array(
		'cdnjs.cloudflare.com',
		'use.fontawesome.com',
		'kit.fontawesome.com',
		'maxcdn.bootstrapcdn.com',
	);

	/**
	 * Results of this request, keyed by URL, so two identical links cost one
	 * download and a failure is not retried within the same page.
	 *
	 * @var array<string,string>
	 */
	private static array $memo = array();

	/**
	 * Rewrites one `<link>` tag.
	 *
	 * Returns the tag to print, whether this handler owned it, and counters.
	 * `handled` is true even when a download failed: the tag is then returned
	 * unchanged, and the asset pipeline must not try anything else with it.
	 *
	 * @param string              $tag      Full `<link>` tag.
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array{tag:string,handled:bool,stats:array<string,int>}
	 */
	public static function rewrite_link( string $tag, array $settings ): array {
		$result = array(
			'tag'     => $tag,
			'handled' => false,
			'stats'   => array(),
		);

		$attributes = MMF_Assets::attributes( $tag );
		$rel        = strtolower( trim( (string) ( $attributes['rel'] ?? '' ) ) );
		$href       = isset( $attributes['href'] ) ? (string) $attributes['href'] : '';

		if ( '' === $href ) {
			return $result;
		}

		$method   = self::method( $settings['fonts_method'] ?? 'off', array( 'off', 'swap', 'local' ) );
		$fawesome = self::method( $settings['fawesome_method'] ?? 'off', array( 'off', 'inline' ) );
		$font_cdn = self::is_font_cdn( $href );

		// A preconnect hint to a CDN that is no longer used only costs a DNS
		// lookup; it is dropped whenever local hosting is selected. A failure to
		// localise later leaves the stylesheet as it was, and the lost hint is
		// then merely an optimisation that did not happen.
		if ( ( str_contains( $rel, 'preconnect' ) || str_contains( $rel, 'dns-prefetch' ) ) && 'local' === $method ) {
			if ( $font_cdn ) {
				$result['tag']             = '';
				$result['handled']         = true;
				$result['stats']['fonts']  = 1;
			}

			return $result;
		}

		if ( ! str_contains( $rel, 'stylesheet' ) ) {
			return $result;
		}

		if ( self::is_google_css( $href ) && 'off' !== $method ) {
			$result['handled'] = true;

			if ( 'swap' === $method ) {
				$swapped = self::with_display_swap( $href );

				if ( $swapped !== $href ) {
					$result['tag']            = MMF_Assets::replace_attribute( $tag, 'href', self::escape_amp( $swapped ) );
					$result['stats']['fonts'] = 1;
				}

				return $result;
			}

			$local = self::localize( $href );

			if ( '' === $local ) {
				$result['stats']['failed'] = 1;

				return $result;
			}

			$result['tag']            = MMF_Assets::replace_attribute( $tag, 'href', $local );
			$result['stats']['fonts'] = 1;

			return $result;
		}

		if ( self::is_fontawesome( $href ) && 'inline' === $fawesome ) {
			$result['handled'] = true;

			$css = self::inline_stylesheet( $href );

			if ( '' === $css ) {
				$result['stats']['failed'] = 1;

				return $result;
			}

			$media = strtolower( trim( (string) ( $attributes['media'] ?? '' ) ) );

			$result['tag'] = '<style' . ( '' !== $media ? ' media="' . esc_attr( $media ) . '"' : '' ) . '>' . $css . '</style>';
			$result['stats']['fonts'] = 1;

			return $result;
		}

		return $result;
	}

	/**
	 * Normalises a method value from a settings array.
	 *
	 * A value outside the allowed list means `off`: an unexpected word must
	 * never start downloading files or rewriting a tag.
	 *
	 * @param mixed    $value   Raw value.
	 * @param string[] $allowed Allowed method names.
	 */
	private static function method( $value, array $allowed ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		return in_array( $value, $allowed, true ) ? $value : 'off';
	}

	/**
	 * Whether the URL is a Google Fonts stylesheet.
	 *
	 * @param string $href URL from the tag.
	 */
	private static function is_google_css( string $href ): bool {
		$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $href, PHP_URL_PATH );

		return 'fonts.googleapis.com' === $host && str_starts_with( $path, '/css' );
	}

	/**
	 * Whether the URL is a Font Awesome stylesheet.
	 *
	 * @param string $href URL from the tag.
	 */
	private static function is_fontawesome( string $href ): bool {
		return in_array( strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) ), self::FONTAWESOME_HOSTS, true );
	}

	/**
	 * Whether the URL belongs to a font CDN.
	 *
	 * @param string $href URL from the tag.
	 */
	private static function is_font_cdn( string $href ): bool {
		return in_array( strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) ), self::FONT_CDN_HOSTS, true );
	}

	/**
	 * Adds `display=swap` to a Google Fonts URL, unless it already has one.
	 *
	 * @param string $href Google Fonts URL.
	 */
	private static function with_display_swap( string $href ): string {
		if ( 1 === preg_match( '/(?:^|[?&])display=/i', $href ) ) {
			return $href;
		}

		return $href . ( str_contains( $href, '?' ) ? '&' : '?' ) . 'display=swap';
	}

	/**
	 * Re-encodes an ampersand for an attribute value.
	 *
	 * @param string $url URL.
	 */
	private static function escape_amp( string $url ): string {
		return str_replace( '&', '&amp;', $url );
	}

	/**
	 * Downloads a Google Fonts stylesheet and its font files, and returns the
	 * URL of the local stylesheet.
	 *
	 * The local stylesheet keeps the same `@font-face` rules, with every URL
	 * pointing at the cache. Any failure returns an empty string: a half-local
	 * stylesheet is worse than an honest external one.
	 *
	 * @param string $href Google Fonts stylesheet URL.
	 */
	private static function localize( string $href ): string {
		if ( array_key_exists( $href, self::$memo ) ) {
			return self::$memo[ $href ];
		}

		$css_name = MMF_Cache::hash( $href, 'fonts' ) . '.fonts.css';
		$css_url  = MMF_Cache::url_of( $css_name );

		if ( '' === $css_url ) {
			return '';
		}

		// A warm cache answers without a single network request.
		if ( MMF_Cache::has_file( $css_name ) ) {
			self::$memo[ $href ] = $css_url;

			return $css_url;
		}

		$css = self::fetch( $href, self::MAX_CSS_BYTES );

		if ( '' === $css ) {
			self::$memo[ $href ] = '';

			return '';
		}

		$urls = self::font_urls( $css );

		if ( array() === $urls || count( $urls ) > self::MAX_FONTS ) {
			self::$memo[ $href ] = '';

			return '';
		}

		$replacements = array();

		foreach ( $urls as $font_url ) {
			$binary = self::fetch_font( $font_url );

			if ( '' === $binary ) {
				self::$memo[ $href ] = '';

				return '';
			}

			$font_name = self::font_file_name( $font_url );

			if ( '' === $font_name ) {
				self::$memo[ $href ] = '';

				return '';
			}

			if ( ! MMF_Cache::has_file( $font_name ) && ! MMF_Cache::write_file( $font_name, $binary ) ) {
				self::$memo[ $href ] = '';

				return '';
			}

			$local = MMF_Cache::url_of( $font_name );

			if ( '' === $local ) {
				self::$memo[ $href ] = '';

				return '';
			}

			$replacements[ $font_url ] = $local;
		}

		$css = str_replace( array_keys( $replacements ), array_values( $replacements ), $css );

		if ( ! MMF_Cache::write_file( $css_name, $css ) ) {
			self::$memo[ $href ] = '';

			return '';
		}

		self::$memo[ $href ] = $css_url;

		return $css_url;
	}

	/**
	 * Absolute `fonts.gstatic.com` URLs referenced by the stylesheet.
	 *
	 * @param string $css Stylesheet.
	 * @return string[]
	 */
	private static function font_urls( string $css ): array {
		$urls = array();

		if ( ! preg_match_all( '/url\(\s*[\'"]?(https:\/\/fonts\.gstatic\.com\/[^\'")]+)[\'"]?\s*\)/i', $css, $matches ) ) {
			return $urls;
		}

		foreach ( (array) $matches[1] as $url ) {
			$url = trim( (string) $url );

			if ( '' !== $url && ! in_array( $url, $urls, true ) ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * File name of a downloaded font, empty when the URL cannot be used.
	 *
	 * @param string $font_url Font file URL.
	 */
	private static function font_file_name( string $font_url ): string {
		if ( 'fonts.gstatic.com' !== strtolower( (string) wp_parse_url( $font_url, PHP_URL_HOST ) ) ) {
			return '';
		}

		$path = (string) wp_parse_url( $font_url, PHP_URL_PATH );
		$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'woff2', 'woff', 'ttf', 'otf', 'eot', 'svg' ), true ) ) {
			$ext = 'woff2';
		}

		return 'font-' . md5( $font_url ) . '.' . $ext;
	}

	/**
	 * Downloads a font file.
	 *
	 * @param string $font_url Font file URL.
	 */
	private static function fetch_font( string $font_url ): string {
		return self::fetch( $font_url, self::MAX_FONT_BYTES );
	}

	/**
	 * Downloads a Font Awesome stylesheet and makes its relative URLs absolute,
	 * so the CSS can be printed inline and the font files still resolve.
	 *
	 * @param string $href Stylesheet URL.
	 */
	private static function inline_stylesheet( string $href ): string {
		if ( array_key_exists( $href, self::$memo ) ) {
			return self::$memo[ $href ];
		}

		$css = self::fetch( $href, self::MAX_CSS_BYTES );

		if ( '' === $css || false !== stripos( $css, '</style' ) ) {
			self::$memo[ $href ] = '';

			return '';
		}

		$css = self::absolutize_urls( $href, $css );

		self::$memo[ $href ] = $css;

		return $css;
	}

	/**
	 * Rewrites relative `url()` references in a stylesheet to absolute ones.
	 *
	 * Public because a localised external stylesheet needs the same treatment:
	 * its font files stay on the original host.
	 *
	 * @param string $base Stylesheet URL.
	 * @param string $css  Stylesheet body.
	 */
	public static function absolutize_urls( string $base, string $css ): string {
		$rewritten = preg_replace_callback(
			'/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
			static function ( $match ) use ( $base ) {
				$url = trim( $match[2] );

				if ( '' === $url || str_starts_with( $url, 'data:' ) || str_starts_with( $url, '#' ) ) {
					return $match[0];
				}

				if ( str_starts_with( $url, '//' ) ) {
					return 'url(' . self::scheme() . ':' . $url . ')';
				}

				if ( 1 === preg_match( '~^[a-z][a-z0-9+.-]*:~i', $url ) ) {
					return $match[0];
				}

				$origin = self::origin( $base );
				$dir    = (string) substr( $base, 0, (int) strrpos( $base, '/' ) + 1 );

				$absolute = str_starts_with( $url, '/' ) ? $origin . $url : $dir . $url;

				return 'url(' . self::collapse( $absolute ) . ')';
			},
			$css
		);

		return is_string( $rewritten ) ? $rewritten : $css;
	}

	/**
	 * Scheme of the site, for protocol-relative URLs.
	 */
	private static function scheme(): string {
		return is_ssl() ? 'https' : 'http';
	}

	/**
	 * Scheme and host of a URL.
	 *
	 * @param string $url Absolute URL.
	 */
	private static function origin( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		return $parts['scheme'] . '://' . $parts['host'];
	}

	/**
	 * Collapses `..` and `.` segments of an absolute URL.
	 *
	 * @param string $url Absolute URL.
	 */
	private static function collapse( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return $url;
		}

		$segments = array();
		$trailing = str_ends_with( (string) $parts['path'], '/' );

		foreach ( explode( '/', (string) $parts['path'] ) as $segment ) {
			if ( '.' === $segment || '' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		$path = '/' . implode( '/', $segments ) . ( $trailing ? '/' : '' );

		$origin = self::origin( $url );

		return '' === $origin ? $path : $origin . $path;
	}

	/**
	 * Downloads a URL from an allowed host.
	 *
	 * Returns an empty string on any failure: wrong host, wrong scheme, network
	 * error, non-200 answer, empty body or a body above the limit.
	 *
	 * @param string $url       URL to fetch.
	 * @param int    $max_bytes Size limit.
	 */
	private static function fetch( string $url, int $max_bytes ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		// The allowlist is the SSRF boundary: only the font CDNs are reachable.
		if ( ! in_array( $host, self::ALLOWED_HOSTS, true ) ) {
			return '';
		}

		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return '';
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'user-agent'  => self::USER_AGENT,
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );

		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > $max_bytes ) {
			return '';
		}

		return $body;
	}
}
