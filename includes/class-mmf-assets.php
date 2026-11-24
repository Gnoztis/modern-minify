<?php
/**
 * Tag rewriter: minifies local scripts and stylesheets, rewrites their URLs to
 * the cache and applies `async` / `defer`.
 *
 * Rules, in order of importance:
 *
 *   1. A tag that is not understood is copied byte for byte. External URLs,
 *      module scripts, non-JavaScript script types and anything that fails to
 *      resolve are left exactly as the theme printed them.
 *   2. A failed file (unreadable, too large, not minifiable into a smaller
 *      result) leaves its tag untouched - including no `async`/`defer`, because
 *      a half-applied change is harder to reason about than no change.
 *   3. `integrity` and `crossorigin` are removed only on a tag whose URL was
 *      rewritten: the browser would reject a changed file against the old hash.
 *   4. The rewritten URL is used only when the cache file exists; a read-only
 *      uploads directory must not produce a 404 on every page.
 *
 * Attribute edits are surgical (a regex replace inside the original tag), so
 * formatting, attribute order and unrelated attributes stay as they were.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Assets' ) ) {
	return;
}

/**
 * Rewrites `<script>`, `<style>` and `<link rel="stylesheet">` tags.
 */
final class MMF_Assets {

	/**
	 * Files above this size are left alone: minifying them in PHP can exhaust
	 * the request memory limit, and a bundle that big is a different problem.
	 */
	private const MAX_FILE_BYTES = 2097152;

	/**
	 * An inline block above this length is never deferred.
	 *
	 * Deferring an inline script means wrapping it, and the wrap is a regular
	 * expression over the block: a multi-hundred-kilobyte block can exceed the
	 * PCRE limits and take the whole page down. The limit is shared with the
	 * delay loader of the next stage, which is the component that actually wraps
	 * inline blocks.
	 */
	private const MAX_INLINE_DEFER = 200000;

	/**
	 * URL prefix => directory, built once per request.
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $url_map = null;

	/**
	 * Scheme and host of the site, built once per request.
	 */
	private static string $origin = '';

	/**
	 * Rewrites the asset tags of a document.
	 *
	 * `$settings` is a boundary value: it normally comes from `MMF_Config`, but
	 * the method is public and a filter or a test can hand over anything, so the
	 * shape is checked here instead of being trusted.
	 *
	 * @param string $html     Markup.
	 * @param mixed  $settings Plugin settings.
	 * @return array{html:string,stats:array<string,int>}
	 */
	public static function rewrite( string $html, $settings ): array {
		$stats    = array();
		$settings = is_array( $settings ) ? $settings : array();

		MMF_External::reset();
		MMF_Fonts::reset();

		if ( '' === $html ) {
			return array(
				'html'  => $html,
				'stats' => $stats,
			);
		}

		$minify_js  = ! empty( $settings['minify_js'] );
		$minify_css = ! empty( $settings['minify_css'] );
		$fonts      = isset( $settings['fonts_method'] ) && is_string( $settings['fonts_method'] ) ? strtolower( trim( $settings['fonts_method'] ) ) : 'off';
		$fawesome   = isset( $settings['fawesome_method'] ) && is_string( $settings['fawesome_method'] ) ? strtolower( trim( $settings['fawesome_method'] ) ) : 'off';
		$delay      = ! empty( $settings['delay_js'] ) && is_array( $settings['delay_js'] );

		// Whole-page mode: generated first, because a CSP without a nonce must
		// switch it off before a single placeholder is printed. Placeholders the
		// loader cannot restore would leave the page without JavaScript.
		$rocket_js = '';

		if ( ! empty( $settings['rocket_mode'] ) ) {
			$rocket_js = MMF_Loader::script( MMF_Loader::MODE_DOMREADY, 3000, MMF_Loader::MAX_BYTES_ROCKET );

			if ( '' !== $rocket_js && MMF_Loader::csp_blocked( $rocket_js ) ) {
				$rocket_js = '';
			}
		}

		$rocket = '' !== $rocket_js;

		// `defer_js` is all-or-nothing: if one external script would stay in
		// the normal flow, deferring the rest reorders the page.
		if ( ! empty( $settings['move_scripts_to_footer'] ) ) {
			$head_end = stripos( $html, '</head>' );
			$head     = false === $head_end ? $html : substr( $html, 0, $head_end );

			foreach ( MMF_HTML::tokenize( $head ) as $head_token ) {
				if ( 'tag' !== $head_token['type'] || $head_token['closing'] || 'script' !== $head_token['name'] ) {
					continue;
				}

				$head_attrs = self::attributes( (string) $head_token['raw'] );
				$head_src   = (string) ( $head_attrs['src'] ?? '' );
				$head_type  = strtolower( trim( (string) ( $head_attrs['type'] ?? '' ) ) );

				if ( '' === $head_src || ! self::is_local_url( $head_src ) || str_contains( $head_type, 'module' ) || self::matches( $head_src, $settings['exclude_js'] ?? array() ) ) {
					$settings['__mmf_no_move'] = true;
					break;
				}
			}
		}

		$defer_state = self::defer_state( $html, $settings );
		$defer_all   = ! empty( $defer_state['allowed'] );

		if ( ! $minify_js
			&& ! $minify_css
			&& ! $delay
			&& ! $rocket
			&& empty( $settings['merge_js'] )
			&& empty( $settings['merge_css'] )
			&& empty( $settings['defer_external'] )
			&& empty( $settings['defer_js'] )
			&& empty( $settings['async_js'] )
			&& empty( $settings['remove_print_css'] )
			&& empty( $settings['move_scripts_to_footer'] )
			&& 'off' === $fonts
			&& 'off' === $fawesome
			&& MMF_External::MODE_KEEP === MMF_External::mode( $settings ) ) {
			return array(
				'html'  => $html,
				'stats' => $stats,
			);
		}

		// Bundling runs on the original markup: a group has to be recognised
		// before the pipeline rewrites URLs and adds attributes.
		$merge_stats = array();

		if ( ! empty( $settings['merge_js'] ) || ! empty( $settings['merge_css'] ) ) {
			$merged      = MMF_Merge::apply( $html, $settings );
			$html        = $merged['html'];
			$merge_stats = $merged['stats'];
		}

		$delay_seconds = $settings['delay_js_timeout'] ?? 5;
		$delay_seconds = is_numeric( $delay_seconds ) ? (float) $delay_seconds : 5.0;
		$delay_timeout = MMF_Loader::normalize_timeout( (int) ( $delay_seconds * 1000 ) );

		$tokens         = MMF_HTML::tokenize( $html );
		$count          = count( $tokens );
		$out            = '';
		$moved          = array();
		$in_head        = false;
		$loader_printed = false;
		$rocket_used    = false;
		$rocket_printed = false;
		$scripts        = array();
		$resources      = array();

		for ( $index = 0; $index < $count; $index++ ) {
			$token = $tokens[ $index ];

			if ( 'tag' === $token['type'] && 'head' === $token['name'] ) {
				$in_head = ! $token['closing'];
			} elseif ( 'tag' === $token['type'] && 'body' === $token['name'] && ! $token['closing'] ) {
				$in_head = false;
			}

			if ( 'tag' !== $token['type'] || $token['closing'] ) {
				// The moved scripts land in front of the closing body tag. The
				// position comes from the token stream, not from a string search:
				// a `</body>` inside a script string must not become the anchor.
				if ( 'tag' === $token['type'] && $token['closing'] && 'body' === $token['name'] ) {
					if ( array() !== $moved ) {
						$out           .= implode( '', $moved );
						$stats['moved'] = count( $moved );
						$moved          = array();
					}

					// The whole-page loader goes to the end of the body: it runs
					// after parsing, so it must not sit in front of the page.
					if ( $rocket_used && ! $rocket_printed ) {
						$out           .= MMF_Loader::script_tag( $rocket_js );
						$rocket_printed = true;
					}
				}

				$out .= (string) $token['raw'];
				continue;
			}

			$name = (string) $token['name'];

			if ( 'link' === $name ) {
				$result = self::safely(
					static function () use ( $token, $settings ) {
						// Font stylesheets are external by design, so they are
						// handled before the local-file pipeline looks at the tag.
						$font = MMF_Fonts::rewrite_link( (string) $token['raw'], $settings );

						if ( ! empty( $font['handled'] ) ) {
							return array(
								'tag'   => (string) $font['tag'],
								'stats' => (array) $font['stats'],
							);
						}

						return self::process_link( (string) $token['raw'], $settings );
					},
					array(
						'tag'   => (string) $token['raw'],
						'stats' => array(),
					)
				);

				$link_attrs = self::attributes( (string) $token['raw'] );
				$link_rel   = strtolower( (string) ( $link_attrs['rel'] ?? '' ) );

				if ( str_contains( $link_rel, 'stylesheet' ) ) {
					$link_href = (string) ( $link_attrs['href'] ?? '' );

					if ( MMF_Assets::has_attribute( (string) $token['raw'], 'data-mmf-bundle' ) ) {
						/* A bundle is represented by its own row from the merge map. */
					} else {
					$link_file = self::resolve_path( $link_href, 'css' );

					$final_tag  = (string) ( $result['tag'] ?? '' );
					$cache_url  = '';
					$after      = 0;
					$action     = 'kept';

					if ( '' === trim( $final_tag ) ) {
						$action = 'removed';
					} elseif ( str_starts_with( ltrim( $final_tag ), '<style' ) ) {
						$action = 'inlined';
						$after  = strlen( $final_tag );
					} else {
						$final_href = (string) ( MMF_Assets::attributes( $final_tag )['href'] ?? '' );

						if ( str_contains( $final_href, '/mmf/' ) ) {
							$cache_url = $final_href;
							$cache_file = MMF_Cache::base_dir() . '/' . basename( (string) wp_parse_url( $final_href, PHP_URL_PATH ) );
							$after      = is_file( $cache_file ) ? (int) filesize( $cache_file ) : 0;
							$action     = 'minified';
						}
					}

					$resources[] = array(
						'kind'         => 'css',
						'cache_url'    => $cache_url,
						'action'       => $action,
						'bytes_after'  => $after,
						'url'          => $link_href,
						'name'         => basename( (string) wp_parse_url( $link_href, PHP_URL_PATH ) ),
						'mode'         => 'normal',
						'module'       => false,
						'inline_after' => false,
						'size'         => '' !== $link_file ? (int) @filesize( $link_file ) : 0,
						'media'        => (string) ( $link_attrs['media'] ?? '' ),
						'position'     => count( $resources ) + 1,
					);
					}
				}

				$out    .= $result['tag'];
				$stats   = self::merge_stats( $stats, $result['stats'] );
				continue;
			}

			if ( 'script' !== $name && 'style' !== $name ) {
				$out .= (string) $token['raw'];
				continue;
			}

			$next     = ( $index + 1 < $count ) ? $tokens[ $index + 1 ] : null;
			$has_raw  = is_array( $next ) && 'content' === $next['type'];
			$content  = $has_raw ? (string) $next['raw'] : '';
			$close_at = $index + ( $has_raw ? 2 : 1 );

			// An unterminated element: the rest of the document is its raw text.
			// Touching it would mean minifying markup as JavaScript, so the whole
			// element is copied as it is.
			if ( $has_raw && ( ! isset( $tokens[ $close_at ] ) || 'tag' !== $tokens[ $close_at ]['type'] || ! $tokens[ $close_at ]['closing'] ) ) {
				$out .= (string) $token['raw'];
				continue;
			}

			$result = self::safely(
				static function () use ( $token, $content, $settings, $rocket, $defer_all ) {
					return 'script' === $token['name']
						? self::process_script( (string) $token['raw'], $content, $settings, $rocket, $defer_all )
						: self::process_style( (string) $token['raw'], $content, $settings );
				},
				array(
					'tag'     => (string) $token['raw'],
					'content' => $content,
					'stats'   => array(),
				)
			);

			if ( ! empty( $result['rocket'] ) ) {
				$rocket_used = true;
			}

			if ( 'script' === $name ) {
				$raw = (string) ( $result['tag'] ?? '' );

				if ( '' !== $raw ) {
					$attrs    = self::attributes( $raw );
					$orig     = self::attributes( (string) $token['raw'] );
					$type     = strtolower( trim( (string) ( $attrs['type'] ?? '' ) ) );
					$position = count( $resources ) + 1;

					$scripts[] = array(
						'name'     => (string) ( $result['name'] ?? 'inline' ),
						'mode'     => (string) ( $result['mode'] ?? 'normal' ),
						'position' => count( $scripts ) + 1,
					);

					$final_src = (string) ( $attrs['src'] ?? '' );
					$after     = 0;
					$cache_url = '';

					if ( str_contains( $final_src, '/mmf/' ) ) {
						$cache_url = $final_src;
						$cache_file = MMF_Cache::base_dir() . '/' . basename( (string) wp_parse_url( $final_src, PHP_URL_PATH ) );
						$after = is_file( $cache_file ) ? (int) filesize( $cache_file ) : 0;
					} elseif ( 'inline' === (string) ( $result['mode'] ?? '' ) ) {
						$after = strlen( $content );
					}

					$action = (string) ( $result['mode'] ?? 'normal' );

					if ( 'normal' === $action ) {
						$action = '' !== $cache_url ? 'minified' : 'kept';
					}

					$resources[] = array(
						'kind'         => 'js',
						'url'          => (string) ( $orig['src'] ?? ( $attrs['src'] ?? '' ) ),
						'cache_url'    => $cache_url,
						'action'       => $action,
						'bytes_after'  => $after,
						'name'         => (string) ( $result['name'] ?? 'inline' ),
						'mode'         => (string) ( $result['mode'] ?? 'normal' ),
						'module'       => str_contains( $type, 'module' ),
						'inline_after' => false,
						'size'         => 0,
						'media'        => '',
						'position'     => $position,
					);
				}
			} elseif ( 'link' === $name ) {
				$attrs = self::attributes( (string) $token['raw'] );
				$rel   = strtolower( (string) ( $attrs['rel'] ?? '' ) );

				if ( str_contains( $rel, 'stylesheet' ) ) {
					$href = (string) ( $attrs['href'] ?? '' );
					$size = 0;
					$file = self::resolve_path( $href, 'css' );

					if ( '' !== $file ) {
						$size = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a missing file is reported by the size staying 0.
					}

					$resources[] = array(
						'kind'         => 'css',
						'url'          => $href,
						'name'         => basename( (string) wp_parse_url( $href, PHP_URL_PATH ) ),
						'mode'         => 'normal',
						'module'       => false,
						'inline_after' => false,
						'size'         => $size,
						'media'        => (string) ( $attrs['media'] ?? '' ),
						'position'     => count( $resources ) + 1,
					);
				}
			}

			// A footer move takes the whole element out of the head, so the
			// content and the closing tag are consumed here as well. The rewrite
			// is already applied: the moved tag keeps its new URL and attributes.
			if ( $in_head && ! empty( $result['move'] ) && 'script' === $name ) {
				$close = ( isset( $tokens[ $close_at ] ) && 'tag' === $tokens[ $close_at ]['type'] && $tokens[ $close_at ]['closing'] )
					? (string) $tokens[ $close_at ]['raw']
					: '';

				// A moved placeholder still needs the loader, and the loader has
				// to stay in the document even when every delayed script moved.
				if ( ! empty( $result['delay'] ) && ! $loader_printed ) {
					$out           .= MMF_Loader::script_tag( MMF_Loader::script( MMF_Loader::MODE_INTERACTION, $delay_timeout ) );
					$loader_printed = true;
				}

				$moved[] = $result['tag'] . $result['content'] . $close;
				$index   = $close_at;

				continue;
			}

			// The loader is printed once, in front of the first placeholder, so
			// it is parsed before the scripts it has to restore.
			if ( ! empty( $result['delay'] ) && ! $loader_printed ) {
				$out           .= MMF_Loader::script_tag( MMF_Loader::script( MMF_Loader::MODE_INTERACTION, $delay_timeout ) );
				$loader_printed = true;
			}

			$out  .= $result['tag'];
			$stats = self::merge_stats( $stats, $result['stats'] );

			if ( $has_raw ) {
				$out .= $result['content'];
				$index++;
			}
		}

		// No closing body tag in the document: the scripts still belong at the end.
		if ( array() !== $moved ) {
			$out           .= implode( '', $moved );
			$stats['moved'] = count( $moved );
		}

		// Same for a document without a closing body tag.
		if ( $rocket_used && ! $rocket_printed ) {
			$out .= MMF_Loader::script_tag( $rocket_js );
		}

		if ( array() !== MMF_Fonts::url_map() ) {
			$out = self::rewrite_preloads( $out );
		}

		$total = count( $resources );

		for ( $i = 0; $i < $total; $i++ ) {
			if ( 'js' !== ( $resources[ $i ]['kind'] ?? '' ) || 'inline' === ( $resources[ $i ]['mode'] ?? '' ) ) {
				continue;
			}

			for ( $j = $i + 1; $j < $total; $j++ ) {
				if ( 'js' !== ( $resources[ $j ]['kind'] ?? '' ) ) {
					continue;
				}

				if ( 'inline' !== ( $resources[ $j ]['mode'] ?? '' ) ) {
					break;
				}

				$resources[ $i ]['inline_after'] = true;
			}
		}

		// The status screen lists the external hosts of the page just rewritten.
		$stats = self::merge_stats( $stats, $merge_stats );
		$stats['external_report'] = MMF_External::report();
		$stats['last_scripts']    = $scripts;
		$stats['last_resources']  = $resources;
		$stats['last_bundles']    = (array) ( $merge_stats['bundles_detail'] ?? array() );
		$stats['last_defer']      = $defer_state;

		return array(
			'html'  => $out,
			'stats' => $stats,
		);
	}

	/**
	 * Runs one tag through its rewriter; a throwing tag keeps its original form.
	 *
	 * @param callable            $callback Tag processor.
	 * @param array<string,mixed> $fallback Result used when the processor throws.
	 * @return array<string,mixed>
	 */
	private static function safely( callable $callback, array $fallback ): array {
		try {
			$result = $callback();

			if ( ! is_array( $result ) || ! isset( $result['tag'] ) || ! is_string( $result['tag'] ) ) {
				return $fallback;
			}

			return $result;
		} catch ( \Throwable $error ) {
			return $fallback;
		}
	}

	/**
	 * Adds one tag's counters to the page counters.
	 *
	 * @param array<string,int> $total Running total.
	 * @param array<string,int> $delta Counters of one tag.
	 * @return array<string,int>
	 */
	private static function merge_stats( array $total, array $delta ): array {
		foreach ( $delta as $key => $value ) {
			if ( is_array( $value ) ) {
				$total[ $key ] = $value;
				continue;
			}

			$total[ $key ] = (int) ( $total[ $key ] ?? 0 ) + (int) $value;
		}

		return $total;
	}

	/**
	 * Rewrites a `<script>` tag, or its content when it has no `src`.
	 *
	 * @param string              $tag     Opening tag.
	 * @param string              $content Inline content (empty for `src` scripts).
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array{tag:string,content:string,stats:array<string,int>}
	 */
	private static function process_script( string $tag, string $content, array $settings, bool $rocket = false, bool $defer_all = false ): array {
		$result = array(
			'tag'     => $tag,
			'content' => $content,
			'stats'   => array(),
			'mode'    => 'normal',
			'name'    => '',
		);

		$attributes = self::attributes( $tag );

		$href = (string) ( $attributes['href'] ?? '' );

		if ( str_contains( strtolower( (string) ( $attributes['rel'] ?? '' ) ), 'stylesheet' ) && '' !== $href && MMF_Assets::matches( $href, $settings['async_css'] ?? array() ) && MMF_Assets::is_local_url( $href ) && ! MMF_Assets::matches( $href, $settings['exclude_css'] ?? array() ) ) {
			$js = 'document.addEventListener("DOMContentLoaded",function(){var l=document.createElement("link");l.rel="stylesheet";l.href=' . wp_json_encode( $href ) . ';document.head.appendChild(l);});';
			$tag_async = MMF_Loader::script_tag( $js, array( 'id' => 'mmf-async-css-' . substr( md5( $href ), 0, 8 ), 'data-mmf-async-css' => '1' ) );

			if ( '' === $tag_async || ( MMF_Loader::csp_present() && ! MMF_Loader::tag_has_nonce( $tag_async ) ) ) {
				$result['stats']['async_blocked'] = 1;

				return $result;
			}

			$result['tag']            = $tag_async;
			$result['content']        = '';
			$result['stats']['async_css'] = 1;

			return $result;
		}
		$type       = strtolower( trim( (string) ( $attributes['type'] ?? '' ) ) );

		// A bundle is already the minified concatenation of its group: touching
		// it again would re-minify it, and moving it would break its order.
		if ( self::has_attribute( $tag, MMF_Merge::MARKER ) ) {
			return $result;
		}

		// Module scripts have their own loading and scoping rules: no rewrite,
		// no minification, no async/defer, no deferral of any kind.
		if ( self::is_module( $type ) ) {
			return $result;
		}

		$src = isset( $attributes['src'] ) ? (string) $attributes['src'] : '';

		if ( '' === $src ) {
			$result['name'] = '';
			$result['mode'] = 'inline';

			if ( ! empty( $settings['minify_js'] )
				&& ! empty( $settings['minify_inline'] )
				&& self::is_js_type( $type )
				&& '' !== trim( $content ) ) {
				$minified = MMF_Minifier::minify_js( $content );

				if ( '' !== $minified && $minified !== $content ) {
					$result['content']         = $minified;
					$result['stats']['inline'] = 1;
				}
			}

			// Whole-page mode also covers inline blocks: the loader executes
			// them in document order between the external files.
			if ( $rocket && ! self::rocket_skipped( $tag, $content, '', $settings ) && self::is_js_type( $type ) ) {
				$result['tag']             = self::rocket_tag( $tag, '' );
				$result['rocket']          = true;
				$result['stats']['rocket'] = 1;
			}

			return $result;
		}

		// A script that is not JavaScript, or that the site excluded, is never
		// touched - and that includes the delay mode.
		if ( ! self::is_js_type( $type ) ) {
			return $result;
		}

		// An exclusion is hands off: no minification, no rewrite, no async/defer,
		// no delay. `defer_skip` is the narrower switch for "process it, but do
		// not defer".
		if ( self::matches( $src, $settings['exclude_js'] ?? array() ) ) {
			return $result;
		}

		$is_local = self::is_local_url( $src );

		// External files: by default they are copied into the cache and served
		// from this site, so the visitor never talks to the CDN.
		if ( ! $is_local ) {
			$external = MMF_External::process( $tag, $settings );

			$result['stats'] = self::merge_stats( $result['stats'], (array) ( $external['stats'] ?? array() ) );

			if ( ! empty( $external['handled'] ) ) {
				$tag           = (string) ( $external['tag'] ?? '' );
				$result['tag'] = $tag;

				if ( '' === $tag ) {
					return $result;
				}
			}
		}

		// Defer for external files is a separate opt-in: a CDN file is often the
		// heaviest one, but its own code may rely on being parsed early. Never
		// async, never on a module, never on an excluded file.
		if ( ! $is_local && ! empty( $settings['defer_external'] )
			&& ! self::has_attribute( $tag, 'async' )
			&& ! self::matches( $src, $settings['defer_skip'] ?? array() ) ) {
			if ( ! self::has_attribute( $tag, 'defer' ) ) {
				$tag                      = self::add_flag( $tag, 'defer' );
				$result['stats']['defer'] = 1;
				$result['mode']           = 'defer';
			}

			$result['tag'] = $tag;
		}

		// Local files are minified and rewritten; an external file that was just
		// localised is not minified here but is delayed like any other tag.
		if ( $is_local && ! empty( $settings['minify_js'] ) ) {
			$file = self::prepare_file( $src, 'js' );

			if ( 'failed' === $file['status'] ) {
				$result['stats']['failed'] = 1;

				return $result;
			}

			if ( 'ok' === $file['status'] ) {
				$tag = self::replace_attribute( $tag, 'src', (string) $file['url'] );
				$tag = self::remove_attribute( $tag, 'integrity' );
				$tag = self::remove_attribute( $tag, 'crossorigin' );

				$result['stats']['files'] = 1;
			}
		}

		// Whole-page mode: every JavaScript tag becomes inert and the loader
		// restores the queue in document order after parsing. A skipped script
		// stays exactly as the theme printed it - including no async/defer.
		if ( $rocket ) {
			$final_src = (string) ( self::attributes( $tag )['src'] ?? $src );

			if ( ! self::rocket_skipped( $tag, $content, $final_src, $settings ) ) {
				$result['tag']             = self::rocket_tag( $tag, $final_src );
				$result['rocket']          = true;
				$result['stats']['rocket'] = 1;
				$result['mode']            = 'rocket';
			}
			$result['name'] = basename( (string) wp_parse_url( $final_src, PHP_URL_PATH ) );

			return $result;
		}

		// Delay JS: the file and its execution wait for the loader. A delayed
		// script never gets async/defer - the loader owns its timing.
		if ( self::should_delay( $src, $settings ) ) {
			$result['tag']             = self::delay_tag( $tag );
			$result['delay']           = true;
			$result['stats']['delayed'] = 1;
			$result['mode']            = 'delayed';

			return $result;
		}

		if ( ! $is_local ) {
			return $result;
		}

		$async     = $settings['async_js'] ?? array();
		$defer_off = $settings['defer_skip'] ?? array();

		if ( self::matches( $src, $async ) ) {
			if ( ! self::has_attribute( $tag, 'async' ) ) {
				$tag                        = self::add_flag( $tag, 'async' );
				$result['stats']['async']   = 1;
				$result['mode']             = 'async';
			}
		} elseif ( ! empty( $settings['defer_js'] )
			&& $defer_all
			&& ! self::has_attribute( $tag, 'async' )
			&& ! self::has_attribute( $tag, 'defer' )
			&& ! self::matches( $src, $defer_off ) ) {
			$tag                      = self::add_flag( $tag, 'defer' );
			$result['stats']['defer'] = 1;
			$result['mode']           = 'defer';
		}

		$result['tag'] = $tag;

		if ( self::has_attribute( $tag, 'async' ) ) {
			$result['mode'] = 'async';
		} elseif ( self::has_attribute( $tag, 'defer' ) && 'defer' !== $result['mode'] ) {
			$result['mode'] = 'defer';
		}

		$result['name'] = basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );

		// Moving is decided here because only this method knows whether the tag
		// ended up async (an async script is already non-blocking and keeps its
		// place) and whether it was excluded at all. The whole-page mode never
		// moves anything: its whole point is document order.
		$result['move'] = ! $rocket
			&& ! empty( $settings['move_scripts_to_footer'] )
			&& ! self::has_attribute( $tag, 'async' )
			&& ! self::has_attribute( $tag, 'data-mmf-bundle' )
			&& empty( $settings['__mmf_no_move'] );

		return $result;
	}

	/**
	 * Whether the whole-page mode must leave a script alone.
	 *
	 * @param string              $tag      Full tag.
	 * @param string              $content  Inline content.
	 * @param string              $src      Final script URL, empty for inline.
	 * @param array<string,mixed> $settings Plugin settings.
	 */
	private static function rocket_skipped( string $tag, string $content, string $src, array $settings ): bool {
		$attributes = self::attributes( $tag );

		// The theme's own opt-out, the analogue of the data attribute the
		// well-known loader uses.
		$defer = strtolower( trim( (string) ( $attributes['data-mmf-defer'] ?? '' ) ) );

		if ( 'false' === $defer ) {
			return true;
		}

		$id = strtolower( trim( (string) ( $attributes['id'] ?? '' ) ) );

		// Never our own loader or another script of this plugin.
		if ( '' !== $id && str_starts_with( $id, 'mmf-' ) ) {
			return true;
		}

		$type = strtolower( trim( (string) ( $attributes['type'] ?? '' ) ) );

		if ( self::is_module( $type ) || ! self::is_js_type( $type ) ) {
			return true;
		}

		// An inline script that writes into the document cannot run after
		// parsing: document.write() at that point replaces the whole page.
		if ( '' === $src && 1 === preg_match( '/document\s*\.\s*write(?:ln)?\s*\(/i', $content ) ) {
			return true;
		}

		$needle = '' !== $src ? $src : $id;

		return '' !== $needle && self::matches( $needle, $settings['rocket_exclude'] ?? array() );
	}

	/**
	 * Turns a tag into a whole-page-mode placeholder.
	 *
	 * External: the URL moves to `data-mmf-rsrc`. Inline: the block keeps its
	 * content and gets `data-mmf-rcode`, so the loader can execute it in place.
	 *
	 * @param string $tag Full tag.
	 * @param string $src Final script URL, empty for inline.
	 */
	private static function rocket_tag( string $tag, string $src ): string {
		if ( '' === $src ) {
			$tag = self::replace_attribute( $tag, 'type', 'text/plain' );
			$tag = self::replace_attribute( $tag, 'data-mmf-rcode', '1' );

			return $tag;
		}

		$tag = self::remove_attribute( $tag, 'src' );
		$tag = self::remove_attribute( $tag, 'async' );
		$tag = self::remove_attribute( $tag, 'defer' );
		$tag = self::replace_attribute( $tag, 'type', 'text/plain' );
		$tag = self::replace_attribute( $tag, 'data-mmf-rsrc', $src );

		return $tag;
	}

	/**
	 * Rewrites `<link rel="preload" as="font">` hrefs that were localised.
	 *
	 * A theme that preloads a font URL and then reads the same URL from a
	 * localised stylesheet would otherwise preload one file and load another.
	 * Only URLs that actually moved to the cache are rewritten.
	 *
	 * @param string $html Document.
	 */
	private static function rewrite_preloads( string $html ): string {
		$map = MMF_Fonts::url_map();

		if ( array() === $map ) {
			return $html;
		}

		$tokens = MMF_HTML::tokenize( $html );
		$out    = '';

		foreach ( $tokens as $token ) {
			if ( 'tag' !== $token['type'] || $token['closing'] || 'link' !== $token['name'] ) {
				$out .= (string) $token['raw'];
				continue;
			}

			$raw   = (string) $token['raw'];
			$attrs = self::attributes( $raw );
			$rel   = strtolower( (string) ( $attrs['rel'] ?? '' ) );
			$as    = strtolower( (string) ( $attrs['as'] ?? '' ) );
			$href  = (string) ( $attrs['href'] ?? '' );

			if ( ! str_contains( $rel, 'preload' ) || 'font' !== $as || ! isset( $map[ $href ] ) ) {
				$out .= $raw;
				continue;
			}

			$out .= self::replace_attribute( $raw, 'href', (string) $map[ $href ] );
		}

		return $out;
	}

	/**
	 * Whether `defer_js` may be applied to the whole page.
	 *
	 * All-or-nothing: deferring a subset reorders execution (a deferred file
	 * runs after parsing, a normal one during it), which breaks scripts that
	 * expect their dependency to be there. One blocker is enough to leave every
	 * script as the theme printed it.
	 *
	 * @param string              $html     Document.
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array{allowed:bool,reason:string,blockers:string[]}
	 */
	private static function defer_state( string $html, array $settings ): array {
		$state = array(
			'allowed'  => false,
			'reason'   => __( 'выключен', 'modern-minify' ),
			'blockers' => array(),
		);

		if ( empty( $settings['defer_js'] ) ) {
			return $state;
		}

		$tokens   = MMF_HTML::tokenize( $html );
		$count    = 0;
		$external = ! empty( $settings['defer_external'] );

		foreach ( $tokens as $token ) {
			if ( 'tag' !== $token['type'] || $token['closing'] || 'script' !== $token['name'] ) {
				continue;
			}

			$attrs = self::attributes( (string) $token['raw'] );
			$src   = isset( $attrs['src'] ) ? (string) $attrs['src'] : '';

			if ( '' === $src ) {
				continue;
			}

			$count++;
			$type = strtolower( trim( (string) ( $attrs['type'] ?? '' ) ) );
			$name = basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );

			if ( self::is_module( $type ) || ! self::is_js_type( $type ) ) {
				$state['blockers'][] = $name . ' (' . __( 'модуль', 'modern-minify' ) . ')';
				continue;
			}

			if ( self::has_attribute( (string) $token['raw'], 'async' ) ) {
				$state['blockers'][] = $name . ' (async)';
				continue;
			}

			if ( self::matches( $src, $settings['exclude_js'] ?? array() ) ) {
				$state['blockers'][] = $name . ' (' . __( 'исключение', 'modern-minify' ) . ')';
				continue;
			}

			if ( self::matches( $src, $settings['defer_skip'] ?? array() ) ) {
				$state['blockers'][] = $name . ' (' . __( 'не откладывать', 'modern-minify' ) . ')';
				continue;
			}

			if ( ! self::is_local_url( $src ) && ! $external ) {
				$state['blockers'][] = $name . ' (' . __( 'внешний', 'modern-minify' ) . ')';
				continue;
			}

			if ( str_contains( strtolower( $src ), 'jquery-migrate' )
				&& function_exists( 'wp_script_is' ) && wp_script_is( 'jquery-migrate', 'registered' ) ) {
				$state['blockers'][] = 'jquery-migrate';
			}
		}

		if ( 0 === $count ) {
			$state['reason'] = __( 'на странице нет внешних скриптов', 'modern-minify' );

			return $state;
		}

		if ( array() !== $state['blockers'] ) {
			/* translators: %s: list of blocking scripts. */
			$state['reason'] = sprintf( __( 'не применён, исключены: %s', 'modern-minify' ), implode( ', ', array_slice( $state['blockers'], 0, 5 ) ) );

			return $state;
		}

		$state['allowed'] = true;
		$state['reason']  = __( 'применён', 'modern-minify' );

		return $state;
	}

	/**
	 * Whether the script belongs to the delay list.
	 *
	 * Patterns are the opt-in: an empty list delays nothing. The hard excludes
	 * (jQuery, wp-i18n, wp-hooks) win over the patterns, so a broad `*` cannot
	 * break the page's dependencies.
	 *
	 * @param string              $src      Script URL.
	 * @param array<string,mixed> $settings Plugin settings.
	 */
	private static function should_delay( string $src, array $settings ): bool {
		$patterns = $settings['delay_js'] ?? array();

		if ( ! self::matches( $src, $patterns ) ) {
			return false;
		}

		if ( self::matches( $src, $settings['delay_js_exclude'] ?? array() ) ) {
			return false;
		}

		return ! MMF_Loader::is_hard_excluded( $src );
	}

	/**
	 * Turns a tag into a loader placeholder.
	 *
	 * The `src` is moved to `data-mmf-src` and the type becomes `text/plain`, so
	 * the browser neither loads nor executes anything. `async` and `defer` are
	 * dropped: the loader decides when the file runs.
	 *
	 * @param string $tag Full tag.
	 */
	private static function delay_tag( string $tag ): string {
		$attributes = self::attributes( $tag );
		$src        = isset( $attributes['src'] ) ? (string) $attributes['src'] : '';

		if ( '' === $src ) {
			return $tag;
		}

		$tag = self::remove_attribute( $tag, 'src' );
		$tag = self::remove_attribute( $tag, 'async' );
		$tag = self::remove_attribute( $tag, 'defer' );
		$tag = self::replace_attribute( $tag, 'type', 'text/plain' );
		$tag = self::replace_attribute( $tag, 'data-mmf-src', $src );
		$tag = self::replace_attribute( $tag, 'data-mmf-delay', '1' );

		return $tag;
	}

	/**
	 * Whether an inline block is small enough to be deferred.
	 *
	 * The pipeline itself never defers an inline script: a deferred inline block
	 * would run after the markup that expects its variables. The limit belongs
	 * to the loader of the next stage, which wraps inline blocks and would hit
	 * the PCRE limits on a multi-hundred-kilobyte block.
	 *
	 * @param string $content Inline script body.
	 */
	public static function can_defer_inline( string $content ): bool {
		return strlen( $content ) <= self::MAX_INLINE_DEFER;
	}

	/**
	 * Minifies an inline `<style>` block.
	 *
	 * @param string              $tag     Opening tag.
	 * @param string              $content CSS.
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array{tag:string,content:string,stats:array<string,int>}
	 */
	private static function process_style( string $tag, string $content, array $settings ): array {
		$result = array(
			'tag'     => $tag,
			'content' => $content,
			'stats'   => array(),
		);

		if ( empty( $settings['minify_css'] ) || empty( $settings['minify_inline'] ) || '' === trim( $content ) ) {
			return $result;
		}

		$minified = MMF_Minifier::minify_css( $content );

		if ( '' !== $minified && $minified !== $content ) {
			$result['content']         = $minified;
			$result['stats']['inline'] = 1;
		}

		return $result;
	}

	/**
	 * Rewrites a `<link>` tag: a stylesheet is minified, optionally inlined or
	 * removed when it is a print stylesheet.
	 *
	 * @param string              $tag      Full tag.
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array{tag:string,stats:array<string,int>}
	 */
	private static function process_link( string $tag, array $settings ): array {
		$result = array(
			'tag'   => $tag,
			'stats' => array(),
		);

		$attributes = self::attributes( $tag );
		$rel        = strtolower( trim( (string) ( $attributes['rel'] ?? '' ) ) );

		// A bundle is already minified and cached; leave it as it is.
		if ( self::has_attribute( $tag, MMF_Merge::MARKER ) ) {
			return $result;
		}

		if ( ! self::is_stylesheet( $rel ) ) {
			return $result;
		}

		$href = isset( $attributes['href'] ) ? (string) $attributes['href'] : '';

		if ( '' === $href ) {
			return $result;
		}

		$media = strtolower( trim( (string) ( $attributes['media'] ?? '' ) ) );

		if ( ! empty( $settings['remove_print_css'] ) && self::is_print_media( $media ) ) {
			$result['tag']           = '';
			$result['stats']['print'] = 1;

			return $result;
		}

		if ( ! self::is_local_url( $href ) ) {
			$external = MMF_External::process( $tag, $settings );

			if ( ! empty( $external['handled'] ) ) {
				return array(
					'tag'   => (string) ( $external['tag'] ?? '' ),
					'stats' => (array) ( $external['stats'] ?? array() ),
				);
			}

			$result['stats'] = self::merge_stats( $result['stats'], (array) ( $external['stats'] ?? array() ) );

			return $result;
		}

		// The two explicit lists are decisions the owner made about specific
		// files, so they must not depend on the minification switch.
		$force_inline = self::matches( $href, $settings['inline_css_extra'] ?? array() );
		$force_async  = self::matches( $href, $settings['async_css'] ?? array() );

		// Theme stylesheet out of the critical path (opt-in). The page is hidden
		// until the stylesheet applies or 1.5 s pass, whichever comes first: the
		// visitor either sees a styled page or nothing, never bare text.
		if ( ! empty( $settings['css_async_theme'] ) && false !== strpos( $href, '/wp-content/themes/' ) && self::is_local_url( $href ) && ! self::matches( $href, $settings['exclude_css'] ?? array() ) ) {
			static $guard_printed = false;

			$guard = '';

			if ( ! $guard_printed ) {
				$guard_printed = true;
				$guard         = '<style id="mmf-fouc-guard">html{visibility:hidden}</style>';
			}

			$js = 'document.addEventListener("DOMContentLoaded",function(){var g=document.getElementById("mmf-fouc-guard");'
				. 'var show=function(){if(g&&g.parentNode){g.parentNode.removeChild(g);}g=null;};'
				. 'var l=document.createElement("link");l.rel="stylesheet";l.href=' . wp_json_encode( $href ) . ';'
				. 'l.onload=show;l.onerror=show;document.head.appendChild(l);setTimeout(show,1500);});';

			$tag_async = MMF_Loader::script_tag( $js, array( 'id' => 'mmf-theme-css-' . substr( md5( $href ), 0, 8 ), 'data-mmf-theme-css' => '1' ) );

			if ( '' !== $tag_async && ( ! MMF_Loader::csp_present() || MMF_Loader::tag_has_nonce( $tag_async ) ) ) {
				$result['tag']                 = $guard . '<link rel="preload" as="style" href="' . esc_url( $href ) . '" data-mmf-theme-async="1">' . $tag_async;
				$result['stats']['theme_async'] = 1;

				return $result;
			}
		}

		if ( empty( $settings['minify_css'] ) && ! $force_inline && ! $force_async ) {
			return $result;
		}

		// A forced file is handled before the minification pipeline: that pipeline
		// returns "unchanged" for a file it cannot improve, and an untouched tag
		// then stayed blocking even though the owner asked for inline or async.
		if ( $force_inline && ! self::matches( $href, $settings['exclude_css'] ?? array() ) ) {
			$inline_path = self::resolve_path( $href, 'css' );

			if ( '' !== $inline_path && is_readable( $inline_path ) ) {
				$inline_raw = (string) file_get_contents( $inline_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local file inside uploads, read once.

				if ( '' !== $inline_raw && strlen( $inline_raw ) <= 200000 && false === stripos( $inline_raw, '</style' ) ) {
					if ( ! empty( $settings['minify_css'] ) && class_exists( 'MMF_Minifier' ) ) {
						$inline_raw = MMF_Minifier::minify_css( $inline_raw );
					}

					$inline_id = isset( $attributes['id'] ) ? ' id="' . esc_attr( (string) $attributes['id'] ) . '"' : '';
					$result['tag']            = '<style' . $inline_id . ( '' !== $media ? ' media="' . esc_attr( $media ) . '"' : '' ) . '>' . $inline_raw . '</style>';
					$result['stats']['inline'] = 1;

					return $result;
				}
			}
		}

		if ( $force_async && ! self::matches( $href, $settings['exclude_css'] ?? array() ) ) {
			$js = 'document.addEventListener("DOMContentLoaded",function(){var l=document.createElement("link");l.rel="stylesheet";l.href=' . wp_json_encode( $href ) . ';document.head.appendChild(l);});';
			$tag_async = MMF_Loader::script_tag( $js, array( 'id' => 'mmf-async-css-' . substr( md5( $href ), 0, 8 ), 'data-mmf-async-css' => '1' ) );

			if ( '' !== $tag_async && ( ! MMF_Loader::csp_present() || MMF_Loader::tag_has_nonce( $tag_async ) ) ) {
				$result['tag']                = $tag_async;
				$result['stats']['async_css'] = 1;

				return $result;
			}

			$result['stats']['async_blocked'] = 1;
		}

		if ( self::matches( $href, $settings['exclude_css'] ?? array() ) ) {
			return $result;
		}

		$file = self::prepare_file( $href, 'css' );

		if ( 'failed' === $file['status'] ) {
			$result['stats']['failed'] = 1;

			return $result;
		}

		if ( 'unchanged' === $file['status'] ) {
			return $result;
		}

		$content  = (string) ( $file['content'] ?? '' );
		$limit_kb = $settings['inline_css_limit'] ?? 20;
		$limit    = is_numeric( $limit_kb ) ? max( 0, (int) $limit_kb ) * 1024 : 20 * 1024;

		// Inlining is skipped for a link carrying an id: scripts and plugins use
		// the id to toggle or replace that exact element. `</style` in the CSS
		// would end the new element early, so such a file is never inlined.
		$inline_id = isset( $attributes['id'] ) ? ' id="' . esc_attr( (string) $attributes['id'] ) . '"' : '';

		// A forced file is inlined even when it carries an id: the id moves onto
		// the <style> element, so a script that toggles or replaces that exact
		// element keeps working. Automatic inlining still skips tagged links.
		if ( ( $force_inline || ( ! empty( $settings['inline_css'] ) && ! isset( $attributes['id'] ) ) )
			&& ( $force_inline || $limit > 0 )
			&& strlen( $content ) <= ( $force_inline ? 200000 : $limit )
			&& false === stripos( $content, '</style' ) ) {
			$result['tag'] = '<style' . $inline_id . ( '' !== $media ? ' media="' . esc_attr( $media ) . '"' : '' ) . '>' . $content . '</style>';
			$result['stats']['inline'] = 1;

			return $result;
		}

		// Asynchronous loading was implemented only inside the script branch and
		// never ran for a <link>: the file stayed blocking no matter what the
		// owner put into the list. It is wired here, where stylesheets live.
		if ( $force_async && ! self::matches( $href, $settings['exclude_css'] ?? array() ) ) {
			$js = 'document.addEventListener("DOMContentLoaded",function(){var l=document.createElement("link");l.rel="stylesheet";l.href=' . wp_json_encode( $href ) . ';document.head.appendChild(l);});';
			$tag_async = MMF_Loader::script_tag( $js, array( 'id' => 'mmf-async-css-' . substr( md5( $href ), 0, 8 ), 'data-mmf-async-css' => '1' ) );

			if ( '' !== $tag_async && ( ! MMF_Loader::csp_present() || MMF_Loader::tag_has_nonce( $tag_async ) ) ) {
				$result['tag']            = $tag_async;
				$result['stats']['async_css'] = 1;

				return $result;
			}

			$result['stats']['async_blocked'] = 1;
		}

		$tag = self::replace_attribute( $tag, 'href', (string) $file['url'] );
		$tag = self::remove_attribute( $tag, 'integrity' );
		$tag = self::remove_attribute( $tag, 'crossorigin' );

		$result['tag']          = $tag;
		$result['stats']['files'] = 1;

		return $result;
	}

	/**
	 * Reads and minifies a local file.
	 *
	 * Statuses:
	 *   - `ok`:        a cache file exists and `url` points at it;
	 *   - `unchanged`: the file is fine but the minifier returned the source
	 *                  (or the file is already ours), so the tag stays as it is;
	 *   - `failed`:    the file cannot be used; the tag must stay as it is.
	 *
	 * @param string $url  URL of the file.
	 * @param string $type `js` or `css`.
	 * @return array{status:string,content?:string,url?:string,size?:int}
	 */
	private static function prepare_file( string $url, string $type ): array {
		$path = self::resolve_path( $url, $type );
		// The original name travels into the cache file name, so a page source
		// shows cleantalk-public.min_8f3a1c.css instead of a bare hash.
		$label = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		if ( '' === $path ) {
			return array( 'status' => 'failed' );
		}

		if ( self::is_own_cache( $path ) ) {
			return array( 'status' => 'unchanged' );
		}

		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a file can disappear between resolve and stat.

		if ( false === $size || $size <= 0 || $size > self::MAX_FILE_BYTES ) {
			return array( 'status' => 'failed' );
		}

		// The path is resolved with realpath() and checked against the mapped
		// WordPress directory, so it is not request input by the time it is read.
		$source = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- a validated path inside the WordPress tree.

		if ( ! is_string( $source ) || '' === $source ) {
			return array( 'status' => 'failed' );
		}

		// A `.js` URL that returns an HTML error page, or a file of binary junk,
		// is not source code: minifying it would replace a broken file with a
		// different broken file. The tag is left exactly as it was.
		if ( ! self::looks_like_source( $source ) ) {
			return array( 'status' => 'failed' );
		}

		// A stylesheet we are about to move into the cache: every relative
		// `url()` has to be resolved against the stylesheet's own directory
		// first, or the browser would look for the font next to the cache.
		if ( 'css' === $type ) {
			$absolute = self::absolute_url( $url );

			if ( '' !== $absolute ) {
				$rebased = MMF_Fonts::absolutize_urls( $absolute, $source );

				if ( $rebased !== $source ) {
					$source = $rebased;
				}
			}
		}

		$minified = ( 'js' === $type ) ? MMF_Minifier::minify_js( $source, true, $label ) : MMF_Minifier::minify_css( $source, true, $label );

		if ( '' === $minified || $minified === $source ) {
			return array( 'status' => 'unchanged' );
		}

		$hash = MMF_Minifier::hash( $source, $type );

		// The cache write may have failed (read-only uploads): rewriting the URL
		// then would point the browser at a file that does not exist.
		if ( ! MMF_Cache::has( $hash, $type, $label ) ) {
			return array( 'status' => 'failed' );
		}

		return array(
			'status'  => 'ok',
			'content' => $minified,
			'url'     => MMF_Cache::url( $hash, $type, $label ),
			'size'    => strlen( $minified ),
		);
	}

	/**
	 * Whether the bytes of a file look like source code at all.
	 *
	 * A `.js` URL that answers with an HTML error page, or a file of binary
	 * junk, is not something to minify: replacing a broken file with a
	 * differently broken file hides the problem instead of fixing it.
	 *
	 * @param string $source File contents.
	 */
	private static function looks_like_source( string $source ): bool {
		if ( str_contains( $source, "\0" ) ) {
			return false;
		}

		$head = strtolower( substr( ltrim( $source ), 0, 512 ) );

		foreach ( array( '<!doctype', '<html', '<body' ) as $marker ) {
			if ( str_contains( $head, $marker ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolves a URL to a readable file of the given type inside the WordPress
	 * tree, or an empty string.
	 *
	 * Public because the version hiding reads the file a `script_loader_src` or
	 * `style_loader_src` URL points at: both paths must resolve a URL the same
	 * way, or a file would be hashed from one copy and served from another.
	 *
	 * @param string $url  URL from the tag.
	 * @param string $type Expected extension: `js` or `css`.
	 */
	public static function resolve_path( string $url, string $type ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $url || str_starts_with( $url, '#' ) ) {
			return '';
		}

		$lower = strtolower( $url );

		foreach ( array( 'data:', 'blob:', 'javascript:', 'about:' ) as $scheme ) {
			if ( str_starts_with( $lower, $scheme ) ) {
				return '';
			}
		}

		$absolute = self::absolute_url( $url );

		if ( '' === $absolute ) {
			return '';
		}

		foreach ( self::url_map() as $prefix => $directory ) {
			if ( '' === $prefix || ! str_starts_with( $absolute, $prefix ) ) {
				continue;
			}

			$relative = (string) preg_replace( '/[?#].*$/', '', substr( $absolute, strlen( $prefix ) ) );

			if ( '' === $relative ) {
				continue;
			}

			$candidate = rtrim( $directory, '/\\' ) . '/' . ltrim( $relative, '/' );
			$real      = realpath( $candidate );

			if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
				continue;
			}

			if ( strtolower( (string) pathinfo( $real, PATHINFO_EXTENSION ) ) !== $type ) {
				continue;
			}

			// The resolved file must stay inside the directory it was mapped to:
			// a `../..` in the URL must not reach anything else.
			$root = realpath( rtrim( $directory, '/\\' ) );

			if ( false === $root ) {
				continue;
			}

			if ( ! str_starts_with( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $root ) ) ) ) {
				continue;
			}

			return $real;
		}

		return '';
	}

	/**
	 * URL prefix => directory, longest prefix first.
	 *
	 * Built once per request: the rewrites of one page all ask the same
	 * question, and `wp_upload_dir()` is not free.
	 *
	 * @return array<string,string>
	 */
	private static function url_map(): array {
		if ( null !== self::$url_map ) {
			return self::$url_map;
		}

		$map = array();

		self::add_map( $map, content_url(), WP_CONTENT_DIR );

		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			self::add_map( $map, content_url( 'plugins' ), WP_PLUGIN_DIR );
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			self::add_map( $map, content_url( 'mu-plugins' ), WPMU_PLUGIN_DIR );
		}

		self::add_map( $map, includes_url(), ABSPATH . WPINC );

		if ( function_exists( 'get_stylesheet_directory_uri' ) && function_exists( 'get_stylesheet_directory' ) ) {
			self::add_map( $map, get_stylesheet_directory_uri(), get_stylesheet_directory() );
		}

		if ( function_exists( 'get_template_directory_uri' ) && function_exists( 'get_template_directory' ) ) {
			self::add_map( $map, get_template_directory_uri(), get_template_directory() );
		}

		$uploads = wp_upload_dir( null, false );

		if ( is_array( $uploads ) && empty( $uploads['error'] ) ) {
			self::add_map( $map, $uploads['baseurl'] ?? '', $uploads['basedir'] ?? '' );
		}

		self::add_map( $map, site_url(), ABSPATH );

		if ( home_url() !== site_url() ) {
			self::add_map( $map, home_url(), ABSPATH );
		}

		uksort(
			$map,
			static function ( $left, $right ) {
				return strlen( (string) $right ) <=> strlen( (string) $left );
			}
		);

		self::$url_map = $map;

		return self::$url_map;
	}

	/**
	 * Adds one prefix => directory pair, skipping values that make no sense.
	 *
	 * A prefix of `/` would claim every URL on the site and map it under the
	 * theme directory, so an empty or broken URI function is dropped instead of
	 * being turned into `trailingslashit( false )`.
	 *
	 * @param array<string,string> $map       Map under construction.
	 * @param mixed                $url       URL returned by a WordPress function.
	 * @param mixed                $directory Directory it corresponds to.
	 */
	private static function add_map( array &$map, $url, $directory ): void {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return;
		}

		if ( ! is_string( $directory ) || '' === trim( $directory ) ) {
			return;
		}

		$prefix = trailingslashit( $url );

		if ( strlen( $prefix ) < 2 ) {
			return;
		}

		$map[ $prefix ] = trailingslashit( $directory );
	}

	/**
	 * Turns any of `//host/x`, `/x` or `x` into an absolute URL.
	 *
	 * Public because the merge pass has to resolve a stylesheet's own location
	 * before it rebases that stylesheet's relative `url()` values.
	 *
	 * @param string $url URL from a tag.
	 */
	public static function absolute_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		if ( str_starts_with( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}

		if ( str_starts_with( $url, '/' ) ) {
			$origin = self::origin();

			return '' === $origin ? '' : $origin . $url;
		}

		if ( '' !== (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return $url;
		}

		// A relative path belongs to the site root, as the browser resolves it
		// against the page URL.
		return trailingslashit( home_url() ) . ltrim( $url, './' );
	}

	/**
	 * Scheme and host of the site, used for root-relative URLs.
	 */
	private static function origin(): string {
		if ( '' !== self::$origin ) {
			return self::$origin;
		}

		$parts = wp_parse_url( home_url() );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		self::$origin = $parts['scheme'] . '://' . $parts['host'];

		if ( ! empty( $parts['port'] ) ) {
			self::$origin .= ':' . (int) $parts['port'];
		}

		return self::$origin;
	}

	/**
	 * Whether the URL points into the WordPress installation.
	 *
	 * This is a prefix check only; whether the file exists is decided later, by
	 * `prepare_file()`.
	 *
	 * Public because the external handler has to tell a file of this site from a
	 * third-party one, and both must answer that question the same way.
	 *
	 * @param string $url URL from a tag.
	 */
	public static function is_local_url( string $url ): bool {
		$absolute = self::absolute_url( trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) ) );

		if ( '' === $absolute ) {
			return false;
		}

		foreach ( array_keys( self::url_map() ) as $prefix ) {
			if ( str_starts_with( $absolute, (string) $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the asset matches one of the configured patterns.
	 *
	 * Two forms are supported, and the README documents both:
	 *
	 *   - a pattern without `*` is a case-insensitive substring of the URL, as
	 *     in `jquery` or `/plugins/foo/`;
	 *   - a pattern with `*` is matched against the whole URL, where `*` stands
	 *     for any run of characters. A leading and a trailing star therefore
	 *     match anywhere in the URL or in its path.
	 *
	 * Both the full URL and its path are tested, so a path pattern works without
	 * repeating the scheme and host. A list coming from a broken option is not
	 * an array: it is treated as empty instead of warning.
	 *
	 * Public because the merge pass asks the same question with the same
	 * wildcard rules; two matchers would drift apart.
	 *
	 * @param string $url      Asset URL.
	 * @param mixed  $patterns Configured patterns.
	 */
	public static function matches( string $url, $patterns ): bool {
		$patterns = is_array( $patterns ) ? $patterns : array();
		$subject  = strtolower( (string) preg_replace( '/[?#].*$/', '', trim( $url ) ) );
		$path     = strtolower( (string) wp_parse_url( $subject, PHP_URL_PATH ) );

		foreach ( $patterns as $pattern ) {
			if ( ! is_scalar( $pattern ) ) {
				continue;
			}

			$pattern = strtolower( trim( (string) $pattern ) );

			// A stored pattern may name our own host, which breaks after a domain
			// move and adds nothing: the lists describe this site. The host is
			// stripped here, so old entries keep matching and new ones can be
			// written as /wp-content/... . A foreign host stays.
			if ( '' !== $pattern && preg_match( '#^https?://#', $pattern ) ) {
				$p_host = strtolower( (string) wp_parse_url( $pattern, PHP_URL_HOST ) );
				$s_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

				if ( '' !== $p_host && $p_host === $s_host ) {
					$p_path = (string) wp_parse_url( $pattern, PHP_URL_PATH );

					if ( '' !== $p_path ) {
						$pattern = $p_path;
					}
				}
			}

			if ( '' === $pattern ) {
				continue;
			}

			if ( ! str_contains( $pattern, '*' ) ) {
				if ( str_contains( $subject, $pattern ) || ( '' !== $path && str_contains( $path, $pattern ) ) ) {
					return true;
				}

				continue;
			}

			$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';

			if ( 1 === preg_match( $regex, $subject ) ) {
				return true;
			}

			if ( '' !== $path && 1 === preg_match( $regex, $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the file is one of our own cached copies.
	 *
	 * @param string $path Resolved path.
	 */
	private static function is_own_cache( string $path ): bool {
		$directory = MMF_Cache::base_dir();

		if ( '' === $directory ) {
			return false;
		}

		return str_starts_with(
			wp_normalize_path( $path ),
			trailingslashit( wp_normalize_path( $directory ) )
		);
	}

	/**
	 * Attributes of a tag, names lowercased and values entity-decoded.
	 *
	 * The first occurrence of a repeated attribute wins, as in HTML.
	 *
	 * Public because the font handler rewrites `<link>` tags as well: both
	 * classes must parse a tag exactly the same way.
	 *
	 * @param string $tag Full tag.
	 * @return array<string,string>
	 */
	public static function attributes( string $tag ): array {
		$attributes = array();

		if ( ! preg_match_all( '/\s([^\s=\/>]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?/', $tag, $matches, PREG_SET_ORDER ) ) {
			return $attributes;
		}

		foreach ( $matches as $match ) {
			$name = strtolower( $match[1] );

			if ( array_key_exists( $name, $attributes ) ) {
				continue;
			}

			$value = isset( $match[2] ) ? $match[2] : '';

			if ( strlen( $value ) >= 2 && ( '"' === $value[0] || "'" === $value[0] ) ) {
				$value = substr( $value, 1, -1 );
			}

			$attributes[ $name ] = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		}

		return $attributes;
	}

	/**
	 * Replaces (or adds) an attribute value, keeping the rest of the tag.
	 *
	 * @param string $tag   Full tag.
	 * @param string $name  Attribute name.
	 * @param string $value New value, unescaped.
	 */
	public static function replace_attribute( string $tag, string $name, string $value ): string {
		$quoted = '"' . str_replace( '"', '&quot;', $value ) . '"';
		$count  = 0;

		$replaced = preg_replace_callback(
			'/(\s' . preg_quote( $name, '/' ) . '\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
			static function ( $match ) use ( $quoted ) {
				return $match[1] . $quoted;
			},
			$tag,
			1,
			$count
		);

		if ( is_string( $replaced ) && $count > 0 ) {
			return $replaced;
		}

		return self::add_flag( $tag, $name . '=' . $quoted );
	}

	/**
	 * Removes an attribute altogether.
	 *
	 * @param string $tag  Full tag.
	 * @param string $name Attribute name.
	 */
	public static function remove_attribute( string $tag, string $name ): string {
		$removed = preg_replace(
			'/\s+' . preg_quote( $name, '/' ) . '\s*(?:=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?/i',
			'',
			$tag,
			1
		);

		return is_string( $removed ) ? $removed : $tag;
	}

	/**
	 * Adds a valueless attribute (or `name="value"`).
	 *
	 * @param string $tag       Full tag.
	 * @param string $attribute Text to insert, e.g. `defer`.
	 */
	private static function add_flag( string $tag, string $attribute ): string {
		$patched = preg_replace( '/\s*(\/?>)$/', ' ' . $attribute . '$1', $tag, 1 );

		return is_string( $patched ) ? $patched : $tag;
	}

	/**
	 * Whether the tag already carries the attribute.
	 *
	 * Public because the merge pass has to recognise the same attributes the
	 * per-tag pipeline looks at.
	 *
	 * @param string $tag  Full tag.
	 * @param string $name Attribute name.
	 */
	public static function has_attribute( string $tag, string $name ): bool {
		return 1 === preg_match(
			'/\s' . preg_quote( $name, '/' ) . '(?=[\s=\/>])/i',
			$tag
		);
	}

	/**
	 * Whether the script type is JavaScript.
	 *
	 * An empty type is classic JavaScript; a template or JSON type is not, and
	 * must be copied as it is.
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
				'module',
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

	/**
	 * Whether the script type is a module.
	 *
	 * @param string $type Raw `type` attribute.
	 */
	private static function is_module( string $type ): bool {
		return 'module' === strtolower( trim( explode( ';', $type )[0] ) );
	}

	/**
	 * Whether a `rel` value means "the main stylesheet".
	 *
	 * Alternate stylesheets are left alone: inlining them would apply a style
	 * the visitor did not choose.
	 *
	 * @param string $rel Lowercased `rel` attribute.
	 */
	private static function is_stylesheet( string $rel ): bool {
		return str_contains( $rel, 'stylesheet' ) && ! str_contains( $rel, 'alternate' );
	}

	/**
	 * Whether the media query list contains a print part.
	 *
	 * @param string $media Lowercased `media` attribute.
	 */
	private static function is_print_media( string $media ): bool {
		foreach ( explode( ',', $media ) as $part ) {
			if ( str_starts_with( trim( $part ), 'print' ) ) {
				return true;
			}
		}

		return false;
	}
}
