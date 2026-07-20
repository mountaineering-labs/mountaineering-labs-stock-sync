<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable outbound queue. Enqueue is a plain local DB insert (no network
 * I/O), so it never adds latency to a customer-facing checkout request.
 * A background WP-Cron job does the actual HTTP delivery, with retry and
 * exponential backoff for failed peers.
 *
 * Delivery is triggered two ways:
 *  - a single "run soon" event scheduled right after something is enqueued
 *    (near-immediate delivery under normal conditions), and
 *  - a recurring safety-net tick every couple of minutes, which also picks
 *    up anything still waiting after a failed attempt or backoff delay.
 *
 * Note: WP-Cron only fires on site visits (or a real system cron hitting
 * wp-cron.php). On a very low-traffic site, delivery can lag until the next
 * visitor. For guaranteed timely delivery, point a real server cron at
 * wp-cron.php on each site.
 */
class WCSS_Queue {

	const TABLE         = 'wcss_queue';
	const MAX_ATTEMPTS  = 20;
	const LOCK_KEY      = 'wcss_queue_processing_lock';
	const RUN_SOON_HOOK = 'wcss_process_queue';
	const TICK_HOOK     = 'wcss_queue_tick';

	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function create_table() {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			msg_id VARCHAR(36) NOT NULL DEFAULT '',
			target_url VARCHAR(255) NOT NULL,
			sku VARCHAR(191) NOT NULL,
			type VARCHAR(10) NOT NULL,
			qty INT NOT NULL,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL,
			next_attempt_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY next_attempt_at (next_attempt_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Add a message to the outbound queue and make sure delivery gets
	 * attempted soon. Purely local (DB insert + scheduling a WP-Cron
	 * event) - never performs network I/O itself.
	 */
	public static function enqueue( $target_url, $sku, $qty, $type ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin-owned queue table, no WP API exists for it.
		$wpdb->insert(
			self::table_name(),
			array(
				'msg_id'          => wp_generate_uuid4(),
				'target_url'      => $target_url,
				'sku'             => $sku,
				'type'            => $type,
				'qty'             => (int) $qty,
				'attempts'        => 0,
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! wp_next_scheduled( self::RUN_SOON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::RUN_SOON_HOOK );
		}
	}

	/**
	 * Attempt delivery of everything currently due. Safe to call from
	 * either the "run soon" one-off event or the recurring safety-net tick.
	 */
	public static function process() {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, 1, 60 );

		global $wpdb;
		$table    = self::table_name();
		$settings = WCSS_Settings::get();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin-owned queue table; queue reads must not be cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE next_attempt_at <= %s AND attempts < %d ORDER BY id ASC LIMIT 50',
				$table,
				gmdate( 'Y-m-d H:i:s' ),
				self::MAX_ATTEMPTS
			)
		);

		foreach ( $rows as $row ) {
			// Rows enqueued by a pre-1.1.0 version have no msg_id; assign one
			// so the receiver's replay protection accepts the message.
			if ( empty( $row->msg_id ) ) {
				$row->msg_id = wp_generate_uuid4();
				$wpdb->update( $table, array( 'msg_id' => $row->msg_id ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin-owned queue table.
			}

			$ok = WCSS_Client::instance()->send_now(
				$row->target_url,
				array(
					'msg_id'  => $row->msg_id,
					'type'    => $row->type,
					'sku'     => $row->sku,
					'qty'     => (int) $row->qty,
					'site_id' => $settings['site_id'],
				)
			);

			if ( $ok ) {
				$wpdb->delete( $table, array( 'id' => $row->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin-owned queue table.
				continue;
			}

			$attempts = (int) $row->attempts + 1;

			if ( $attempts >= self::MAX_ATTEMPTS ) {
				WCSS_Logger::log(
					'Gave up on queue item after max attempts - run a full resync from the hub to correct any drift',
					array( 'sku' => $row->sku, 'target' => $row->target_url )
				);
				$wpdb->delete( $table, array( 'id' => $row->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin-owned queue table.
				continue;
			}

			// Simple linear backoff, capped at 30 minutes between attempts.
			$delay_minutes = min( 30, $attempts );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin-owned queue table.
			$wpdb->update(
				$table,
				array(
					'attempts'        => $attempts,
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay_minutes * MINUTE_IN_SECONDS ),
				),
				array( 'id' => $row->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}

		delete_transient( self::LOCK_KEY );
	}

	public static function pending_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin-owned queue table; count must be live.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table_name() ) );
	}
}
