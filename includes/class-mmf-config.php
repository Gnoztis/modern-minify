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
	 */
	public const SCHEMA = 2;

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
			// Master switch: an off plugin touches nothing.
			'enabled'                => array(
				'type'    => 'bool',
				'default' => false,
			),
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
				'default' => false,
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
			'remove_meta'            => array(
				'type'    => 'bool',
				'default' => false,
			),
			'remove_emoji'           => array(
				'type'    => 'bool',
				'default' => false,
			),
			// Fonts (stage 3): how a Google Fonts / Font Awesome request is
			// treated. `inherit` keeps the tag as the theme printed it.
			'fonts_method'           => array(
				'type'    => 'choice',
				'default' => 'inherit',
				'choices' => array( 'inherit', 'inline', 'async', 'exclude' ),
			),
			'fonts_swap'             => array(
				'type'    => 'bool',
				'default' => true,
			),
			'fonts_local'            => array(
				'type'    => 'bool',
				'default' => false,
			),
			'fonts_disable'          => array(
				'type'    => 'bool',
				'default' => false,
			),
			// Cache: age in days after which a cached file is dropped. 0 keeps
			// files until the cache is cleared by hand.
			'cache_ttl'              => array(
				'type'    => 'int',
				'default' => 30,
				'min'     => 0,
				'max'     => 3650,
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
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::prepare( is_array( $stored ) ? $stored : array() );
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
	 * Whether the plugin is switched on.
	 */
	public static function is_enabled(): bool {
		return (bool) self::get( 'enabled', false );
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
