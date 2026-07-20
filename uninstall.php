<?php
/**
 * Runs when the plugin is deleted via the WordPress admin.
 * Removes everything the plugin created: settings, log, queue table,
 * scheduled events, sync checkpoints and replay-protection transients.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'wcss_settings' );
delete_option( 'wcss_log' );
delete_option( 'wcss_db_version' );

wp_clear_scheduled_hook( 'wcss_queue_tick' );
wp_clear_scheduled_hook( 'wcss_process_queue' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- removing the plugin's own table on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcss_queue" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bulk cleanup of the plugin's own postmeta on uninstall.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_wcss_last_synced_stock'" );

// Replay-protection transients (and their timeout rows).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bulk cleanup of the plugin's own transients on uninstall.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wcss_seen_%' OR option_name LIKE '_transient_timeout_wcss_seen_%' OR option_name LIKE '_transient_wcss_queue_processing_lock' OR option_name LIKE '_transient_timeout_wcss_queue_processing_lock'" );
