<?php
/**
 * Plugin Name: Mountaineering Labs Multi-Store Stock Sync for WooCommerce
 * Plugin URI: https://github.com/mountaineering-labs/mountaineering-labs-stock-sync
 * Description: Central stock management across multiple WooCommerce installations (hub/spoke, SKU-based matching).
 * Version: 1.4.1
 * Author: Mountaineering Labs
 * Author URI: https://mountaineering.no
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mountaineering-labs-stock-sync
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCSS_VERSION', '1.4.1' );
define( 'WCSS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCSS_URL', plugin_dir_url( __FILE__ ) );

require_once WCSS_DIR . 'includes/class-wcss-logger.php';
require_once WCSS_DIR . 'includes/class-wcss-settings.php';
require_once WCSS_DIR . 'includes/class-wcss-queue.php';
require_once WCSS_DIR . 'includes/class-wcss-stock.php';
require_once WCSS_DIR . 'includes/class-wcss-client.php';
require_once WCSS_DIR . 'includes/class-wcss-hooks.php';
require_once WCSS_DIR . 'includes/class-wcss-rest.php';

/**
 * Bootstraps the plugin once WooCommerce is confirmed active.
 */
function wcss_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Mountaineering Labs Stock Sync requires WooCommerce to be active.', 'mountaineering-labs-stock-sync' ) . '</p></div>';
			}
		);
		return;
	}

	WCSS_Settings::instance();
	WCSS_Hooks::instance();
	WCSS_Rest::instance();

	// Run schema/cron migrations after upgrades too. Activation hooks do NOT
	// fire when a plugin is updated in place ("replace current with uploaded"),
	// so a version marker in the DB is the only reliable trigger.
	if ( get_option( 'wcss_db_version' ) !== WCSS_VERSION ) {
		WCSS_Queue::create_table();
		if ( ! wp_next_scheduled( WCSS_Queue::TICK_HOOK ) ) {
			wp_schedule_event( time(), 'wcss_two_minutes', WCSS_Queue::TICK_HOOK );
		}
		update_option( 'wcss_db_version', WCSS_VERSION );
	}
}
add_action( 'plugins_loaded', 'wcss_init' );

add_action( WCSS_Queue::RUN_SOON_HOOK, array( 'WCSS_Queue', 'process' ) );
add_action( WCSS_Queue::TICK_HOOK, array( 'WCSS_Queue', 'process' ) );

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['wcss_two_minutes'] = array(
			'interval' => 2 * MINUTE_IN_SECONDS,
			'display'  => 'Every 2 minutes (Mountaineering Labs Stock Sync)',
		);
		return $schedules;
	}
);

register_activation_hook(
	__FILE__,
	function () {
		WCSS_Queue::create_table();
		if ( ! wp_next_scheduled( WCSS_Queue::TICK_HOOK ) ) {
			wp_schedule_event( time(), 'wcss_two_minutes', WCSS_Queue::TICK_HOOK );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( WCSS_Queue::TICK_HOOK );
		wp_clear_scheduled_hook( WCSS_Queue::RUN_SOON_HOOK );
	}
);
