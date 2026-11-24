<?php
/**
 * Texts of the pipeline warnings.
 *
 * Deliberately no hooks: the plugin's messages live on its own settings screen
 * (or in the admin bar for the test mode marker), never on every admin page.
 * The old global notice hook printed a merge warning on the dashboard, the
 * plugins screen and everywhere else; it is gone.
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
 * Holds the texts used by the settings screen.
 */
final class MMF_Notices {

	/**
	 * Warning shown when external scripts are deferred.
	 */
	public static function defer_external_warning(): string {
		return __( 'Включён defer для внешних скриптов. Чужой код может зависеть от того, что загрузился раньше остальных: включайте осознанно и проверяйте страницу, а при поломке добавьте файл в исключения или выключите настройку.', 'modern-minify' );
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
}
