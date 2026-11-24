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

	/**
	 * Attaches the screen.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_reset' ) );
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

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE,
					'mmf-reset' => $deleted,
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
		echo '<h1>' . esc_html__( 'Modern Minify', 'modern-minify' ) . '</h1>';

		self::render_notices();

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

			self::render_merge_warning( (string) $slug );
			self::render_rocket_notices( (string) $slug );

			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( $fields as $key ) {
				self::render_field( (string) $key );
			}

			echo '</tbody></table>';

			if ( 'advanced' === $slug ) {
				self::render_external_note();
			}

			echo '</div>';
		}
		submit_button();
		echo '</form>';

		if ( 'advanced' === $tab ) {
			self::render_cache_path();
		}

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
			'status'     => array( 'enabled' ),
			'javascript' => array( 'minify_js', 'merge_js', 'async_js', 'defer_js', 'defer_external', 'defer_skip', 'exclude_js', 'move_scripts_to_footer', 'delay_js', 'delay_js_exclude', 'delay_js_timeout', 'rocket_mode', 'rocket_exclude' ),
			'css'        => array( 'minify_css', 'merge_css', 'inline_css', 'inline_css_limit', 'remove_print_css', 'exclude_css' ),
			'fonts'      => array( 'fonts_method', 'fawesome_method' ),
			'settings'   => array( 'minify_html', 'minify_inline', 'disable_when_logged_in', 'remove_generator', 'remove_emoji', 'ver_mode' ),
			'advanced'   => array( 'cache_ttl', 'test_mode', 'debug', 'external_mode', 'external_max_size', 'external_exclude', 'merge_max_files', 'merge_max_size' ),
		);
	}

	/**
	 * Label and description of every field.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function field_meta(): array {
		return array(
			'enabled'                => array(
				__( 'Оптимизация включена', 'modern-minify' ),
				__( 'Выключенный плагин не трогает ни одной страницы.', 'modern-minify' ),
			),
			'minify_js'              => array(
				__( 'Минифицировать JavaScript', 'modern-minify' ),
				__( 'Локальные файлы сжимаются в кеш, адрес в теге заменяется.', 'modern-minify' ),
			),
			'merge_js'               => array(
				__( 'Объединять JS-файлы', 'modern-minify' ),
				__( 'Объединение в этом выпуске не выполняется: настройка сохранена для следующего.', 'modern-minify' ),
			),
			'async_js'               => array(
				__( 'Шаблоны для async', 'modern-minify' ),
				__( 'По одному шаблону на строку. Символ * означает любое количество символов.', 'modern-minify' ),
			),
			'defer_js'               => array(
				__( 'Откладывать все скрипты (defer)', 'modern-minify' ),
				__( 'Кроме уже async, файлов jquery и списка «не откладывать».', 'modern-minify' ),
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
				__( 'Объединение в этом выпуске не выполняется: настройка сохранена для следующего.', 'modern-minify' ),
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
				__( '0 — хранить до ручной очистки.', 'modern-minify' ),
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

		echo '<tr><th scope="row">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '</th><td>';

		if ( 'bool' === $type ) {
			// A hidden zero makes "unchecked" post a value: otherwise the key is
			// absent from the request and the sanitiser would restore the default.
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
			echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . ( $value ? ' checked="checked"' : '' ) . ' />';
		} elseif ( 'list' === $type ) {
			$lines = is_array( $value ) ? implode( "\n", array_map( 'strval', $value ) ) : '';
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="5" class="large-text code">' . esc_textarea( $lines ) . '</textarea>';
		} elseif ( 'int' === $type ) {
			printf(
				'<input type="number" id="%s" name="%s" value="%d" min="%d" max="%d" class="small-text" />',
				esc_attr( $id ),
				esc_attr( $name ),
				(int) $value,
				(int) $field['min'],
				(int) $field['max']
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

		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Prints the status panel: counters, cache state and the reset link.
	 */
	private static function render_status(): void {
		$stats = MMF_Stats::all();
		$cache = MMF_Cache::stats();

		echo '<h2>' . esc_html__( 'Состояние', 'modern-minify' ) . '</h2>';
		echo '<p class="mmf-status ' . ( MMF_Config::is_enabled() ? 'mmf-status--on' : 'mmf-status--off' ) . '">';
		echo MMF_Config::is_enabled() ? esc_html__( 'оптимизация включена', 'modern-minify' ) : esc_html__( 'оптимизация выключена', 'modern-minify' );
		echo '</p>';

		echo '<table class="widefat striped mmf-status-table"><tbody>';

		$rows = array(
			__( 'Страниц обработано', 'modern-minify' )   => (string) (int) $stats['pages'],
			__( 'Файлов минифицировано', 'modern-minify' ) => (string) (int) $stats['files'],
			__( 'Инлайн-блоков сжато', 'modern-minify' )   => (string) (int) $stats['inline'],
			__( 'Скриптов отложено', 'modern-minify' )     => (string) (int) $stats['defer'],
			__( 'Скриптов async', 'modern-minify' )        => (string) (int) $stats['async'],
			__( 'Скриптов перенесено в подвал', 'modern-minify' ) => (string) (int) $stats['moved'],
			__( 'Скриптов отложено, всего', 'modern-minify' ) => (string) (int) $stats['delayed'],
			__( 'Отложено на последней учтённой странице', 'modern-minify' ) => (string) (int) $stats['last_delayed'],
			__( 'Отложено Rocket-режимом', 'modern-minify' ) => (string) (int) $stats['rocket'],
			__( 'Пакетов собрано', 'modern-minify' )      => (string) (int) $stats['bundles'],
			__( 'Файлов в пакетах', 'modern-minify' )     => (string) (int) $stats['bundled_files'],
			__( 'Запросов сэкономлено', 'modern-minify' ) => (string) max( 0, (int) $stats['bundled_files'] - (int) $stats['bundles'] ),
			__( 'Размер пакетов, КБ', 'modern-minify' )    => (string) (int) round( (int) $stats['bundle_bytes'] / 1024 ),
			__( 'Внешних файлов локализовано', 'modern-minify' ) => (string) (int) $stats['external_localized'],
			__( 'Внешних оставлено', 'modern-minify' )     => (string) (int) $stats['external_kept'],
			__( 'Внешних не удалось скачать', 'modern-minify' ) => (string) (int) $stats['external_failed'],
			__( 'Внешних тегов удалено', 'modern-minify' ) => (string) (int) $stats['external_removed'],
			__( 'Предупреждений о шрифтах', 'modern-minify' ) => (string) (int) $stats['font_warnings'],
			__( 'Ошибок обработки', 'modern-minify' )      => (string) (int) $stats['failed'],
			__( 'Файлов в кеше', 'modern-minify' )         => (string) (int) $cache['files'],
			__( 'Размер кеша', 'modern-minify' )           => size_format( (int) $cache['size'] ),
			__( 'Кеш обновлён', 'modern-minify' )          => $cache['updated'] > 0 ? (string) wp_date( 'Y-m-d H:i:s', (int) $cache['updated'] ) : __( 'ещё не было', 'modern-minify' ),
		);

		foreach ( $rows as $label => $number ) {
			echo '<tr><td>' . esc_html( (string) $label ) . '</td><td>' . esc_html( $number ) . '</td></tr>';
		}

		echo '</tbody></table>';

		self::render_external_report( $stats );

		printf(
			'<p class="description">%s %s</p>',
			esc_html__( 'Счётчики накопительные и записываются не чаще раза в минуту.', 'modern-minify' ),
			esc_html__( 'Кеш можно очистить в любой момент.', 'modern-minify' )
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
	}

	/**
	 * Prints the external hosts of the last counted page.
	 *
	 * @param array<string,mixed> $stats Counters.
	 */
	private static function render_external_report( array $stats ): void {
		$report = isset( $stats['last_external'] ) && is_array( $stats['last_external'] ) ? $stats['last_external'] : array();

		echo '<h2>' . esc_html__( 'Внешние хосты на последней учтённой странице', 'modern-minify' ) . '</h2>';

		if ( array() === $report ) {
			echo '<p class="description">' . esc_html__( 'Внешних файлов не найдено.', 'modern-minify' ) . '</p>';

			return;
		}

		$labels = array(
			'localized' => __( 'локализован', 'modern-minify' ),
			'excluded'  => __( 'оставлен внешним (исключение)', 'modern-minify' ),
			'kept'      => __( 'оставлен внешним (режим)', 'modern-minify' ),
			'failed'    => __( 'не удалось скачать', 'modern-minify' ),
			'removed'   => __( 'тег удалён', 'modern-minify' ),
		);

		echo '<table class="widefat striped mmf-status-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Хост', 'modern-minify' ) . '</th>';
		echo '<th>' . esc_html__( 'Что сделано', 'modern-minify' ) . '</th>';
		echo '</tr></thead><tbody>';

		$lines = array();

		foreach ( $report as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['host'], $entry['status'] ) ) {
				continue;
			}

			$host   = (string) $entry['host'];
			$status = (string) $entry['status'];
			$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;

			echo '<tr><td><code>' . esc_html( $host ) . '</code></td><td>' . esc_html( $label ) . '</td></tr>';

			$lines[] = $host . ' — ' . $label;
		}

		echo '</tbody></table>';

		echo '<p class="description">' . esc_html__( 'Этот список можно скопировать и прислать как диагностику:', 'modern-minify' ) . '</p>';
		echo '<textarea readonly rows="' . esc_attr( (string) max( 2, count( $lines ) ) ) . '" class="large-text code">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea>';
	}

	/**
	 * Prints the built-in exclusion list on the advanced tab.
	 */
	private static function render_external_note(): void {
		echo '<h2>' . esc_html__( 'Внешние файлы: встроенный список исключений', 'modern-minify' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Эти хосты не локализуются: их скрипты сверяют домен в Referer или Origin, и локальная копия ломает виджет. Список расширяется фильтром mmf_external_excluded.', 'modern-minify' ) . '</p>';
		echo '<ul class="mmf-excludes">';

		foreach ( MMF_External::DEFAULT_EXCLUDES as $needle ) {
			echo '<li><code>' . esc_html( (string) $needle ) . '</code></li>';
		}

		echo '</ul>';
	}

	/**
	 * Prints the cache path on the advanced tab.
	 */
	private static function render_cache_path(): void {
		$cache = MMF_Cache::stats();

		echo '<h2>' . esc_html__( 'Кеш на диске', 'modern-minify' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><td>' . esc_html__( 'Каталог', 'modern-minify' ) . '</td><td><code>' . esc_html( (string) $cache['dir'] ) . '</code></td></tr>';
		echo '<tr><td>' . esc_html__( 'URL', 'modern-minify' ) . '</td><td><code>' . esc_html( (string) $cache['url'] ) . '</code></td></tr>';
		echo '<tr><td>' . esc_html__( 'Доступен для записи', 'modern-minify' ) . '</td><td>' . ( $cache['writable'] ? esc_html__( 'да', 'modern-minify' ) : esc_html__( 'нет', 'modern-minify' ) ) . '</td></tr>';
		echo '</tbody></table>';
	}

	/**
	 * Prints the plugin's notices above the form.
	 */
	private static function render_notices(): void {
		if ( isset( $_GET['settings-updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Настройки сохранены. Кеш очищен, файлы будут собраны заново.', 'modern-minify' ) . '</p></div>';
		}

		if ( isset( $_GET['mmf-reset'] ) ) {
			$deleted = (int) sanitize_text_field( wp_unslash( (string) $_GET['mmf-reset'] ) );

			/* translators: %d: number of deleted files. */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Кеш очищен, удалено файлов: %d.', 'modern-minify' ), $deleted ) ) . '</p></div>';
		}

		if ( MMF_Conflicts::is_active() ) {
			echo '<div class="notice notice-error"><p>' . esc_html( MMF_Notices::conflict_message() ) . '</p></div>';
		}

		if ( MMF_Config::get( 'test_mode' ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Тестовый режим: оптимизация применяется только к администраторам, посетители видят исходную страницу.', 'modern-minify' ) . '</p></div>';
		}
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
	 * Prints the HTTP/2 warning on the tabs where combining can be enabled.
	 *
	 * @param string $slug Tab slug.
	 */
	private static function render_merge_warning( string $slug ): void {
		if ( 'javascript' === $slug && MMF_Config::get( 'merge_js' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( MMF_Notices::merge_warning() ) . '</p></div>';
		}

		if ( 'css' === $slug && MMF_Config::get( 'merge_css' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( MMF_Notices::merge_warning() ) . '</p></div>';
		}

		if ( 'javascript' === $slug && MMF_Config::get( 'defer_external' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( MMF_Notices::defer_external_warning() ) . '</p></div>';
		}
	}

	/**
	 * Styles of the screen: inline, this screen only.
	 */
	private static function inline_css(): string {
		return '.mmf-wrap .mmf-tabs{margin-bottom:16px}'
			. '.mmf-wrap .mmf-panel .form-table th{width:260px}'
			. '.mmf-wrap .mmf-status{display:inline-block;padding:3px 10px;border-radius:3px;background:#f0f0f1}'
			. '.mmf-wrap .mmf-status--on{background:#edfaef;color:#0a6b1f}'
			. '.mmf-wrap .mmf-status--off{background:#fcf0f1;color:#8a1111}'
			. '.mmf-wrap .mmf-status-table{margin:12px 0;max-width:640px}'
			. '.mmf-wrap .mmf-excludes{display:flex;flex-wrap:wrap;gap:4px 18px;margin:8px 0 0;padding:0;list-style:none}'
			. '.mmf-wrap .mmf-actions{margin-top:16px}';
	}

	/**
	 * The one behaviour the screen needs: confirm before wiping the cache.
	 */
	private static function inline_js(): string {
		$prompt = (string) wp_json_encode( __( 'Очистить весь кеш минифицированных файлов, шрифтов и стилей?', 'modern-minify' ) );

		return '(function(){var links=document.querySelectorAll(".mmf-reset");'
			. 'for(var i=0;i<links.length;i++){links[i].addEventListener("click",function(event){'
			. 'if(!window.confirm(' . $prompt . ')){event.preventDefault();}});}})();';
	}
}
