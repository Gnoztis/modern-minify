<?php
/**
 * Combining consecutive local files into one bundle.
 *
 * The option exists because a request saved is sometimes a request saved, and
 * because some hosts still charge per request. It is off by default and it is
 * deliberately pessimistic:
 *
 *   - only consecutive local tags of the same kind, with only whitespace
 *     between them, are considered a group;
 *   - the whole group fails closed: a single unreadable file, a foreign URL, an
 *     `async`/`defer`/`module` in the set, a `media` mismatch, a `print`
 *     stylesheet or a limit breach means every tag of the group stays exactly
 *     where it was - no partial bundle is ever built;
 *   - the bundle is minified with the same minifier and stored with the same
 *     content-addressed cache naming as any other file, so changing a
 *     minification setting changes the hash and therefore the bundle.
 *
 * The bundle tag carries `data-mmf-bundle="1"` and the rest of the pipeline
 * leaves it alone: its content is already the minified concatenation, and
 * moving or deferring it would break the order the bundle was built to keep.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Merge' ) ) {
	return;
}

/**
 * Builds CSS and JavaScript bundles.
 */
final class MMF_Merge {

	public const MAX_FILES = 10;
	public const MAX_BYTES = 1048576;

	/**
	 * Marker attribute of a bundle tag.
	 */
	public const MARKER = 'data-mmf-bundle';

	/**
	 * Rewrites the markup, replacing eligible groups with bundle tags.
	 *
	 * @param string              $html     Document.
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array{html:string,stats:array<string,int>}
	 */
	public static function apply( string $html, array $settings ): array {
		$stats = array(
			'bundles'       => 0,
			'bundled_files' => 0,
			'bundle_bytes'  => 0,
			'css_before'    => 0,
			'css_after'     => 0,
		);

		if ( '' === $html ) {
			return array(
				'html'  => $html,
				'stats' => $stats,
			);
		}

		$stats['css_before'] = self::count_stylesheets( $html );

		$tokens = MMF_HTML::tokenize( $html );
		$count  = count( $tokens );
		$out    = '';
		$index  = 0;

		while ( $index < $count ) {
			$token = $tokens[ $index ];

			if ( 'tag' !== $token['type'] || $token['closing'] || ! in_array( (string) $token['name'], array( 'link', 'script' ), true ) ) {
				$out  .= (string) $token['raw'];
				$index++;
				continue;
			}


			$group = self::group( $tokens, $index, $settings );

			if ( null === $group ) {
				$out  .= (string) $token['raw'];
				$index++;
				continue;
			}

			$bundle = self::build( $group['items'], $group['type'], $settings );

			if ( empty( $bundle['tag'] ) ) {
				// Fail closed: the group is printed token by token, untouched.
				for ( $cursor = $index; $cursor <= $group['last']; $cursor++ ) {
					$out .= (string) $tokens[ $cursor ]['raw'];
				}

				$index = $group['last'] + 1;
				continue;
			}

			$stats['bundles']       += 1;
			$stats['bundled_files'] += count( $group['items'] );
			$stats['bundle_bytes']  += strlen( $bundle['content'] );

			$out  .= $bundle['tag'];
			$index = $group['last'] + 1;
		}

		$stats['css_after'] = self::count_stylesheets( $out );

		return array(
			'html'  => $out,
			'stats' => $stats,
		);
	}

	/**
	 * Number of stylesheet links in a document.
	 *
	 * @param string $html Document.
	 */
	private static function count_stylesheets( string $html ): int {
		$count = 0;

		foreach ( MMF_HTML::tokenize( $html ) as $token ) {
			if ( 'tag' !== $token['type'] || $token['closing'] || 'link' !== $token['name'] ) {
				continue;
			}

			$rel = strtolower( (string) ( MMF_Assets::attributes( (string) $token['raw'] )['rel'] ?? '' ) );

			if ( str_contains( $rel, 'stylesheet' ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Collects the run of mergeable tags starting at a token.
	 *
	 * @param array<int,array<string,mixed>> $tokens   Token stream.
	 * @param int                            $start    Index of the first tag.
	 * @param array<string,mixed>            $settings Plugin settings.
	 * @return array{items:array<int,array{src:string,media:string}>,last:int,type:string}|null
	 */
	private static function group( array $tokens, int $start, array $settings ): ?array {
		$first = self::candidate( $tokens, $start, $settings );

		if ( null === $first ) {
			return null;
		}

		$type  = $first['type'];
		$media = $first['media'];
		$items = array( $first );
		$last  = $first['last'];
		$index = $last + 1;

		while ( $index < count( $tokens ) ) {
			// Whitespace between two tags keeps the run alive; anything else
			// (a paragraph, a comment, another tag) ends it.
			if ( 'text' === $tokens[ $index ]['type'] && '' === trim( (string) $tokens[ $index ]['raw'] ) ) {
				$index++;
				continue;
			}

			if ( 'tag' !== $tokens[ $index ]['type'] || $tokens[ $index ]['closing'] ) {
				break;
			}

			$next = self::candidate( $tokens, $index, $settings );

			if ( null === $next || $next['type'] !== $type || $next['media'] !== $media ) {
				break;
			}

			$items[] = $next;
			$last    = $next['last'];
			$index   = $last + 1;
		}

		if ( count( $items ) < 2 ) {
			return null;
		}

		return array(
			'items' => $items,
			'last'  => $last,
			'type'  => $type,
		);
	}

	/**
	 * Whether the tag is a merge candidate, without reading the file yet.
	 *
	 * @param array<int,array<string,mixed>> $tokens   Token stream.
	 * @param int                            $index    Tag index.
	 * @param array<string,mixed>            $settings Plugin settings.
	 * @return array{src:string,media:string,type:string,last:int}|null
	 */
	private static function candidate( array $tokens, int $index, array $settings ): ?array {
		$token = $tokens[ $index ];
		$raw   = (string) $token['raw'];
		$name  = (string) $token['name'];
		$attrs = MMF_Assets::attributes( $raw );

		if ( MMF_Assets::has_attribute( $raw, self::MARKER ) || MMF_Assets::has_attribute( $raw, 'integrity' ) || MMF_Assets::has_attribute( $raw, 'crossorigin' ) ) {
			return null;
		}

		$last = $index;

		// An element with content is inline; consume its tokens so the caller
		// can skip the whole thing when it is not a candidate.
		if ( isset( $tokens[ $index + 1 ] ) && 'content' === $tokens[ $index + 1 ]['type'] ) {
			$close = $index + 2;

			if ( ! isset( $tokens[ $close ] ) || 'tag' !== $tokens[ $close ]['type'] || ! $tokens[ $close ]['closing'] ) {
				return null;
			}

			$last = $close;
		} elseif ( isset( $tokens[ $index + 1 ] ) && 'tag' === $tokens[ $index + 1 ]['type']
			&& $tokens[ $index + 1 ]['closing'] && (string) $tokens[ $index + 1 ]['name'] === $name ) {
			// A `<script src>` element: its closing tag belongs to the element
			// and must not end the run.
			$last = $index + 1;
		}

		if ( 'link' === $name ) {
			if ( empty( $settings['merge_css'] ) ) {
				return null;
			}

			$rel = strtolower( (string) ( $attrs['rel'] ?? '' ) );

			if ( ! str_contains( $rel, 'stylesheet' ) || str_contains( $rel, 'alternate' ) ) {
				return null;
			}

			$media = strtolower( trim( (string) ( $attrs['media'] ?? '' ) ) );

			// A print stylesheet must stay its own request: merging it with
			// screen CSS would apply print rules to the screen.
			if ( '' !== $media && 'all' !== $media && ( str_contains( $media, 'print' ) || str_contains( $media, 'speech' ) ) ) {
				return null;
			}

			$src = (string) ( $attrs['href'] ?? '' );

			if ( '' === $src || ! MMF_Assets::is_local_url( $src ) || MMF_Assets::matches( $src, $settings['exclude_css'] ?? array() ) ) {
				return null;
			}

			return array(
				'src'   => $src,
				'media' => $media,
				'type'  => 'css',
				'last'  => $last,
			);
		}

		if ( '' !== (string) ( $attrs['type'] ?? '' ) && 'text/javascript' !== strtolower( trim( (string) $attrs['type'] ) ) ) {
			return null;
		}

		if ( empty( $settings['merge_js'] ) || '' === (string) ( $attrs['src'] ?? '' ) ) {
			return null;
		}

		if ( MMF_Assets::has_attribute( $raw, 'async' ) || MMF_Assets::has_attribute( $raw, 'defer' ) ) {
			return null;
		}

		$src = (string) $attrs['src'];

		if ( ! MMF_Assets::is_local_url( $src ) || MMF_Assets::matches( $src, $settings['exclude_js'] ?? array() ) ) {
			return null;
		}

		// jQuery and its companions are always excluded from merging: everything
		// else on the page waits for them.
		$lower = strtolower( $src );

		foreach ( MMF_Loader::HARD_EXCLUDES as $needle ) {
			if ( str_contains( $lower, $needle ) ) {
				return null;
			}
		}

		return array(
			'src'   => $src,
			'media' => '',
			'type'  => 'js',
			'last'  => $last,
		);
	}

	/**
	 * Reads, minifies and caches one group.
	 *
	 * @param array<int,array{src:string,media:string}> $items    Group members.
	 * @param string                                    $type     `css` or `js`.
	 * @param array<string,mixed>                       $settings Plugin settings.
	 * @return array{tag:string,content:string}|array{} Empty on any failure.
	 */
	private static function build( array $items, string $type, array $settings ): array {
		$max_files = isset( $settings['merge_max_files'] ) && is_numeric( $settings['merge_max_files'] )
			? (int) $settings['merge_max_files']
			: self::MAX_FILES;
		$max_files = max( 2, min( 50, $max_files ) );

		$max_mb = isset( $settings['merge_max_size'] ) && is_numeric( $settings['merge_max_size'] )
			? (int) $settings['merge_max_size']
			: 1;
		$max_bytes = max( 1, min( 20, $max_mb ) ) * 1024 * 1024;

		if ( count( $items ) > $max_files ) {
			return array();
		}

		$parts = array();
		$total = 0;

		foreach ( $items as $item ) {
			$path = MMF_Assets::resolve_path( $item['src'], $type );

			if ( '' === $path ) {
				return array();
			}

			// The path is resolved with realpath() and checked against the
			// mapped WordPress directory before it is read.
			$source = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- validated path inside the WordPress tree.

			if ( ! is_string( $source ) || '' === $source ) {
				return array();
			}

			// The bundle lives in the cache, so relative url() values inside a
			// stylesheet have to be rebased to that stylesheet's own directory.
			if ( 'css' === $type ) {
				$absolute = MMF_Assets::absolute_url( $item['src'] );

				if ( '' !== $absolute ) {
					$source = MMF_Fonts::absolutize_urls( $absolute, $source );
				}
			}

			$minified = 'css' === $type ? MMF_Minifier::minify_css( $source ) : MMF_Minifier::minify_js( $source );

			if ( ! is_string( $minified ) || '' === trim( $minified ) ) {
				return array();
			}

			$total += strlen( $minified );

			if ( $total > $max_bytes ) {
				return array();
			}

			$parts[] = $minified;
		}

		if ( count( $parts ) < 2 ) {
			return array();
		}

		$content = implode( 'css' === $type ? "\n" : ";\n", $parts );
		$hash    = MMF_Cache::hash( $content, $type );
		$put     = MMF_Cache::put( $hash, $content, $type );

		if ( ! is_array( $put ) || empty( $put['url'] ) ) {
			return array();
		}

		$media = isset( $items[0]['media'] ) ? (string) $items[0]['media'] : '';

		if ( 'css' === $type ) {
			$tag = '<link rel="stylesheet" href="' . esc_url( (string) $put['url'] ) . '" ' . self::MARKER . '="1"';

			if ( '' !== $media && 'all' !== $media ) {
				$tag .= ' media="' . esc_attr( $media ) . '"';
			}

			$tag .= '>';
		} else {
			$tag = '<script src="' . esc_url( (string) $put['url'] ) . '" ' . self::MARKER . '="1"></script>';
		}

		return array(
			'tag'     => $tag,
			'content' => $content,
		);
	}
}
