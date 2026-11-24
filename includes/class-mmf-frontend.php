<?php
/**
 * Output buffer: the entry point of the pipeline.
 *
 * The plugin rewrites the HTML of a theme it does not control, so the buffer is
 * started only for an ordinary front-end page and is always removed safely:
 *
 *   - the callback processes the document only when PHP hands it the FINAL
 *     buffer. If something flushes the page in pieces, nothing is processed at
 *     all, because the full document never exists in one place;
 *   - processing happens exactly once per request, even if the callback is
 *     called again;
 *   - anything unexpected - a thrown exception, a response that does not look
 *     like HTML, a document that would lose its doctype or most of its tags -
 *     returns the original bytes unchanged.
 *
 * There are no logs: a failure is invisible to the visitor by design and is
 * reported through counters and the status screen.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Frontend' ) ) {
	return;
}

/**
 * Buffers a page and runs the minification pipeline over it.
 */
final class MMF_Frontend {

	/**
	 * Shared instance.
	 */
	private static ?MMF_Frontend $instance = null;

	/**
	 * Whether the buffer has been started in this request.
	 */
	private bool $started = false;

	/**
	 * Whether the document has already been processed.
	 */
	private bool $done = false;

	/**
	 * Whether processing was abandoned for this request.
	 */
	private bool $aborted = false;

	/**
	 * Buffer level right above ours, `0` until the buffer starts.
	 */
	private int $level = 0;

	/**
	 * Shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Attaches the buffer start.
	 */
	public function register_hooks(): void {
		// Priority 1: headers are not sent yet and the template has not run.
		add_action( 'template_redirect', array( $this, 'maybe_start' ), 1 );
	}

	/**
	 * Starts the output buffer when this request is an ordinary page.
	 */
	public function maybe_start(): void {
		if ( $this->started || ! $this->should_process() ) {
			return;
		}

		$level = ob_get_level();

		ob_start( array( $this, 'filter' ) );

		if ( ob_get_level() !== $level + 1 ) {
			// Another buffer interfered with ours: never touch the page.
			$this->started = true;
			$this->aborted = true;

			return;
		}

		$this->started = true;
		$this->level   = $level + 1;
	}

	/**
	 * Output buffer callback.
	 *
	 * @param string $html  Buffer contents.
	 * @param int    $phase PHP output handler phase.
	 */
	public function filter( $html, $phase = 0 ) {
		if ( ! is_string( $html ) || '' === $html || $this->aborted || $this->done ) {
			return $html;
		}

		if ( 0 === ( (int) $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			// A partial flush means the document is being written in pieces; the
			// rest is already on its way to the browser. Abandon the page.
			$this->aborted = true;

			return $html;
		}

		// PHP hands the buffer over at the level we started it on. Any other
		// level means another layer took over the output: leave it alone.
		if ( $this->level > 0 && ob_get_level() !== $this->level ) {
			$this->aborted = true;

			return $html;
		}

		$this->done = true;

		return $this->process( $html );
	}

	/**
	 * Whether this request may be buffered.
	 */
	public function should_process(): bool {
		if ( ! MMF_Config::is_enabled() ) {
			return false;
		}

		// A second minifier on the same page breaks CSS and JS; the admin is told
		// about it and this plugin stays out of the way.
		if ( MMF_Conflicts::is_active() ) {
			return false;
		}

		if ( is_admin() ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( is_feed() || $this->flag( 'is_preview' ) || $this->flag( 'is_customize_preview' ) ) {
			return false;
		}

		if ( $this->flag( 'is_favicon' ) || $this->flag( 'is_robots' ) || $this->flag( 'is_trackback' ) ) {
			return false;
		}

		if ( wp_is_json_request() ) {
			return false;
		}

		if ( $this->is_sitemap_request() || $this->emergency_off() ) {
			return false;
		}

		if ( MMF_Config::get( 'disable_when_logged_in' ) && is_user_logged_in() ) {
			return false;
		}

		return true;
	}

	/**
	 * Runs the pipeline over one document.
	 *
	 * Public because it is the unit the fixtures drive: the buffer callback is a
	 * thin wrapper around it. The argument is a boundary value - a filter or a
	 * test can pass anything - so a non-string is returned unchanged.
	 *
	 * @param mixed $html Document.
	 */
	public function process( $html ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}

		try {
			if ( ! $this->looks_like_html( $html ) || ! $this->headers_allow_html() ) {
				return $html;
			}

			$settings = MMF_Config::all();
			$assets   = MMF_Assets::rewrite( $html, $settings );
			$html_out = (string) ( $assets['html'] ?? $html );

			if ( ! empty( $settings['minify_html'] ) ) {
				$html_out = MMF_HTML::minify( $html_out );
			}

			if ( ! $this->looks_sane( $html, $html_out ) ) {
				return $html;
			}

			$stats = array( 'pages' => 1 );

			foreach ( (array) ( $assets['stats'] ?? array() ) as $key => $value ) {
				$stats[ $key ] = (int) $value;
			}

			MMF_Stats::record( $stats );

			return $html_out;
		} catch ( \Throwable $error ) {
			return $html;
		}
	}

	/**
	 * Calls an optional WordPress condition without assuming it exists.
	 *
	 * @param string $function Condition function name.
	 */
	private function flag( string $function ): bool {
		return function_exists( $function ) && (bool) call_user_func( $function );
	}

	/**
	 * Whether the response is a document and not a fragment or a payload.
	 *
	 * @param string $html Document.
	 */
	private function looks_like_html( string $html ): bool {
		if ( strlen( $html ) < 100 ) {
			return false;
		}

		return false !== stripos( $html, '<html' )
			|| false !== stripos( $html, '<!doctype' )
			|| false !== stripos( $html, '<body' );
	}

	/**
	 * Whether the response headers allow HTML processing.
	 *
	 * A page served as JSON or XML is left alone even if it slipped past the
	 * request checks.
	 */
	private function headers_allow_html(): bool {
		if ( ! function_exists( 'headers_list' ) ) {
			return true;
		}

		foreach ( (array) headers_list() as $header ) {
			$header = strtolower( (string) $header );

			if ( ! str_starts_with( $header, 'content-type:' ) ) {
				continue;
			}

			return str_contains( $header, 'text/html' ) || str_contains( $header, 'application/xhtml' );
		}

		// No explicit header: WordPress sends text/html for a template page.
		return true;
	}

	/**
	 * Whether the request is a sitemap.
	 */
	private function is_sitemap_request(): bool {
		if ( function_exists( 'get_query_var' ) && '' !== (string) get_query_var( 'sitemap' ) ) {
			return true;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '';

		if ( '' === $uri ) {
			return false;
		}

		$path = strtolower( (string) wp_parse_url( $uri, PHP_URL_PATH ) );

		return str_contains( $path, 'sitemap' ) || str_ends_with( $path, '.xml' );
	}

	/**
	 * The emergency switches of an ordinary page request.
	 *
	 * `mmf_no_minify=1` is the documented one; `mmf_off=1` is the short form
	 * used by the admin-bar link of the next stage.
	 */
	private function emergency_off(): bool {
		foreach ( array( 'mmf_no_minify', 'mmf_off' ) as $name ) {
			if ( ! isset( $_GET[ $name ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( (string) $_GET[ $name ] ) );

			if ( in_array( strtolower( $value ), array( '1', 'yes', 'true' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Last-resort check that the pipeline did not eat the document.
	 *
	 * A whitespace minifier cannot remove tags or the doctype, so a result that
	 * lost them means a bug: the original bytes win.
	 *
	 * @param string $before Original document.
	 * @param string $after  Processed document.
	 */
	private function looks_sane( string $before, string $after ): bool {
		if ( '' === $after ) {
			return false;
		}

		if ( strlen( $after ) < (int) ( strlen( $before ) * 0.4 ) ) {
			return false;
		}

		if ( substr_count( $after, '<' ) < (int) ( substr_count( $before, '<' ) * 0.8 ) ) {
			return false;
		}

		foreach ( array( '</html', '</body', '<!doctype' ) as $marker ) {
			if ( false !== stripos( $before, $marker ) && false === stripos( $after, $marker ) ) {
				return false;
			}
		}

		return true;
	}
}
