<?php
/**
 * The shared script loader.
 *
 * One engine for the two deferred modes: `interaction` (Delay JS - wait for the
 * first scroll, move, touch, key, click or wheel, with a timeout as the safety
 * net) and `domready` (run after the document is parsed, in document order).
 * Both modes work from the same placeholder format and the same queue, so the
 * plugin never ships two loaders.
 *
 * Placeholder format printed by the pipeline:
 *
 *   <script type="text/plain" data-mmf-src="…" data-mmf-delay="1"></script>
 *   <script type="text/plain" data-mmf-inline="1" data-mmf-delay="1">…</script>
 *
 * The loader clones each placeholder (so every original attribute survives),
 * clears `type`/`src`/`async`/`defer`/`data-mmf-*`, sets the real source,
 * forces `async=false` to keep execution order, copies the CSP nonce from any
 * tagged script and replaces the placeholder in place - so the document order
 * is preserved without moving nodes around.
 *
 * The script is printed inline (no request of its own) and has a hard size
 * budget: past it the loader refuses to be generated and the pipeline falls
 * back to serving the file normally, because a broken deferred page is worse
 * than a slow one.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Loader' ) ) {
	return;
}

/**
 * Generates the inline loader and owns the placeholder contract.
 */
final class MMF_Loader {

	/**
	 * Wait for the first interaction, with a timeout as the safety net.
	 */
	public const MODE_INTERACTION = 'interaction';

	/**
	 * Run when the document has been parsed.
	 */
	public const MODE_DOMREADY = 'domready';

	/**
	 * The loader must stay small: it is inlined into every delayed page.
	 */
	public const MAX_BYTES = 2048;

	/**
	 * Timeout bounds, in milliseconds.
	 */
	public const MIN_TIMEOUT = 1000;
	public const MAX_TIMEOUT = 30000;

	/**
	 * Files that are never deferred by either mode.
	 *
	 * `jquery` covers jquery, jquery-core and jquery-migrate: the rest of the
	 * page depends on it being there. `wp-i18n` and `wp-hooks` carry the
	 * translations and the hooks other inline scripts expect to find.
	 */
	public const HARD_EXCLUDES = array( 'jquery', 'wp-i18n', 'wp-hooks' );

	/**
	 * Events that count as the first interaction.
	 */
	private const EVENTS = array( 'scroll', 'mousemove', 'touchstart', 'keydown', 'click', 'wheel' );

	/**
	 * Placeholder selector, shared with the pipeline that prints it.
	 */
	public const SELECTOR = 'script[type="text/plain"][data-mmf-src],script[type="text/plain"][data-mmf-inline]';

	/**
	 * The loader body for one mode, or an empty string when it would be too big.
	 *
	 * @param string $mode    `interaction` or `domready`.
	 * @param int    $timeout Fallback delay in milliseconds.
	 */
	public static function script( string $mode, int $timeout = 5000 ): string {
		$mode    = self::normalize_mode( $mode );
		$timeout = self::normalize_timeout( $timeout );

		$events = '';

		foreach ( self::EVENTS as $event ) {
			$events .= ( '' === $events ? '' : ',' ) . '"' . $event . '"';
		}

		$trigger = self::MODE_DOMREADY === $mode
			? 'if("loading"===d.readyState){d.addEventListener("DOMContentLoaded",R,{once:!0})}else{R()}'
			: 'if("loading"===d.readyState){d.addEventListener("DOMContentLoaded",S,{once:!0})}else{S()}';

		$js = '(function(){var w=window,d=document,n=null,c=navigator.connection;'
			. 'if(w.mmfL)return;w.mmfL=1;'
			. 'var q=' . wp_json_encode( self::SELECTOR ) . ';'
			. 'function N(){if(n===null){var s=d.querySelector("script[nonce]");'
			. 'n=s?(s.nonce||s.getAttribute("nonce")||""):""}return n}'
			. 'function R(){if(w.mmfL>1)return;w.mmfL=2;var l=d.querySelectorAll(q),i,k,e,s,u,a,x;'
			. 'for(i=0;i<l.length;i++){e=l[i];s=d.createElement("script");'
			. 'for(k=0;k<e.attributes.length;k++){a=e.attributes[k];'
			. 'if(/^(type|src|async|defer|data-mmf-)/i.test(a.name))continue;s.setAttribute(a.name,a.value)}'
			. 's.async=!1;x=N();if(x)s.setAttribute("nonce",x);u=e.getAttribute("data-mmf-src");'
			. 'if(u){s.src=u}else{s.textContent=e.textContent||""}'
			. 'e.parentNode.replaceChild(s,e)}}'
			. 'function T(){setTimeout(R,' . $timeout . ')}'
			. 'function S(){T();[' . $events . '].forEach(function(t){w.addEventListener(t,R,{once:!0,passive:!0})})}'
			. 'if(c&&c.saveData){R();return}'
			. $trigger
			. '})();';

		// A loader above the budget is not printed: the caller then serves the
		// scripts as usual instead of deferring them with a missing loader.
		if ( strlen( $js ) > self::MAX_BYTES ) {
			return '';
		}

		return $js;
	}

	/**
	 * Wraps the loader in a script tag, with the nonce a CSP plugin provides.
	 *
	 * `wp_get_inline_script_tag()` is the core way to print a script tag, and it
	 * is what CSP plugins filter to add the nonce. The fallback keeps the tag
	 * valid on a site where an old core function is unavailable.
	 *
	 * @param string              $js         Loader body.
	 * @param array<string,string> $attributes Extra attributes.
	 */
	public static function script_tag( string $js, array $attributes = array() ): string {
		if ( '' === $js ) {
			return '';
		}

		$attributes = array_merge( array( 'id' => 'mmf-loader' ), $attributes );

		if ( function_exists( 'wp_get_inline_script_tag' ) ) {
			return wp_get_inline_script_tag( $js, $attributes );
		}

		$built = '<script';

		foreach ( $attributes as $name => $value ) {
			$built .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}

		return $built . '>' . $js . '</script>';
	}

	/**
	 * Normalises a mode name; an unknown value means the interaction mode.
	 *
	 * @param mixed $mode Raw mode.
	 */
	public static function normalize_mode( $mode ): string {
		return self::MODE_DOMREADY === $mode ? self::MODE_DOMREADY : self::MODE_INTERACTION;
	}

	/**
	 * Clamps a timeout to a sane range of milliseconds.
	 *
	 * @param mixed $timeout Raw timeout.
	 */
	public static function normalize_timeout( $timeout ): int {
		$timeout = is_numeric( $timeout ) ? (int) $timeout : 5000;

		return max( self::MIN_TIMEOUT, min( self::MAX_TIMEOUT, $timeout ) );
	}

	/**
	 * Whether a URL is on the never-defer list.
	 *
	 * @param string $url Asset URL.
	 */
	public static function is_hard_excluded( string $url ): bool {
		$url = strtolower( $url );

		foreach ( self::HARD_EXCLUDES as $needle ) {
			if ( str_contains( $url, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
