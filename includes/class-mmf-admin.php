<?php
/**
 * Settings screen: Настройки → Modern Minify.
 *
 * One page with tabs, no top-level menu. Every field of `MMF_Config::fields()`
 * is printed exactly once, the whole form is submitted at once (the inactive
 * tabs are hidden with `display:none`, not removed), and the Settings API
 * sanitises it through `MMF_Config::sanitize()`.
 *
 * The screen's own CSS and JavaScript are inline and are attached only on this
 * screen (`admin_enqueue_scripts` checks the hook suffix first), so no other
 * admin page pays for them.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Admin' ) ) {
	return;
}

/**
 * Renders the settings screen and handles its actions.
 */
final class MMF_Admin {

	/**
	 * Page slug, also the value of `?page=`.
	 */
	public const PAGE = 'modern-minify';

	/**
	 * Capability required for the screen and for the cache reset.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Nonce action of the cache reset.
	 */
	private const RESET_NONCE = 'mmf_reset_cache';
	private const ADVICE_NONCE = 'mmf_apply_advice';
	private const WEBP_NONCE = 'mmf_delete_webp';
	private const BULK_NONCE = 'mmf_webp_bulk';
	private const CSS_ACTION_NONCE = 'mmf_css_action';
	private const AVATARS_NONCE = 'mmf_convert_avatars';
	public const BULK_OPTION = 'mmf_webp_queue';
	public const BULK_LOCK = 'mmf_webp_lock';
	private const BULK_TTL = 300;
	private const BULK_BATCH = 20;

	/**
	 * Nonce action of the test-mode switch in the admin bar.
	 */
	public const TEST_NONCE = 'mmf_toggle_test_mode';

	/**
	 * Nonce action of the recommended-settings button.
	 */
	public const RECOMMENDED_NONCE = 'mmf_recommended_settings';

	/**
	 * Nonce action of the one-time toast flag.
	 */
	public const TOAST_NONCE = 'mmf_toast';

	/**
	 * Attaches the screen.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_reset' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_toggle_test' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_apply_recommended' ) );
		add_action( 'admin_post_mmf_apply_advice', array( __CLASS__, 'handle_apply_advice' ) );
		// The advisor button lives inside the settings form, and that form
		// carries `action=update` from settings_fields(). A POST field wins over
		// the query string in $_REQUEST, so admin-post.php dispatched
		// `admin_post_update`, found no handler and answered 400 with an empty
		// message. Handling the flag on admin_init avoids the dispatch entirely.
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_apply_advice' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_delete_webp' ) );
		add_action( 'admin_post_mmf_css_action', array( __CLASS__, 'handle_css_action' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_convert_avatars' ) );
		add_action( 'wp_ajax_mmf_webp_start', array( __CLASS__, 'ajax_webp_start' ) );
		add_action( 'wp_ajax_mmf_webp_batch', array( __CLASS__, 'ajax_webp_batch' ) );
		add_action( 'wp_ajax_mmf_webp_status', array( __CLASS__, 'ajax_webp_status' ) );
		add_action( 'wp_ajax_mmf_webp_pause', array( __CLASS__, 'ajax_webp_pause' ) );
		add_action( 'wp_ajax_mmf_webp_resume', array( __CLASS__, 'ajax_webp_resume' ) );
		add_action( 'wp_ajax_mmf_webp_stop', array( __CLASS__, 'ajax_webp_stop' ) );
	}

	/**
	 * The recommended profile: the safe, useful subset of the options.
	 *
	 * The risky features stay off on purpose. User exclusions and pattern lists
	 * are not part of the profile, with one exception: Delay JS is controlled
	 * only by its pattern list, so "Delay JS off" means clearing that list.
	 *
	 * @return array<string,mixed>
	 */
	public static function recommended_profile(): array {
		return array(
			'minify_html'      => true,
			'minify_css'       => true,
			'minify_js'        => true,
			'minify_inline'    => true,
			'fonts_method'     => 'local',
			'fawesome_method'  => 'inline',
			'disable_gravatar' => true,
			'ver_mode'         => 'hash',
			'remove_emoji'     => true,
			'external_mode'    => 'local',
			'merge_js'         => false,
			'merge_css'        => true,
			'defer_js'         => false,
			'delay_js'         => array(),
			'rocket_mode'      => false,
			'defer_external'   => false,
		);
	}

	/**
	 * Human-readable list of what the profile changes.
	 *
	 * @return string[]
	 */
	public static function recommended_labels(): array {
		return array(
			__( 'Минификация HTML, CSS и JS — включить', 'modern-minify' ),
			__( 'Минификация инлайн-блоков — включить', 'modern-minify' ),
			__( 'Google Fonts — скачивать локально', 'modern-minify' ),
			__( 'Font Awesome — встроить CSS', 'modern-minify' ),
			__( 'Граватары — заменить локальной заглушкой', 'modern-minify' ),
			__( 'Версии файлов — хеш содержимого', 'modern-minify' ),
			__( 'Emoji — удалять', 'modern-minify' ),
			__( 'Внешние файлы — локально', 'modern-minify' ),
			__( 'Объединение CSS — включить (CSS блокирует отрисовку)', 'modern-minify' ),
			__( 'Объединение JS — выключить (на HTTP/2 чаще вредит)', 'modern-minify' ),
			__( 'Delay JS — выключить (список шаблонов будет очищен)', 'modern-minify' ),
			__( 'Rocket-режим — выключить', 'modern-minify' ),
			__( 'defer для локальных скриптов — выключить', 'modern-minify' ),
			__( 'defer для внешних — выключить', 'modern-minify' ),
		);
	}

	/**
	 * Applies the recommended profile and resets the cache.
	 */
	public static function handle_apply_recommended(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';

		if ( self::PAGE !== $page ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';

		if ( 'mmf_recommended' !== $action ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( self::RECOMMENDED_NONCE );

		$profile = self::recommended_profile();
		$current = MMF_Config::all();
		$matches = true;

		foreach ( $profile as $key => $value ) {
			$now = $current[ $key ] ?? null;

			if ( is_array( $value ) ? (array) $now !== $value : ( is_bool( $value ) ? (bool) $now !== $value : (string) $now !== (string) $value ) ) {
				$matches = false;
				break;
			}
		}

		if ( $matches ) {
			MMF_Stats::record_action( __( 'Настройки уже соответствуют рекомендуемым.', 'modern-minify' ) );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => self::PAGE,
						'mmf-toast'       => 'recommended-noop',
						'mmf-toast-nonce' => wp_create_nonce( self::TOAST_NONCE ),
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		// A set operation: applying the profile twice must leave exactly the
		// same state, and nothing outside the profile is touched.
		MMF_Config::update( $profile );
		$deleted = MMF_Cache::delete_all();

		MMF_Stats::record_action( sprintf( __( 'Рекомендуемые настройки применены, кеш сброшен (удалено файлов: %d).', 'modern-minify' ), (int) $deleted ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::PAGE,
					'mmf-recommended' => '1',
					'mmf-reset'       => $deleted,
					'mmf-toast'       => 'recommended',
					'mmf-toast-nonce' => wp_create_nonce( self::TOAST_NONCE ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Toggles the test mode from the admin bar.
	 *
	 * A GET request with a nonce, like the cache reset: it changes one checkbox
	 * and returns to the page the admin came from.
	 */
	public static function handle_toggle_test(): void {
		if ( ! isset( $_GET['mmf-toggle-test'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( self::TEST_NONCE );

		$current = (bool) MMF_Config::get( 'test_mode' );
		$current = (bool) MMF_Config::get( 'test_mode' );
		MMF_Config::update( array( 'test_mode' => ! $current ) );

		MMF_Stats::record_action(
			$current
				? __( 'Тестовый режим выключен.', 'modern-minify' )
				: __( 'Тестовый режим включён.', 'modern-minify' )
		);

		$redirect = function_exists( 'wp_get_referer' ) ? wp_get_referer() : false;

		if ( ! is_string( $redirect ) || '' === $redirect ) {
			$redirect = admin_url( 'options-general.php?page=' . self::PAGE );
		}

		$redirect = add_query_arg(
			array(
				'mmf-toast'       => $current ? 'test-off' : 'test-on',
				'mmf-toast-nonce' => wp_create_nonce( self::TOAST_NONCE ),
			),
			$redirect
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Adds the page under Settings.
	 */
	public static function register_page(): void {
		add_options_page(
			__( 'Modern Minify', 'modern-minify' ),
			__( 'Modern Minify', 'modern-minify' ),
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Attaches the screen's inline CSS and JavaScript - on this screen only.
	 *
	 * @param string $hook_suffix Screen hook.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE !== $hook_suffix ) {
			return;
		}

		wp_register_style( 'mmf-admin', false, array(), MMF_VERSION );
		wp_enqueue_style( 'mmf-admin' );
		wp_add_inline_style( 'mmf-admin', self::inline_css() );

		wp_register_script( 'mmf-admin', false, array(), MMF_VERSION, true );
		wp_enqueue_script( 'mmf-admin' );
		wp_add_inline_script( 'mmf-admin', self::inline_js() );
	}

	/**
	 * Handles the cache reset link.
	 *
	 * A GET request with a nonce: the action deletes regenerable files, so a
	 * form of its own would only add a nested form to the settings page.
	 */
	public static function handle_reset(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';

		if ( self::PAGE !== $page ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';

		if ( 'mmf_reset_cache' !== $action ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( self::RESET_NONCE );

		$deleted = MMF_Cache::delete_all();

		// A manual reset is also a statistics reset: after it the counters would
		// describe a state that no longer exists. The TTL cleanup never comes
		// here - it removes old files, not accumulated observations.
		MMF_Stats::reset();
		delete_option( 'mmf_last_scripts' );

		MMF_Stats::record_action( sprintf( __( 'Кеш очищен и статистика сброшена (удалено файлов: %d).', 'modern-minify' ), (int) $deleted ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::PAGE,
					'mmf-reset'       => $deleted,
					'mmf-toast'       => 'reset',
					'mmf-toast-nonce' => wp_create_nonce( self::TOAST_NONCE ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Prints the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Недостаточно прав для этой страницы.', 'modern-minify' ) );
		}

		$tab = self::current_tab();

		echo '<div class="wrap mmf-wrap">';

		self::render_toasts();
		self::render_alerts();

		echo '<nav class="nav-tab-wrapper mmf-tabs">';

		foreach ( self::tabs() as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::tab_url( (string) $slug ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( (string) $label )
			);
		}

		echo '</nav>';

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( 'mmf' );

		foreach ( self::tab_fields() as $slug => $fields ) {
			ob_start();

			printf(
				'<div class="mmf-panel%s" id="mmf-panel-%s" data-tab="%s"%s>',
				$slug === $tab ? '' : ' mmf-panel--hidden',
				esc_attr( (string) $slug ),
				esc_attr( (string) $slug ),
				$slug === $tab ? '' : ' style="display:none"'
			);

			if ( 'status' === $slug ) {
				self::render_status();
			}

			if ( 'external' === $slug ) {
				self::render_external_tab();
			}

			self::render_rocket_notices( (string) $slug );

			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( $fields as $key ) {
				self::render_field( (string) $key );
			}

			echo '</tbody></table>';

			if ( 'settings' === $slug ) {
				self::render_html_note();
			}

			if ( 'javascript' === $slug ) {
				$advisor_url = add_query_arg( array( 'page' => self::PAGE, 'tab' => 'advisor' ), admin_url( 'options-general.php' ) );
				echo '<p class="mmf-actions"><a class="button" href="' . esc_url( $advisor_url ) . '">' . esc_html__( 'Открыть «Советник»', 'modern-minify' ) . '</a></p>';
			}

			if ( 'advisor' === $slug ) {
				self::render_advisor();
				self::render_css_advisor();
			}

			if ( 'images' === $slug ) {
				self::render_images_tab();
			}

			echo '</div>';

			$panel_html = (string) ob_get_clean();
			echo $panel_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the panel is built by the methods above and escaped there.
		}
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Tabs of the screen.
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array(
			'status'     => __( 'Статус', 'modern-minify' ),
			'javascript' => __( 'JavaScript', 'modern-minify' ),
			'css'        => __( 'CSS', 'modern-minify' ),
			'fonts'      => __( 'Шрифты', 'modern-minify' ),
			'advisor'    => __( 'Советник', 'modern-minify' ),
			'images'     => __( 'Изображения', 'modern-minify' ),
			'external'   => __( 'Внешние файлы', 'modern-minify' ),
			'settings'   => __( 'Настройки', 'modern-minify' ),
			'advanced'   => __( 'Дополнительно', 'modern-minify' ),
		);
	}

	/**
	 * Which settings belong to which tab.
	 *
	 * Every field of the configuration appears here exactly once; a fixture
	 * checks that, so a new setting cannot be added without a place to edit it.
	 *
	 * @return array<string,string[]>
	 */
	public static function tab_fields(): array {
		return array(
			'status'     => array(),
			'javascript' => array( 'minify_js', 'merge_js', 'async_js', 'defer_js', 'defer_external', 'defer_skip', 'exclude_js', 'remove_jquery_migrate', 'move_scripts_to_footer', 'delay_js', 'delay_js_exclude', 'delay_js_timeout', 'rocket_mode', 'rocket_exclude' ),
			'css'        => array( 'minify_css', 'merge_css', 'inline_css', 'inline_css_limit', 'inline_css_extra', 'async_css', 'remove_print_css', 'exclude_css' ),
			'fonts'      => array( 'fonts_method', 'fawesome_method', 'inline_fonts_css' ),
			'external'   => array(),
			'settings'   => array( 'minify_html', 'minify_inline', 'disable_when_logged_in', 'remove_generator', 'remove_emoji', 'ver_mode', 'disable_gravatar' ),
			'advisor'    => array(),
			'images'     => array( 'image_webp', 'image_webp_ondemand', 'webp_require_accept', 'image_quality_jpeg', 'image_png_lossless', 'image_quality_png', 'avatar_webp', 'avatar_quality' ),
			'advanced'   => array( 'cache_ttl', 'cache_max_files', 'cache_max_size', 'test_mode', 'debug', 'merge_max_files', 'merge_max_size' ),
		);
	}

	/**
	 * Label and description of every field.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function field_meta(): array {
		return array(
			'minify_js'              => array(
				__( 'Минифицировать JavaScript', 'modern-minify' ),
				__( 'Локальные файлы сжимаются в кеш, адрес в теге заменяется.', 'modern-minify' ),
			),
			'merge_js'               => array(
				__( 'Объединять JS-файлы', 'modern-minify' ),
				__( 'Только рядом стоящие локальные файлы без async/defer/module; при ошибке теги остаются как были. На HTTP/2 объединение JS чаще вредит: скрипты и так не блокируют отрисовку, а один большой файл откладывает выполнение. Включайте по замеру.', 'modern-minify' ),
			),
			'async_js'               => array(
				__( 'Шаблоны для async', 'modern-minify' ),
				__( 'По одному шаблону на строку. Символ * означает любое количество символов.', 'modern-minify' ),
			),
			'defer_js'               => array(
				__( 'Откладывать все скрипты (если хоть один исключён — не откладывается ничего)', 'modern-minify' ),
				__( 'Режим «всё или ничего»: если хотя бы один внешний скрипт остаётся обычным (исключение, async, module, внешний домен), defer не применяется ни к кому. Причина видна на «Статусе».', 'modern-minify' ),
			),
			'remove_jquery_migrate'  => array(
				__( 'Убирать jQuery Migrate', 'modern-minify' ),
				__( 'jQuery Migrate не нужен современным темам; включите, если появились предупреждения от старого кода. Админка не затрагивается.', 'modern-minify' ),
			),
			'defer_skip'             => array(
				__( 'Не откладывать', 'modern-minify' ),
				__( 'Эти файлы минифицируются, но остаются в потоке страницы.', 'modern-minify' ),
			),
			'exclude_js'             => array(
				__( 'Исключения JavaScript', 'modern-minify' ),
				__( 'Эти файлы не обрабатываются вовсе: ни минификации, ни async/defer.', 'modern-minify' ),
			),
			'move_scripts_to_footer' => array(
				__( 'Перенести скрипты в подвал', 'modern-minify' ),
				__( 'Локальные скрипты из head переезжают в конец body с сохранением порядка.', 'modern-minify' ),
			),
			'delay_js'               => array(
				__( 'Откладывать загрузку (Delay JS)', 'modern-minify' ),
				__( 'Файлы, чьи URL совпали с шаблонами, не загружаются и не исполняются до первого взаимодействия посетителя. Пустой список — ничего не откладывается.', 'modern-minify' ),
			),
			'delay_js_exclude'       => array(
				__( 'Не откладывать', 'modern-minify' ),
				__( 'Исключения для Delay JS. jQuery, wp-i18n и wp-hooks не откладываются никогда, даже если попали под шаблон.', 'modern-minify' ),
			),
			'delay_js_timeout'       => array(
				__( 'Страховочный таймаут, секунды', 'modern-minify' ),
				__( 'Если посетитель ничего не делает, отложенные скрипты загрузятся сами через столько секунд после готовности страницы.', 'modern-minify' ),
			),
			'rocket_mode'            => array(
				__( 'Rocket-режим: отложить весь JavaScript', 'modern-minify' ),
				__( 'Все скрипты, включая инлайн, выполняются после разбора страницы в исходном порядке. Если что-то сломалось — добавьте файл в исключения или выключите режим; для проверки используйте тестовый режим.', 'modern-minify' ),
			),
			'rocket_exclude'         => array(
				__( 'Исключения Rocket-режима', 'modern-minify' ),
				__( 'Пути файлов и id инлайн-скриптов через строку; поддерживается *. Скрипты с data-mmf-defer="false", module и сам загрузчик не трогаются никогда.', 'modern-minify' ),
			),
			'defer_external'         => array(
				__( 'defer для внешних скриптов', 'modern-minify' ),
				__( 'Внешний файл получает defer (но не async). Чужой код может зависеть от порядка загрузки — включайте осознанно и проверяйте страницу.', 'modern-minify' ),
			),
			'merge_max_files'        => array(
				__( 'Объединение: максимум файлов в пакете', 'modern-minify' ),
				__( 'Если рядом стоящих файлов больше, пакет не собирается и теги остаются как были.', 'modern-minify' ),
			),
			'merge_max_size'         => array(
				__( 'Объединение: максимум размера пакета, МиБ', 'modern-minify' ),
				__( 'Суммарный размер после минификации. Превышение — пакет не собирается.', 'modern-minify' ),
			),
			'minify_css'             => array(
				__( 'Минифицировать CSS', 'modern-minify' ),
				__( 'Локальные файлы сжимаются в кеш, адрес в теге заменяется.', 'modern-minify' ),
			),
			'merge_css'              => array(
				__( 'Объединять CSS-файлы', 'modern-minify' ),
				__( 'CSS блокирует первую отрисовку: файлы всё равно ждут друг друга в критической цепочке, поэтому объединение помогает и на HTTP/2. Только рядом стоящие локальные файлы с одинаковым media; print и speech не объединяются.', 'modern-minify' ),
			),
			'inline_css_extra'       => array(
				__( 'Встроить эти CSS-файлы', 'modern-minify' ),
				__( 'Шаблоны путей через строку: выбранный файл встраивается в страницу, даже если больше общего порога.', 'modern-minify' ),
			),
			'async_css'              => array(
				__( 'Загружать эти CSS асинхронно', 'modern-minify' ),
				__( 'Стили вставляются после DOMContentLoaded нашим инлайн-скриптом. Возможен FOUC; без nonce при CSP режим не применяется.', 'modern-minify' ),
			),
			'inline_css'             => array(
				__( 'Встраивать небольшие CSS', 'modern-minify' ),
				__( 'Файл меньше порога заменяется на style прямо в странице.', 'modern-minify' ),
			),
			'inline_css_limit'       => array(
				__( 'Порог встраивания, КиБ', 'modern-minify' ),
				__( '0 отключает встраивание, даже если переключатель включён.', 'modern-minify' ),
			),
			'remove_print_css'       => array(
				__( 'Удалять print-стили', 'modern-minify' ),
				__( 'Убирает ссылки со media="print".', 'modern-minify' ),
			),
			'exclude_css'            => array(
				__( 'Исключения CSS', 'modern-minify' ),
				__( 'Эти файлы не обрабатываются вовсе.', 'modern-minify' ),
			),
			'fonts_method'           => array(
				__( 'Google Fonts', 'modern-minify' ),
				__( 'Локальное размещение скачивает CSS и файлы шрифтов в кеш; при ошибке ссылка остаётся исходной.', 'modern-minify' ),
			),
			'fawesome_method'        => array(
				__( 'Font Awesome', 'modern-minify' ),
				__( 'Встроить CSS в страницу; адреса шрифтов остаются абсолютными.', 'modern-minify' ),
			),
			'inline_fonts_css'       => array(
				__( 'CSS шрифтов инлайном', 'modern-minify' ),
				__( 'Локализованный CSS Google Fonts печатается в <style>, а не отдельным файлом. Больше 50 КБ — остаётся файлом, чтобы не раздувать HTML.', 'modern-minify' ),
			),
			'minify_html'            => array(
				__( 'Минифицировать HTML', 'modern-minify' ),
				__( 'Убирает комментарии и пробелы между блоками; защищённые блоки не трогает.', 'modern-minify' ),
			),
			'minify_inline'          => array(
				__( 'Минифицировать инлайн-блоки', 'modern-minify' ),
				__( 'Содержимое script и style прямо в странице. Отключается отдельно: ломается чаще всего именно оно.', 'modern-minify' ),
			),
			'disable_when_logged_in' => array(
				__( 'Не оптимизировать авторизованных', 'modern-minify' ),
				__( 'Страницы пользователей, которые вошли на сайт, остаются исходными.', 'modern-minify' ),
			),
			'remove_generator'       => array(
				__( 'Убирать версию WordPress из head и фида', 'modern-minify' ),
				__( 'Удаляет meta name="generator" и generator-тег RSS: версия в исходном коде подсказывает, какие уязвимости применимы.', 'modern-minify' ),
			),
			'remove_emoji'           => array(
				__( 'Убирать emoji-скрипты', 'modern-minify' ),
				__( 'Снимает скрипт и стили emoji ядра на страницах сайта; админка не затрагивается.', 'modern-minify' ),
			),
			'ver_mode'               => array(
				__( 'Параметр ver в URL своих файлов', 'modern-minify' ),
				__( 'Хеш не раскрывает версию и сбрасывает кеш при изменении файла; удаление экономит запрос, но после обновления браузер может держать старый файл до конца кеша. У внешних файлов параметр всегда удаляется.', 'modern-minify' ),
			),
			'cache_ttl'              => array(
				__( 'Срок жизни кеша, дней', 'modern-minify' ),
				__( '0 — не удалять по возрасту; пределы количества и размера ниже действуют. Файлы моложе часа не удаляются никогда.', 'modern-minify' ),
			),
			'cache_max_files'        => array(
				__( 'Предел файлов в кеше', 'modern-minify' ),
				__( 'Когда файлов больше, удаляются самые старые; файлы моложе часа не трогаются.', 'modern-minify' ),
			),
			'cache_max_size'         => array(
				__( 'Предел размера кеша, МБ', 'modern-minify' ),
				__( 'Когда кеш больше, удаляются самые старые файлы; файлы моложе часа не трогаются.', 'modern-minify' ),
			),
			'test_mode'              => array(
				__( 'Тестовый режим', 'modern-minify' ),
				__( 'Оптимизация только для администраторов: посетители видят исходную страницу.', 'modern-minify' ),
			),
			'debug'                  => array(
				__( 'Режим отладки', 'modern-minify' ),
				__( 'Подробности на этом экране; записей в лог нет.', 'modern-minify' ),
			),
			'external_mode'          => array(
				__( 'Внешние файлы', 'modern-minify' ),
				__( 'Локально — скачать в кеш и отдавать со своего домена; не трогать; удалить теги (по умолчанию выключено, потому что ломает функциональность).', 'modern-minify' ),
			),
			'disable_gravatar'       => array(
				__( 'Не загружать граватары (локальная заглушка)', 'modern-minify' ),
				__( 'Граватары заменяются локальной заглушкой; аватары, заданные другим плагином (загруженный файл, uploads, data URI, свой домен), не затрагиваются. Админка и письма не меняются.', 'modern-minify' ),
			),
			'avatar_webp'            => array(
				__( 'Отдавать локальные аватары в WebP', 'modern-minify' ),
				__( 'Только загруженные JPEG и PNG на своём домене и только когда браузер принимает WebP; исходный файл не меняется, копия живёт в кеше. GIF и SVG не трогаются.', 'modern-minify' ),
			),
			'avatar_quality'         => array(
				__( 'Качество WebP для аватаров', 'modern-minify' ),
				__( 'От 60 до 95; меньше — легче файл. Действует на новые копии.', 'modern-minify' ),
			),
			'image_webp'             => array(
				__( 'Создавать WebP для загруженных картинок', 'modern-minify' ),
				__( 'Рядом с JPEG/PNG появляется файл .webp; оригинал не меняется и не удаляется. Отдаётся только браузерам с Accept: image/webp.', 'modern-minify' ),
			),
			'webp_require_accept'    => array(
				__( 'Отдавать WebP только с Accept: image/webp', 'modern-minify' ),
				__( 'Выключено: копия отдаётся всем, если она существует. Включайте только если HTML не кешируется снаружи: при кеше первый запрос без WebP закрепит обычные картинки для всех.', 'modern-minify' ),
			),
			'image_webp_ondemand'    => array(
				__( 'Конвертировать при первом просмотре (работает, когда страницу отдаёт PHP)', 'modern-minify' ),
				__( 'Ставит файл в очередь после ответа (1–2 за запрос) и добавляет cron-догон раз в 5 минут. На сайте с полным кешем страниц PHP исполняется редко — надёжнее массовая конвертация. Выключено по умолчанию.', 'modern-minify' ),
			),
			'image_quality_jpeg'     => array(
				__( 'Качество WebP для JPEG', 'modern-minify' ),
				__( '60–95, по умолчанию 82. Действует на новые файлы.', 'modern-minify' ),
			),
			'image_quality_png'      => array(
				__( 'Качество WebP для PNG (без lossless)', 'modern-minify' ),
				__( '60–95, по умолчанию 82; не используется, пока включён lossless.', 'modern-minify' ),
			),
			'image_png_lossless'     => array(
				__( 'PNG в WebP без потерь', 'modern-minify' ),
				__( 'По умолчанию включено: прозрачность и пиксели сохраняются, файл может быть больше JPEG-варианта.', 'modern-minify' ),
			),
			'external_max_size'      => array(
				__( 'Предел размера внешнего файла, МиБ', 'modern-minify' ),
				__( 'Файл больше предела не скачивается: тег остаётся исходным.', 'modern-minify' ),
			),
			'external_exclude'       => array(
				__( 'Оставить внешними', 'modern-minify' ),
				__( 'Дополнительные хосты или части URL через строку. Встроенный список API-скриптов показан ниже.', 'modern-minify' ),
			),
		);
	}

	/**
	 * Human labels of the choice values.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function choice_labels(): array {
		return array(
			'fonts_method'    => array(
				'off'   => __( 'Не обрабатывать', 'modern-minify' ),
				'swap'  => __( 'Добавить display=swap', 'modern-minify' ),
				'local' => __( 'Скачать и отдать локально', 'modern-minify' ),
			),
			'fawesome_method' => array(
				'off'    => __( 'Оставить как есть', 'modern-minify' ),
				'inline' => __( 'Встроить CSS в страницу', 'modern-minify' ),
			),
			'ver_mode'        => array(
				'hash'   => __( 'Заменить на хеш содержимого', 'modern-minify' ),
				'remove' => __( 'Удалить параметр', 'modern-minify' ),
				'keep'   => __( 'Не трогать', 'modern-minify' ),
			),
			'external_mode'   => array(
				'local'  => __( 'Скачать и отдавать со своего домена', 'modern-minify' ),
				'keep'   => __( 'Не трогать', 'modern-minify' ),
				'remove' => __( 'Удалить теги (ломает функциональность)', 'modern-minify' ),
			),
		);
	}

	/**
	 * Current tab slug, validated against the tab list.
	 */
	private static function current_tab(): string {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		$tabs = self::tabs();

		return isset( $tabs[ $tab ] ) ? $tab : 'status';
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $slug Tab slug.
	 */
	private static function tab_url( string $slug ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'tab'  => $slug,
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Prints one field.
	 *
	 * @param string $key Setting name.
	 */
	private static function render_field( string $key ): void {
		$fields = MMF_Config::fields();
		$meta   = self::field_meta();

		if ( ! isset( $fields[ $key ] ) ) {
			return;
		}

		$field = $fields[ $key ];
		$label = isset( $meta[ $key ][0] ) ? (string) $meta[ $key ][0] : $key;
		$help  = isset( $meta[ $key ][1] ) ? (string) $meta[ $key ][1] : '';
		$id    = 'mmf-' . $key;
		$name  = 'mmf_settings[' . $key . ']';
		$value = MMF_Config::get( $key );
		$type  = (string) ( $field['type'] ?? 'bool' );
		$unsupported = in_array( $key, array( 'avatar_webp', 'avatar_quality' ), true ) && ! MMF_Avatars::supports_webp();

		echo '<tr class="' . ( 'bool' === $type ? 'mmf-row--bool' : 'mmf-row' ) . '"><th scope="row">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label );
		echo self::recommended_badge( $key, $value );
		echo '</label>';
		echo '</th><td>';

		if ( 'bool' === $type ) {
			// A hidden zero makes "unchecked" post a value: otherwise the key is
			// absent from the request and the sanitiser would restore the default.
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';

			// The checkbox and its description share one line; clicking the text
			// toggles the box because both are inside the label.
			echo '<label class="mmf-bool"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . ( $value ? ' checked="checked"' : '' ) . ( $unsupported ? ' disabled="disabled"' : '' ) . ' />';

			if ( '' !== $help ) {
				echo '<span class="mmf-bool__text description">' . esc_html( $help ) . '</span>';
			}

			echo '</label>';
		} elseif ( 'list' === $type ) {
			$lines = is_array( $value ) ? implode( "\n", array_map( 'strval', $value ) ) : '';
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="5" class="large-text code">' . esc_textarea( $lines ) . '</textarea>';
		} elseif ( 'int' === $type ) {
			printf(
				'<input type="number" id="%s" name="%s" value="%d" min="%d" max="%d" class="small-text"%s />',
				esc_attr( $id ),
				esc_attr( $name ),
				(int) $value,
				(int) $field['min'],
				(int) $field['max'],
				$unsupported ? ' disabled="disabled"' : ''
			);
		} else {
			$labels = self::choice_labels();

			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';

			foreach ( (array) $field['choices'] as $choice ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( (string) $choice ),
					(string) $choice === (string) $value ? ' selected="selected"' : '',
					esc_html( (string) ( $labels[ $key ][ $choice ] ?? $choice ) )
				);
			}

			echo '</select>';
		}

		if ( $unsupported ) {
			echo '<p class="description">' . esc_html__( 'На этом сервере нет WebP (GD или Imagick), поэтому настройка недоступна и ничего не меняет.', 'modern-minify' ) . '</p>';
		}

		echo self::recommended_warning( $key, $value );

		if ( '' !== $help && 'bool' !== $type ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}

		$hints = array(
			'merge_js'  => __( 'JS на HTTP/2 обычно не объединяем: один большой файл задерживает исполнение', 'modern-minify' ),
		);

		if ( isset( $hints[ $key ] ) ) {
			echo '<p class="description mmf-field-hint">' . esc_html( $hints[ $key ] ) . '</p>';
		}

		self::render_examples( $key );

		echo '</td></tr>';
	}

	/**
	 * Prints the status panel: state, enabled features, four grouped counters,
	 * the disk cache and the reset link.
	 */
	private static function render_status(): void {
		$stats = MMF_Stats::all();
		$cache = MMF_Cache::stats();

		echo '<div class="mmf-status-head"><h2>' . esc_html__( 'Состояние', 'modern-minify' ) . '</h2>';
		echo '<span class="mmf-status mmf-status--on"><span class="mmf-status__dot" aria-hidden="true"></span>' . esc_html__( 'плагин активен', 'modern-minify' ) . '</span></div>';

		self::render_last_action();
		self::render_features();

		echo '<div class="mmf-status-columns">';

		self::status_group(
			__( 'Страницы и файлы', 'modern-minify' ),
			array(
				__( 'Страниц обработано', 'modern-minify' )    => (string) (int) $stats['pages'],
				__( 'Файлов минифицировано', 'modern-minify' ) => (string) (int) $stats['files'],
				__( 'Инлайн-блоков сжато', 'modern-minify' )   => (string) (int) $stats['inline'],
				__( 'Ошибок обработки', 'modern-minify' )       => (string) (int) $stats['failed'],
			)
		);

		self::status_group(
			__( 'Отложенная загрузка', 'modern-minify' ),
			array(
				__( 'Скриптов defer', 'modern-minify' )        => (string) (int) $stats['defer'],
				__( 'defer на странице', 'modern-minify' )      => ! empty( $stats['last_defer']['allowed'] )
					? __( 'применён', 'modern-minify' )
					: (string) ( $stats['last_defer']['reason'] ?? __( 'не применён', 'modern-minify' ) ),
				__( 'Скриптов async', 'modern-minify' )        => (string) (int) $stats['async'],
				__( 'Перенесено в подвал', 'modern-minify' )   => (string) (int) $stats['moved'],
				__( 'Delay JS, всего', 'modern-minify' )       => (string) (int) $stats['delayed'],
				__( 'Delay JS на последней странице', 'modern-minify' ) => (string) (int) $stats['last_delayed'],
				__( 'Rocket-режимом', 'modern-minify' )        => (string) (int) $stats['rocket'],
			)
		);

		self::status_group(
			__( 'Пакеты', 'modern-minify' ),
			array(
				__( 'Пакетов собрано', 'modern-minify' )       => (string) (int) $stats['bundles'],
				__( 'CSS-файлов: было → стало', 'modern-minify' ) => ( 0 === (int) $stats['last_css_before'] && 0 === (int) $stats['last_css_after'] ) ? '—' : (int) $stats['last_css_before'] . ' → ' . (int) $stats['last_css_after'],
				__( 'Файлов в пакетах', 'modern-minify' )      => (string) (int) $stats['bundled_files'],
				__( 'Инлайн-стилей в пакетах', 'modern-minify' ) => (string) (int) $stats['inline_merged'],
				__( 'Запросов сэкономлено', 'modern-minify' )  => (string) max( 0, (int) $stats['bundled_files'] - (int) $stats['bundles'] ),
				__( 'Размер пакетов, КБ', 'modern-minify' )     => (string) (int) round( (int) $stats['bundle_bytes'] / 1024 ),
			)
		);

		$reasons = isset( $stats['merge_reasons'] ) && is_array( $stats['merge_reasons'] ) ? $stats['merge_reasons'] : array();

		if ( array() !== $reasons ) {
			$labels = array(
				'inline-id'     => __( 'инлайн-стиль с id', 'modern-minify' ),
				'inline-import' => __( 'инлайн-стиль с @import', 'modern-minify' ),
				'size'          => __( 'превышен размер пакета', 'modern-minify' ),
			);
			$parts  = array();

			foreach ( $reasons as $reason ) {
				$parts[] = (string) ( $labels[ $reason ] ?? $reason );
			}

			echo '<p class="description">' . esc_html( sprintf( __( 'Не объединено: %s.', 'modern-minify' ), implode( ', ', $parts ) ) ) . '</p>';
		}

		self::status_group(
			__( 'Внешние файлы', 'modern-minify' ),
			array(
				__( 'Локализовано', 'modern-minify' )             => (string) (int) $stats['external_localized'],
				__( 'Оставлено внешними', 'modern-minify' )       => (string) (int) $stats['external_kept'],
				__( 'Не удалось скачать', 'modern-minify' )       => (string) (int) $stats['external_failed'],
				__( 'Тегов удалено', 'modern-minify' )            => (string) (int) $stats['external_removed'],
				__( 'Предупреждений о шрифтах', 'modern-minify' ) => (string) (int) $stats['font_warnings'],
				__( 'CSS шрифтов отдано файлом', 'modern-minify' ) => (string) (int) $stats['fonts_files'],
				__( 'Аватаров заменено', 'modern-minify' )        => (string) (int) $stats['gravatar'],
				__( 'Аватаров в WebP', 'modern-minify' )           => (string) (int) $stats['avatar_webp'],
				__( 'Экономия WebP, КБ', 'modern-minify' )         => (string) (int) round( (int) $stats['avatar_saved'] / 1024 ),
				__( 'Ошибок WebP', 'modern-minify' )               => (string) (int) $stats['avatar_errors'],
				__( 'WebP на сервере', 'modern-minify' )           => MMF_Avatars::supports_webp() ? __( 'доступен', 'modern-minify' ) : __( 'недоступен', 'modern-minify' ),
			)
		);

		$methods = array(
			'imagick' => 'Imagick',
			'gd'      => 'GD',
			'cwebp'   => 'cwebp',
		);

		$max_files = (int) MMF_Config::get( 'cache_max_files', 1000 );
		$max_size  = (int) MMF_Config::get( 'cache_max_size', 100 ) * 1024 * 1024;
		$ttl_days  = (int) MMF_Config::get( 'cache_ttl', 7 );
		$cleaned   = (int) get_option( MMF_Cache::LAST_CLEANUP_OPTION, 0 );

		$cache_warn = array();

		if ( (int) $cache['files'] > $max_files ) {
			$cache_warn[] = __( 'Файлов в кеше', 'modern-minify' );
		}

		if ( (int) $cache['size'] > $max_size ) {
			$cache_warn[] = __( 'Размер кеша', 'modern-minify' );
		}

		self::status_group(
			__( 'Кеш и ошибки', 'modern-minify' ),
			array(
				__( 'Файлов в кеше', 'modern-minify' )      => (int) $cache['files'] . ' / ' . $max_files,
				__( 'Размер кеша', 'modern-minify' )        => size_format( (int) $cache['size'] ) . ' / ' . size_format( $max_size ),
				__( 'Самый старый файл', 'modern-minify' )  => $cache['oldest'] > 0 ? (string) wp_date( 'Y-m-d H:i:s', (int) $cache['oldest'] ) : __( '—', 'modern-minify' ),
				__( 'TTL, дней', 'modern-minify' )          => $ttl_days > 0 ? (string) $ttl_days : __( 'без ограничения', 'modern-minify' ),
				__( 'Последняя очистка', 'modern-minify' )  => $cleaned > 0 ? (string) wp_date( 'Y-m-d H:i:s', $cleaned ) : __( 'ещё не было', 'modern-minify' ),
				__( 'Кеш обновлён', 'modern-minify' )       => $cache['updated'] > 0 ? (string) wp_date( 'Y-m-d H:i:s', (int) $cache['updated'] ) : __( 'ещё не было', 'modern-minify' ),
				__( 'Каталог', 'modern-minify' )            => (string) $cache['dir'],
				__( 'Доступен для записи', 'modern-minify' ) => $cache['writable'] ? __( 'да', 'modern-minify' ) : __( 'нет', 'modern-minify' ),
			),
			$cache_warn
		);

		echo '</div>';


		printf(
			'<p class="description">%s %s</p>',
			esc_html__( 'Счётчики накопительные и записываются не чаще раза в минуту.', 'modern-minify' ),
			esc_html__( 'Кеш очищается только вручную: файлы с прежними именами остаются, чтобы страницы из кеша не запрашивали удалённые файлы.', 'modern-minify' )
		);

		$reset_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => self::PAGE,
					'action' => 'mmf_reset_cache',
				),
				admin_url( 'options-general.php' )
			),
			self::RESET_NONCE
		);

		echo '<p class="mmf-actions"><a class="button mmf-reset" href="' . esc_url( $reset_url ) . '">' . esc_html__( 'Сбросить кеш', 'modern-minify' ) . '</a></p>';

		self::render_recommended();
	}

	/**
	 * Prints the recommended-settings block.
	 */
	private static function render_recommended(): void {
		$labels = self::recommended_labels();

		$recommended_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => self::PAGE,
					'action' => 'mmf_recommended',
				),
				admin_url( 'options-general.php' )
			),
			self::RECOMMENDED_NONCE
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Безопасный набор для обычного сайта: включает минификацию, локальные шрифты и граватары, выключает рискованные режимы.', 'modern-minify' )
		);

		echo '<p class="mmf-actions">';
		echo '<a class="button button-secondary mmf-recommended" href="' . esc_url( $recommended_url ) . '" aria-describedby="mmf-recommended-popover">' . esc_html__( 'Применить рекомендуемые настройки', 'modern-minify' ) . '</a>';
		echo '<button type="button" class="mmf-help" aria-expanded="false" aria-describedby="mmf-recommended-popover" aria-label="' . esc_attr__( 'Что изменится', 'modern-minify' ) . '"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span></button>';
		echo '</p>';

		echo '<div class="mmf-popover" id="mmf-recommended-popover" role="tooltip" hidden>';
		echo '<h4>' . esc_html__( 'Что изменится', 'modern-minify' ) . '</h4><ul>';

		foreach ( $labels as $label ) {
			echo '<li>' . esc_html( $label ) . '</li>';
		}

		echo '</ul>';
		echo '<p class="mmf-popover__note">' . esc_html__( 'Не изменяются: исключения, шаблоны async/defer, лимиты, TTL, тестовый режим.', 'modern-minify' ) . '</p>';
		echo '<p class="mmf-popover__actions"><a class="button button-primary" href="' . esc_url( $recommended_url ) . '">' . esc_html__( 'Применить', 'modern-minify' ) . '</a> <button type="button" class="button mmf-popover__cancel">' . esc_html__( 'Отмена', 'modern-minify' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * Explains what is written to disk and what is not.
	 */
	private static function render_html_note(): void {
		echo '<p class="description mmf-html-note">' . esc_html__( 'HTML сжимается на лету, файлы не создаются. CSS и JS копируются в uploads/mmf/ один раз на каждую версию файла — иначе их пришлось бы сжимать на каждом запросе. Копии удаляются по TTL и при удалении плагина.', 'modern-minify' ) . '</p>';
	}

	/**
	 * Short human label of a recommended value, for the badge.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value Recommended value.
	 */
	private static function recommended_value_label( string $key, $value ): string {
		$map = array(
			'fonts_method'    => array(
				'local' => __( 'локально', 'modern-minify' ),
				'swap'  => 'display=swap',
				'off'   => __( 'не трогать', 'modern-minify' ),
			),
			'fawesome_method' => array(
				'inline' => __( 'инлайном', 'modern-minify' ),
				'off'    => __( 'не трогать', 'modern-minify' ),
			),
			'ver_mode'        => array(
				'hash'   => __( 'хеш', 'modern-minify' ),
				'remove' => __( 'удалить', 'modern-minify' ),
				'keep'   => __( 'не трогать', 'modern-minify' ),
			),
			'external_mode'   => array(
				'local'  => __( 'локально', 'modern-minify' ),
				'keep'   => __( 'не трогать', 'modern-minify' ),
				'remove' => __( 'удалить', 'modern-minify' ),
			),
		);

		if ( is_bool( $value ) ) {
			return $value ? __( 'включить', 'modern-minify' ) : __( 'выключить', 'modern-minify' );
		}

		if ( is_array( $value ) ) {
			return array() === $value ? __( 'выключить', 'modern-minify' ) : __( 'включить', 'modern-minify' );
		}

		$value = is_scalar( $value ) ? (string) $value : '';

		return isset( $map[ $key ][ $value ] ) ? (string) $map[ $key ][ $value ] : $value;
	}

	/**
	 * Returns the badge for a field whose recommended value is "on" and is off.
	 *
	 * Only that one direction gets a badge; every other deviation is a warning
	 * under the field, and a matching value shows nothing at all. This keeps the
	 * two mechanisms from overlapping.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value Current value.
	 */
	private static function recommended_badge( string $key, $value ): string {
		$profile = self::recommended_profile();

		if ( ! array_key_exists( $key, $profile ) || ! is_bool( $profile[ $key ] ) || true !== $profile[ $key ] ) {
			return '';
		}

		if ( (bool) $value === true ) {
			return '';
		}

		return '<span class="mmf-badge mmf-badge--recommended">' . esc_html__( 'Рекомендуется включить', 'modern-minify' ) . '</span>';
	}

	/**
	 * Returns the warning for any other deviation from the profile.
	 *
	 * A recommendation to turn something off always carries the reason; an enum
	 * choice names the recommended value.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value Current value.
	 */
	private static function recommended_warning( string $key, $value ): string {
		$profile = self::recommended_profile();

		if ( ! array_key_exists( $key, $profile ) ) {
			return '';
		}

		$recommended = $profile[ $key ];

		if ( is_array( $recommended ) ) {
			if ( (array) $value === $recommended ) {
				return '';
			}

			$text = __( 'Рекомендуем выключить', 'modern-minify' ) . ': ' . self::recommended_reason( $key );
		} elseif ( is_bool( $recommended ) ) {
			if ( (bool) $value === $recommended ) {
				return '';
			}

			if ( $recommended ) {
				return '';
			}

			$text = __( 'Рекомендуем выключить', 'modern-minify' ) . ': ' . self::recommended_reason( $key );
		} else {
			if ( (string) $value === (string) $recommended ) {
				return '';
			}

			/* translators: %s: recommended value. */
			$text = sprintf( __( 'Рекомендуем: %s', 'modern-minify' ), self::recommended_value_label( $key, $recommended ) );

			$reason = self::recommended_reason( $key, false );

			if ( '' !== $reason ) {
				$text .= ' — ' . $reason;
			}
		}

		return '<p class="mmf-recommend-warning" role="status"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html( $text ) . '</p>';
	}

	/**
	 * Why a field is recommended the way it is.
	 *
	 * @param string $key      Setting name.
	 * @param bool   $fallback Whether to return the generic fallback.
	 */
	private static function recommended_reason( string $key, bool $fallback = true ): string {
		$reasons = array(
			'merge_js'      => __( 'объединение на HTTP/2 чаще вредит', 'modern-minify' ),
			'merge_css'     => __( 'объединение на HTTP/2 чаще вредит', 'modern-minify' ),
			'delay_js'      => __( 'отложенная загрузка ломает порядок скриптов', 'modern-minify' ),
			'rocket_mode'   => __( 'отложенная загрузка ломает порядок скриптов', 'modern-minify' ),
			'defer_external' => __( 'чужой код может зависеть от порядка загрузки', 'modern-minify' ),
			'ver_mode'      => __( 'с версией в URL видно, какой у сайта WordPress', 'modern-minify' ),
		);

		if ( isset( $reasons[ $key ] ) ) {
			return (string) $reasons[ $key ];
		}

		return $fallback ? __( 'значение выбрано как безопасное для обычного сайта', 'modern-minify' ) : '';
	}

	/**
	 * One card of the status grid.
	 *
	 * @param string               $title Card title.
	 * @param array<string,string> $rows  Label to value.
	 */
	private static function status_group( string $title, array $rows, array $warn = array() ): void {
		echo '<section class="mmf-card mmf-status-card"><h3 class="mmf-section-title">' . esc_html( $title ) . '</h3><dl class="mmf-metrics">';

		foreach ( $rows as $label => $value ) {
			$class = in_array( (string) $label, $warn, true ) ? 'mmf-metric__value mmf-metric__value--warn' : 'mmf-metric__value';

			echo '<div class="mmf-metric"><dt class="mmf-metric__label">' . esc_html( (string) $label ) . '</dt><dd class="' . esc_attr( $class ) . '">' . esc_html( (string) $value ) . '</dd></div>';
		}

		echo '</dl></section>';
	}

	/**
	 * The last action's result, so it survives the toast.
	 */
	private static function render_last_action(): void {
		$last = MMF_Stats::last_action();

		echo '<section class="mmf-card mmf-last-action"><h3 class="mmf-section-title">' . esc_html__( 'Последнее действие', 'modern-minify' ) . '</h3>';

		if ( '' === $last['message'] ) {
			echo '<p class="description">' . esc_html__( 'Пока ничего не менялось.', 'modern-minify' ) . '</p>';
		} else {
			echo '<p class="mmf-last-action__text">' . esc_html( $last['message'] ) . '</p>';

			if ( $last['time'] > 0 ) {
				echo '<p class="description">' . esc_html( (string) wp_date( 'Y-m-d H:i:s', $last['time'] ) ) . '</p>';
			}
		}

		echo '</section>';
	}

	/**
	 * Lists what the plugin is currently doing, instead of a master switch.
	 */
	private static function render_features(): void {
		$groups = array(
			__( 'Минификация', 'modern-minify' ) => array(
				array( 'dashicons-editor-code', __( 'HTML', 'modern-minify' ), (bool) MMF_Config::get( 'minify_html' ) ),
				array( 'dashicons-editor-code', __( 'CSS', 'modern-minify' ), (bool) MMF_Config::get( 'minify_css' ) ),
				array( 'dashicons-editor-code', __( 'JavaScript', 'modern-minify' ), (bool) MMF_Config::get( 'minify_js' ) ),
				array( 'dashicons-editor-code', __( 'Инлайн-блоки', 'modern-minify' ), (bool) MMF_Config::get( 'minify_inline' ) ),
				array( 'dashicons-screenoptions', __( 'Пакеты CSS', 'modern-minify' ), (bool) MMF_Config::get( 'merge_css' ) ),
				array( 'dashicons-screenoptions', __( 'Пакеты JS', 'modern-minify' ), (bool) MMF_Config::get( 'merge_js' ) ),
				array( 'dashicons-editor-insertmore', __( 'Встраивание CSS', 'modern-minify' ), (bool) MMF_Config::get( 'inline_css' ) ),
				array( 'dashicons-printer', __( 'Print-стили', 'modern-minify' ), (bool) MMF_Config::get( 'remove_print_css' ) ),
			),
			__( 'Внешние файлы', 'modern-minify' ) => array(
				array( 'dashicons-admin-site-alt3', __( 'Локализация CDN', 'modern-minify' ), MMF_External::MODE_LOCAL === MMF_External::mode( MMF_Config::all() ) ),
				array( 'dashicons-download', __( 'Google Fonts локально', 'modern-minify' ), 'local' === strtolower( (string) MMF_Config::get( 'fonts_method', 'off' ) ) ),
				array( 'dashicons-editor-code', __( 'CSS шрифтов инлайном', 'modern-minify' ), (bool) MMF_Config::get( 'inline_fonts_css' ) ),
				array( 'dashicons-admin-appearance', __( 'Font Awesome инлайном', 'modern-minify' ), 'inline' === strtolower( (string) MMF_Config::get( 'fawesome_method', 'off' ) ) ),
				array( 'dashicons-clock', __( 'defer внешних', 'modern-minify' ), (bool) MMF_Config::get( 'defer_external' ) ),
				array( 'dashicons-buddicons-community', __( 'Граватары локально', 'modern-minify' ), (bool) MMF_Config::get( 'disable_gravatar' ) ),
				array( 'dashicons-format-image', __( 'WebP картинок', 'modern-minify' ), MMF_Images::enabled() ),
			),
			__( 'Скрытие версий', 'modern-minify' ) => array(
				array( 'dashicons-shield', __( 'Версия в URL', 'modern-minify' ), 'keep' !== (string) MMF_Config::get( 'ver_mode', 'hash' ) ),
				array( 'dashicons-hidden', __( 'Generator', 'modern-minify' ), (bool) MMF_Config::get( 'remove_generator' ) ),
				array( 'dashicons-smiley', __( 'Emoji-скрипты', 'modern-minify' ), (bool) MMF_Config::get( 'remove_emoji' ) ),
			),
			__( 'Прочее', 'modern-minify' ) => array(
				array( 'dashicons-download', __( 'Скрипты в подвале', 'modern-minify' ), (bool) MMF_Config::get( 'move_scripts_to_footer' ) ),
				array( 'dashicons-clock', __( 'Delay JS', 'modern-minify' ), array() !== (array) MMF_Config::get( 'delay_js', array() ) ),
				array( 'dashicons-performance', __( 'Rocket-режим', 'modern-minify' ), (bool) MMF_Config::get( 'rocket_mode' ) ),
				array( 'dashicons-visibility', __( 'Тестовый режим', 'modern-minify' ), (bool) MMF_Config::get( 'test_mode' ) ),
			),
		);

		echo '<div class="mmf-chips">';

		foreach ( $groups as $title => $chips ) {
			echo '<section class="mmf-card mmf-chip-group"><h3 class="mmf-section-title">' . esc_html( $title ) . '</h3><ul class="mmf-chips-list">';

			foreach ( $chips as $chip ) {
				$on = (bool) $chip[2];

				printf(
					'<li class="mmf-chip %s"><span class="dashicons %s mmf-chip__icon" aria-hidden="true"></span><span class="mmf-chip__dot" aria-hidden="true"></span><span class="mmf-chip__label">%s</span><span class="screen-reader-text">%s</span></li>',
					$on ? 'mmf-chip--on' : 'mmf-chip--off',
					esc_attr( (string) $chip[0] ),
					esc_html( (string) $chip[1] ),
					$on ? esc_html__( 'включено', 'modern-minify' ) : esc_html__( 'выключено', 'modern-minify' )
				);
			}

			echo '</ul></section>';
		}

		echo '</div>';
	}

	/**
	 * Prints the scripts of the last counted page with their modes.
	 *
	 * This is the diagnostic that turns "jQuery is not defined" into "this file
	 * stayed normal while the rest was deferred".
	 *
	 * @param array<string,mixed> $stats Counters.
	 */
	private static function render_script_report( array $stats ): void {
		$scripts = isset( $stats['last_scripts'] ) && is_array( $stats['last_scripts'] ) ? $stats['last_scripts'] : array();
		$defer   = isset( $stats['last_defer'] ) && is_array( $stats['last_defer'] ) ? $stats['last_defer'] : array();

		$labels = array(
			'normal'  => __( 'обычный', 'modern-minify' ),
			'defer'   => 'defer',
			'async'   => 'async',
			'delayed' => __( 'отложен', 'modern-minify' ),
			'inline'  => __( 'инлайн', 'modern-minify' ),
			'rocket'  => 'Rocket',
		);

		echo '<section class="mmf-card mmf-report"><h2 class="mmf-section-title">' . esc_html__( 'Скрипты последней учтённой страницы', 'modern-minify' ) . '</h2>';

		if ( array() === $scripts ) {
			echo '<p class="description">' . esc_html__( 'Данных пока нет: страница ещё не обрабатывалась.', 'modern-minify' ) . '</p>';
			echo '</section>';

			return;
		}

		$has_normal = false;

		foreach ( $scripts as $script ) {
			if ( 'normal' === ( $script['mode'] ?? '' ) ) {
				$has_normal = true;
			}
		}

		if ( ! empty( $defer['allowed'] ) && $has_normal ) {
			echo '<p class="mmf-alert mmf-alert--error" role="alert">' . esc_html__( 'defer применён, но в списке есть скрипты в обычном режиме — это и есть причина ошибок порядка в консоли.', 'modern-minify' ) . '</p>';
		}

		echo '<table class="widefat striped mmf-report-table mmf-scripts-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Скрипт', 'modern-minify' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Режим', 'modern-minify' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Позиция', 'modern-minify' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $scripts as $script ) {
			$name = isset( $script['name'] ) && '' !== (string) $script['name'] ? (string) $script['name'] : __( 'инлайн', 'modern-minify' );
			$mode = isset( $script['mode'] ) ? (string) $script['mode'] : 'normal';

			echo '<tr><td><code>' . esc_html( $name ) . '</code></td>';
			echo '<td><span class="mmf-badge mmf-badge--status">' . esc_html( $labels[ $mode ] ?? $mode ) . '</span></td>';
			echo '<td>' . esc_html( (string) (int) ( $script['position'] ?? 0 ) ) . '</td></tr>';
		}

		echo '</tbody></table></section>';
	}

	/**
	 * Prints the external hosts of the last counted page.
	 *
	 * @param array<string,mixed> $stats Counters.
	 */
	private static function render_external_report( array $stats ): void {
		$report = isset( $stats['last_external'] ) && is_array( $stats['last_external'] ) ? $stats['last_external'] : array();

		echo '<section class="mmf-card mmf-report"><h2 class="mmf-section-title">' . esc_html__( 'Внешние хосты на последней учтённой странице', 'modern-minify' ) . '</h2>';

		if ( array() === $report ) {
			echo '<p class="description">' . esc_html__( 'Внешних файлов не найдено.', 'modern-minify' ) . '</p>';
			echo '</section>';

			return;
		}

		$labels = array(
			'localized' => __( 'локализован', 'modern-minify' ),
			'excluded'  => __( 'оставлен внешним (исключение)', 'modern-minify' ),
			'kept'      => __( 'оставлен внешним (режим)', 'modern-minify' ),
			'failed'    => __( 'не удалось скачать', 'modern-minify' ),
			'removed'   => __( 'тег удалён', 'modern-minify' ),
		);

		echo '<table class="widefat striped mmf-report-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Хост', 'modern-minify' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Что сделано', 'modern-minify' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $report as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['host'], $entry['status'] ) ) {
				continue;
			}

			$host   = (string) $entry['host'];
			$status = (string) $entry['status'];
			$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;

			echo '<tr><td><code>' . esc_html( $host ) . '</code></td><td><span class="mmf-badge mmf-badge--status">' . esc_html( $label ) . '</span></td></tr>';
		}

		echo '</tbody></table></section>';
	}

	/**
	 * The whole external-files tab: mode, both exclusion lists, the size limit
	 * and the hosts of the last counted page.
	 */
	private static function render_external_tab(): void {
		echo '<table class="form-table" role="presentation"><tbody>';
		self::render_field( 'external_mode' );
		echo '</tbody></table>';

		self::render_builtin_exclusions();
		self::render_user_exclusions();

		echo '<table class="form-table" role="presentation"><tbody>';
		self::render_field( 'external_max_size' );
		echo '</tbody></table>';

		self::render_external_report( MMF_Stats::all() );
	}

	/**
	 * Built-in hosts: a table with the reason and a disabled control.
	 */
	private static function render_builtin_exclusions(): void {
		echo '<section class="mmf-card"><h2 class="mmf-section-title">' . esc_html__( 'Встроенные исключения', 'modern-minify' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Эти хосты не локализуются: их скрипты сверяют домен в Referer или Origin, и локальная копия ломает виджет. Список не редактируется.', 'modern-minify' ) . '</p>';

		echo '<table class="widefat striped mmf-status-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Хост', 'modern-minify' ) . '</th>';
		echo '<th>' . esc_html__( 'Причина', 'modern-minify' ) . '</th>';
		echo '<th>' . esc_html__( 'Оставить внешним', 'modern-minify' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( MMF_External::BUILTIN as $host => $reason_key ) {
			$host = (string) $host;

			echo '<tr><td><code>' . esc_html( $host ) . '</code><br /><span class="description">' . esc_html__( 'домен и поддомены', 'modern-minify' ) . '</span></td>';
			echo '<td>' . esc_html( MMF_External::reason_label( (string) $reason_key ) ) . '</td>';
			echo '<td><label><input type="checkbox" checked="checked" disabled="disabled" /> <span class="description">' . esc_html__( 'не редактируется', 'modern-minify' ) . '</span></label></td></tr>';
		}

		echo '</tbody></table></section>';
	}

	/**
	 * User exclusions: an editable list with add and remove controls.
	 */
	private static function render_user_exclusions(): void {
		$current = MMF_Config::get( 'external_exclude', array() );
		$current = is_array( $current ) ? array_values( array_map( 'strval', $current ) ) : array();

		echo '<section class="mmf-card"><h2 class="mmf-section-title">' . esc_html__( 'Свои исключения', 'modern-minify' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Хост или часть URL на строку. Пустые строки при сохранении отбрасываются.', 'modern-minify' ) . '</p>';

		echo '<table class="widefat striped mmf-status-table mmf-user-excludes"><thead><tr>';
		echo '<th>' . esc_html__( 'Хост или часть URL', 'modern-minify' ) . '</th>';
		echo '<th>' . esc_html__( 'Действие', 'modern-minify' ) . '</th>';
		echo '</tr></thead><tbody id="mmf-exclude-rows">';

		foreach ( $current as $row ) {
			echo self::exclude_row_markup( $row );
		}

		echo self::exclude_row_markup( '' );
		echo '</tbody></table>';

		echo '<p><button type="button" class="button mmf-exclude-add">' . esc_html__( 'Добавить исключение', 'modern-minify' ) . '</button></p>';

		echo '<template id="mmf-exclude-template">' . self::exclude_row_markup( '', false ) . '</template>';
		echo '</section>';
	}

	/**
	 * One row of the user exclusion table.
	 *
	 * @param string $value Current value.
	 * @param bool   $named Whether the input carries the option name; the template
	 *                      row does not, so it is not submitted until cloned.
	 */
	private static function exclude_row_markup( string $value, bool $named = true ): string {
		$name = $named ? ' name="mmf_settings[external_exclude][]"' : '';

		return '<tr class="mmf-exclude-row"><td><input type="text" class="regular-text code"' . $name . ' value="' . esc_attr( $value ) . '" /></td>'
			. '<td><button type="button" class="button-link mmf-exclude-remove">' . esc_html__( 'Убрать', 'modern-minify' ) . '</button></td></tr>';
	}

	/**
	 * Prints the plugin's notices above the form.
	 */
	private static function render_alerts(): void {
		if ( MMF_Conflicts::is_active() ) {
			echo '<div class="mmf-alert mmf-alert--error" role="alert"><span class="dashicons dashicons-warning" aria-hidden="true"></span><p>' . esc_html( MMF_Notices::conflict_message() ) . '</p></div>';
		}

		if ( MMF_Config::get( 'test_mode' ) ) {
			echo '<div class="mmf-alert mmf-alert--info"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><p>' . esc_html__( 'Тестовый режим: оптимизация применяется только к администраторам, посетители видят исходную страницу.', 'modern-minify' ) . '</p></div>';
		}
	}

	/**
	 * Prints the one-time toast of the action that redirected here.
	 *
	 * The flag carries a nonce so it cannot be crafted, and the inline JS removes
	 * it from the URL after the toast appears, so F5 does not repeat the message.
	 */
	private static function render_toasts(): void {
		$type  = isset( $_GET['mmf-toast'] ) ? sanitize_key( wp_unslash( (string) $_GET['mmf-toast'] ) ) : '';
		$nonce = isset( $_GET['mmf-toast-nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mmf-toast-nonce'] ) ) : '';

		if ( '' === $type || ! wp_verify_nonce( $nonce, self::TOAST_NONCE ) ) {
			return;
		}

		$deleted = isset( $_GET['mmf-reset'] ) ? (int) sanitize_text_field( wp_unslash( (string) $_GET['mmf-reset'] ) ) : 0;

		$messages = array(
			'reset'       => sprintf( __( 'Кеш очищен и статистика сброшена (удалено файлов: %d).', 'modern-minify' ), $deleted ),
			'recommended' => sprintf( __( 'Рекомендуемые настройки применены, кеш сброшен (удалено файлов: %d).', 'modern-minify' ), $deleted ),
			'test-on'     => __( 'Тестовый режим включён.', 'modern-minify' ),
			'test-off'    => __( 'Тестовый режим выключен.', 'modern-minify' ),
			'recommended-noop' => __( 'Настройки уже соответствуют рекомендуемым.', 'modern-minify' ),
			'advice'      => __( 'Выбранные шаблоны добавлены в список, кеш сброшен.', 'modern-minify' ),
			'advice-blocked'     => __( 'Шаблоны не применены: они затрагивают тему, jQuery, ядро WordPress или накрывают несколько файлов. Подробности — в «Последнем действии».', 'modern-minify' ),
			'advice-rolled-back' => __( 'Настройки отменены: после применения страница теряет jQuery. Шаблон слишком широкий.', 'modern-minify' ),
			'webp-deleted' => __( 'Сгенерированные WebP удалены.', 'modern-minify' ),
			'css-action'  => __( 'Действие применено, кеш сброшен.', 'modern-minify' ),
			'css-action-blocked' => __( 'Асинхронная загрузка отклонена: это стиль темы или ядра — страница осталась бы без оформления.', 'modern-minify' ),
			'avatars'     => __( 'Аватары обработаны.', 'modern-minify' ),
		);

		$message = isset( $messages[ $type ] ) ? (string) $messages[ $type ] : '';

		if ( '' === $message ) {
			return;
		}

		echo self::toast_markup( $type, $message );
	}

	/**
	 * Markup of one toast. Public so a fixture can check the roles.
	 *
	 * @param string $type    `success` or `error`.
	 * @param string $message Message.
	 */
	public static function toast_markup( string $type, string $message ): string {
		$error = 'error' === $type;
		$role  = $error ? 'alert' : 'status';
		$live  = $error ? 'assertive' : 'polite';
		$icon  = $error ? 'dashicons-warning' : 'dashicons-yes-alt';

		$html = '<div class="mmf-toasts" id="mmf-toasts" aria-live="' . esc_attr( $live ) . '">';
		$html .= '<div class="mmf-toast mmf-toast--' . ( $error ? 'error' : 'success' ) . '" data-type="' . ( $error ? 'error' : 'success' ) . '" role="' . esc_attr( $role ) . '">';
		$html .= '<span class="dashicons ' . $icon . ' mmf-toast__icon" aria-hidden="true"></span>';
		$html .= '<p class="mmf-toast__text">' . esc_html( $message ) . '</p>';
		$html .= '<button type="button" class="mmf-toast__close" aria-label="' . esc_attr__( 'Закрыть уведомление', 'modern-minify' ) . '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>';
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Prints the whole-page mode notices: what it does, why it is blocked and how
	 * it relates to Delay JS.
	 *
	 * @param string $slug Tab slug.
	 */
	private static function render_rocket_notices( string $slug ): void {
		if ( 'javascript' !== $slug || ! MMF_Config::get( 'rocket_mode' ) ) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p>' . esc_html( MMF_Notices::rocket_warning() ) . '</p></div>';

		$js = MMF_Loader::script( MMF_Loader::MODE_DOMREADY, 5000, MMF_Loader::MAX_BYTES_ROCKET );

		if ( MMF_Loader::csp_blocked( $js ) ) {
			printf(
				'<div class="notice notice-error inline"><p>%s <code>%s</code></p></div>',
				esc_html__( 'Rocket-режим не активирован: на сайте есть Content-Security-Policy без nonce для нашего загрузчика. Разрешите nonce (плагин политики фильтрует wp_inline_script_attributes) или добавьте в политику хеш:', 'modern-minify' ),
				esc_html( MMF_Loader::hash( $js ) )
			);
		}

		$delay_patterns = MMF_Config::get( 'delay_js', array() );

		if ( is_array( $delay_patterns ) && array() !== $delay_patterns ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Rocket-режим откладывает все скрипты, поэтому список Delay JS не действует: если файл попал в оба, побеждает Rocket-режим.', 'modern-minify' ) . '</p></div>';
		}
	}

	/**
	 * Deletes every generated WebP sidecar, a reversible off switch.
	 */
	public static function handle_delete_webp(): void {
		if ( ! isset( $_GET['action'] ) || 'mmf_delete_webp' !== sanitize_key( (string) wp_unslash( $_GET['action'] ) ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'modern-minify' ) );
		}

		check_admin_referer( self::WEBP_NONCE );

		$deleted = MMF_Images::delete_all();
		MMF_Stats::record_action( sprintf( __( 'Удалено WebP-файлов: %d.', 'modern-minify' ), (int) $deleted ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::PAGE,
					'mmf-toast'       => 'webp-deleted',
					'mmf-webp'        => $deleted,
					'mmf-toast-nonce' => wp_create_nonce( self::TOAST_NONCE ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Rejects advice patterns that would take the site down.
	 *
	 * The lesson came from a live incident: `*scripts.min.js*` matched four
	 * files including the theme's own script, and deferring it left the page
	 * without its interface. A pattern that matches more than one resource, or
	 * that touches the theme, jQuery or a WordPress core script, is refused
	 * before anything is written.
	 *
	 * @param array<int,string> $patterns Patterns the user asked to add.
	 * @param string            $kind     `js` or `css`.
	 * @return array{ok:bool,rejected:array<int,array{pattern:string,reason:string}>,matched:int}
	 */
	private static function advice_guard( array $patterns, string $kind ): array {
		$stats     = MMF_Stats::all();
		$resources = isset( $stats['last_resources'] ) && is_array( $stats['last_resources'] ) ? $stats['last_resources'] : array();
		$urls      = array();

		foreach ( $resources as $resource ) {
			if ( ! is_array( $resource ) || $kind !== ( $resource['kind'] ?? '' ) ) {
				continue;
			}

			$url = isset( $resource['url'] ) ? (string) $resource['url'] : '';

			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		$critical = array( '/wp-includes/', '/wp-content/themes/', 'jquery', 'wp-i18n', 'wp-hooks', 'wp-polyfill' );
		$rejected = array();
		$matched  = 0;

		foreach ( $patterns as $pattern ) {
			$regex = '#^' . str_replace( '\*', '.*', preg_quote( (string) $pattern, '#' ) ) . '$#i';
			$hits  = array();

			foreach ( $urls as $url ) {
				if ( preg_match( $regex, $url ) ) {
					$hits[] = $url;
				}
			}

			$matched += count( $hits );

			if ( array() === $hits ) {
				continue;
			}

			$reason = '';

			foreach ( $hits as $hit ) {
				foreach ( $critical as $needle ) {
					if ( false !== stripos( $hit, $needle ) ) {
						$reason = __( 'скрипт темы, jQuery или ядра WordPress', 'modern-minify' );
						break 2;
					}
				}
			}

			if ( '' === $reason && count( $hits ) > 1 ) {
				/* translators: %d: number of matched files. */
				$reason = sprintf( __( 'шаблон накрывает %d файла — укажите полный путь', 'modern-minify' ), count( $hits ) );
			}

			if ( '' !== $reason ) {
				$rejected[] = array(
					'pattern' => (string) $pattern,
					'reason'  => $reason,
				);
			}
		}

		return array(
			'ok'       => array() === $rejected,
			'rejected' => $rejected,
			'matched'  => $matched,
		);
	}

	/**
	 * Reads the home page and reports whether jQuery is still a normal script.
	 *
	 * A post-write sanity check: if the page had jQuery before the change and
	 * lost it after, the caller rolls the settings back. A page that cannot be
	 * fetched is not treated as broken — an unreachable site must not lock the
	 * screen.
	 *
	 * @return array{jquery:bool,scripts:int,styles:int,checked:bool}
	 */
	private static function page_health(): array {
		$out = array( 'jquery' => false, 'scripts' => 0, 'styles' => 0, 'checked' => false );

		if ( ! function_exists( 'wp_remote_get' ) || ! function_exists( 'home_url' ) ) {
			return $out;
		}

		$url = add_query_arg( 'mmf_health', time(), home_url( '/' ) );
		$res = wp_remote_get( $url, array( 'timeout' => 8, 'redirection' => 3 ) );

		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return $out;
		}

		$body = (string) wp_remote_retrieve_body( $res );

		if ( '' === $body ) {
			return $out;
		}

		// `\ssrc=` and not just `src=`: the delayed-script transform moves the
		// URL into `data-mmf-rsrc`, and a loose pattern would read that as a
		// live script, hiding exactly the breakage this check looks for.
		preg_match_all( '#<script[^>]*\ssrc=[\'"]([^\'"]+)[\'"]#i', $body, $scripts );
		preg_match_all( '#<link[^>]+rel=[\'"]stylesheet[\'"]#i', $body, $styles );

		foreach ( (array) $scripts[1] as $src ) {
			if ( false !== stripos( (string) $src, 'jquery' ) ) {
				$out['jquery'] = true;
				break;
			}
		}

		$out['scripts'] = count( (array) $scripts[1] );
		$out['styles']  = count( (array) $styles[0] );
		$out['checked'] = true;

		return $out;
	}

	/**
	 * Runs the advice handler when the settings form was submitted by its button.
	 *
	 * `settings_fields()` puts `action=update` into the same POST body, and a
	 * POST field overrides the `?action=` of the form target, so the request
	 * never reached `admin_post_mmf_apply_advice`. Our own flag is unambiguous.
	 */
	public static function maybe_handle_apply_advice(): void {
		if ( empty( $_POST['mmf_apply_advice'] ) ) {
			return;
		}

		self::handle_apply_advice();
	}

	/**
	 * Adds the checked advice patterns to the chosen list.
	 *
	 * Read-only analysis becomes a change only here, on an explicit POST from
	 * the advisor button.
	 */
	public static function handle_apply_advice(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'modern-minify' ) );
		}

		check_admin_referer( self::ADVICE_NONCE, 'mmf_advice_nonce' );

		$target = isset( $_POST['mmf_advice_mode'] ) && 'defer' === sanitize_key( (string) wp_unslash( $_POST['mmf_advice_mode'] ) ) ? 'defer_skip' : 'delay_js';
		$posted = isset( $_POST['mmf_advice_patterns'] ) ? (array) wp_unslash( $_POST['mmf_advice_patterns'] ) : array();
		$added  = array();

		foreach ( $posted as $pattern ) {
			if ( ! is_string( $pattern ) ) {
				continue;
			}

			$pattern = trim( sanitize_text_field( $pattern ) );

			if ( '' !== $pattern ) {
				$added[] = $pattern;
			}
		}

		$added = array_values( array_unique( $added ) );

		// Nothing is written before the patterns are checked against the page
		// they will affect: a wildcard that covers the theme's own script must
		// be refused here, not discovered by visitors.
		$guard = self::advice_guard( $added, 'js' );

		if ( ! $guard['ok'] ) {
			$lines = array();

			foreach ( $guard['rejected'] as $item ) {
				$lines[] = $item['pattern'] . ' — ' . $item['reason'];
			}

			$reason = implode( '; ', $lines );
			MMF_Stats::record_action( sprintf( /* translators: %s: reasons. */ __( 'Шаблоны не применены: %s', 'modern-minify' ), $reason ) );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => self::PAGE,
						'tab'             => 'advisor',
						'mmf-toast'       => 'advice-blocked',
						'mmf-toast-nonce' => wp_create_nonce( self::TOAST_NONCE ),
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		// Only checked patterns are added, and an existing entry is never duplicated.
		$current = (array) MMF_Config::get( $target, array() );
		$merged  = array_values( array_unique( array_merge( $current, $added ) ) );
		$new     = count( $merged ) - count( $current );

		if ( $new > 0 ) {
			$before = self::page_health();

			MMF_Config::update( array( $target => $merged ) );
			$deleted = MMF_Cache::delete_all();

			$after = self::page_health();

			// The change is verified on the real home page. A page that had
			// jQuery before and lost it after means the pattern took the site
			// apart; the previous list is restored immediately.
			if ( $before['checked'] && $after['checked'] && $before['jquery'] && ! $after['jquery'] ) {
				MMF_Config::update( array( $target => $current ) );
				MMF_Cache::delete_all();
				MMF_Stats::record_action( __( 'Настройки отменены: после применения страница теряет jQuery. Шаблон слишком широкий.', 'modern-minify' ) );

				wp_safe_redirect(
					add_query_arg(
						array(
							'page'            => self::PAGE,
							'tab'             => 'advisor',
							'mmf-toast'       => 'advice-rolled-back',
							'mmf-toast-nonce' => wp_create_nonce( self::TOAST_NONCE ),
						),
						admin_url( 'options-general.php' )
					)
				);
				exit;
			}

			MMF_Stats::record_action( sprintf( __( 'Добавлено шаблонов в список: %1$d, кеш сброшен (удалено файлов: %2$d).', 'modern-minify' ), $new, (int) $deleted ) );
		} else {
			MMF_Stats::record_action( __( 'Выбранные шаблоны уже есть в списке.', 'modern-minify' ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::PAGE,
					'tab'             => 'javascript',
					'mmf-toast'       => 'advice',
					'mmf-advice'      => $new,
					'mmf-toast-nonce' => wp_create_nonce( self::TOAST_NONCE ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Bulk state as stored in the option.
	 *
	 * @return array<string,mixed>
	 */
	private static function bulk_state(): array {
		$state = get_option( self::BULK_OPTION, array() );

		if ( ! is_array( $state ) || ! isset( $state['ids'] ) || ! is_array( $state['ids'] ) ) {
			return array();
		}

		return $state;
	}

	/**
	 * Takes the lock, or reports that another run is alive.
	 */
	private static function bulk_lock(): bool {
		$lock = (int) get_option( self::BULK_LOCK, 0 );

		if ( $lock > 0 && ( time() - $lock ) < self::BULK_TTL ) {
			return false;
		}

		update_option( self::BULK_LOCK, time(), false );

		return true;
	}

	/**
	 * Builds and stores the queue of attachments without a fresh sidecar.
	 *
	 * @param array<int,int>|null $ids Explicit attachment IDs, a test seam; null queries the library.
	 * @return array<string,mixed>
	 */
	public static function bulk_start( ?array $ids = null ): array {
		if ( ! is_admin() ) {
			return array( 'error' => 'front' );
		}

		$state = self::bulk_state();

		if ( array() !== $state && empty( $state['paused'] ) ) {
			return array( 'error' => 'busy' );
		}

		if ( ! self::bulk_lock() ) {
			return array( 'error' => 'busy' );
		}

		delete_option( self::BULK_LOCK );

		if ( null === $ids ) {
			$ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => array( 'image/jpeg', 'image/png' ),
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
				)
			);
		}

		$queue = array();

		foreach ( (array) $ids as $id ) {
			if ( MMF_Images::needs_sidecar( (int) $id ) ) {
				$queue[] = (int) $id;
			}
		}

		$state = array(
			'ids'     => array_values( $queue ),
			'total'   => count( $queue ),
			'done'    => 0,
			'paused'  => false,
			'stats'   => array(
				'created'      => 0,
				'recreated'    => 0,
				'reused'       => 0,
				'already-webp' => 0,
				'skipped'      => 0,
				'small'        => 0,
				'error'        => 0,
				'bigger'       => 0,
			),
			'updated' => time(),
		);

		update_option( self::BULK_OPTION, $state, false );

		if ( array() === $queue ) {
			delete_option( self::BULK_LOCK );
			$state['finished'] = true;
		}

		return $state;
	}

	/**
	 * Processes one batch of twenty attachments.
	 *
	 * @return array<string,mixed>
	 */
	public static function bulk_batch(): array {
		if ( ! is_admin() ) {
			return array( 'error' => 'front' );
		}

		$state = self::bulk_state();

		if ( array() === $state ) {
			return array( 'error' => 'empty' );
		}

		if ( ! empty( $state['paused'] ) ) {
			return $state;
		}

		if ( ! self::bulk_lock() ) {
			return array( 'error' => 'busy' );
		}

		$chunk = array_splice( $state['ids'], 0, self::BULK_BATCH );

		foreach ( $chunk as $id ) {
			try {
				$counters = MMF_Images::convert_attachment( (int) $id );
			} catch ( \Throwable $error ) {
				throw new \RuntimeException( 'attachment ' . (int) $id . ': ' . $error->getMessage(), 0, $error );
			}

			foreach ( $counters as $key => $value ) {
				if ( isset( $state['stats'][ $key ] ) ) {
					$state['stats'][ $key ] += (int) $value;
				}
			}
		}

		$state['done']    = min( (int) $state['total'], (int) $state['done'] + count( $chunk ) );
		$state['updated'] = time();

		$finished = $state['done'] >= $state['total'] || array() === $state['ids'];

		if ( $finished ) {
			$state['finished'] = true;
			delete_option( self::BULK_OPTION );
		} else {
			update_option( self::BULK_OPTION, $state, false );
		}

		delete_option( self::BULK_LOCK );

		return $state;
	}

	/**
	 * Current state for the initial page paint.
	 *
	 * @return array<string,mixed>
	 */
	public static function bulk_status(): array {
		$state = self::bulk_state();

		if ( array() === $state ) {
			return array(
				'ids'   => array(),
				'total' => 0,
				'done'  => 0,
				'stats' => array(),
			);
		}

		return $state;
	}

	/**
	 * Pauses the run; the lock is released so a resume can take it.
	 *
	 * @return array<string,mixed>
	 */
	public static function bulk_pause(): array {
		$state = self::bulk_state();

		if ( array() !== $state ) {
			$state['paused']  = true;
			$state['updated'] = time();
			update_option( self::BULK_OPTION, $state, false );
		}

		delete_option( self::BULK_LOCK );

		return $state;
	}

	/**
	 * Resumes a paused run.
	 *
	 * @return array<string,mixed>
	 */
	public static function bulk_resume(): array {
		$state = self::bulk_state();

		if ( array() === $state ) {
			return array( 'error' => 'busy' );
		}

		$state['paused']  = false;
		$state['updated'] = time();

		update_option( self::BULK_OPTION, $state, false );

		return $state;
	}

	/**
	 * Stops and forgets the queue.
	 *
	 * @return array<string,mixed>
	 */
	public static function bulk_stop(): array {
		delete_option( self::BULK_OPTION );
		delete_option( self::BULK_LOCK );

		return array(
			'ids'   => array(),
			'total' => 0,
			'done'  => 0,
			'stats' => array(),
		);
	}

	/**
	 * Sends a bulk result as JSON.
	 *
	 * @param array<string,mixed> $result Method result.
	 */
	/**
	 * Human error payload for the bulk AJAX endpoints.
	 *
	 * @param string $code   Machine code.
	 * @param string $detail Optional technical detail.
	 * @return array<string,string>
	 */
	private static function bulk_error( string $code, string $detail = '' ): array {
		$map = array(
			'nonce_expired'     => array( __( 'Истёк токен безопасности.', 'modern-minify' ), __( 'Обновите страницу и запустите снова.', 'modern-minify' ) ),
			'forbidden'         => array( __( 'Недостаточно прав.', 'modern-minify' ), __( 'Войдите под администратором сайта.', 'modern-minify' ) ),
			'busy'              => array( __( 'Уже идёт другая конвертация.', 'modern-minify' ), __( 'Дождитесь завершения или нажмите «Остановить».', 'modern-minify' ) ),
			'converter_missing' => array( __( 'Нет WebP в PHP.', 'modern-minify' ), __( 'Включите GD с IMG_WEBP, Imagick или установите cwebp.', 'modern-minify' ) ),
			'file_error'        => array( __( 'Не удалось прочитать файл картинки.', 'modern-minify' ), __( 'Проверьте права на uploads и сам файл.', 'modern-minify' ) ),
			'timeout'           => array( __( 'Сервер не ответил вовремя.', 'modern-minify' ), __( 'Уменьшите пакет или повторите позже.', 'modern-minify' ) ),
			'exception'         => array( __( 'Ошибка сервера при конвертации.', 'modern-minify' ), __( 'Смотрите журнал ошибок PHP.', 'modern-minify' ) ),
		);

		$pair = $map[ $code ] ?? array( __( 'Не удалось выполнить пакет.', 'modern-minify' ), __( 'Попробуйте ещё раз.', 'modern-minify' ) );

		$message = '' !== $detail ? $pair[0] . ' (' . $detail . ')' : $pair[0];

		return array( 'error' => $code, 'message' => $message, 'hint' => $pair[1], 'detail' => $detail );
	}

	/**
	 * Sends a bulk result or a readable error.
	 *
	 * @param array<string,mixed> $result Method result.
	 */
	private static function send_bulk( array $result ): void {
		if ( isset( $result['error'] ) ) {
			$code = (string) $result['error'];
			$error = self::bulk_error( in_array( $code, array( 'nonce_expired', 'forbidden', 'busy', 'converter_missing', 'file_error', 'timeout', 'exception' ), true ) ? $code : 'exception' );

			wp_send_json_error( $error );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Verifies AJAX nonce and capability with readable errors.
	 *
	 * @param string $nonce Nonce value.
	 */
	private static function ajax_guard( string $nonce ): void {
		if ( ! wp_verify_nonce( $nonce, self::BULK_NONCE ) ) {
			wp_send_json_error( self::bulk_error( 'nonce_expired' ) );
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( self::bulk_error( 'forbidden' ) );
		}
	}

	/**
	 * AJAX: starts the queue.
	 */
	public static function ajax_webp_start(): void {
		$nonce = isset( $_POST['nonce'] ) ? (string) wp_unslash( $_POST['nonce'] ) : '';

		if ( ! wp_verify_nonce( $nonce, self::BULK_NONCE ) ) {
			wp_send_json_error( self::bulk_error( 'nonce_expired' ) );
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( self::bulk_error( 'forbidden' ) );
		}

		try {
			self::send_bulk( self::bulk_start() );
		} catch ( \Throwable $error ) {
			wp_send_json_error( self::bulk_error( 'exception', $error->getMessage() ) );
		}
	}

	/**
	 * AJAX: one batch.
	 */
	public static function ajax_webp_batch(): void {
		$nonce = isset( $_POST['nonce'] ) ? (string) wp_unslash( $_POST['nonce'] ) : '';

		if ( ! wp_verify_nonce( $nonce, self::BULK_NONCE ) ) {
			wp_send_json_error( self::bulk_error( 'nonce_expired' ) );
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( self::bulk_error( 'forbidden' ) );
		}

		try {
			self::send_bulk( self::bulk_batch() );
		} catch ( \Throwable $error ) {
			wp_send_json_error( self::bulk_error( 'exception', $error->getMessage() ) );
		}
	}

	/**
	 * AJAX: current state.
	 */
	public static function ajax_webp_status(): void {
		$nonce = isset( $_POST['nonce'] ) ? (string) wp_unslash( $_POST['nonce'] ) : '';

		if ( ! wp_verify_nonce( $nonce, self::BULK_NONCE ) ) {
			wp_send_json_error( self::bulk_error( 'nonce_expired' ) );
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( self::bulk_error( 'forbidden' ) );
		}

		wp_send_json_success( self::bulk_status() );
	}

	/**
	 * AJAX: pause.
	 */
	public static function ajax_webp_pause(): void {
		check_ajax_referer( self::BULK_NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'code' => 'cap' ) );
		}

		wp_send_json_success( self::bulk_pause() );
	}

	/**
	 * AJAX: resume.
	 */
	public static function ajax_webp_resume(): void {
		check_ajax_referer( self::BULK_NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'code' => 'cap' ) );
		}

		self::send_bulk( self::bulk_resume() );
	}

	/**
	 * AJAX: stop.
	 */
	public static function ajax_webp_stop(): void {
		check_ajax_referer( self::BULK_NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'code' => 'cap' ) );
		}

		wp_send_json_success( self::bulk_stop() );
	}

	/**
	 * The CSS half of the advisor: read-only verdicts for stylesheets.
	 */
	private static function render_css_advisor(): void {
		$stats     = MMF_Stats::all();
		$resources = isset( $stats['last_resources'] ) && is_array( $stats['last_resources'] ) ? $stats['last_resources'] : array();
		$styles    = array();

		foreach ( $resources as $resource ) {
			if ( is_array( $resource ) && 'css' === ( $resource['kind'] ?? '' ) ) {
				$styles[] = $resource;
			}
		}

		$analysis = MMF_Advisor::analyse( $styles );

		echo '<section class="mmf-card mmf-advisor"><h2 class="mmf-section-title">' . esc_html__( 'Стили', 'modern-minify' ) . '</h2>';

		if ( array() === $analysis['items'] ) {
			echo '<p class="description">' . esc_html__( 'Данных пока нет: откройте любую страницу сайта.', 'modern-minify' ) . '</p>';
			echo '</section>';

			return;
		}

		echo '<div class="mmf-advisor-scroll"><table class="widefat mmf-advisor-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Стиль', 'modern-minify' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Размер', 'modern-minify' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Вердикт', 'modern-minify' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Действие', 'modern-minify' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $analysis['items'] as $item ) {
			$size = (int) $item['size'] > 0 ? number_format( (float) ( $item['size'] / 1024 ), 1, '.', ' ' ) . ' КБ' : '—';

			echo '<tr><td><span class="mmf-advisor__name">' . esc_html( (string) $item['name'] ) . '</span> <span class="mmf-advisor__path" title="' . esc_attr( (string) $item['url'] ) . '">' . esc_html( (string) $item['url'] ) . '</span><p class="mmf-advisor__reason">' . esc_html( MMF_Advisor::reason_label( (string) $item['reason'] ) ) . '</p></td>';
			echo '<td class="mmf-advisor__size">' . esc_html( $size ) . '</td>';
			$css_reason = (string) ( $item['reason'] ?? '' );
			$css_labels = array(
				'css-inline'   => __( 'можно встроить', 'modern-minify' ),
				'css-widget'   => __( 'можно асинхронно', 'modern-minify' ),
				'css-plugin'   => __( 'рискованно', 'modern-minify' ),
				'css-theme'    => __( 'нельзя', 'modern-minify' ),
				'css-unknown'  => __( 'неизвестно', 'modern-minify' ),
				// Processed rows must name what actually happened: the generic
				// "уже отложен" used to appear next to "уже встроен в страницу".
				'inlined-css'  => __( 'уже встроен', 'modern-minify' ),
				'async-css'    => __( 'уже отложен', 'modern-minify' ),
				'bundled'      => __( 'уже в пакете', 'modern-minify' ),
				'optimized'    => __( 'уже оптимизирован', 'modern-minify' ),
				'excluded'     => __( 'исключён', 'modern-minify' ),
				'unknown-source' => __( 'источник неизвестен', 'modern-minify' ),
			);
			$css_label = $css_labels[ $css_reason ] ?? MMF_Advisor::verdict_label( (string) $item['verdict'] );
			echo '<td><span class="mmf-verdict mmf-verdict--' . esc_attr( (string) $item['verdict'] ) . '">' . esc_html( $css_label ) . '</span></td>';
			echo '<td class="mmf-advisor__actions">' . self::css_action_links( $item ) . '</td></tr>';
		}

		echo '</tbody></table></div></section>';
	}

	/**
	 * Action links of one CSS row.
	 *
	 * @param array<string,mixed> $item Advisor item.
	 */
	private static function css_action_links( array $item ): string {
		if ( ! empty( $item['unknown'] ) || MMF_Advisor::DONE === ( $item['verdict'] ?? '' ) ) {
			return '<span class="description">—</span>';
		}

		$pattern = (string) ( $item['pattern'] ?? '' );

		if ( '' === $pattern ) {
			return '<span class="description">—</span>';
		}

		$links = array();
		$base  = admin_url( 'admin-post.php?action=mmf_css_action' );
		$url   = static function ( string $do, string $pattern ) use ( $base ): string {
			return wp_nonce_url( add_query_arg( array( 'do' => $do, 'pattern' => $pattern ), $base ), self::CSS_ACTION_NONCE );
		};

		if ( in_array( (string) ( $item['reason'] ?? '' ), array( 'css-inline', 'css-plugin' ), true ) && false === strpos( (string) $item['url'], '/themes/' ) ) {
			$links[] = '<a class="button button-small" href="' . esc_url( $url( 'inline', $pattern ) ) . '">' . esc_html__( 'Встроить', 'modern-minify' ) . '</a>';
		}

		// Asynchronous loading is offered for every non-theme stylesheet: a
		// small plugin file is exactly what people want out of the critical
		// path, and the theme sheet is refused earlier by handle_css_action().
		if ( false === strpos( (string) $item['url'], '/themes/' ) ) {
			if ( MMF_Advisor::async_css_available() ) {
				$links[] = '<a class="button button-small mmf-async-css" title="' . esc_attr__( 'Убрать файл из критического пути и загрузить после отрисовки; возможен краткий скачок оформления', 'modern-minify' ) . '" href="' . esc_url( $url( 'async', $pattern ) ) . '">' . esc_html__( 'Отложить загрузку', 'modern-minify' ) . '</a>';
			} else {
				$links[] = '<span class="description">' . esc_html__( 'асинхронно недоступно: CSP без nonce для инлайн-скрипта', 'modern-minify' ) . '</span>';
			}
		}

		if ( ! MMF_Config::get( 'merge_css' ) ) {
			$links[] = '<a class="button button-small" href="' . esc_url( $url( 'merge', $pattern ) ) . '">' . esc_html__( 'Объединить', 'modern-minify' ) . '</a>';
		}

		$links[] = '<a class="button button-small" href="' . esc_url( $url( 'exclude', $pattern ) ) . '">' . esc_html__( 'Исключить', 'modern-minify' ) . '</a>';

		if ( array() === $links ) {
			return '<span class="description">—</span>';
		}

		$primary = array_shift( $links );
		$primary = str_replace( '<a ', '<a title="' . esc_attr__( 'Применить это действие к оригинальному файлу', 'modern-minify' ) . '" ', $primary );
		$menu    = '';

		$links = array_map(
			static function ( $link ) {
				return str_replace( 'class="button button-small', 'class="mmf-menu__item', $link );
			},
			$links
		);

		if ( array() !== $links ) {
			$menu = '<button type="button" class="button button-small mmf-menu-toggle" aria-expanded="false" aria-label="' . esc_attr__( 'Другие действия', 'modern-minify' ) . '">⋯</button><div class="mmf-menu" hidden>' . implode( '', $links ) . '</div>';
		} else {
			$menu = '<button type="button" class="button button-small mmf-menu-toggle" aria-expanded="false" disabled="disabled" aria-label="' . esc_attr__( 'Других действий нет', 'modern-minify' ) . '">⋯</button>';
		}

		return '<span class="mmf-actions-wrap">' . $primary . $menu . '</span>';
	}

	/**
	 * Applies one CSS advice action and resets the cache.
	 */
	public static function handle_css_action(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'modern-minify' ) );
		}

		check_admin_referer( self::CSS_ACTION_NONCE );

		$do      = isset( $_GET['do'] ) ? sanitize_key( (string) wp_unslash( $_GET['do'] ) ) : '';
		$pattern = isset( $_GET['pattern'] ) ? trim( sanitize_text_field( (string) wp_unslash( $_GET['pattern'] ) ) ) : '';

		if ( '' === $pattern || ! in_array( $do, array( 'inline', 'async', 'exclude', 'merge' ), true ) ) {
			wp_die( esc_html__( 'Некорректное действие.', 'modern-minify' ) );
		}

		// The advisor writes a pattern here, not a bare URL: the stored value is
		// matched against the full address, so a scheme-less path or a full URL
		// from the page would never match. Normalise both into `*<path>*`.
		$path = (string) wp_parse_url( $pattern, PHP_URL_PATH );

		if ( '' !== $path ) {
			$pattern = $path;
		}

		$pattern = trim( $pattern, " \t\n\r\0\x0B*" );

		if ( '' !== $pattern ) {
			$pattern = '*' . $pattern . '*';
		}

		// Theme and core stylesheets are never loaded asynchronously: the page
		// would lose its layout before the script wakes up.
		if ( 'async' === $do && ( false !== stripos( $pattern, '/themes/' ) || false !== stripos( $pattern, '/wp-includes/' ) ) ) {
			MMF_Stats::record_action( __( 'Асинхронная загрузка отклонена: это стиль темы или ядра.', 'modern-minify' ) );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => self::PAGE,
						'tab'             => 'advisor',
						'mmf-toast'       => 'css-action-blocked',
						'mmf-toast-nonce' => wp_create_nonce( self::TOAST_NONCE ),
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		$label = '';

		if ( 'merge' === $do ) {
			MMF_Config::update( array( 'merge_css' => true ) );
			$label = __( 'Объединение CSS включено.', 'modern-minify' );
		} else {
			$key   = array( 'inline' => 'inline_css_extra', 'async' => 'async_css', 'exclude' => 'exclude_css' )[ $do ];
			$list  = (array) MMF_Config::get( $key, array() );
			$list[] = $pattern;
			MMF_Config::update( array( $key => array_values( array_unique( $list ) ) ) );
			$label = sprintf( /* translators: %s: action. */ __( 'Список обновлён: %s.', 'modern-minify' ), $do );
		}

		$deleted = MMF_Cache::delete_all();
		MMF_Stats::record_action( $label . ' ' . sprintf( __( 'Кеш сброшен (удалено файлов: %d).', 'modern-minify' ), (int) $deleted ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::PAGE,
					'tab'             => 'advisor',
					'mmf-toast'       => 'css-action',
					'mmf-toast-nonce' => wp_create_nonce( self::TOAST_NONCE ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Walks users' avatars once and converts what can be converted.
	 */
	public static function handle_convert_avatars(): void {
		if ( ! isset( $_GET['action'] ) || 'mmf_convert_avatars' !== sanitize_key( (string) wp_unslash( $_GET['action'] ) ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'modern-minify' ) );
		}

		check_admin_referer( self::AVATARS_NONCE );

		$before = (int) MMF_Stats::all()['avatar_sidecar'];
		$users  = function_exists( 'get_users' ) ? get_users( array( 'fields' => 'ID', 'number' => 500 ) ) : array();
		$saved  = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
		$_SERVER['HTTP_ACCEPT'] = 'image/webp';

		foreach ( (array) $users as $user_id ) {
			$url = get_avatar_url( (int) $user_id, array( 'size' => 96 ) );

			if ( is_string( $url ) && '' !== $url ) {
				MMF_Avatars::webp_url( $url );
			}
		}

		$_SERVER['HTTP_ACCEPT'] = $saved;
		$done = max( 0, (int) MMF_Stats::all()['avatar_sidecar'] - $before );

		MMF_Stats::record_action( sprintf( __( 'Аватары обработаны, новых WebP: %d.', 'modern-minify' ), $done ) );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'tab' => 'images', 'mmf-toast' => 'avatars', 'mmf-toast-nonce' => wp_create_nonce( self::TOAST_NONCE ) ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * The whole "Изображения" tab below the fields.
	 */
	private static function render_images_tab(): void {
		$stats  = MMF_Stats::all();
		$method = MMF_Images::method();
		$labels = array(
			'imagick' => 'Imagick',
			'gd'      => 'GD',
			'cwebp'   => 'cwebp',
		);
		$abilities = array(
			'imagick' => __( 'альфа, lossless, качество', 'modern-minify' ),
			'gd'      => __( 'альфа и качество; lossless PNG недоступен', 'modern-minify' ),
			'cwebp'   => __( 'альфа, lossless и качество; внешний бинарь', 'modern-minify' ),
		);

		echo '<section class="mmf-card"><h2 class="mmf-section-title">' . esc_html__( 'Конвертер', 'modern-minify' ) . '</h2>';

		if ( '' === $method ) {
			echo '<p class="mmf-alert mmf-alert--error" role="alert">' . esc_html__( 'Ни Imagick, ни GD с WebP, ни cwebp не найдены. Конвертация недоступна, настройки ниже ничего не меняют. Нужно расширение PHP с поддержкой WebP или бинарь cwebp.', 'modern-minify' ) . '</p>';
		} else {
			echo '<p><strong>' . esc_html( $labels[ $method ] ?? $method ) . '</strong> — ' . esc_html( $abilities[ $method ] ?? '' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Определяется один раз при первом запросе. Порядок выбора: Imagick, затем GD, затем cwebp.', 'modern-minify' ) . '</p>';
		}

		echo '</section>';

		echo '<section class="mmf-card mmf-bulk" id="mmf-webp-bulk" data-nonce="' . esc_attr( wp_create_nonce( self::BULK_NONCE ) ) . '">';
		echo '<h2 class="mmf-section-title">' . esc_html__( 'Массовая конвертация', 'modern-minify' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Только JPEG и PNG без свежей WebP-копии. Пакетами по 20 вложений, только в админке; уже готовые пропускаются.', 'modern-minify' ) . '</p>';
		echo '<p class="mmf-bulk__text" id="mmf-webp-progress">' . esc_html__( 'обработано 0 из 0', 'modern-minify' ) . '</p>';
		echo '<div class="mmf-progress" id="mmf-webp-progressbar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden><div class="mmf-progress__bar"></div></div>';
		echo '<p class="description" id="mmf-webp-counts"></p>';
		echo '<p class="mmf-bulk__actions">';
		echo '<button type="button" class="button button-primary" id="mmf-webp-start">' . esc_html__( 'Конвертировать существующие изображения', 'modern-minify' ) . '</button> ';
		echo '<button type="button" class="button" id="mmf-webp-pause" hidden>' . esc_html__( 'Пауза', 'modern-minify' ) . '</button> ';
		echo '<button type="button" class="button" id="mmf-webp-resume" hidden>' . esc_html__( 'Продолжить', 'modern-minify' ) . '</button> ';
		echo '<button type="button" class="button" id="mmf-webp-stop" hidden>' . esc_html__( 'Остановить', 'modern-minify' ) . '</button>';
		echo '</p></section>';

		echo '<section class="mmf-card"><h2 class="mmf-section-title">' . esc_html__( 'Правила', 'modern-minify' ) . '</h2><ul class="mmf-rules">';
		echo '<li>' . esc_html__( 'Пропускаются файлы меньше 10 КБ, GIF, SVG, битые картинки, исходники, которые уже WebP, и свежие готовые копии.', 'modern-minify' ) . '</li>';
		echo '<li>' . esc_html__( 'Если WebP получился больше оригинала, копия удаляется и отдаётся оригинал.', 'modern-minify' ) . '</li>';
		echo '<li>' . esc_html__( 'Оригиналы не изменяются и не удаляются; копия удаляется вместе с вложением или кнопкой ниже.', 'modern-minify' ) . '</li>';
		echo '<li>' . esc_html__( 'Отдача — только браузерам с Accept: image/webp и только при существующей свежей копии.', 'modern-minify' ) . '</li>';
		echo '</ul></section>';

		$missing = (int) $stats['image_missing'] + (int) $stats['avatar_missing'];
		$disk    = MMF_Images::disk_stats();

		if ( $missing > 0 ) {
			echo '<p class="mmf-alert mmf-alert--error" role="alert">' . esc_html( sprintf( __( 'Есть картинки без WebP: %d запроса. Запустите массовую конвертацию.', 'modern-minify' ), $missing ) ) . ' <a href="#mmf-webp-bulk">' . esc_html__( 'К массовой конвертации', 'modern-minify' ) . '</a></p>';
		}

		echo '<p class="description">' . esc_html( sprintf( __( 'Найдено готовых WebP на диске: %d', 'modern-minify' ), (int) $disk['files'] ) ) . '</p>';
		echo '<p class="description">' . esc_html( sprintf( __( 'На диске: %1$d файлов, %2$s МБ', 'modern-minify' ), (int) $disk['files'], number_format( $disk['bytes'] / 1048576, 1, '.', ' ' ) ) ) . '</p>';

		$jobs      = (array) get_option( MMF_Images::JOBS_OPTION, array() );
		$last_q    = (int) get_option( MMF_Images::LAST_QUEUE_OPTION, 0 );
		$last_cron = (int) get_option( MMF_Images::LAST_CRON_OPTION, 0 );
		$cron_done = (int) get_option( MMF_Images::LAST_CRON_COUNT, 0 );

		echo '<p class="description">' . esc_html( sprintf( __( 'В очереди: %d; последняя обработка очереди: %s; последний cron: %s (%d файлов).', 'modern-minify' ), count( $jobs ), $last_q > 0 ? (string) wp_date( 'Y-m-d H:i:s', $last_q ) : __( 'ещё не было', 'modern-minify' ), $last_cron > 0 ? (string) wp_date( 'Y-m-d H:i:s', $last_cron ) : __( 'ещё не было', 'modern-minify' ), $cron_done ) ) . '</p>';

		if ( (bool) MMF_Config::get( 'image_webp_ondemand', false ) && array() !== $jobs && ( time() - max( $last_q, $last_cron ) ) > 3600 ) {
			echo '<p class="mmf-alert mmf-alert--error" role="alert">' . esc_html__( 'PHP на сайте исполняется редко (кеш страниц) — запустите массовую конвертацию.', 'modern-minify' ) . '</p>';
		}

		self::status_group(
			__( 'Почему не WebP', 'modern-minify' ),
			array(
				__( 'Аватаров найдено', 'modern-minify' )        => (string) (int) $stats['avatar_found'],
				__( 'Аватаров с копией', 'modern-minify' )       => (string) (int) $stats['avatar_sidecar'],
				__( 'Нет поддержки WebP в PHP', 'modern-minify' ) => (string) (int) $stats['avatar_fail_support'],
				__( 'Не JPEG/PNG', 'modern-minify' )             => (string) (int) $stats['avatar_fail_type'],
				__( 'Ошибка записи', 'modern-minify' )           => (string) (int) $stats['avatar_fail_write'],
				__( 'Файл не найден', 'modern-minify' )          => (string) (int) $stats['avatar_fail_missing'],
				__( 'Внешний URL', 'modern-minify' )             => (string) (int) $stats['avatar_fail_external'],
				__( 'Конвертер', 'modern-minify' )               => '' !== MMF_Images::method() ? MMF_Images::method() : __( 'недоступен', 'modern-minify' ),
			)
		);

		$avatars_url = wp_nonce_url(
			add_query_arg( array( 'page' => self::PAGE, 'action' => 'mmf_convert_avatars' ), admin_url( 'options-general.php' ) ),
			self::AVATARS_NONCE
		);

		echo '<p class="mmf-actions"><a class="button mmf-convert-avatars" href="' . esc_url( $avatars_url ) . '">' . esc_html__( 'Конвертировать все аватары', 'modern-minify' ) . '</a></p>';

		$fmt = static function ( $value ) {
			return (int) $value > 0 ? (string) (int) $value : '—';
		};

		echo '<div class="mmf-status-columns">';

		self::status_group(
			__( 'Счётчики WebP', 'modern-minify' ),
			array(
				__( 'Создано', 'modern-minify' )              => $fmt( $stats['image_created'] ),
				__( 'Пересоздано', 'modern-minify' )          => $fmt( $stats['image_recreated'] ),
				__( 'WebP уже был', 'modern-minify' )         => $fmt( $stats['image_reused'] ),
				__( 'Исходник уже WebP', 'modern-minify' )    => $fmt( $stats['image_webp_existing'] ),
				__( 'Отдано', 'modern-minify' )               => $fmt( $stats['image_served'] ),
				__( 'В очереди', 'modern-minify' )            => $fmt( $stats['convert_queued'] ),
			)
		);

		self::status_group(
			__( 'Ошибки и пропуски', 'modern-minify' ),
			array(
				__( 'Сэкономлено, КБ', 'modern-minify' )      => $fmt( (int) round( (int) $stats['image_saved'] / 1024 ) ),
				__( 'Ошибок', 'modern-minify' )               => $fmt( $stats['image_errors'] ),
				__( 'Не отдано: файла нет', 'modern-minify' ) => $fmt( $missing ),
				__( 'Аватаров WebP', 'modern-minify' )        => $fmt( $stats['avatar_webp'] ),
				__( 'Пропущено: меньше 10 КБ', 'modern-minify' ) => $fmt( $stats['image_small'] ),
				__( 'Пропущено: WebP больше', 'modern-minify' ) => $fmt( $stats['image_bigger'] ),
			)
		);

		echo '</div>';

		$webp_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => self::PAGE,
					'action' => 'mmf_delete_webp',
				),
				admin_url( 'options-general.php' )
			),
			self::WEBP_NONCE
		);

		echo '<p class="mmf-actions"><a class="button mmf-delete-webp" href="' . esc_url( $webp_url ) . '">' . esc_html__( 'Удалить все созданные WebP', 'modern-minify' ) . '</a></p>';
	}

	/**
	 * Help popover with pattern examples for the list fields.
	 *
	 * @param string $key Setting name.
	 */
	private static function render_examples( string $key ): void {
		$examples = array(
			'delay_js'       => array( '*metrika*', '*/plugins/abc-pagination/*', '*front_link_checker.js*' ),
			'defer_skip'     => array( '*front_link_checker.js*', '*/jquery/*' ),
			'async_js'       => array( '*google-analytics.com*', '*/plugins/expert-review/*' ),
			'exclude_js'     => array( '*scripts.min.js*', '*/wp-includes/js/*' ),
			'exclude_css'    => array( '*/plugins/abc-pagination/*', '*style.css*' ),
			'rocket_exclude' => array( '*front_link_checker.js*' ),
		);

		if ( ! isset( $examples[ $key ] ) ) {
			return;
		}

		$id = 'mmf-examples-' . $key;

		echo '<button type="button" class="button-link mmf-examples-toggle" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '">' . esc_html__( 'Примеры шаблонов', 'modern-minify' ) . '</button>';
		echo '<div class="mmf-examples" id="' . esc_attr( $id ) . '" hidden><p class="description">' . esc_html__( 'Один шаблон на строку; * заменяет любое количество символов; пустой список ничего не откладывает; совпадение ищется по полному URL вместе с параметрами.', 'modern-minify' ) . '</p><ul>';

		foreach ( $examples[ $key ] as $example ) {
			echo '<li><code>' . esc_html( $example ) . '</code></li>';
		}

		echo '</ul></div>';
	}

	/**
	 * Prints the read-only advice block on the JavaScript tab.
	 */
	private static function render_advisor(): void {
		$stats     = MMF_Stats::all();
		$resources = isset( $stats['last_resources'] ) && is_array( $stats['last_resources'] ) ? $stats['last_resources'] : array();
		$analysis  = MMF_Advisor::analyse( $resources );

		usort(
			$analysis['items'],
			static function ( $left, $right ) {
				return (int) $right['size'] <=> (int) $left['size'];
			}
		);

		$counts = array( 'all' => count( $analysis['items'] ), 'can' => 0, 'risky' => 0, 'cannot' => 0, 'done' => 0 );

		foreach ( $analysis['items'] as $row ) {
			$counts[ (string) $row['verdict'] ] = ( $counts[ (string) $row['verdict'] ] ?? 0 ) + 1;
		}

		echo '<section class="mmf-card mmf-advisor"><h2 class="mmf-section-title">' . esc_html__( 'Что можно отложить', 'modern-minify' ) . '</h2>';

		if ( array() === $analysis['items'] ) {
			echo '<p class="description">' . esc_html__( 'Данных пока нет: откройте любую страницу сайта.', 'modern-minify' ) . '</p>';
			echo '</section>';

			return;
		}

		echo '<p class="mmf-advisor__summary">';
		echo esc_html(
			sprintf(
				/* translators: 1: blocking requests, 2: deferable requests, 3: kilobytes. */
				__( 'Блокирующих запросов: %1$d, можно отложить: %2$d (≈ %3$s КиБ)', 'modern-minify' ),
				(int) $analysis['blocking'],
				(int) $analysis['deferable'],
				number_format( (float) round( $analysis['bytes'] / 1024, 1 ), 1, '.', ' ' )
			)
		);
		echo '</p>';

		echo '<div class="mmf-advisor-filters" role="group" aria-label="' . esc_attr__( 'Фильтр по вердикту', 'modern-minify' ) . '">';
		$chips = array(
			'all'    => __( 'Все', 'modern-minify' ),
			'can'    => __( 'можно отложить', 'modern-minify' ),
			'risky'  => __( 'рискованно', 'modern-minify' ),
			'cannot' => __( 'нельзя', 'modern-minify' ),
			'done'   => __( 'уже оптимизировано', 'modern-minify' ),
		);

		foreach ( $chips as $slug => $label ) {
			echo '<button type="button" class="button mmf-filter' . ( 'all' === $slug ? ' mmf-filter--active' : '' ) . '" data-filter="' . esc_attr( $slug ) . '">' . esc_html( $label . ' ' . (int) ( $counts[ $slug ] ?? 0 ) ) . '</button>';
		}

		echo '</div>';

		if ( count( $analysis['items'] ) > 20 ) {
			echo '<p><input type="search" class="mmf-advisor-search" placeholder="' . esc_attr__( 'Поиск по имени или адресу', 'modern-minify' ) . '" /></p>';
		}

		echo '<div class="mmf-advisor-scroll"><table class="widefat mmf-advisor-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Ресурс', 'modern-minify' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'До → после', 'modern-minify' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Вердикт', 'modern-minify' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Действие', 'modern-minify' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $analysis['items'] as $item ) {
			$verdict = (string) $item['verdict'];
			$reason  = MMF_Advisor::reason_label( (string) $item['reason'] );
			$size    = (int) $item['size'] > 0 ? number_format( (float) ( $item['size'] / 1024 ), 1, ',', ' ' ) . ' КБ' : '—';
			$after   = (int) ( $item['after'] ?? 0 );
			$sizes   = $after > 0 && $after !== (int) $item['size'] ? $size . ' → ' . number_format( (float) ( $after / 1024 ), 1, ',', ' ' ) . ' КБ' : $size;
			$url     = (string) $item['url'];
			$short   = strlen( $url ) > 74 ? substr( $url, 0, 30 ) . '…' . substr( $url, -40 ) : $url;
			$source  = (string) ( $item['source'] ?? MMF_Advisor::LOCAL );

			echo '<tr data-verdict="' . esc_attr( $verdict ) . '" data-size="' . (int) $item['size'] . '" data-search="' . esc_attr( strtolower( (string) $item['name'] . ' ' . $url ) ) . '">';
			echo '<td><span class="mmf-advisor__name">' . esc_html( (string) $item['name'] ) . '</span> <span class="mmf-advisor__path" title="' . esc_attr( $url ) . '">' . esc_html( $short ) . '</span>';
			echo '<div class="mmf-advisor__badges"><span class="mmf-source mmf-source--' . esc_attr( $source ) . '">' . esc_html( MMF_Advisor::source_label( $source ) ) . '</span></div>';

			if ( ! empty( $item['files'] ) ) {
				echo '<details class="mmf-advisor__files"><summary>' . esc_html( sprintf( __( 'файлов: %d', 'modern-minify' ), count( (array) $item['files'] ) ) ) . '</summary><ul>';

				foreach ( (array) $item['files'] as $file ) {
					echo '<li>' . esc_html( (string) ( $file['name'] ?? $file['url'] ?? '' ) ) . '</li>';
				}

				echo '</ul></details>';
			}
			echo '<p class="mmf-advisor__reason" title="' . esc_attr( $reason ) . '">' . esc_html( $reason ) . '</p></td>';
			echo '<td class="mmf-advisor__size">' . esc_html( $size ) . '</td>';
			echo '<td><span class="mmf-verdict mmf-verdict--' . esc_attr( $verdict ) . '">' . esc_html( MMF_Advisor::verdict_label( $verdict ) ) . '</span><p class="mmf-advisor__reason" title="' . esc_attr( $reason ) . '">' . esc_html( $reason ) . '</p></td>';
			echo '<td>';

			$reason  = (string) ( $item['reason'] ?? '' );
			$risky   = MMF_Advisor::RISKY === $verdict && in_array( $reason, array( 'local', 'inline-after' ), true );
			$blocked = in_array( $reason, array( 'jquery', 'module', 'third-party-critical' ), true );

			if ( MMF_Advisor::CAN === $verdict || $risky ) {
				// The full path, not the file name: `*scripts.min.js*` matches three
// different plugins plus the theme, and our own guard refuses it — the
// advisor must never offer a pattern it will reject one step later.
				$risky_path = (string) wp_parse_url( (string) $item['url'], PHP_URL_PATH );
				$pattern    = $risky ? ( '' !== $risky_path ? '*' . $risky_path . '*' : '' ) : (string) $item['pattern'];

				echo '<label class="mmf-bool mmf-advisor-action"><input type="checkbox" class="mmf-advice-pattern' . ( $risky ? ' mmf-advice-risky' : '' ) . '" name="mmf_advice_patterns[]" value="' . esc_attr( $pattern ) . '" checked="checked" title="' . esc_attr( $pattern ) . '" /> <span class="mmf-bool__text">' . esc_html__( 'Отложить', 'modern-minify' ) . '</span></label>';
			} elseif ( $blocked ) {
				echo '<span class="description">' . esc_html__( 'жёсткое исключение, не откладывается', 'modern-minify' ) . '</span>';
			} elseif ( MMF_Advisor::DONE === $verdict ) {
				// Already deferred or already optimised: nothing to tick, and a
				// bare dash made people wonder whether it was deferred at all.
				echo '<span class="description">' . esc_html__( 'уже применено', 'modern-minify' ) . '</span>';
			} else {
				echo '<span class="description">' . esc_html__( '—', 'modern-minify' ) . '</span>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table></div>';

		echo '<p class="mmf-advisor__actions">';
		echo '<select name="mmf_advice_mode"><option value="delay">' . esc_html__( 'Delay JS', 'modern-minify' ) . '</option><option value="defer">' . esc_html__( 'Не откладывать (defer_skip)', 'modern-minify' ) . '</option></select> ';
		echo '<button type="submit" class="button button-primary" name="mmf_apply_advice" value="1" formaction="' . esc_url( admin_url( 'admin-post.php?action=mmf_apply_advice' ) ) . '"' . ( 0 === (int) $analysis['deferable'] ? ' disabled="disabled"' : '' ) . '>' . esc_html__( 'Отсрочить выбранные', 'modern-minify' ) . '</button>';
		echo '<input type="hidden" name="mmf_advice_nonce" value="' . esc_attr( wp_create_nonce( self::ADVICE_NONCE ) ) . '" />';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Настройки не меняются, пока вы не нажмёте кнопку. Будут добавлены только отмеченные шаблоны.', 'modern-minify' ) . '</p>';
		echo '</section>';
	}

	/**
	 * Styles of the screen: inline, this screen only.
	 */
	public static function inline_css(): string {
		return <<<'CSS'
.mmf-wrap{--mmf-bg:#fff;--mmf-surface:#f6f7f7;--mmf-fg:#1d2327;--mmf-muted:#3c434a;--mmf-border:#c3c4c7;--mmf-accent:var(--wp-admin-theme-color,#2271b1);--mmf-ok-fg:#0a5c1c;--mmf-ok-bg:#edfaef;--mmf-err-fg:#7a0f0f;--mmf-err-bg:#fcf0f1;--mmf-toast-fg:#1d2327;--mmf-toast-bg:#fff;--mmf-chip-bg:#f6f7f7;--mmf-chip-off-fg:#3c434a;--mmf-dot-on:#1a7f37;--mmf-dot-off:#8c8f94;--mmf-popover-bg:#fff;--mmf-popover-fg:#1d2327;--mmf-popover-muted:#3c434a;--mmf-badge-bg:#edfaef;--mmf-badge-fg:#0a5c1c;--mmf-warn-bg:#fcf9e8;--mmf-warn-fg:#1d2327;--mmf-warn-num:#b26200;--mmf-radius:8px;--mmf-gap:8px;--mmf-gap-2:16px;--mmf-gap-3:24px;color:var(--mmf-fg);max-width:1400px}
.mmf-wrap .mmf-tabs{margin-bottom:var(--mmf-gap-2)}
.mmf-wrap .mmf-panel--hidden{display:none !important}
.mmf-wrap .mmf-panel .form-table{table-layout:fixed;width:100%}
.mmf-wrap .mmf-panel .form-table th{width:200px;overflow-wrap:anywhere}
.mmf-wrap .mmf-panel .form-table th,.mmf-wrap .mmf-panel .form-table td{vertical-align:top}
.mmf-wrap .mmf-panel .form-table input[type=text],.mmf-wrap .mmf-panel .form-table input[type=url],.mmf-wrap .mmf-panel .form-table textarea{width:100%;max-width:100%;box-sizing:border-box}
.mmf-wrap .mmf-panel .form-table input[type=number],.mmf-wrap .mmf-panel .form-table select{max-width:100%;box-sizing:border-box}
.mmf-wrap .mmf-panel .form-table textarea{min-height:96px}
.mmf-wrap .mmf-card{background:var(--mmf-bg);border:1px solid var(--mmf-border);border-radius:var(--mmf-radius);padding:var(--mmf-gap-2);margin:0 0 var(--mmf-gap-2)}
.mmf-wrap .mmf-section-title{margin:0 0 var(--mmf-gap);font-size:13px;line-height:1.4;text-transform:uppercase;letter-spacing:.04em;color:var(--mmf-muted);font-weight:600}
.mmf-wrap .mmf-status-head{display:flex;align-items:center;gap:var(--mmf-gap);flex-wrap:wrap;margin:0 0 var(--mmf-gap-2)}
.mmf-wrap .mmf-status-head h2{margin:0}
.mmf-wrap .mmf-status{display:inline-flex;align-items:center;gap:6px;padding:2px 10px;border-radius:999px;background:var(--mmf-ok-bg);color:var(--mmf-ok-fg);margin:0}
.mmf-wrap .mmf-status__dot{width:8px;height:8px;border-radius:50%;background:var(--mmf-dot-on);flex:none}
@media (max-width:900px){.mmf-wrap .mmf-status-head h2{flex:1 0 100%}}
.mmf-wrap .mmf-status--on{background:var(--mmf-ok-bg);color:var(--mmf-ok-fg)}
.mmf-wrap .mmf-last-action__text{margin:0 0 4px;font-size:14px}
.mmf-wrap .mmf-chips{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--mmf-gap-2);margin:0 0 var(--mmf-gap-3)}
.mmf-wrap .mmf-chips-list{display:flex;flex-wrap:wrap;gap:var(--mmf-gap);margin:0;padding:0;list-style:none}
.mmf-wrap .mmf-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border:1px solid var(--mmf-border);border-radius:999px;background:var(--mmf-chip-bg);color:var(--mmf-fg);font-size:13px;line-height:1.6}
.mmf-wrap .mmf-chip__icon{font-size:16px;width:16px;height:16px;color:var(--mmf-muted)}
.mmf-wrap .mmf-chip__dot{width:8px;height:8px;border-radius:50%;background:var(--mmf-dot-off);flex:0 0 auto}
.mmf-wrap .mmf-chip--on .mmf-chip__dot{background:var(--mmf-dot-on)}
.mmf-wrap .mmf-chip--off .mmf-chip__label{color:var(--mmf-chip-off-fg)}
.mmf-wrap .mmf-status-columns{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--mmf-gap-2);align-items:start}
.mmf-wrap .mmf-status-card{margin:0}
.mmf-wrap .mmf-status-card code{word-break:break-all}
.mmf-wrap .mmf-metrics{display:grid;gap:var(--mmf-gap);margin:0}
.mmf-wrap .mmf-metric{display:flex;justify-content:space-between;align-items:baseline;gap:var(--mmf-gap-2);padding-bottom:var(--mmf-gap);border-bottom:1px solid var(--mmf-border)}
.mmf-wrap .mmf-metric:last-child{border-bottom:0;padding-bottom:0}
.mmf-wrap .mmf-metric__label{margin:0;color:var(--mmf-muted);font-size:13px}
.mmf-wrap .mmf-metric__value--warn{color:var(--mmf-warn-num)}
.mmf-wrap .mmf-metric__value{margin:0;font-size:20px;font-weight:600;font-variant-numeric:tabular-nums;color:var(--mmf-fg);text-align:right}
.mmf-wrap .mmf-actions{margin:var(--mmf-gap-2) 0 0}
.mmf-toasts{position:fixed;right:24px;bottom:24px;top:auto;left:auto;z-index:100000;display:flex;flex-direction:column-reverse;gap:8px;width:min(420px,calc(100vw - 48px));max-width:420px;box-sizing:border-box}
.mmf-toasts[hidden]{display:none}
.mmf-toast{display:flex;gap:8px;align-items:flex-start;min-width:0;max-width:100%;box-sizing:border-box;background:var(--mmf-toast-bg,#fff);color:var(--mmf-toast-fg,#1d2327);border:1px solid var(--mmf-border,#c3c4c7);border-radius:var(--mmf-radius,8px);padding:12px 16px;box-shadow:0 8px 24px rgba(0,0,0,.18);font-size:13px;line-height:1.45}
.mmf-toast--success{border-left:4px solid #00a32a}
.mmf-toast--error{border-left:4px solid #d63638}
.mmf-toast__body{flex:1;min-width:0;overflow-wrap:anywhere}
.mmf-toast__text{margin:0;flex:1;overflow-wrap:anywhere;word-break:break-word}
.mmf-toast__count{display:inline-block;margin-left:6px;padding:0 6px;border-radius:999px;background:rgba(0,0,0,.08);font-size:11px;font-weight:600;line-height:1.6;vertical-align:baseline;white-space:nowrap}
.mmf-toast__close{border:0;background:transparent;color:inherit;cursor:pointer;padding:0;line-height:1;flex:0 0 auto}
.mmf-toast__icon{margin-top:2px;flex:0 0 auto}
.mmf-wrap .mmf-alert{display:flex;gap:var(--mmf-gap);align-items:flex-start;border:1px solid var(--mmf-border);border-radius:var(--mmf-radius);padding:12px 16px;margin:0 0 var(--mmf-gap-2);background:var(--mmf-surface);color:var(--mmf-fg)}
.mmf-wrap .mmf-alert--error{border-color:var(--mmf-err-fg);background:var(--mmf-err-bg);color:var(--mmf-err-fg)}
.mmf-wrap .mmf-alert p{margin:0}
.mmf-wrap .mmf-badge{display:inline-block;margin-left:8px;padding:1px 8px;border-radius:999px;background:var(--mmf-badge-bg);color:var(--mmf-badge-fg);font-size:11px;line-height:1.7;vertical-align:baseline;white-space:nowrap}
.mmf-wrap .mmf-verdict{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap}
.mmf-wrap .mmf-verdict--can{background:#edfaef;color:#0a5c1c}
.mmf-wrap .mmf-verdict--risky{background:#fcf9e8;color:#7a4b00}
.mmf-wrap .mmf-verdict--cannot{background:#fcf0f1;color:#7a0f0f}
.mmf-wrap .mmf-verdict--done{background:#f0f0f1;color:#3c434a}
.mmf-wrap .mmf-examples{margin:8px 0 0;padding:10px 12px;background:var(--mmf-surface);border:1px solid var(--mmf-border);border-radius:var(--mmf-radius);max-width:640px}
.mmf-wrap .mmf-examples ul{margin:6px 0 0 18px}
.mmf-wrap .mmf-progress{height:8px;border-radius:4px;background:#f0f0f1;overflow:hidden;margin:8px 0}
.mmf-wrap .mmf-progress__bar{height:100%;width:0;background:var(--wp-admin-theme-color,#2271b1);transition:width .2s}
.mmf-wrap .mmf-progress[hidden]{display:none}
.mmf-wrap .mmf-bulk__actions{display:flex;gap:var(--mmf-gap);flex-wrap:wrap;margin:12px 0 0}
.mmf-wrap .mmf-rules{margin:0 0 0 18px}
.mmf-wrap .mmf-advisor__summary{font-weight:600}
.mmf-wrap .mmf-advisor-filters{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}
.mmf-wrap .mmf-advisor-filters .button{font-size:12px;padding:2px 8px;min-height:26px}
.mmf-wrap .mmf-filter--active{border-color:var(--wp-admin-theme-color);color:var(--wp-admin-theme-color);font-weight:600}
.mmf-wrap .mmf-advisor-scroll{max-width:100%;overflow-x:auto}
.mmf-wrap .mmf-advisor-table{table-layout:fixed;width:100%;min-width:720px;border-collapse:collapse}
.mmf-wrap .mmf-advisor-table th{position:sticky;top:0;z-index:1;background:var(--mmf-bg);white-space:nowrap;padding:6px 8px;text-align:left;border-bottom:1px solid var(--mmf-border)}
.mmf-wrap .mmf-advisor-table th:nth-child(2),.mmf-wrap .mmf-advisor-table td:nth-child(2){width:110px;text-align:right}
.mmf-wrap .mmf-advisor-table th:nth-child(3),.mmf-wrap .mmf-advisor-table td:nth-child(3){width:120px}
.mmf-wrap .mmf-advisor-table th:nth-child(4),.mmf-wrap .mmf-advisor-table td:nth-child(4){width:120px}
.mmf-wrap .mmf-advisor-table td{height:56px;max-height:56px;padding:4px 8px;vertical-align:middle;border-bottom:1px solid var(--mmf-border);line-height:1.25;overflow:hidden}
.mmf-wrap .mmf-advisor-table tbody tr:hover{background:var(--mmf-surface)}
.mmf-wrap .mmf-advisor-table.striped>tbody>:nth-child(odd){background:transparent}
.mmf-wrap .mmf-advisor__name{font-weight:600;white-space:nowrap}
.mmf-wrap .mmf-advisor__path{display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom;color:var(--mmf-muted);font-size:12px}
.mmf-wrap .mmf-advisor__reason{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin:2px 0 0;font-size:12px;color:var(--mmf-muted)}
.mmf-wrap .mmf-advisor__size{font-variant-numeric:tabular-nums;white-space:nowrap;text-align:right}
.mmf-wrap .mmf-advisor-table .mmf-verdict{display:inline-block;width:120px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:center;vertical-align:middle}
.mmf-wrap .mmf-advisor__badges{display:inline-block;margin-left:4px}
.mmf-wrap .mmf-source{display:inline-block;padding:1px 6px;border-radius:999px;background:var(--mmf-surface);border:1px solid var(--mmf-border);color:var(--mmf-muted);font-size:11px;white-space:nowrap}
.mmf-wrap .mmf-source--external{background:#eef4fb;color:#0b4a7a;border-color:#b6d3ea}
.mmf-wrap .mmf-source--external-known{background:var(--mmf-warn-bg);color:#7a4b00;border-color:#e6d9a8}
.mmf-wrap .mmf-actions-wrap{display:inline-flex;gap:4px;align-items:center;position:relative;white-space:nowrap}
.mmf-wrap .mmf-menu{position:absolute;top:100%;right:0;z-index:100;min-width:150px;background:var(--mmf-popover-bg);border:1px solid var(--mmf-border);border-radius:var(--mmf-radius);box-shadow:0 4px 12px rgba(0,0,0,.08);padding:4px;display:flex;flex-direction:column;gap:2px}
.mmf-wrap .mmf-menu[hidden]{display:none !important}
.mmf-wrap .mmf-menu__item{display:block;padding:6px 8px;border-radius:4px;text-decoration:none;color:var(--mmf-popover-fg);white-space:nowrap}
.mmf-wrap .mmf-menu__item:hover,.mmf-wrap .mmf-menu__item:focus{background:var(--mmf-surface)}
.mmf-wrap .mmf-advisor-action{white-space:nowrap;display:inline-flex;gap:4px;align-items:center}
@media (max-width:900px){.mmf-wrap .mmf-advisor-scroll{overflow:visible}.mmf-wrap .mmf-advisor-table{display:block;min-width:0}.mmf-wrap .mmf-advisor-table thead{display:none}.mmf-wrap .mmf-advisor-table tbody,.mmf-wrap .mmf-advisor-table tr,.mmf-wrap .mmf-advisor-table td{display:block;width:auto}.mmf-wrap .mmf-advisor-table tr{border:1px solid var(--mmf-border);border-radius:var(--mmf-radius);padding:8px;margin:0 0 8px}.mmf-wrap .mmf-advisor-table td{border:0;height:auto;max-height:none;padding:2px 0}}
.mmf-wrap .mmf-advisor__summary{font-weight:600}
.mmf-wrap .mmf-advisor-scroll{max-width:100%;overflow-x:auto}
.mmf-wrap .mmf-advisor-table{table-layout:fixed;width:100%;min-width:720px;border-collapse:collapse}
.mmf-wrap .mmf-advisor-table th{position:sticky;top:0;z-index:1;background:var(--mmf-bg);white-space:nowrap;padding:8px 10px;text-align:left;border-bottom:1px solid var(--mmf-border)}
.mmf-wrap .mmf-advisor-table th:nth-child(2),.mmf-wrap .mmf-advisor-table td:nth-child(2){width:90px;text-align:right}
.mmf-wrap .mmf-advisor-table th:nth-child(3),.mmf-wrap .mmf-advisor-table td:nth-child(3){width:130px}
.mmf-wrap .mmf-advisor-table th:nth-child(4),.mmf-wrap .mmf-advisor-table td:nth-child(4){width:120px}
.mmf-wrap .mmf-advisor-table td{padding:6px 10px;vertical-align:top;border-bottom:1px solid var(--mmf-border);line-height:1.3}
.mmf-wrap .mmf-advisor-table tbody tr:hover{background:var(--mmf-surface)}
.mmf-wrap .mmf-advisor-table.striped>tbody>:nth-child(odd){background:transparent}
.mmf-wrap .mmf-advisor__name{font-weight:600;white-space:nowrap}
.mmf-wrap .mmf-advisor__path{display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom;color:var(--mmf-muted);font-size:12px}
.mmf-wrap .mmf-advisor__reason{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:4px 0 0;font-size:12px;color:var(--mmf-muted)}
.mmf-wrap .mmf-advisor__size{font-variant-numeric:tabular-nums;white-space:nowrap}
.mmf-wrap .mmf-advisor-table .mmf-verdict{display:inline-block;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:top}
.mmf-wrap .mmf-advisor__badges{margin-top:2px}
.mmf-wrap .mmf-source{display:inline-block;padding:1px 6px;border-radius:999px;background:var(--mmf-surface);border:1px solid var(--mmf-border);color:var(--mmf-muted);font-size:11px;white-space:nowrap}
.mmf-wrap .mmf-source--external{background:#eef4fb;color:#0b4a7a;border-color:#b6d3ea}
.mmf-wrap .mmf-source--external-known{background:var(--mmf-warn-bg);color:#7a4b00;border-color:#e6d9a8}
.mmf-wrap .mmf-advisor__summary{font-weight:600}
.mmf-wrap .mmf-advisor-scroll{max-width:100%;overflow-x:auto}
.mmf-wrap .mmf-advisor-table{table-layout:fixed;width:100%;min-width:620px}
.mmf-wrap .mmf-advisor-table th{white-space:nowrap}
.mmf-wrap .mmf-advisor-table th:nth-child(2),.mmf-wrap .mmf-advisor-table td:nth-child(2){width:90px;text-align:right}
.mmf-wrap .mmf-advisor-table th:nth-child(3),.mmf-wrap .mmf-advisor-table td:nth-child(3){width:240px}
.mmf-wrap .mmf-advisor-table th:nth-child(4),.mmf-wrap .mmf-advisor-table td:nth-child(4){width:90px}
.mmf-wrap .mmf-advisor-table td:first-child{min-width:120px}
.mmf-wrap .mmf-advisor-table td:first-child code{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mmf-wrap .mmf-advisor-table td:first-child .description{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-all;margin:0}
.mmf-wrap .mmf-advisor-table .mmf-verdict{white-space:nowrap}
.mmf-wrap .mmf-advisor__actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 4px}
.mmf-wrap .mmf-recommend-warning{display:flex;align-items:flex-start;gap:6px;margin:8px 0 0;padding:8px 12px;border-radius:var(--mmf-radius);background:var(--mmf-warn-bg);color:var(--mmf-warn-fg);font-size:13px;max-width:100%;box-sizing:border-box}
.mmf-wrap .mmf-recommend-warning .dashicons{font-size:16px;width:16px;height:16px;margin-top:2px;color:#b26200}
.mmf-wrap .mmf-card .widefat{width:100%;max-width:100%}
.mmf-wrap .mmf-report-table{table-layout:fixed;width:100%;border-collapse:collapse}
.mmf-wrap .mmf-report-table th:first-child,.mmf-wrap .mmf-report-table td:first-child{width:60%}
.mmf-wrap .mmf-report-table th:last-child,.mmf-wrap .mmf-report-table td:last-child{width:40%;text-align:left}
.mmf-wrap .mmf-report-table td{padding:8px 0;border-top:1px solid var(--mmf-border);vertical-align:top;overflow-wrap:anywhere}
.mmf-wrap .mmf-report-table thead th{text-align:left}
.mmf-wrap .mmf-badge--status{margin-left:0}
.mmf-wrap .mmf-bool{display:inline-flex;align-items:center;gap:8px;flex-wrap:nowrap;max-width:100%}
.mmf-wrap .mmf-bool__text{color:var(--mmf-muted);font-size:13px;flex:1 1 auto;min-width:0;overflow-wrap:anywhere}
.mmf-wrap .mmf-advisor-table th:nth-child(4),.mmf-wrap .mmf-advisor-table td:nth-child(4){width:170px}
.mmf-wrap .mmf-advisor-table td{height:auto;max-height:none;overflow:visible}
.mmf-wrap .mmf-advisor__path,.mmf-wrap .mmf-advisor__reason,.mmf-wrap .mmf-advisor-table .mmf-verdict{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mmf-wrap .mmf-actions-wrap{white-space:nowrap;gap:6px}
.mmf-wrap .mmf-help{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;border:1px solid var(--mmf-border);background:var(--mmf-bg);color:var(--mmf-muted);cursor:pointer;margin-left:var(--mmf-gap);vertical-align:middle;padding:0}
.mmf-wrap .mmf-help .dashicons{font-size:18px;width:18px;height:18px}
.mmf-wrap .mmf-popover{position:absolute;max-width:360px;background:var(--mmf-popover-bg);color:var(--mmf-popover-fg);border:1px solid var(--mmf-border);border-radius:var(--mmf-radius);padding:var(--mmf-gap-2);box-shadow:0 4px 12px rgba(0,0,0,.08);z-index:100001;font-size:13px;box-sizing:border-box}
.mmf-wrap .mmf-popover[hidden]{display:none}
.mmf-wrap .mmf-popover::after{content:"";position:absolute;width:8px;height:8px;background:var(--mmf-popover-bg);border:1px solid var(--mmf-border);transform:rotate(45deg);box-sizing:border-box}
.mmf-wrap .mmf-popover--below::after{top:-5px;left:var(--mmf-arrow-x,24px);border-right:0;border-bottom:0}
.mmf-wrap .mmf-popover--above::after{bottom:-5px;left:var(--mmf-arrow-x,24px);border-left:0;border-top:0}
.mmf-wrap .mmf-popover h4{margin:0 0 var(--mmf-gap);color:var(--mmf-popover-muted);font-size:13px;text-transform:uppercase;letter-spacing:.04em}
.mmf-wrap .mmf-popover ul{margin:0 0 var(--mmf-gap-2);padding-left:16px}
.mmf-wrap .mmf-popover li{margin:0 0 4px}
.mmf-wrap .mmf-popover__note{color:var(--mmf-popover-muted);margin:0 0 var(--mmf-gap-2)}
.mmf-wrap .mmf-popover__actions{display:flex;gap:var(--mmf-gap);margin:0}
.mmf-wrap :focus-visible{outline:2px solid var(--mmf-accent);outline-offset:2px}
@media (max-width:1199px){.mmf-wrap .mmf-chips{grid-template-columns:1fr}}
@media (max-width:1024px){.mmf-wrap .mmf-status-columns{display:block;column-count:2;column-gap:var(--mmf-gap-2)}.mmf-wrap .mmf-status-columns .mmf-status-card{break-inside:avoid;margin-bottom:var(--mmf-gap-2);display:inline-block;width:100%}}
@media (max-width:768px){.mmf-wrap .mmf-status-columns{column-count:1}}
@media (max-width:899px){.mmf-wrap .mmf-report-table thead{display:none}.mmf-wrap .mmf-report-table tr{display:block;border-top:1px solid var(--mmf-border);padding:8px 0}.mmf-wrap .mmf-report-table td{display:block;width:auto;border:0;padding:2px 0}.mmf-wrap .mmf-report-table td:first-child,.mmf-wrap .mmf-report-table td:last-child{width:auto}}
@media (max-width:782px){.mmf-toasts{top:auto;left:auto;right:16px;bottom:16px;width:calc(100vw - 32px);max-width:none}}
@media (prefers-reduced-motion: no-preference){.mmf-toast{animation:mmf-toast-in .18s ease-out}}
@keyframes mmf-toast-in{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
@media (max-width:782px){.mmf-wrap .mmf-panel .form-table th{display:block;width:auto;padding:12px 0 4px}.mmf-wrap .mmf-panel .form-table td{display:block;width:auto;padding:0 0 12px}}
@media (max-width:900px){.mmf-wrap .mmf-bool{display:flex;flex-direction:column;align-items:flex-start;gap:4px}}
CSS;
	}

	/**
	 * Pairs of CSS custom properties whose contrast must stay readable.
	 *
	 * The fixture reads the values from `inline_css()` and computes the ratio; a
	 * pair below 4.5:1 fails the build.
	 *
	 * @return array<int,array{fg:string,bg:string,label:string}>
	 */
	public static function contrast_pairs(): array {
		return array(
			array( 'fg' => '--mmf-fg', 'bg' => '--mmf-bg', 'label' => 'body text' ),
			array( 'fg' => '--mmf-muted', 'bg' => '--mmf-bg', 'label' => 'muted text' ),
			array( 'fg' => '--mmf-fg', 'bg' => '--mmf-surface', 'label' => 'text on surface' ),
			array( 'fg' => '--mmf-muted', 'bg' => '--mmf-surface', 'label' => 'muted on surface' ),
			array( 'fg' => '--mmf-ok-fg', 'bg' => '--mmf-ok-bg', 'label' => 'active status' ),
			array( 'fg' => '--mmf-err-fg', 'bg' => '--mmf-err-bg', 'label' => 'error status' ),
			array( 'fg' => '--mmf-toast-fg', 'bg' => '--mmf-toast-bg', 'label' => 'toast' ),
			array( 'fg' => '--mmf-chip-off-fg', 'bg' => '--mmf-chip-bg', 'label' => 'disabled chip' ),
			array( 'fg' => '--mmf-fg', 'bg' => '--mmf-chip-bg', 'label' => 'chip' ),
			array( 'fg' => '--mmf-popover-fg', 'bg' => '--mmf-popover-bg', 'label' => 'popover' ),
			array( 'fg' => '--mmf-popover-muted', 'bg' => '--mmf-popover-bg', 'label' => 'popover note' ),
			array( 'fg' => '--mmf-badge-fg', 'bg' => '--mmf-badge-bg', 'label' => 'recommended badge' ),
			array( 'fg' => '--mmf-warn-fg', 'bg' => '--mmf-warn-bg', 'label' => 'recommendation warning' ),
			array( 'fg' => '--mmf-warn-num', 'bg' => '--mmf-bg', 'label' => 'over-limit counter' ),
		);
	}

	/**
	 * The screen's behaviour: toasts, the recommended-settings popover, the reset
	 * confirmation and the exclusion-table switches.
	 */
	public static function inline_js(): string {
		$js = <<<'JS'
(function(){
var toastBox=document.getElementById("mmf-toasts");
if(toastBox){
  var toasts=toastBox.querySelectorAll(".mmf-toast");
  for(var i=0;i<toasts.length;i++){(function(t){
    var type=t.getAttribute("data-type")||"success",timer=null;
    function close(){if(t.parentNode){t.parentNode.removeChild(t);if(!toastBox.querySelectorAll(".mmf-toast").length){toastBox.setAttribute("hidden","hidden");}}}
    function start(){if("error"===type){return}timer=setTimeout(close,8000);}
    function pause(){if(timer){clearTimeout(timer);timer=null;}}
    var button=t.querySelector(".mmf-toast__close");
    if(button){button.addEventListener("click",close);}
    t.addEventListener("mouseenter",pause);
    t.addEventListener("mouseleave",start);
    t.addEventListener("focusin",pause);
    t.addEventListener("focusout",start);
    start();
  })(toasts[i]);}
  document.addEventListener("keydown",function(event){if("Escape"===event.key){var left=toastBox.querySelectorAll(".mmf-toast");for(var k=0;k<left.length;k++){if(left[k].parentNode){left[k].parentNode.removeChild(left[k]);}}}});
  if(window.history&&history.replaceState){
    try{var url=new URL(window.location.href);["mmf-toast","mmf-toast-nonce","mmf-reset","mmf-recommended"].forEach(function(k){url.searchParams.delete(k);});history.replaceState(null,"",url.toString());}catch(e){}
  }
}
var help=document.querySelector(".mmf-help"),pop=document.getElementById("mmf-recommended-popover");
if(help&&pop){
  var apply=document.querySelector(".mmf-recommended"),raf=0,open=false;
  function place(){
    if(!open){return}
    var r=help.getBoundingClientRect();
    var w=Math.min(360,Math.max(0,window.innerWidth-16));
    pop.style.width=w+"px";
    var h=pop.offsetHeight||0;
    var cx=r.left+r.width/2;
    var left=Math.max(8,Math.min(cx-w/2,window.innerWidth-8-w));
    var below=r.bottom+8,above=r.top-h-8;
    var y=below,cls="mmf-popover--below";
    if(below+h>window.innerHeight-8&&above>=8){y=above;cls="mmf-popover--above";}
    else if(below+h>window.innerHeight-8){y=Math.max(8,window.innerHeight-h-8);}
    pop.classList.remove("mmf-popover--above","mmf-popover--below");
    pop.classList.add(cls);
    pop.style.left=(left+window.scrollX)+"px";
    pop.style.top=(y+window.scrollY)+"px";
    var arrow=Math.max(8,Math.min(cx-left,w-8));
    pop.style.setProperty("--mmf-arrow-x",arrow+"px");
  }
  function schedule(){if(raf){return}raf=window.requestAnimationFrame(function(){raf=0;place();});}
  function openPop(){open=true;pop.hidden=false;help.setAttribute("aria-expanded","true");place();}
  function closePop(focusBack){open=false;pop.hidden=true;help.setAttribute("aria-expanded","false");if(focusBack){help.focus();}}
  help.addEventListener("click",function(event){event.preventDefault();if(open){closePop(false);}else{openPop();}});
  help.addEventListener("mouseenter",function(){if(!open){openPop();}});
  help.addEventListener("focus",function(){if(!open){openPop();}});
  if(apply){apply.addEventListener("click",function(event){if(!open){event.preventDefault();openPop();}});}
  var cancel=pop.querySelector(".mmf-popover__cancel");
  if(cancel){cancel.addEventListener("click",function(){closePop(true);});}
  document.addEventListener("keydown",function(event){if("Escape"===event.key&&open){closePop(true);}});
  document.addEventListener("click",function(event){if(open&&!pop.contains(event.target)&&!help.contains(event.target)){closePop(false);}});
  window.addEventListener("scroll",schedule,{passive:true});
  window.addEventListener("resize",schedule);
}
var advisorFilters=document.querySelectorAll(".mmf-filter");
var advisorRows=document.querySelectorAll(".mmf-advisor-table tbody tr[data-verdict]");
var advisorSearch=document.querySelector(".mmf-advisor-search");
function advisorApply(){var active=document.querySelector(".mmf-filter--active");var filter=active?active.getAttribute("data-filter"):"all";var term=advisorSearch?advisorSearch.value.toLowerCase():"";for(var i=0;i<advisorRows.length;i++){var row=advisorRows[i];var show=("all"===filter||row.getAttribute("data-verdict")===filter)&&(!term||-1!==(row.getAttribute("data-search")||"").indexOf(term));row.hidden=!show;}}
for(var fi=0;fi<advisorFilters.length;fi++){advisorFilters[fi].addEventListener("click",function(){for(var j=0;j<advisorFilters.length;j++){advisorFilters[j].classList.remove("mmf-filter--active");}this.classList.add("mmf-filter--active");advisorApply();});}
if(advisorSearch){advisorSearch.addEventListener("input",advisorApply);}
var adviceButton=document.querySelector("button[name=\"mmf_apply_advice\"]");
if(adviceButton){adviceButton.addEventListener("click",function(event){var risky=document.querySelectorAll(".mmf-advice-risky:checked");if(!risky.length){return}var key="mmfAdviceRisky";try{if(window.sessionStorage.getItem(key)){return}window.sessionStorage.setItem(key,"1");}catch(e){}if(!window.confirm(__MMF_ADVICE_RISKY_PROMPT__)){event.preventDefault();}});}
var asyncLinks=document.querySelectorAll(".mmf-async-css");
for(var ai=0;ai<asyncLinks.length;ai++){asyncLinks[ai].addEventListener("click",function(event){if(!window.confirm(__MMF_ASYNC_CSS_PROMPT__)){event.preventDefault();}});}
var menuButtons=document.querySelectorAll(".mmf-menu-toggle");
function closeMenu(button,focusBack){
  var menu=button.nextElementSibling;
  if(!menu||!menu.classList.contains("mmf-menu")){return;}
  menu.hidden=true;
  menu.style.display="none";
  if(menu.__mmfHome&&menu.__mmfHome.parent){menu.__mmfHome.parent.insertBefore(menu,menu.__mmfHome.next);menu.__mmfHome=null;}
  button.setAttribute("aria-expanded","false");
  if(focusBack){button.focus();}
}
function closeOtherMenus(keep){
  for(var i=0;i<menuButtons.length;i++){if(menuButtons[i]!==keep){closeMenu(menuButtons[i],false);}}
}
function openMenu(button){
  var menu=button.nextElementSibling;
  if(!menu||!menu.classList.contains("mmf-menu")){return;}
  closeOtherMenus(button);
  // The menu is moved to <body> and styled inline: a table cell or the
  // horizontally scrolling wrapper would otherwise clip it, and a stylesheet
  // rule could be overridden by the theme.
  menu.__mmfHome={parent:menu.parentNode,next:menu.nextSibling};
  document.body.appendChild(menu);
  menu.hidden=false;
  menu.style.display="block";
  menu.style.position="fixed";
  menu.style.zIndex="100001";
  menu.style.minWidth="170px";
  menu.style.maxWidth="min(320px,calc(100vw - 24px))";
  menu.style.background="#fff";
  menu.style.color="#1d2327";
  menu.style.border="1px solid #c3c4c7";
  menu.style.borderRadius="4px";
  menu.style.boxShadow="0 4px 12px rgba(0,0,0,.15)";
  menu.style.padding="4px";
  menu.style.textAlign="left";
  var items=menu.querySelectorAll(".mmf-menu__item");
  for(var k=0;k<items.length;k++){items[k].style.display="block";items[k].style.padding="6px 8px";items[k].style.whiteSpace="nowrap";items[k].style.textDecoration="none";items[k].style.color="#1d2327";}
  var r=button.getBoundingClientRect();
  var w=menu.offsetWidth||170;
  var h=menu.offsetHeight||120;
  var openUp=(r.bottom+h+8)>window.innerHeight;
  menu.style.top=(openUp?Math.max(8,r.top-h-4):(r.bottom+4))+"px";
  menu.style.left=Math.max(8,Math.min(r.right-w,window.innerWidth-w-8))+"px";
  button.setAttribute("aria-expanded","true");
}
for(var mi=0;mi<menuButtons.length;mi++){
  menuButtons[mi].addEventListener("click",function(event){
    event.stopPropagation();
    var menu=this.nextElementSibling;
    if(menu&&menu.classList.contains("mmf-menu")&&!menu.hidden){closeMenu(this,false);}else{openMenu(this);}
  });
}
document.addEventListener("click",function(event){
  for(var i=0;i<menuButtons.length;i++){
    var m=menuButtons[i].nextElementSibling;
    if(m&&m.classList.contains("mmf-menu")&&!m.hidden&&!m.contains(event.target)){closeMenu(menuButtons[i],false);}
  }
});
document.addEventListener("keydown",function(event){
  if("Escape"!==event.key){return;}
  for(var i=0;i<menuButtons.length;i++){
    var m=menuButtons[i].nextElementSibling;
    if(m&&m.classList.contains("mmf-menu")&&!m.hidden){closeMenu(menuButtons[i],true);}
  }
});
window.addEventListener("scroll",function(){
  for(var i=0;i<menuButtons.length;i++){
    var m=menuButtons[i].nextElementSibling;
    if(!m||!m.classList.contains("mmf-menu")||m.hidden){continue;}
    var r=menuButtons[i].getBoundingClientRect();
    var h=m.offsetHeight||120;
    var up=(r.bottom+h+8)>window.innerHeight;
    m.style.top=(up?Math.max(8,r.top-h-4):(r.bottom+4))+"px";
  }
},true);
var exampleButtons=document.querySelectorAll(".mmf-examples-toggle");
for(var ei=0;ei<exampleButtons.length;ei++){exampleButtons[ei].addEventListener("click",function(){var box=document.getElementById(this.getAttribute("aria-controls"));if(!box){return}var open=!box.hidden;box.hidden=open;this.setAttribute("aria-expanded",open?"false":"true");});}
var avatarsButton=document.querySelector(".mmf-convert-avatars");
if(avatarsButton){avatarsButton.addEventListener("click",function(event){if(!window.confirm(__MMF_AVATARS_PROMPT__)){event.preventDefault();}});}
var webpDelete=document.querySelector(".mmf-delete-webp");
if(webpDelete){webpDelete.addEventListener("click",function(event){if(!window.confirm(__MMF_WEBP_PROMPT__)){event.preventDefault();}});}
var bulk=document.getElementById("mmf-webp-bulk");
if(bulk){
  var bulkNonce=bulk.getAttribute("data-nonce");
  var bulkProgressBar=document.getElementById("mmf-webp-progressbar");
  var bulkBar=bulkProgressBar?bulkProgressBar.querySelector(".mmf-progress__bar"):null;
  var bulkProgress=document.getElementById("mmf-webp-progress");
  var bulkCounts=document.getElementById("mmf-webp-counts");
  var bulkStart=document.getElementById("mmf-webp-start");
  var bulkPause=document.getElementById("mmf-webp-pause");
  var bulkResume=document.getElementById("mmf-webp-resume");
  var bulkStop=document.getElementById("mmf-webp-stop");
  var bulkBusy=false,bulkTimer=0,bulkFailures=0,bulkLastAction="mmf_webp_batch",bulkBusyChain={action:"",attempts:0},bulkRetryToken=0,bulkToastBox=null;
  function bulkNum(value){return Number(value??0)||0;}
  function bulkToastBoxRef(){
    if(bulkToastBox&&bulkToastBox.parentNode){return bulkToastBox;}
    bulkToastBox=document.getElementById("mmf-toasts");
    if(!bulkToastBox){
      bulkToastBox=document.createElement("div");
      bulkToastBox.className="mmf-toasts";
      bulkToastBox.id="mmf-toasts";
      bulkToastBox.setAttribute("aria-live","polite");
      document.body.appendChild(bulkToastBox);
    }
    bulkToastBox.removeAttribute("hidden");
    return bulkToastBox;
  }
  function bulkToastClearTimer(el){if(el.__mmfTimer){window.clearTimeout(el.__mmfTimer);el.__mmfTimer=0;}}
  function bulkDropToast(el){
    bulkToastClearTimer(el);
    if(el.parentNode){el.parentNode.removeChild(el);}
    if(bulkToastBox&&!bulkToastBox.querySelectorAll(".mmf-toast").length){bulkToastBox.setAttribute("hidden","hidden");}
  }
  function bulkArmToast(el,ms){
    bulkToastClearTimer(el);
    el.__mmfTimer=window.setTimeout(function(){bulkDropToast(el);},ms);
  }
  function bulkToast(text,type,details){
    var isError=type==="error";
    var box=bulkToastBoxRef();
    var visible=box.querySelectorAll(".mmf-toast");
    for(var i=0;i<visible.length;i++){
      if(visible[i].getAttribute("data-text")===text){
        var merged=(Number(visible[i].getAttribute("data-count"))||1)+1;
        visible[i].setAttribute("data-count",String(merged));
        var badge=visible[i].querySelector(".mmf-toast__count");
        if(badge){badge.textContent="×"+merged;badge.hidden=false;}
        bulkArmToast(visible[i],isError?8000:5000);
        return;
      }
    }
    var el=document.createElement("div");
    el.className="mmf-toast "+(isError?"mmf-toast--error":"mmf-toast--success");
    el.setAttribute("role",isError?"alert":"status");
    el.setAttribute("data-text",text);
    el.setAttribute("data-count","1");
    var icon=document.createElement("span");
    icon.className="dashicons "+(isError?"dashicons-warning":"dashicons-yes-alt")+" mmf-toast__icon";
    icon.setAttribute("aria-hidden","true");
    el.appendChild(icon);
    var body=document.createElement("div");body.className="mmf-toast__body";
    var line=document.createElement("p");line.className="mmf-toast__text";
    var title=document.createElement("strong");title.textContent=text;line.appendChild(title);
    var counter=document.createElement("span");counter.className="mmf-toast__count";counter.textContent="×1";counter.hidden=true;line.appendChild(counter);
    body.appendChild(line);
    if(details&&details.hint){var hint=document.createElement("p");hint.className="description";hint.textContent=details.hint;body.appendChild(hint);}
    if(details&&details.debug){var det=document.createElement("details");var sum=document.createElement("summary");sum.textContent="Подробности";det.appendChild(sum);var pre=document.createElement("pre");pre.style.whiteSpace="pre-wrap";pre.style.maxHeight="160px";pre.style.overflow="auto";pre.textContent=details.debug;det.appendChild(pre);body.appendChild(det);}
    var close=document.createElement("button");
    close.type="button";close.className="mmf-toast__close";
    close.setAttribute("aria-label","Закрыть уведомление");
    var closeIcon=document.createElement("span");closeIcon.className="dashicons dashicons-no-alt";closeIcon.setAttribute("aria-hidden","true");close.appendChild(closeIcon);
    close.addEventListener("click",function(){bulkDropToast(el);});
    el.appendChild(body);el.appendChild(close);
    box.insertBefore(el,box.firstChild);
    var all=box.querySelectorAll(".mmf-toast");
    if(all.length>3){bulkDropToast(all[all.length-1]);}
    bulkArmToast(el,isError?8000:5000);
  }
  function bulkDetails(action,res){
    return "action: "+action+"\nstatus: "+(res.status||0)+"\ntime: "+new Date().toISOString()+"\nresponse: "+String(res.raw||res.error||"").slice(0,300);
  }
  function bulkIsBusy(data){return !!(data&&(data.error==="busy"||data.code==="busy"));}
  function bulkRequest(action,retried,token){
    if(typeof token==="undefined"){token=bulkRetryToken;}
    bulkLastAction=action;
    var controller=typeof AbortController!=="undefined"?new AbortController():null;
    var timer=window.setTimeout(function(){if(controller){controller.abort();}},60000);
    var body=new URLSearchParams();body.set("action",action);body.set("nonce",bulkNonce);
    return fetch(window.ajaxurl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString(),signal:controller?controller.signal:undefined}).then(function(response){
      return response.text().then(function(text){return {status:response.status,raw:text};});
    }).then(function(result){
      window.clearTimeout(timer);
      if(token!==bulkRetryToken){return {ok:false,status:0,data:null,raw:"",error:"cancelled",cancelled:true};}
      var data=null;
      try{data=JSON.parse(result.raw);}catch(e){}
      var payload=data&&data.data?data.data:null;
      if(bulkIsBusy(payload)){
        if(bulkBusyChain.action!==action){bulkBusyChain.action=action;bulkBusyChain.attempts=0;}
        bulkBusyChain.attempts++;
        if(bulkBusyChain.attempts>=20){
          bulkBusyChain.action="";bulkBusyChain.attempts=0;
          return {ok:false,status:result.status,data:payload,raw:result.raw,error:"busy_timeout",busy:true};
        }
        return new Promise(function(resolve){window.setTimeout(function(){resolve(bulkRequest(action,retried,token));},500);});
      }
      if(bulkBusyChain.action===action){bulkBusyChain.action="";bulkBusyChain.attempts=0;}
      var code=payload&&payload.code?payload.code:"";
      if(!retried&&(result.status===403||code==="nonce_expired")){return bulkRequest(action,true,token);}
      return {ok:!!(data&&data.success),status:result.status,data:payload,raw:result.raw,error:""};
    }).catch(function(error){
      window.clearTimeout(timer);
      return {ok:false,status:0,data:null,raw:"",error:error&&error.name==="AbortError"?"timeout":"network"};
    });
  }
  function bulkFail(res,action){
    if(res.cancelled){return false;}
    if(res.error==="busy_timeout"||res.busy||bulkIsBusy(res.data)){
      bulkBusy=false;
      if(bulkTimer){window.clearTimeout(bulkTimer);}
      bulkBusyChain.action="";bulkBusyChain.attempts=0;
      bulkToast("Конвертация уже идёт. Подождите или нажмите «Остановить».","error");
      return false;
    }
    bulkFailures++;
    var message="Не удалось выполнить пакет.";
    var hint="Попробуйте ещё раз.";
    if(res.error==="timeout"){message="Сервер не ответил за 60 секунд.";hint="Уменьшите пакет или повторите позже.";}
    else if(res.data&&res.data.message){message=res.data.message;hint=res.data.hint||hint;if(res.data.code==="nonce_expired"||res.status===403){hint="Обновите страницу — истёк токен.";}}
    else if(res.status===500){message="ошибка сервера 500";hint="Смотрите журнал ошибок PHP.";}
    else if(res.status===0){message="Нет связи с сервером.";hint="Проверьте сеть и повторите.";}
    bulkToast(message,"error",{hint:hint,debug:bulkDetails(action,res)});
    if(bulkFailures>=3){bulkBusy=false;if(bulkTimer){window.clearTimeout(bulkTimer);}bulkToast("остановлено после 3 ошибок подряд","error",{hint:"Исправьте причину и запустите снова."});}
    return bulkFailures<3;
  }
  function bulkPaint(state){
    if(!state){return}
    var total=bulkNum(state.total),done=bulkNum(state.done);
    if(bulkProgressBar&&bulkBar){var percent=total?Math.round(done/total*100):0;if(total>0){bulkProgressBar.hidden=false;}bulkBar.style.width=percent+"%";bulkProgressBar.setAttribute("aria-valuenow",String(percent));}
    if(bulkProgress){bulkProgress.textContent=total>0?("обработано "+done+" из "+total):"ещё не запускалось";}
    if(bulkCounts&&state.stats){var s=state.stats;bulkCounts.innerHTML='<span class="mmf-chip">создано '+bulkNum(s.created)+'</span> <span class="mmf-chip">пересоздано '+bulkNum(s.recreated)+'</span> <span class="mmf-chip">уже был '+bulkNum(s.reused)+'</span> <span class="mmf-chip">маленьких '+bulkNum(s.small)+'</span> <span class="mmf-chip">ошибок '+bulkNum(s.error)+'</span>';}
    if(bulkStart){bulkStart.hidden=total>0&&done<total;bulkStart.disabled=total>0&&done<total;}
    if(bulkPause){bulkPause.hidden=!(total>0&&done<total&&!state.paused);}
    if(bulkResume){bulkResume.hidden=!(state.paused&&total>0&&done<total);}
    if(bulkStop){bulkStop.hidden=!(total>0&&done<total);}
  }
  function bulkRun(){
    if(bulkBusy){return}
    bulkBusy=true;
    bulkRequest("mmf_webp_batch",false).then(function(res){
      bulkBusy=false;
      if(!res.ok){if(!bulkFail(res,"mmf_webp_batch")){return}bulkTimer=window.setTimeout(bulkRun,1500);return;}
      bulkFailures=0;var state=res.data;bulkPaint(state);
      if(state.finished){if(bulkProgressBar&&bulkBar){bulkBar.style.width="100%";bulkProgressBar.setAttribute("aria-valuenow","100");window.setTimeout(function(){bulkProgressBar.hidden=true;},3000);}bulkToast("Готово: обработано "+bulkNum(state.total)+" изображений. Рекомендуется сбросить кеш.","success");return;}
      if(state.paused){return}
      bulkTimer=window.setTimeout(bulkRun,60);
    });
  }
  if(bulkStart){bulkStart.addEventListener("click",function(){bulkFailures=0;bulkRetryToken++;bulkBusyChain.action="";bulkBusyChain.attempts=0;bulkRequest("mmf_webp_start",false).then(function(res){if(!res.ok){bulkFail(res,"mmf_webp_start");return;}bulkPaint(res.data);if(bulkNum(res.data.total)===0){bulkToast("Новых изображений нет.","success");return;}bulkRun();});});}
  if(bulkPause){bulkPause.addEventListener("click",function(){if(bulkTimer){window.clearTimeout(bulkTimer);}bulkRequest("mmf_webp_pause",false).then(function(res){if(res.ok){bulkPaint(res.data);}});});}
  if(bulkResume){bulkResume.addEventListener("click",function(){bulkFailures=0;bulkRequest("mmf_webp_resume",false).then(function(res){if(res.ok){bulkPaint(res.data);bulkRun();}});});}
  if(bulkStop){bulkStop.addEventListener("click",function(){if(bulkTimer){window.clearTimeout(bulkTimer);}bulkBusy=false;bulkRetryToken++;bulkBusyChain.action="";bulkBusyChain.attempts=0;if(bulkProgressBar){bulkProgressBar.hidden=true;if(bulkBar){bulkBar.style.width="0%";}bulkProgressBar.setAttribute("aria-valuenow","0");}bulkRequest("mmf_webp_stop",false).then(function(){bulkPaint({ids:[],total:0,done:0,stats:{}});});});}
  bulkRequest("mmf_webp_status",false).then(function(res){if(res.ok){bulkPaint(res.data);if(bulkNum(res.data.total)>bulkNum(res.data.done)&&!res.data.paused){bulkRun();}}});
}var links=document.querySelectorAll(".mmf-reset");
for(var j=0;j<links.length;j++){links[j].addEventListener("click",function(event){if(!window.confirm(__MMF_RESET_PROMPT__)){event.preventDefault();}});}
var addRow=document.querySelector(".mmf-exclude-add"),tpl=document.getElementById("mmf-exclude-template"),rowsBox=document.getElementById("mmf-exclude-rows");
if(addRow&&tpl&&rowsBox){addRow.addEventListener("click",function(){var clone=tpl.content.cloneNode(true),field=clone.querySelector("input");if(field){field.name="mmf_settings[external_exclude][]";}rowsBox.appendChild(clone);});}
if(rowsBox){rowsBox.addEventListener("click",function(event){
  var button=event.target.closest?event.target.closest(".mmf-exclude-remove"):null;
  if(!button){return}
  var row=button.closest("tr");
  if(row){row.parentNode.removeChild(row);}
});}
})();
JS;
		$js = str_replace( '__MMF_RESET_PROMPT__', (string) wp_json_encode( __( 'Очистить весь кеш минифицированных файлов, шрифтов и стилей?', 'modern-minify' ) ), $js );

		$js = str_replace( '__MMF_WEBP_PROMPT__', (string) wp_json_encode( __( 'Удалить все созданные WebP? Оригиналы останутся.', 'modern-minify' ) ), $js );
		$js = str_replace( '__MMF_ADVICE_RISKY_PROMPT__', (string) wp_json_encode( __( 'Откладывать скрипты плагинов рискованно: проверьте страницу после сохранения.', 'modern-minify' ) ), $js );

		$js = str_replace( '__MMF_AVATARS_PROMPT__', (string) wp_json_encode( __( 'Пройти по пользователям и конвертировать их аватары? Это может занять время.', 'modern-minify' ) ), $js );

		return str_replace( '__MMF_ASYNC_CSS_PROMPT__', (string) wp_json_encode( __( 'Асинхронный CSS может дать вспышку неоформленного контента (FOUC). Проверьте первый экран.', 'modern-minify' ) ), $js );
	}
}
