<?php
/**
 * Detection of a second minifier.
 *
 * Two minifiers that rewrite the same page produce broken CSS and JavaScript:
 * the first one minifies, the second one minifies the already rewritten markup
 * and its rewritten asset URLs. When another active plugin does the same job,
 * this plugin refuses to process the page and says so in the admin instead of
 * quietly fighting over the output.
 *
 * Detection is by the directory of an active plugin, which is what WordPress
 * itself exposes: `active_plugins` holds paths like `<slug>/<file>.php`. The
 * list is deliberately small and explicit; a site with a minifier that is not
 * on it can add the slug through the `mmf_conflicting_plugins` filter:
 *
 *     add_filter( 'mmf_conflicting_plugins', fn( $slugs ) => array_merge( $slugs, [ 'my-minifier' ] ) );
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Conflicts' ) ) {
	return;
}

/**
 * Knows which other active plugins already minify the page.
 */
final class MMF_Conflicts {

	/**
	 * Filter that adds plugin slugs to the detection.
	 */
	public const FILTER = 'mmf_conflicting_plugins';

	/**
	 * Plugins whose job is minification or whose optimisation bundle includes
	 * it. Only the directory slug is matched: a site can rename the folder, and
	 * then the generic token below is the fallback.
	 */
	private const KNOWN = array(
		'autoptimize',
		'better-wp-minify',
		'bwp-minify',
		'fast-velocity-minify',
		'litespeed-cache',
		'minify-html',
		'w3-total-cache',
		'wp-optimize',
		'wp-rocket',
	);

	/**
	 * A plugin directory carrying this token is treated as a minifier even when
	 * it is not on the list: `minify`, `minifier`, `minification`.
	 */
	private const TOKEN = 'minif';

	/**
	 * Result of the last detection, `null` until asked.
	 *
	 * @var string[]|null
	 */
	private static ?array $detected = null;

	/**
	 * Whether another minifier is active.
	 */
	public static function is_active(): bool {
		return array() !== self::detected();
	}

	/**
	 * Slugs of the active conflicting plugins.
	 *
	 * @return string[]
	 */
	public static function detected(): array {
		if ( null !== self::$detected ) {
			return self::$detected;
		}

		$own   = self::own_slug();
		$found = array();
		$extra = apply_filters( self::FILTER, array() );
		$extra = is_array( $extra ) ? $extra : array();
		$known = self::KNOWN;

		// A filter is a boundary value: an entry that is not a scalar is ignored
		// instead of being cast to a string with a warning.
		foreach ( $extra as $slug ) {
			if ( is_scalar( $slug ) && '' !== trim( (string) $slug ) ) {
				$known[] = strtolower( trim( (string) $slug ) );
			}
		}

		foreach ( self::active_slugs() as $slug ) {
			if ( '' === $slug || $slug === $own ) {
				continue;
			}

			if ( in_array( $slug, $known, true ) || str_contains( $slug, self::TOKEN ) ) {
				$found[] = $slug;
			}
		}

		self::$detected = array_values( array_unique( $found ) );

		return self::$detected;
	}

	/**
	 * Drops the cached result.
	 *
	 * Needed after a site switch in multisite: the active plugins differ per
	 * site.
	 */
	public static function flush(): void {
		self::$detected = null;
	}

	/**
	 * Directory names of the active plugins of the current site.
	 *
	 * @return string[]
	 */
	private static function active_slugs(): array {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );

			if ( is_array( $network ) ) {
				$active = array_merge( $active, array_keys( $network ) );
			}
		}

		$slugs = array();

		foreach ( $active as $path ) {
			if ( ! is_string( $path ) || '' === $path ) {
				continue;
			}

			$parts   = explode( '/', str_replace( '\\', '/', $path ) );
			$slugs[] = strtolower( $parts[0] );
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Directory name of this plugin, never a conflict with itself.
	 */
	private static function own_slug(): string {
		return strtolower( basename( untrailingslashit( MMF_DIR ) ) );
	}
}
