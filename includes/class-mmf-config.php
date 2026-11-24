<?php
/**
 * Settings model.
 *
 * The whole plugin state is one array option: a single read per request, one
 * schema version and one sanitiser, so nothing can write a half-valid array
 * into the database. Every consumer reads through `MMF_Config::get()`, never
 * through `get_option()` directly: defaults and type coercion are applied in
 * one place.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Config' ) ) {
	return;
}

/**
 * Reads, normalises and writes the `mmf_settings` option.
 */
final class MMF_Config {

	/**
	 * Option name. Stable: it is part of the install and uninstall contract.
	 */
	public const OPTION = 'mmf_settings';

	/**
	 * Schema version of the stored array.
	 *
	 * Bumped when a key changes meaning and the stored value has to be migrated
	 * rather than coerced; `prepare()` always writes the current number, so the
	 * stored array tells which rules produced it.
	 *
	 * 3: the font method vocabulary changed (`inherit`/`inline`/`async`/`exclude`
	 *    became `off`/`swap`/`local`); an unknown value falls back to `off`,
	 *    which is the safe direction - nothing is touched.
	 * 4: `remove_meta` became `remove_generator` (the generator tag only) and the
	 *    `ver_mode` choice arrived; the old key is ignored and the new fields
	 *    take their defaults, which hide the version.
	 * 5: Delay JS arrived (`delay_js`, `delay_js_exclude`, `delay_js_timeout`);
	 *    empty patterns mean nothing is delayed, which is the safe default.
	 * 6: external files default to `local` (`external_mode`, `external_max_size`,
	 *    `external_exclude`); a site that wants the old behaviour sets `keep`.
	 * 7: the whole-page mode arrived (`rocket_mode`, `rocket_exclude`), off by
	 *    default because it defers every script.
	 * 8: bundling limits and external defer (`defer_external`, `merge_max_files`,
	 *    `merge_max_size`); bundling stays off and external defer stays off.
	 * 9: the master switch is gone (an active plugin is an enabled plugin),
	 *    Google Fonts default to `local`, and gravatar replacement is on by
	 *    default (`disable_gravatar`).
	 * 10: the localised Google Fonts stylesheet is printed inline
	 *    (`inline_fonts_css`), and the cleantalk.org domain family is never
	 *    localised.
	 * 11: jQuery Migrate is deregistered on the front end by default, and
	 *    `defer_js` became all-or-nothing.
	 */
	public const SCHEMA = 11;

	/**
	 * Upper bound of one exclusion list, in entries.
	 *
	 * The lists are matched on every front-end request: a runaway list is a
	 * performance problem, not a feature.
	 */
	private const LIST_LIMIT = 500;

	/**
	 * Normalised settings of the current request, `null` until first read.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Field map: type, default and, for choices, the allowed values.
	 *
	 * Stage 2 builds the settings screen from this map, so a field exists in
	 * exactly one place: the form, the sanitiser and the readers cannot drift.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		return array(
			// What is minified.
			'minify_js'              => array(
				'type'    => 'bool',
				'default' => true,
			),
			'minify_css'             => array(
				'type'    => 'bool',
				'default' => true,
			),
			'minify_html'            => array(
				'type'    => 'bool',
				'default' => true,
			),
			// Combining files: fewer requests, but one broken file breaks the bundle.
			'merge_js'               => array(
				'type'    => 'bool',
				'default' => false,
			),
			'merge_css'              => array(
				'type'    => 'bool',
				'default' => true,
			),
			// CSS shaping.
			'inline_css'             => array(
				'type'    => 'bool',
				'default' => false,
			),
			// Size limit of a stylesheet that may be inlined, in KiB. 0 disables
			// inlining even when the switch above is on.
			'inline_css_limit'       => array(
				'type'    => 'int',
				'default' => 20,
				'min'     => 0,
				'max'     => 5120,
			),
			'remove_print_css'       => array(
				'type'    => 'bool',
				'default' => false,
			),
			// JS loading: `async` for the listed patterns, `defer` for everything
			// not excluded. `async` on a script with dependencies breaks them, so
			// the async list is opt-in while defer is a single switch.
			'async_js'               => array(
				'type'    => 'list',
				'default' => array(),
			),
			'defer_js'               => array(
				'type'    => 'bool',
				'default' => false,
			),
			// Processed and minified as usual, but never deferred: a script that
			// must run before the markup it manipulates belongs here.
			'defer_skip'             => array(
				'type'    => 'list',
				'default' => array(),
			),
			// Moves local head scripts to the end of the body. Async scripts,
			// modules and excluded files stay where the theme printed them.
			'move_scripts_to_footer' => array(
				'type'    => 'bool',
				'default' => false,
			),
			// Delay JS: files whose URLs match these patterns are held back until
			// the first interaction (or the fallback timeout). An empty list
			// delays nothing on purpose.
			'delay_js'               => array(
				'type'    => 'list',
				'default' => array(),
			),
			'delay_js_exclude'       => array(
				'type'    => 'list',
				'default' => array(),
			),
			// Seconds after DOM ready before a delayed page loads its scripts
			// anyway, so a visitor who does nothing still gets a working page.
			'delay_js_timeout'       => array(
				'type'    => 'int',
				'default' => 5,
				'min'     => 1,
				'max'     => 30,
			),
			// External files: `local` copies them into the cache and serves them
			// from this site, `keep` leaves the tag alone, `remove` deletes it.
			// `local` is the default because a third-party request leaks the
			// visitor's IP and Referer and adds a foreign host to script-src.
			'external_mode'          => array(
				'type'    => 'choice',
				'default' => 'local',
				'choices' => array( 'local', 'keep', 'remove' ),
			),
			// Size cap of one downloaded file, in MiB.
			'external_max_size'      => array(
				'type'    => 'int',
				'default' => 2,
				'min'     => 1,
				'max'     => 20,
			),
			// Extra hosts or URL fragments that must stay external.
			'external_exclude'       => array(
				'type'    => 'list',
				'default' => array(),
			),
			// Whole-page mode: every JavaScript tag waits for the document to be
			// parsed and then runs in document order. Off by default because it
			// touches every script on the page.
			'rocket_mode'            => array(
				'type'    => 'bool',
				'default' => false,
			),
			// Paths and ids that the whole-page mode must leave alone.
			'rocket_exclude'         => array(
				'type'    => 'list',
				'default' => array(),
			),
			// `defer` on external scripts: off by default, because foreign code
			// may rely on being parsed before the rest of the page.
			'defer_external'         => array(
				'type'    => 'bool',
				'default' => false,
			),
			// Bundle limits: a group above either one is left as separate tags.
			'merge_max_files'        => array(
				'type'    => 'int',
				'default' => 10,
				'min'     => 2,
				'max'     => 50,
			),
			'merge_max_size'         => array(
				'type'    => 'int',
				'default' => 1,
				'min'     => 1,
				'max'     => 20,
			),
			'exclude_js'             => array(
				'type'    => 'list',
				'default' => array(),
			),
			'exclude_css'            => array(
				'type'    => 'list',
				'default' => array(),
			),
			// Context rules.
			'disable_when_logged_in' => array(
				'type'    => 'bool',
				'default' => false,
			),
			// Version hiding is a security feature, not cosmetics: the WordPress
			// version in a URL or a meta tag tells an attacker which known
			// vulnerabilities apply. Both are on by default for that reason.
			'remove_generator'       => array(
				'type'    => 'bool',
				'default' => true,
			),
			'remove_emoji'           => array(
				'type'    => 'bool',
				'default' => true,
			),
			// What happens to the `ver` query parameter of the site's own assets:
			// `hash` replaces it with a short content hash (cache busting without
			// the version), `remove` drops it, `keep` does nothing.
			'ver_mode'               => array(
				'type'    => 'choice',
				'default' => 'hash',
				'choices' => array( 'hash', 'remove', 'keep' ),
			),
			// Inline blocks: minifying `<script>` and `<style>` bodies. An
			// individual switch because this is the change that breaks themes most
			// often, so it must be possible to keep the file pipeline and turn
			// only this off.
			'minify_inline'          => array(
				'type'    => 'bool',
				'default' => true,
			),
			// Fonts: how a Google Fonts request is treated. `off` keeps the tag
			// as the theme printed it, `swap` only appends display=swap, `local`
			// downloads the stylesheet and the font files into the cache.
			'fonts_method'           => array(
				'type'    => 'choice',
				'default' => 'local',
				'choices' => array( 'off', 'swap', 'local' ),
			),
			// Font Awesome: `off` keeps the tag, `inline` replaces the stylesheet
			// link with an inline style block.
			'fawesome_method'        => array(
				'type'    => 'choice',
				'default' => 'off',
				'choices' => array( 'off', 'inline' ),
			),
			// Print the localised Google Fonts stylesheet in the page instead of
			// serving it as one more file. Above the threshold it stays a file.
			// jQuery Migrate is legacy: modern themes do not need it, and it is a
			// separate request plus console noise. Front-end only.
			'remove_jquery_migrate'  => array(
				'type'    => 'bool',
				'default' => true,
			),
			'inline_fonts_css'       => array(
				'type'    => 'bool',
				'default' => true,
			),
			// Avatars: a gravatar URL sends the visitor's IP and a hash of their
			// address to a third party, so a local inline SVG is served instead.
			// On by default; the admin screen and outgoing mail are untouched.
			'disable_gravatar'       => array(
				'type'    => 'bool',
				'default' => true,
			),
			'avatar_webp'            => array(
				'type'    => 'bool',
				'default' => true,
			),
			'avatar_quality'         => array(
				'type'    => 'int',
				'default' => 82,
				'min'     => 60,
				'max'     => 95,
			),
			'image_webp'             => array(
				'type'    => 'bool',
				'default' => true,
			),
			'image_quality_jpeg'     => array(
				'type'    => 'int',
				'default' => 82,
				'min'     => 60,
				'max'     => 95,
			),
			'image_quality_png'      => array(
				'type'    => 'int',
				'default' => 82,
				'min'     => 60,
				'max'     => 95,
			),
			'image_png_lossless'     => array(
				'type'    => 'bool',
				'default' => true,
			),
			'image_webp_ondemand'    => array(
				'type'    => 'bool',
				'default' => false,
			),
			// Test mode: the pipeline runs only for administrators, so the site
			// owner can look at the result on the live site without risking
			// visitors.
			'test_mode'              => array(
				'type'    => 'bool',
				'default' => false,
			),
			// Cache: age in days after which a cached file is dropped. 0 keeps
			// files until the cache is cleared by hand.
			'cache_ttl'              => array(
				'type'    => 'int',
				'default' => 7,
				'min'     => 0,
				'max'     => 3650,
			),
			'cache_max_files'        => array(
				'type'    => 'int',
				'default' => 1000,
				'min'     => 100,
				'max'     => 100000,
			),
			'cache_max_size'         => array(
				'type'    => 'int',
				'default' => 100,
				'min'     => 10,
				'max'     => 10240,
			),
			// Diagnostics for the admin screen: no writes, only reporting.
			'debug'                  => array(
				'type'    => 'bool',
				'default' => false,
			),
		);
	}

	/**
	 * Defaults of every field.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		$defaults = array();

		foreach ( self::fields() as $key => $field ) {
			$defaults[ $key ] = $field['default'];
		}

		return $defaults;
	}

	/**
	 * All settings, defaults applied and values coerced.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			// Schema 9 made `local` the default for Google Fonts. A stored `off`
			// from an older schema is the old default, not a deliberate choice,
			// so it is migrated once; a value saved after the upgrade stays.
			if ( (int) ( $stored['schema'] ?? 0 ) < self::SCHEMA && 'off' === ( $stored['fonts_method'] ?? '' ) ) {
				$stored['fonts_method'] = 'local';
			}

			self::$cache = self::prepare( $stored );
		}

		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string|null $key      Setting name, `null` for the whole array.
	 * @param mixed       $fallback Returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( ?string $key = null, $fallback = null ) {
		if ( null === $key ) {
			return self::all();
		}

		$settings = self::all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Writes settings.
	 *
	 * Unknown keys are dropped and known ones are coerced, so a caller cannot
	 * store a value the readers do not expect. An unchanged array is not written
	 * twice; `update_option()` reports `false` for that, which is not a failure.
	 *
	 * @param array<string,mixed> $settings Settings to change; the rest is kept.
	 */
	public static function update( array $settings ): bool {
		$current = self::all();
		$clean   = self::prepare( array_merge( $current, $settings ) );

		if ( $clean === $current || $clean === get_option( self::OPTION ) ) {
			self::$cache = $clean;

			return true;
		}

		$saved = (bool) update_option( self::OPTION, $clean );

		if ( $saved ) {
			self::$cache = $clean;
		}

		return $saved;
	}

	/**
	 * Registers the option with the Settings API.
	 *
	 * The screen itself arrives in stage 2; the registration is here from the
	 * start so the option can never be written through the Settings API without
	 * `sanitize()` - a screen added later inherits the guard instead of having
	 * to remember it.
	 */
	public static function register_settings(): void {
		register_setting(
			'mmf',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Callback for `register_setting()`: accepts raw request data.
	 *
	 * @param mixed $input Raw value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		return self::prepare( is_array( $input ) ? $input : array() );
	}

	/**
	 * Drops the per-request cache, so the next read hits the option again.
	 *
	 * Needed after a site switch in multisite and after another code path wrote
	 * the option directly.
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Applies defaults, types, bounds and the schema version.
	 *
	 * @param array<string,mixed> $input Raw or stored settings.
	 * @return array<string,mixed>
	 */
	private static function prepare( array $input ): array {
		$settings = array();

		foreach ( self::fields() as $key => $field ) {
			$value = array_key_exists( $key, $input ) ? $input[ $key ] : $field['default'];

			switch ( $field['type'] ) {
				case 'bool':
					$settings[ $key ] = self::to_bool( $value );
					break;

				case 'list':
					$settings[ $key ] = self::to_list( $value );
					break;

				case 'int':
					$settings[ $key ] = max(
						(int) $field['min'],
						min( (int) $field['max'], self::to_int( $value, (int) $field['default'] ) )
					);
					break;

				default:
					$settings[ $key ] = self::to_choice( $value, $field['choices'], (string) $field['default'] );
			}
		}

		// Not a setting: it records which sanitiser produced the stored array.
		$settings['schema'] = self::SCHEMA;

		return $settings;
	}

	/**
	 * Checkbox value: `true`, `1`, `on`, `yes` are on; anything else is off.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return (bool) $value;
	}

	/**
	 * Integer value; a non-numeric value keeps the default instead of becoming 0.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $fallback Default of the field.
	 */
	private static function to_int( $value, int $fallback ): int {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		return $fallback;
	}

	/**
	 * One value out of a fixed list; anything else keeps the default.
	 *
	 * @param mixed    $value    Raw value.
	 * @param string[] $choices  Allowed values.
	 * @param string   $fallback Default of the field.
	 */
	private static function to_choice( $value, array $choices, string $fallback ): string {
		$value = is_scalar( $value ) ? (string) $value : '';

		return in_array( $value, $choices, true ) ? $value : $fallback;
	}

	/**
	 * Exclusion / pattern list from a textarea, an array or a comma list.
	 *
	 * Newlines and commas both separate entries: the admin textarea and a
	 * programmatic `update( array( 'exclude_js' => 'a.js, b.js' ) )` mean the
	 * same thing. Empty entries and duplicates are dropped, order is kept.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function to_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$list = array();

		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = trim( sanitize_text_field( (string) $item ) );

			if ( '' === $item || in_array( $item, $list, true ) ) {
				continue;
			}

			$list[] = $item;

			if ( count( $list ) >= self::LIST_LIMIT ) {
				break;
			}
		}

		return $list;
	}
}
