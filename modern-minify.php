<?php
/**
 * Plugin Name:       Modern Minify
 * Description:       Минификация CSS, JS и HTML, асинхронная загрузка файлов и обработка Google Fonts.
 * Version:           0.4.0
 * Requires at least: 7.0
 * Tested up to:      7.1
 * Requires PHP:      8.0
 * Author:            Gnostis
 * Author URI:        https://github.com/Gnoztis
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/Gnoztis/modern-minify
 * Text Domain:       modern-minify
 * Domain Path:       /languages
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MMF_VERSION', '0.4.0' );
define( 'MMF_PLUGIN_FILE', __FILE__ );
define( 'MMF_DIR', plugin_dir_path( __FILE__ ) );
define( 'MMF_URL', plugin_dir_url( __FILE__ ) );

require_once MMF_DIR . 'includes/class-mmf-config.php';
require_once MMF_DIR . 'includes/class-mmf-cache.php';
require_once MMF_DIR . 'includes/class-mmf-minifier.php';
require_once MMF_DIR . 'includes/class-mmf-html.php';
require_once MMF_DIR . 'includes/class-mmf-assets.php';
require_once MMF_DIR . 'includes/class-mmf-loader.php';
require_once MMF_DIR . 'includes/class-mmf-fonts.php';
require_once MMF_DIR . 'includes/class-mmf-stats.php';
require_once MMF_DIR . 'includes/class-mmf-conflicts.php';
require_once MMF_DIR . 'includes/class-mmf-notices.php';
require_once MMF_DIR . 'includes/class-mmf-frontend.php';
require_once MMF_DIR . 'includes/class-mmf-version-hide.php';
require_once MMF_DIR . 'includes/class-mmf-admin.php';
require_once MMF_DIR . 'includes/class-mmf-admin-bar.php';
require_once MMF_DIR . 'includes/class-mmf-github-updater.php';

/**
 * Registers the modules of the plugin.
 *
 * Runs on `plugins_loaded` so every other plugin has already declared itself
 * when the output pipeline starts looking at their assets.
 */
function mmf_bootstrap(): void {
	MMF_Cache::register_hooks();
	MMF_Admin::register_hooks();
	MMF_Admin_Bar::register_hooks();
	MMF_Notices::register_hooks();
	MMF_Frontend::instance()->register_hooks();
	MMF_Version_Hide::register_hooks();

	if ( class_exists( 'MMF_GitHub_Updater' ) ) {
		( new MMF_GitHub_Updater() )->register_hooks();
	}
}
add_action( 'plugins_loaded', 'mmf_bootstrap' );

/**
 * Loads the translations shipped in `languages/`.
 */
function mmf_load_textdomain(): void {
	load_plugin_textdomain( 'modern-minify', false, dirname( plugin_basename( MMF_PLUGIN_FILE ) ) . '/languages' );
}
add_action( 'init', 'mmf_load_textdomain' );

/**
 * Registers the option with the Settings API, so a saving screen (stage 3) is
 * always filtered by `MMF_Config::sanitize()`.
 */
add_action( 'admin_init', array( 'MMF_Config', 'register_settings' ) );

register_activation_hook( MMF_PLUGIN_FILE, array( 'MMF_Cache', 'activate' ) );
register_deactivation_hook( MMF_PLUGIN_FILE, array( 'MMF_Cache', 'deactivate' ) );
