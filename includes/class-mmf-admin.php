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
		add_action( 'admin_init', array( __CLASS__, 'handle_delete_webp' ) );
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
			printf(
				'<div class="mmf-panel" id="mmf-panel-%s"%s>',
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
				self::render_advisor();
			}

			echo '</div>';
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
			'css'        => array( 'minify_css', 'merge_css', 'inline_css', 'inline_css_limit', 'remove_print_css', 'exclude_css' ),
			'fonts'      => array( 'fonts_method', 'fawesome_method', 'inline_fonts_css' ),
			'external'   => array(),
			'settings'   => array( 'minify_html', 'minify_inline', 'disable_when_logged_in', 'remove_generator', 'remove_emoji', 'ver_mode', 'disable_gravatar', 'avatar_webp', 'avatar_quality', 'image_webp', 'image_quality_jpeg', 'image_quality_png', 'image_png_lossless' ),
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
			'merge_css' => __( 'CSS блокирует отрисовку, поэтому объединение сокращает критический путь', 'modern-minify' ),
		);

		if ( isset( $hints[ $key ] ) ) {
			echo '<p class="description mmf-field-hint">' . esc_html( $hints[ $key ] ) . '</p>';
		}

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

		self::status_group(
			__( 'Картинки WebP', 'modern-minify' ),
			array(
				__( 'Создано WebP', 'modern-minify' )        => (string) (int) $stats['image_created'],
				__( 'Пересоздано WebP', 'modern-minify' )     => (string) (int) $stats['image_recreated'],
				__( 'WebP уже был', 'modern-minify' )         => (string) (int) $stats['image_reused'],
				__( 'Исходник уже WebP', 'modern-minify' )    => (string) (int) $stats['image_webp_existing'],
				__( 'Отдано WebP', 'modern-minify' )         => (string) (int) $stats['image_served'],
				__( 'Сэкономлено, КБ', 'modern-minify' )     => (string) (int) round( (int) $stats['image_saved'] / 1024 ),
				__( 'Ошибок конвертации', 'modern-minify' )  => (string) (int) $stats['image_errors'],
				__( 'Пропущено: меньше 10 КБ', 'modern-minify' ) => (string) (int) $stats['image_small'],
				__( 'Пропущено: WebP больше', 'modern-minify' ) => (string) (int) $stats['image_bigger'],
				__( 'Конвертер', 'modern-minify' )           => MMF_Images::method() !== '' ? (string) ( $methods[ MMF_Images::method() ] ?? MMF_Images::method() ) : __( 'недоступен', 'modern-minify' ),
			)
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

		echo '<p class="mmf-actions"><a class="button mmf-reset" href="' . esc_url( $reset_url ) . '">' . esc_html__( 'Сбросить кеш', 'modern-minify' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $webp_url ) . '">' . esc_html__( 'Удалить все WebP', 'modern-minify' ) . '</a></p>';

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
			'webp-deleted' => __( 'Сгенерированные WebP удалены.', 'modern-minify' ),
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

		// Only checked patterns are added, and an existing entry is never duplicated.
		$current = (array) MMF_Config::get( $target, array() );
		$merged  = array_values( array_unique( array_merge( $current, $added ) ) );
		$new     = count( $merged ) - count( $current );

		if ( $new > 0 ) {
			MMF_Config::update( array( $target => $merged ) );
			$deleted = MMF_Cache::delete_all();
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
	 * Prints the read-only advice block on the JavaScript tab.
	 */
	private static function render_advisor(): void {
		$stats     = MMF_Stats::all();
		$resources = isset( $stats['last_resources'] ) && is_array( $stats['last_resources'] ) ? $stats['last_resources'] : array();
		$analysis  = MMF_Advisor::analyse( $resources );

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

		echo '<div class="mmf-advisor-scroll"><table class="widefat striped mmf-report-table mmf-advisor-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Ресурс', 'modern-minify' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Размер', 'modern-minify' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Вердикт', 'modern-minify' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Отложить', 'modern-minify' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $analysis['items'] as $item ) {
			$verdict = (string) $item['verdict'];

			echo '<tr><td><code>' . esc_html( (string) $item['name'] ) . '</code><br /><span class="description">' . esc_html( (string) $item['url'] ) . '</span></td>';
			echo '<td>' . esc_html( $item['size'] > 0 ? size_format( (int) $item['size'] ) : '—' ) . '</td>';
			echo '<td><span class="mmf-verdict mmf-verdict--' . esc_attr( $verdict ) . '">' . esc_html( MMF_Advisor::verdict_label( $verdict ) ) . '</span><br /><span class="description">' . esc_html( MMF_Advisor::reason_label( (string) $item['reason'] ) ) . '</span></td>';
			echo '<td>';

			if ( MMF_Advisor::CAN === $verdict ) {
				echo '<label class="mmf-bool"><input type="checkbox" name="mmf_advice_patterns[]" value="' . esc_attr( (string) $item['pattern'] ) . '" checked="checked" /> <span class="mmf-bool__text description">' . esc_html__( 'добавить', 'modern-minify' ) . '</span></label>';
			} else {
				echo '—';
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
.mmf-wrap .mmf-toasts{position:fixed;top:48px;right:var(--mmf-gap-2);z-index:100000;display:flex;flex-direction:column;gap:var(--mmf-gap);max-width:360px}
.mmf-wrap .mmf-toast{display:flex;gap:var(--mmf-gap);align-items:flex-start;background:var(--mmf-toast-bg);color:var(--mmf-toast-fg);border:1px solid var(--mmf-border);border-radius:var(--mmf-radius);padding:12px 16px;box-shadow:0 8px 24px rgba(0,0,0,.12)}
.mmf-wrap .mmf-toast--success{border-left:4px solid #00a32a}
.mmf-wrap .mmf-toast--error{border-left:4px solid #d63638}
.mmf-wrap .mmf-toast__text{margin:0;flex:1}
.mmf-wrap .mmf-toast__close{border:0;background:transparent;color:inherit;cursor:pointer;padding:0;line-height:1}
.mmf-wrap .mmf-toast__icon{margin-top:2px}
.mmf-wrap .mmf-alert{display:flex;gap:var(--mmf-gap);align-items:flex-start;border:1px solid var(--mmf-border);border-radius:var(--mmf-radius);padding:12px 16px;margin:0 0 var(--mmf-gap-2);background:var(--mmf-surface);color:var(--mmf-fg)}
.mmf-wrap .mmf-alert--error{border-color:var(--mmf-err-fg);background:var(--mmf-err-bg);color:var(--mmf-err-fg)}
.mmf-wrap .mmf-alert p{margin:0}
.mmf-wrap .mmf-badge{display:inline-block;margin-left:8px;padding:1px 8px;border-radius:999px;background:var(--mmf-badge-bg);color:var(--mmf-badge-fg);font-size:11px;line-height:1.7;vertical-align:baseline;white-space:nowrap}
.mmf-wrap .mmf-verdict{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap}
.mmf-wrap .mmf-verdict--can{background:#edfaef;color:#0a5c1c}
.mmf-wrap .mmf-verdict--risky{background:#fcf9e8;color:#7a4b00}
.mmf-wrap .mmf-verdict--cannot{background:#fcf0f1;color:#7a0f0f}
.mmf-wrap .mmf-verdict--done{background:#f0f0f1;color:#3c434a}
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
@media (max-width:782px){.mmf-wrap .mmf-toasts{top:62px;left:var(--mmf-gap-2);right:var(--mmf-gap-2);max-width:none}}
@media (prefers-reduced-motion: no-preference){.mmf-wrap .mmf-toast{animation:mmf-toast-in .18s ease-out}}
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
var links=document.querySelectorAll(".mmf-reset");
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
		return str_replace( '__MMF_RESET_PROMPT__', (string) wp_json_encode( __( 'Очистить весь кеш минифицированных файлов, шрифтов и стилей?', 'modern-minify' ) ), $js );
	}
}
