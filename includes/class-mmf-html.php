<?php
/**
 * HTML minifier.
 *
 * The pipeline rewrites markup produced by an arbitrary theme, so this class is
 * deliberately conservative: it collapses whitespace and drops comments, and
 * copies every byte of a protected region unchanged. A protected region is the
 * inner content of `pre`, `textarea`, `code`, `svg`, `script`, `style`, `title`,
 * `template`, `noscript` and `xmp`, plus CDATA sections and any non-tag markup.
 *
 * Protected:
 *   - `<pre>`, `<textarea>`, `<code>`: whitespace is the content there.
 *   - `<svg>`: its own parser, comments and whitespace may matter.
 *   - `<script>`/`<style>`: already minified (or deliberately left alone) by
 *     MMF_Assets; collapsing their whitespace would corrupt JavaScript strings
 *     and CSS selectors.
 *   - Gutenberg block comments and WordPress loop comments (`more`,
 *     `nextpage`, `noteaser`) are kept because the block editor and the loop
 *     read them.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_HTML' ) ) {
	return;
}

/**
 * Collapses HTML whitespace without touching protected content.
 */
final class MMF_HTML {

	/**
	 * Elements whose inner content is copied byte for byte.
	 */
	private const RAW_ELEMENTS = array(
		'pre',
		'textarea',
		'code',
		'svg',
		'script',
		'style',
		'title',
		'template',
		'noscript',
		'xmp',
	);

	/**
	 * Elements that start a block box.
	 *
	 * Whitespace next to a block box is not rendered, so it can be removed
	 * completely. Everything not listed here is treated as inline: an unknown
	 * element keeps its surrounding space, which is the safe direction. The
	 * classic bug this prevents is `<span>a</span> <span>b</span>` becoming
	 * `ab`.
	 */
	private const BLOCK_ELEMENTS = array(
		'address',
		'article',
		'aside',
		'base',
		'blockquote',
		'body',
		'caption',
		'col',
		'colgroup',
		'dd',
		'details',
		'dialog',
		'div',
		'dl',
		'dt',
		'fieldset',
		'figcaption',
		'figure',
		'footer',
		'form',
		'frame',
		'frameset',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'head',
		'header',
		'hgroup',
		'hr',
		'html',
		'legend',
		'li',
		'link',
		'main',
		'menu',
		'meta',
		'nav',
		'noframes',
		'noscript',
		'ol',
		'optgroup',
		'option',
		'p',
		'pre',
		'search',
		'section',
		'script',
		'style',
		'summary',
		'table',
		'tbody',
		'td',
		'template',
		'tfoot',
		'th',
		'thead',
		'title',
		'tr',
		'ul',
		'xmp',
	);

	/**
	 * Minifies a whole document.
	 *
	 * @param string $html Markup.
	 */
	public static function minify( string $html ): string {
		if ( '' === $html || false === strpos( $html, '<' ) ) {
			return $html;
		}

		$tokens = self::tokenize( $html );
		$out    = '';

		// A whitespace run is pending until the next significant token decides
		// whether it is rendered at all.
		$pending     = false;
		$prev_inline = false;

		foreach ( $tokens as $token ) {
			switch ( $token['type'] ) {
				case 'text':
					$raw  = $token['raw'];
					$core = trim( (string) preg_replace( '/\s+/', ' ', $raw ) );

					if ( '' === $core ) {
						$pending = true;
						continue 2;
					}

					if ( $prev_inline && ( $pending || 1 === preg_match( '/^\s/', $raw ) ) ) {
						$out .= ' ';
					}

					$out        .= $core;
					$prev_inline = true;
					$pending     = 1 === preg_match( '/\s$/', $raw );
					continue 2;

				case 'comment':
					if ( self::keep_comment( $token['raw'] ) ) {
						$out .= $token['raw'];
					}
					// A dropped comment does not break a whitespace run: the
					// space on either side of it is still one boundary.
					continue 2;

				case 'tag':
					$inline = self::is_inline( $token['name'] );

					if ( $pending && $prev_inline && $inline ) {
						$out .= ' ';
					}

					$out        .= $token['raw'];
					$prev_inline = $inline;
					$pending     = false;
					continue 2;

				case 'content':
					// Protected inner content of a raw element: verbatim, and it
					// behaves like text for the whitespace around it.
					$out        .= $token['raw'];
					$prev_inline = true;
					$pending     = false;
					continue 2;

				default:
					$out    .= $token['raw'];
					$pending = false;
			}
		}

		return $out;
	}

	/**
	 * Splits markup into tokens.
	 *
	 * Exposed because the asset rewriter must see exactly the same element
	 * boundaries: rewriting a `<script>` inside a `<pre>` code sample would
	 * corrupt the sample.
	 *
	 * Token shape:
	 *   - `text`:        text between tags;
	 *   - `tag`:         `raw`, `name`, `closing`;
	 *   - `content`:     protected inner content of a raw element;
	 *   - `comment`:     an HTML comment, kept or dropped by `keep_comment()`;
	 *   - `declaration`: doctype, processing instruction or CDATA section.
	 *
	 * @param string $html Markup.
	 * @return array<int,array<string,mixed>>
	 */
	public static function tokenize( string $html ): array {
		$tokens = array();
		$length = strlen( $html );
		$offset = 0;

		while ( $offset < $length ) {
			$lt = strpos( $html, '<', $offset );

			if ( false === $lt ) {
				$tokens[] = array(
					'type' => 'text',
					'raw'  => substr( $html, $offset ),
				);
				break;
			}

			if ( $lt > $offset ) {
				$tokens[] = array(
					'type' => 'text',
					'raw'  => substr( $html, $offset, $lt - $offset ),
				);
			}

			$offset = $lt;

			if ( '<!--' === substr( $html, $offset, 4 ) ) {
				$end  = strpos( $html, '-->', $offset + 4 );
				$stop = false === $end ? $length : $end + 3;
				$tokens[] = array(
					'type' => 'comment',
					'raw'  => substr( $html, $offset, $stop - $offset ),
				);
				$offset = $stop;
				continue;
			}

			if ( 0 === substr_compare( $html, '<![CDATA[', $offset, 9 ) ) {
				$end  = strpos( $html, ']]>', $offset + 9 );
				$stop = false === $end ? $length : $end + 3;
				$tokens[] = array(
					'type' => 'declaration',
					'raw'  => substr( $html, $offset, $stop - $offset ),
				);
				$offset = $stop;
				continue;
			}

			if ( '<!' === substr( $html, $offset, 2 ) ) {
				$end  = strpos( $html, '>', $offset + 2 );
				$stop = false === $end ? $length : $end + 1;
				$tokens[] = array(
					'type' => 'declaration',
					'raw'  => substr( $html, $offset, $stop - $offset ),
				);
				$offset = $stop;
				continue;
			}

			if ( '<?' === substr( $html, $offset, 2 ) ) {
				$end  = strpos( $html, '?>', $offset + 2 );
				$stop = false === $end ? $length : $end + 2;
				$tokens[] = array(
					'type' => 'declaration',
					'raw'  => substr( $html, $offset, $stop - $offset ),
				);
				$offset = $stop;
				continue;
			}

			if ( preg_match( '/\G<\/?([a-zA-Z][a-zA-Z0-9:_-]*)/', $html, $match, 0, $offset ) ) {
				$name    = strtolower( $match[1] );
				$tag_end = self::tag_end( $html, $offset );
				$raw     = substr( $html, $offset, $tag_end - $offset );
				$closing = str_starts_with( $raw, '</' );

				$tokens[] = array(
					'type'    => 'tag',
					'raw'     => $raw,
					'name'    => $name,
					'closing' => $closing,
				);

				$offset = $tag_end;

				if ( $closing || ! in_array( $name, self::RAW_ELEMENTS, true ) || str_ends_with( rtrim( $raw ), '/>' ) ) {
					continue;
				}

				$close = stripos( $html, '</' . $name, $offset );

				if ( false === $close ) {
					// An unterminated element: the rest of the document is its
					// content and must be copied as it is.
					if ( $offset < $length ) {
						$tokens[] = array(
							'type' => 'content',
							'raw'  => substr( $html, $offset ),
						);
					}
					break;
				}

				if ( $close > $offset ) {
					$tokens[] = array(
						'type' => 'content',
						'raw'  => substr( $html, $offset, $close - $offset ),
					);
				}

				$close_end = self::tag_end( $html, $close );

				$tokens[] = array(
					'type'    => 'tag',
					'raw'     => substr( $html, $close, $close_end - $close ),
					'name'    => $name,
					'closing' => true,
				);

				$offset = $close_end;
				continue;
			}

			// A `<` that starts no tag is literal text.
			$tokens[] = array(
				'type' => 'text',
				'raw'  => '<',
			);
			$offset++;
		}

		return $tokens;
	}

	/**
	 * Offset just past the closing `>` of a tag, ignoring `>` inside quotes.
	 *
	 * @param string $html  Markup.
	 * @param int    $start Offset of the opening `<`.
	 */
	public static function tag_end( string $html, int $start ): int {
		$length = strlen( $html );
		$index  = $start + 1;
		$quote  = '';

		while ( $index < $length ) {
			$char = $html[ $index ];

			if ( '' === $quote ) {
				if ( '"' === $char || "'" === $char ) {
					$quote = $char;
				} elseif ( '>' === $char ) {
					return $index + 1;
				}
			} elseif ( $char === $quote ) {
				$quote = '';
			}

			$index++;
		}

		return $length;
	}

	/**
	 * Whether a comment must survive minification.
	 *
	 * @param string $comment Full comment, `<!--` to `-->`.
	 */
	private static function keep_comment( string $comment ): bool {
		$lower = strtolower( $comment );

		// Conditional comments, both halves: the block is one comment token
		// because the opening half is terminated only by the closing `-->`.
		if ( str_contains( $lower, '<![endif]' ) ) {
			return true;
		}

		if ( str_starts_with( $lower, '<!--[if' ) ) {
			return true;
		}

		// Gutenberg block delimiters: the block editor re-reads them.
		if ( str_starts_with( $lower, '<!-- wp:' ) || str_starts_with( $lower, '<!-- /wp:' ) ) {
			return true;
		}

		// Loop markers consumed by WordPress itself.
		foreach ( array( '<!--more', '<!--nextpage', '<!--noteaser' ) as $marker ) {
			if ( str_starts_with( $lower, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an element is rendered inline (its surrounding space is visible).
	 *
	 * @param string $name Lowercase element name.
	 */
	private static function is_inline( string $name ): bool {
		return ! in_array( $name, self::BLOCK_ELEMENTS, true );
	}
}
