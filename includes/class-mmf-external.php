<?php
/**
 * External files: download them once and serve them from the site's own domain.
 *
 * A third-party script leaks the visitor's IP and Referer, adds a foreign host
 * to `script-src`, and makes the page wait for a CDN that may be unreachable.
 * By default the file is therefore copied into the cache and the tag points at
 * the local copy; the browser never talks to the CDN.
 *
 * What is deliberately *not* done:
 *
 *   - only https, only GET, `wp_safe_remote_get()` (private and loopback hosts
 *     are refused by WordPress), manual redirects with an https check on every
 *     hop, a size cap and a content-type check - a wrong answer must never be
 *     served as a script;
 *   - only `.js` and `.css`; a top-level font or an arbitrary binary is not
 *     proxied. Fonts referenced from a localised stylesheet keep their absolute
 *     CDN URL and are reported, so the owner knows what is still outside;
 *   - `integrity` and `crossorigin` are dropped only on a tag that was actually
 *     rewritten;
 *   - any failure leaves the tag exactly as it was and is counted. A failed
 *     download is remembered for a few minutes only: a CDN hiccup must not
 *     poison the file forever.
 *   - a conservative list of hosts whose scripts are bound to the domain
 *     (maps, captchas, payment widgets) is never localised: their copy checks
 *     the Referer or Origin and breaks when served from elsewhere.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_External' ) ) {
	return;
}

/**
 * Localises external scripts and stylesheets.
 */
final class MMF_External {

	public const MODE_LOCAL  = 'local';
	public const MODE_KEEP   = 'keep';
	public const MODE_REMOVE = 'remove';

	/**
	 * Network limits.
	 */
	private const TIMEOUT        = 5;
	private const MAX_REDIRECTS  = 3;
	private const DEFAULT_MAX_MB = 2;

	/**
	 * How long a failed URL is remembered, in seconds.
	 *
	 * Long enough to stop a broken CDN from being retried on every page view,
	 * short enough that the file comes back when the CDN does.
	 */
	private const NEGATIVE_TTL = 300;

	/**
	 * Hosts whose scripts are bound to the site's domain.
	 *
	 * They validate the Referer or the Origin against the registered domain, so
	 * a local copy is a broken widget. The list is matched as a substring of
	 * the URL and can be extended or replaced with the
	 * `mmf_external_excluded` filter.
	 */
	public const DEFAULT_EXCLUDES = array(
		// Maps: the key is validated against the Referer.
		'maps.googleapis.com',
		'maps.google.com',
		'maps.yandex',
		'api-maps.yandex',
		'api.mapbox.com',
		'api.2gis',
		// Captchas: the widget checks the Origin.
		'recaptcha',
		'hcaptcha.com',
		'challenges.cloudflare.com',
		'turnstile',
		// Payments: the widget is registered for one domain.
		'js.stripe.com',
		'checkout.stripe.com',
		'paypal.com/sdk',
		'paypalobjects.com',
		'checkout.qiwi.com',
		'securepay.tinkoff',
		'yookassa',
		'money.yandex',
	);

	/**
	 * Domain suffixes that are never localised, subdomains included.
	 *
	 * A host matches when it IS the suffix or ends with `.` + suffix, so
	 * `cleantalk.org` covers `api.cleantalk.org` and `fd-api.cleantalk.org`
	 * while `notcleantalk.org` and `cleantalk.org.evil.com` stay localisable.
	 * The service also owns a separate registrable domain with a prefix
	 * (`fd-cleantalk.org`), so that one is listed on its own.
	 */
	public const DEFAULT_DOMAINS = array(
		// Cloud anti-spam: the script checks Origin and Referer.
		'cleantalk.org',
		'fd-cleantalk.org',
	);

	/**
	 * External hosts seen on the page being rewritten.
	 *
	 * @var array<int,array{host:string,status:string}>
	 */
	private static array $report = array();

	/**
	 * Resets the per-page report.
	 */
	public static function reset(): void {
		self::$report = array();
	}

	/**
	 * External hosts seen in the page just rewritten.
	 *
	 * @return array<int,array{host:string,status:string}>
	 */
	public static function report(): array {
		return self::$report;
	}

	/**
	 * The configured mode, normalised.
	 *
	 * @param mixed $settings Plugin settings.
	 */
	public static function mode( $settings ): string {
		$mode = is_array( $settings ) && isset( $settings['external_mode'] ) && is_string( $settings['external_mode'] )
			? strtolower( trim( $settings['external_mode'] ) )
			: '';

		return in_array( $mode, array( self::MODE_LOCAL, self::MODE_KEEP, self::MODE_REMOVE ), true )
			? $mode
			: self::MODE_LOCAL;
	}

	/**
	 * Handles one external tag.
	 *
	 * Returns the tag to print, whether this handler owned it, and counters.
	 * `handled` is true when the tag was rewritten or must be removed; a kept,
	 * excluded or failed file leaves the tag alone but still reports its host.
	 *
	 * @param string              $tag      Full tag.
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array{tag:string,handled:bool,stats:array<string,int>}
	 */
	public static function process( string $tag, array $settings ): array {
		$result = array(
			'tag'     => $tag,
			'handled' => false,
			'stats'   => array(),
		);

		$attributes = MMF_Assets::attributes( $tag );
		$type       = isset( $attributes['href'] ) ? 'css' : 'js';
		$url        = 'css' === $type
			? (string) ( $attributes['href'] ?? '' )
			: (string) ( $attributes['src'] ?? '' );

		if ( '' === $url ) {
			return $result;
		}

		if ( 'css' === $type ) {
			$rel = strtolower( (string) ( $attributes['rel'] ?? '' ) );

			if ( ! str_contains( $rel, 'stylesheet' ) || str_contains( $rel, 'alternate' ) ) {
				return $result;
			}
		} else {
			$script_type = strtolower( (string) ( $attributes['type'] ?? '' ) );

			if ( 'module' === $script_type || ! self::is_js_type( $script_type ) ) {
				return $result;
			}
		}

		if ( MMF_Assets::is_local_url( $url ) ) {
			return $result;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return $result;
		}

		$mode = self::mode( $settings );

		if ( self::MODE_KEEP === $mode ) {
			self::record( $host, 'kept' );

			return $result;
		}

		if ( self::MODE_REMOVE === $mode ) {
			self::record( $host, 'removed' );

			return array(
				'tag'     => '',
				'handled' => true,
				'stats'   => array( 'external_removed' => 1 ),
			);
		}

		if ( self::is_excluded( $url, $settings ) ) {
			self::record( $host, 'excluded' );

			return array(
				'tag'     => $tag,
				'handled' => false,
				'stats'   => array( 'external_kept' => 1 ),
			);
		}

		$local = self::localize( $url, $type, $settings );

		if ( '' === (string) ( $local['url'] ?? '' ) ) {
			self::record( $host, 'failed' );

			return array(
				'tag'     => $tag,
				'handled' => false,
				'stats'   => array( 'external_failed' => 1 ),
			);
		}

		$attribute = 'css' === $type ? 'href' : 'src';

		$tag = MMF_Assets::replace_attribute( $tag, $attribute, (string) $local['url'] );
		$tag = MMF_Assets::remove_attribute( $tag, 'integrity' );
		$tag = MMF_Assets::remove_attribute( $tag, 'crossorigin' );

		$stats = array( 'external_localized' => 1 );

		if ( ! empty( $local['font_warning'] ) ) {
			$stats['font_warnings'] = 1;
		}

		self::record( $host, 'localized' );

		return array(
			'tag'     => $tag,
			'handled' => true,
			'stats'   => $stats,
		);
	}

	/**
	 * Downloads a file and stores it in the cache.
	 *
	 * @param string              $url      External URL.
	 * @param string              $type     `js` or `css`.
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array{url:string,font_warning:bool}
	 */
	private static function localize( string $url, string $type, array $settings ): array {
		$empty = array(
			'url'          => '',
			'font_warning' => false,
		);

		$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
		$ext = strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		// Only the two asset types this pipeline understands. A font or an
		// arbitrary binary is never proxied from here.
		if ( $ext !== $type ) {
			return $empty;
		}

		$failure_key = 'mmf_ext_' . md5( $url );

		// A recent failure is not retried on every page view, and it expires.
		if ( false !== get_transient( $failure_key ) ) {
			return $empty;
		}

		// A file that was already downloaded is reused without a request. The
		// mapping stores the cache file name, so a file removed by the TTL
		// cleanup is detected instead of serving a dead URL.
		$mapping_key = 'mmf_ext_map_' . md5( $url );
		$mapped      = get_transient( $mapping_key );

		if ( is_string( $mapped ) && '' !== $mapped && MMF_Cache::has_file( $mapped ) ) {
			$mapped_url = MMF_Cache::url_of( $mapped );

			if ( '' !== $mapped_url ) {
				return array(
					'url'          => $mapped_url,
					'font_warning' => false,
				);
			}
		}

		$body = self::fetch( $url, $type, $settings );

		if ( '' === $body ) {
			set_transient( $failure_key, time(), self::NEGATIVE_TTL );

			return $empty;
		}

		$font_warning = false;

		if ( 'css' === $type ) {
			// Fonts inside a localised stylesheet are not downloaded: their
			// absolute CDN URLs are kept so the icons and the text still render,
			// and the owner sees the warning.
			$font_warning = 1 === preg_match( '/url\([^)]*\.(woff2?|ttf|otf|eot)([?#][^)]*)?\)/i', $body );
			$body         = MMF_Fonts::absolutize_urls( $url, $body, true );
		}

		$hash = MMF_Cache::hash( $body, $type );
		$put  = MMF_Cache::put( $hash, $body, $type );

		if ( ! is_array( $put ) || empty( $put['path'] ) ) {
			return $empty;
		}

		$local_url = MMF_Cache::url( $hash, $type );
		$name      = basename( (string) $put['path'] );

		if ( '' === $local_url ) {
			return $empty;
		}

		delete_transient( $failure_key );
		set_transient( $mapping_key, $name, self::mapping_ttl() );

		return array(
			'url'          => $local_url,
			'font_warning' => $font_warning,
		);
	}

	/**
	 * Lifetime of the URL to cache-file mapping, in seconds.
	 *
	 * It follows the cache TTL, so the mapping never outlives the files it
	 * points at by much; 0 means "keep until cleared", which is 30 days here.
	 */
	private static function mapping_ttl(): int {
		$days = class_exists( 'MMF_Config' ) ? (int) MMF_Config::get( 'cache_ttl', 30 ) : 30;

		return ( $days > 0 ? $days : 30 ) * DAY_IN_SECONDS;
	}

	/**
	 * Whether the URL points at a host that must stay external.
	 *
	 * @param string              $url      External URL.
	 * @param array<string,mixed> $settings Plugin settings.
	 */
	private static function is_excluded( string $url, array $settings ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' !== $host && self::host_is_excluded( $host ) ) {
			return true;
		}

		$haystack = strtolower( $url );
		$filtered = apply_filters( 'mmf_external_excluded', self::DEFAULT_EXCLUDES );
		$needles  = is_array( $filtered ) ? $filtered : self::DEFAULT_EXCLUDES;

		$extra = $settings['external_exclude'] ?? array();

		if ( is_array( $extra ) ) {
			foreach ( $extra as $entry ) {
				if ( is_scalar( $entry ) && '' !== trim( (string) $entry ) ) {
					$needles[] = strtolower( trim( (string) $entry ) );
				}
			}
		}

		foreach ( $needles as $needle ) {
			if ( ! is_scalar( $needle ) ) {
				continue;
			}

			$needle = strtolower( trim( (string) $needle ) );

			if ( '' === $needle ) {
				continue;
			}

			// A user may type a bare domain: compare it to the host, not to the
			// whole URL, so `cleantalk.org` does not match `notcleantalk.org`.
			if ( ! str_contains( $needle, '/' ) && false === strpos( $needle, '?' ) && self::host_matches( $host, $needle ) ) {
				return true;
			}

			if ( str_contains( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a host is on the built-in suffix list.
	 *
	 * @param string $host Lowercase host.
	 */
	public static function host_is_excluded( string $host ): bool {
		$host = strtolower( trim( $host ) );
		$list = apply_filters( 'mmf_external_excluded_domains', self::DEFAULT_DOMAINS );
		$list = is_array( $list ) ? $list : self::DEFAULT_DOMAINS;

		foreach ( $list as $suffix ) {
			if ( is_scalar( $suffix ) && self::host_matches( $host, strtolower( trim( (string) $suffix ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Exact host or a subdomain of it; never a lookalike.
	 *
	 * @param string $host   Host to test.
	 * @param string $suffix Domain suffix.
	 */
	private static function host_matches( string $host, string $suffix ): bool {
		if ( '' === $host || '' === $suffix ) {
			return false;
		}

		return $host === $suffix || str_ends_with( $host, '.' . $suffix );
	}

	/**
	 * Downloads a URL with the safety guards.
	 *
	 * @param string              $url      External URL.
	 * @param string              $type     `js` or `css`.
	 * @param array<string,mixed> $settings Plugin settings.
	 */
	private static function fetch( string $url, string $type, array $settings ): string {
		$max_mb = $settings['external_max_size'] ?? self::DEFAULT_MAX_MB;
		$max_mb = is_numeric( $max_mb ) ? (int) $max_mb : self::DEFAULT_MAX_MB;
		$limit  = max( 1, min( 20, $max_mb ) ) * 1024 * 1024;

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$parts = wp_parse_url( $url );

			// https on every hop: a redirect to plain http would undo the reason
			// the file is being localised at all.
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
				return '';
			}

			// wp_safe_remote_get() refuses private and loopback hosts and runs the
			// host allowlist filter, which is the SSRF boundary here.
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
					'sslverify'   => true,
				)
			);

			if ( is_wp_error( $response ) ) {
				return '';
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 === $code ) {
				return self::validate_body( $response, $type, $limit );
			}

			if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				return '';
			}

			$location = wp_remote_retrieve_header( $response, 'location' );

			if ( ! is_string( $location ) || '' === trim( $location ) ) {
				return '';
			}

			$url = self::absolute( $url, trim( $location ) );
		}

		return '';
	}

	/**
	 * Checks the response body and content type.
	 *
	 * @param mixed $response HTTP response.
	 * @param string $type    `js` or `css`.
	 * @param int    $limit   Size limit in bytes.
	 */
	private static function validate_body( $response, string $type, int $limit ): string {
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );

		if ( ! self::content_type_allowed( $content_type, $type ) ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );

		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > $limit ) {
			return '';
		}

		// A null byte means binary junk; an HTML document is an error page, not
		// a script, whatever the content type said.
		if ( str_contains( $body, "\0" ) ) {
			return '';
		}

		$head = strtolower( substr( ltrim( $body ), 0, 512 ) );

		foreach ( array( '<!doctype', '<html', '<body' ) as $marker ) {
			if ( str_starts_with( $head, $marker ) ) {
				return '';
			}
		}

		return $body;
	}

	/**
	 * Whether the response content type is acceptable for the asset type.
	 *
	 * An empty header is accepted: some static servers omit it, and the body
	 * checks above still reject an error page.
	 *
	 * @param string $content_type Raw header.
	 * @param string $type         `js` or `css`.
	 */
	private static function content_type_allowed( string $content_type, string $type ): bool {
		$content_type = strtolower( trim( explode( ';', $content_type )[0] ) );

		if ( '' === $content_type ) {
			return true;
		}

		$allowed = 'js' === $type
			? array( 'application/javascript', 'text/javascript', 'application/x-javascript', 'application/ecmascript', 'text/ecmascript', 'text/plain', 'application/octet-stream' )
			: array( 'text/css', 'text/plain', 'application/octet-stream' );

		return in_array( $content_type, $allowed, true );
	}

	/**
	 * Turns a `Location` header into an absolute URL.
	 *
	 * @param string $base     URL of the response.
	 * @param string $location Location header.
	 */
	private static function absolute( string $base, string $location ): string {
		if ( str_starts_with( $location, '//' ) ) {
			$scheme = strtolower( (string) wp_parse_url( $base, PHP_URL_SCHEME ) );

			return ( 'https' === $scheme ? 'https:' : 'http:' ) . $location;
		}

		if ( 1 === preg_match( '~^[a-z][a-z0-9+.-]*://~i', $location ) ) {
			return $location;
		}

		$parts = wp_parse_url( $base );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = (string) ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];

		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		if ( str_starts_with( $location, '/' ) ) {
			return $origin . $location;
		}

		$dir = (string) substr( $base, 0, (int) strrpos( $base, '/' ) + 1 );

		return $dir . $location;
	}

	/**
	 * Records one external host for the status screen.
	 *
	 * @param string $host   Host name.
	 * @param string $status `localized`, `kept`, `excluded`, `failed` or `removed`.
	 */
	private static function record( string $host, string $status ): void {
		foreach ( self::$report as $entry ) {
			if ( $entry['host'] === $host ) {
				return;
			}
		}

		if ( count( self::$report ) >= 50 ) {
			return;
		}

		self::$report[] = array(
			'host'   => $host,
			'status' => $status,
		);
	}

	/**
	 * Whether the script type is JavaScript.
	 *
	 * @param string $type Raw `type` attribute.
	 */
	private static function is_js_type( string $type ): bool {
		$type = strtolower( trim( explode( ';', $type )[0] ) );

		if ( '' === $type ) {
			return true;
		}

		return in_array(
			$type,
			array(
				'text/javascript',
				'text/ecmascript',
				'text/jscript',
				'application/javascript',
				'application/x-javascript',
				'application/ecmascript',
			),
			true
		);
	}
}
