<?php
/**
 * Admin notices of the pipeline.
 *
 * Two conditions must be visible to the site administrator without opening a
 * settings screen:
 *
 *   - another active minifier: the plugin refuses to process the page, so the
 *     admin has to know why nothing is minified;
 *   - file combining is on: on HTTP/2 combining usually makes the page slower,
 *     and staying silent about a setting that hurts is not helpful.
 *
 * The texts live here as data as well, so the settings screen of stage 3 can
 * print the same warning next to the switch instead of duplicating it.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Notices' ) ) {
	return;
}

/**
 * Renders the pipeline warnings in the admin.
 */
final class MMF_Notices {

	/**
	 * Attaches the notices.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
	}

	/**
	 * Warning shown when file combining is enabled.
	 */
	public static function merge_warning(): string {
		return __( 'Включено объединение файлов. На HTTP/2 объединение обычно замедляет сайт: браузер загружает файлы параллельно, и один большой файл только откладывает первую отрисовку. Включайте, только если понимаете, зачем это нужно.', 'modern-minify' );
	}

	/**
	 * Message shown when another minifier is active.
	 */
	public static function conflict_message(): string {
		return __( 'Обнаружен другой активный плагин минификации. Modern Minify не обрабатывает страницы, чтобы двойная минификация не сломала CSS и JS. Отключите минификацию в одном из плагинов.', 'modern-minify' );
	}

	/**
	 * Warning shown next to the whole-page mode switch.
	 */
	public static function rocket_warning(): string {
		return __( 'Rocket-режим откладывает все скрипты страницы, включая инлайн, до окончания разбора DOM. Если что-то сломалось — добавьте файл или id в исключения, выключите режим или проверьте страницу в тестовом режиме.', 'modern-minify' );
	}

	/**
	 * Prints the notices on the screens where the plugin is managed.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_relevant_screen() ) {
			return;
		}

		if ( MMF_Conflicts::is_active() ) {
			echo '<div class="notice notice-error"><p>' . esc_html( self::conflict_message() ) . '</p></div>';

			return;
		}

		if ( MMF_Config::get( 'merge_js' ) || MMF_Config::get( 'merge_css' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( self::merge_warning() ) . '</p></div>';
		}
	}

	/**
	 * Whether the current admin screen is one where these notices belong.
	 */
	private static function is_relevant_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return true;
		}

		$screen = get_current_screen();

		if ( ! is_object( $screen ) || ! isset( $screen->id ) ) {
			return true;
		}

		$id = (string) $screen->id;

		return in_array( $id, array( 'plugins', 'dashboard', 'index' ), true )
			|| str_starts_with( $id, 'settings_page_' );
	}
}
