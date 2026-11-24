<?php
/**
 * Admin bar: one menu with its actions.
 *
 * The bar is where an owner looks when a page looks wrong, so everything lives
 * under a single node: settings, turning the optimisation off for the current
 * URL (`?mmf_off=1`) and the test-mode switch. A marker inside the menu says
 * which modes are active, so a page that looks different from what visitors see
 * is explained without opening the settings.
 *
 * @package ModernMinify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'MMF_Admin_Bar' ) ) {
	return;
}

/**
 * Adds the plugin's node to the admin bar.
 */
final class MMF_Admin_Bar {

	/**
	 * Attaches the node.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_nodes' ), 100 );
	}

	/**
	 * Adds the parent node and its children.
	 *
	 * @param mixed $bar Admin bar instance.
	 */
	public static function add_nodes( $bar ): void {
		if ( ! is_object( $bar ) || ! method_exists( $bar, 'add_node' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'mmf',
				'title' => esc_html__( 'Modern Minify', 'modern-minify' ),
				'href'  => esc_url( admin_url( 'options-general.php?page=' . MMF_Admin::PAGE ) ),
			)
		);

		$bar->add_node(
			array(
				'id'     => 'mmf-settings',
				'parent' => 'mmf',
				'title'  => esc_html__( 'Настройки', 'modern-minify' ),
				'href'   => esc_url( admin_url( 'options-general.php?page=' . MMF_Admin::PAGE ) ),
			)
		);

		// The switch only makes sense on the front end: the admin is never
		// minified, so toggling it there would do nothing.
		if ( ! is_admin() ) {
			$off = MMF_Frontend::emergency_off_requested();

			$bar->add_node(
				array(
					'id'     => 'mmf-toggle',
					'parent' => 'mmf',
					'title'  => $off
						? esc_html__( 'Включить на этой странице', 'modern-minify' )
						: esc_html__( 'Выключить на этой странице', 'modern-minify' ),
					'href'   => $off
						? esc_url( remove_query_arg( 'mmf_off' ) )
						: esc_url( add_query_arg( 'mmf_off', '1' ) ),
				)
			);
		}

		$test_mode = (bool) MMF_Config::get( 'test_mode' );

		$bar->add_node(
			array(
				'id'     => 'mmf-test-mode',
				'parent' => 'mmf',
				'title'  => $test_mode
					? esc_html__( 'Тестовый режим: вкл', 'modern-minify' )
					: esc_html__( 'Тестовый режим: выкл', 'modern-minify' ),
				'href'   => esc_url(
					wp_nonce_url(
						add_query_arg( 'mmf-toggle-test', '1', admin_url( 'options-general.php?page=' . MMF_Admin::PAGE ) ),
						MMF_Admin::TEST_NONCE
					)
				),
			)
		);

		// Informational children: what the page is actually doing, without
		// opening the settings screen.
		$delay_patterns = MMF_Config::get( 'delay_js', array() );

		if ( is_array( $delay_patterns ) && array() !== $delay_patterns ) {
			$bar->add_node(
				array(
					'id'     => 'mmf-delay',
					'parent' => 'mmf',
					'title'  => esc_html__( 'Delay JS активен', 'modern-minify' ),
					'href'   => esc_url( admin_url( 'options-general.php?page=' . MMF_Admin::PAGE . '&tab=javascript' ) ),
				)
			);
		}

		if ( $test_mode ) {
			$bar->add_node(
				array(
					'id'     => 'mmf-test-marker',
					'parent' => 'mmf',
					'title'  => esc_html__( 'Страница видна так только администратору', 'modern-minify' ),
					'href'   => esc_url( admin_url( 'options-general.php?page=' . MMF_Admin::PAGE . '&tab=advanced' ) ),
				)
			);
		}
	}
}
