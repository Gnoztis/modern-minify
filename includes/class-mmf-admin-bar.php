<?php
/**
 * Admin bar: the switch and the test-mode marker.
 *
 * The bar is where an owner looks when a page looks wrong. One link turns the
 * optimisation off for the current URL (`?mmf_off=1`) and the marker explains
 * why a visitor may be seeing something different - test mode only runs for
 * administrators.
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
 * Adds the plugin's nodes to the admin bar.
 */
final class MMF_Admin_Bar {

	/**
	 * Attaches the nodes.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_nodes' ), 100 );
	}

	/**
	 * Adds the nodes to the bar.
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
				'id'    => 'mmf-settings',
				'title' => esc_html__( 'Modern Minify: настройки', 'modern-minify' ),
				'href'  => esc_url( admin_url( 'options-general.php?page=' . MMF_Admin::PAGE ) ),
			)
		);

		// The switch only makes sense on the front end: the admin is never
		// minified, so toggling it there would do nothing.
		if ( ! is_admin() ) {
			$off = MMF_Frontend::emergency_off_requested();

			$bar->add_node(
				array(
					'id'    => 'mmf-toggle',
					'title' => $off
						? esc_html__( 'Modern Minify: включить', 'modern-minify' )
						: esc_html__( 'Modern Minify: выключить', 'modern-minify' ),
					'href'  => $off
						? esc_url( remove_query_arg( 'mmf_off' ) )
						: esc_url( add_query_arg( 'mmf_off', '1' ) ),
					'meta'  => array(
						'title' => $off
							? esc_attr__( 'Вернуть оптимизацию на этой странице', 'modern-minify' )
							: esc_attr__( 'Отдать эту страницу без оптимизации', 'modern-minify' ),
					),
				)
			);
		}

		if ( MMF_Config::get( 'test_mode' ) ) {
			$bar->add_node(
				array(
					'id'    => 'mmf-test-mode',
					'title' => esc_html__( 'Modern Minify: оптимизация только для админа (тест)', 'modern-minify' ),
					'href'  => esc_url( admin_url( 'options-general.php?page=' . MMF_Admin::PAGE . '&tab=advanced' ) ),
				)
			);

			$delay_patterns = MMF_Config::get( 'delay_js', array() );

			if ( is_array( $delay_patterns ) && array() !== $delay_patterns ) {
				$bar->add_node(
					array(
						'id'    => 'mmf-delay',
						'title' => esc_html__( 'Modern Minify: Delay JS активен', 'modern-minify' ),
						'href'  => esc_url( admin_url( 'options-general.php?page=' . MMF_Admin::PAGE . '&tab=javascript' ) ),
					)
				);
			}
		}
	}
}
