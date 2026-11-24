<?php
/**
 * Minifier wrappers.
 *
 * Two vendored libraries do the actual work: `JSMin\JSMin` for JavaScript and
 * `MatthiasMullie\Minify\CSS` for CSS. This class owns everything around them:
 * lazy loading (so a plugin that already ships the same library keeps its
 * copy), the result cache, and the promise that a failure never reaches the
 * visitor - on any exception the source is returned unchanged.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Minifier' ) ) {
	return;
}

/**
 * Minifies CSS and JavaScript, with a content-addressed file cache.
 */
final class MMF_Minifier {

	/**
	 * Asset types handled here.
	 */
	private const JS  = 'js';
	private const CSS = 'css';

	/**
	 * Per-request counters for the admin screen.
	 *
	 * `cached` counts cache hits, so a fixture can tell a real second
	 * minification from a reuse of the first result.
	 *
	 * @var array<string,int>
	 */
	private static array $counters = array(
		'js'     => 0,
		'css'    => 0,
		'cached' => 0,
		'failed' => 0,
	);

	/**
	 * Minifies a JavaScript source.
	 *
	 * @param string $source    Source code.
	 * @param bool   $use_cache Read from and write to the file cache.
	 */
	public static function minify_js( string $source, bool $use_cache = true ): string {
		return self::minify( $source, self::JS, $use_cache );
	}

	/**
	 * Minifies a CSS source.
	 *
	 * @param string $source    Source code.
	 * @param bool   $use_cache Read from and write to the file cache.
	 */
	public static function minify_css( string $source, bool $use_cache = true ): string {
		return self::minify( $source, self::CSS, $use_cache );
	}

	/**
	 * Counters of the current request.
	 *
	 * @return array<string,int>
	 */
	public static function stats(): array {
		return self::$counters;
	}

	/**
	 * Zeroes the counters, so one measurement does not inherit the previous one.
	 */
	public static function reset_stats(): void {
		self::$counters = array(
			'js'     => 0,
			'css'    => 0,
			'cached' => 0,
			'failed' => 0,
		);
	}

	/**
	 * Cache name of a source string under the current settings.
	 *
	 * Public because the output pipeline has to build the same URL the
	 * minification just wrote: the name depends on the settings fingerprint,
	 * which belongs to this class. The source is normalised exactly as
	 * `minify()` does it, otherwise a file with leading whitespace would be
	 * stored under one name and looked up under another.
	 *
	 * @param string $source Source code.
	 * @param string $type   `js` or `css`.
	 */
	public static function hash( string $source, string $type ): string {
		if ( ! class_exists( 'MMF_Cache' ) ) {
			return '';
		}

		return MMF_Cache::hash( self::strip_bom( trim( $source ) ), $type, self::fingerprint( $type ) );
	}

	/**
	 * Shared pipeline: cache lookup, minification, cache write, fallback.
	 *
	 * @param string $source    Source code.
	 * @param string $type      `js` or `css`.
	 * @param bool   $use_cache Read from and write to the file cache.
	 */
	private static function minify( string $source, string $type, bool $use_cache ): string {
		$source = self::strip_bom( trim( $source ) );

		if ( '' === $source ) {
			return '';
		}

		$hash = '';

		if ( $use_cache && class_exists( 'MMF_Cache' ) ) {
			$hash   = self::hash( $source, $type );
			$cached = MMF_Cache::get( $hash, $type );

			if ( is_string( $cached ) && '' !== $cached ) {
				self::$counters['cached']++;

				return $cached;
			}
		}

		$minified = self::run( $source, $type );

		if ( '' === $minified ) {
			// The library refused the input (unterminated string, bad pattern, or
			// nothing left after the comments). A visitor must still get the
			// original code: a broken page is worse than an unminified one.
			self::$counters['failed']++;

			return $source;
		}

		if ( '' !== $hash ) {
			MMF_Cache::put( $hash, $minified, $type );
		}

		self::$counters[ $type ]++;

		return $minified;
	}

	/**
	 * Calls the library of the given type.
	 *
	 * Returns an empty string on any failure - the caller decides on the
	 * fallback. Exceptions are part of the contract here: JSMin throws on
	 * malformed input by design.
	 *
	 * @param string $source Source code.
	 * @param string $type   `js` or `css`.
	 */
	private static function run( string $source, string $type ): string {
		try {
			if ( self::JS === $type ) {
				if ( ! self::load_js() ) {
					return '';
				}

				$result = \JSMin\JSMin::minify( $source );
			} else {
				if ( ! self::load_css() ) {
					return '';
				}

				$minifier = new \MatthiasMullie\Minify\CSS( $source );
				$result   = $minifier->minify();
			}
		} catch ( \Throwable $error ) {
			return '';
		}

		return is_string( $result ) && '' !== trim( $result ) ? $result : '';
	}

	/**
	 * Loads the JavaScript library.
	 *
	 * Every file is loaded under `class_exists()`, so a site where another
	 * plugin already declared the same class keeps that copy and our files are
	 * never included twice. The exceptions are declared first: JSMin throws
	 * them, and without an autoloader the class has to exist before the throw.
	 */
	private static function load_js(): bool {
		$dir = MMF_DIR . 'lib/jsmin/';

		if ( ! class_exists( 'JSMin\UnterminatedCommentException' ) ) {
			require_once $dir . 'UnterminatedCommentException.php';
		}

		if ( ! class_exists( 'JSMin\UnterminatedRegExpException' ) ) {
			require_once $dir . 'UnterminatedRegExpException.php';
		}

		if ( ! class_exists( 'JSMin\UnterminatedStringException' ) ) {
			require_once $dir . 'UnterminatedStringException.php';
		}

		if ( ! class_exists( 'JSMin\JSMin' ) ) {
			require_once $dir . 'JSMin.php';
		}

		return class_exists( 'JSMin\JSMin' );
	}

	/**
	 * Loads the CSS library and its base classes.
	 *
	 * The dependency order of the library: base exception, its children, the
	 * path converter, the abstract minifier, the CSS minifier itself.
	 */
	private static function load_css(): bool {
		$dir = MMF_DIR . 'lib/minify-css/';

		if ( ! class_exists( 'MatthiasMullie\Minify\Exception' ) ) {
			require_once $dir . 'Exception.php';
		}

		$exceptions = array(
			'BasicException',
			'FileImportException',
			'IOException',
			'PatternMatchException',
		);

		foreach ( $exceptions as $exception ) {
			if ( ! class_exists( 'MatthiasMullie\Minify\Exceptions\\' . $exception ) ) {
				require_once $dir . 'Exceptions/' . $exception . '.php';
			}
		}

		$converter = array(
			'ConverterInterface',
			'NoConverter',
			'Converter',
		);

		foreach ( $converter as $class ) {
			if ( ! class_exists( 'MatthiasMullie\PathConverter\\' . $class ) ) {
				require_once $dir . 'path-converter/' . $class . '.php';
			}
		}

		if ( ! class_exists( 'MatthiasMullie\Minify\Minify' ) ) {
			require_once $dir . 'Minify.php';
		}

		if ( ! class_exists( 'MatthiasMullie\Minify\CSS' ) ) {
			require_once $dir . 'CSS.php';
		}

		return class_exists( 'MatthiasMullie\Minify\CSS' );
	}

	/**
	 * Settings that change the minified result, and therefore the cache name.
	 *
	 * Deliberately narrow: the cache lifetime and the exclusion lists do not
	 * change the bytes, so they must not invalidate the files.
	 *
	 * @param string $type `js` or `css`.
	 * @return array<string,mixed>
	 */
	private static function fingerprint( string $type ): array {
		if ( ! class_exists( 'MMF_Config' ) ) {
			return array();
		}

		if ( self::CSS === $type ) {
			return array(
				'minify_css' => (bool) MMF_Config::get( 'minify_css', true ),
				'merge_css'  => (bool) MMF_Config::get( 'merge_css', true ),
			);
		}

		return array(
			'minify_js' => (bool) MMF_Config::get( 'minify_js', true ),
			'merge_js'  => (bool) MMF_Config::get( 'merge_js', false ),
		);
	}

	/**
	 * Removes a UTF-8 byte order mark: it is illegal inside a response body and
	 * a minifier treats it as an unexpected character.
	 *
	 * @param string $source Source code.
	 */
	private static function strip_bom( string $source ): string {
		if ( str_starts_with( $source, "\xEF\xBB\xBF" ) ) {
			return substr( $source, 3 );
		}

		return $source;
	}
}
