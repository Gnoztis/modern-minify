<?php
/**
 * Removes the plugin data on uninstall.
 *
 * The plugin keeps one option, the update-check transients of the bundled
 * updater and the minified files in `uploads/mmf/`. The files are removed here
 * and not on deactivation: pages cached with their URLs keep working while the
 * plugin is off, and uninstalling is the point where nothing may stay behind.
 *
 * @package ModernMinify
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-mmf-cache.php';

if ( ! function_exists( 'mmf_uninstall_site' ) ) {
	/**
	 * Deletes the plugin data of the current site.
	 */
	function mmf_uninstall_site(): void {
		MMF_Cache::deactivate();

		delete_option( 'mmf_settings' );

		delete_transient( 'mmf_github_release' );
		delete_transient( 'mmf_github_release_error' );

		MMF_Cache::uninstall();
	}
}

if ( is_multisite() ) {
	$mmf_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $mmf_sites as $mmf_site_id ) {
		switch_to_blog( (int) $mmf_site_id );
		mmf_uninstall_site();
		restore_current_blog();
	}
} else {
	mmf_uninstall_site();
}
