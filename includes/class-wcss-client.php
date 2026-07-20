<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outbound signed HTTP calls to peer sites (spoke -> hub, or hub -> spokes).
 *
 * Public methods only ever enqueue work (fast, local DB write) - they never
 * block the calling request on network I/O. send_now() does the actual HTTP
 * call and is used exclusively by WCSS_Queue's background processing.
 */
class WCSS_Client {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Sign a payload with the shared secret and POST it to a single peer
	 * URL right now. Returns true on success, false on failure. Only ever
	 * called from the queue processor, never from a customer-facing request.
	 */
	public function send_now( $base_url, $payload ) {
		$settings = WCSS_Settings::get();
		if ( empty( $settings['shared_secret'] ) || empty( $base_url ) ) {
			return false;
		}

		$body      = wp_json_encode( $payload );
		$timestamp = time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $settings['shared_secret'] );
		$endpoint  = trailingslashit( $base_url ) . 'wp-json/stock-sync/v1/webhook';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'           => 'application/json',
					'X-Stock-Sync-Timestamp' => (string) $timestamp,
					'X-Stock-Sync-Signature' => $signature,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WCSS_Logger::log( 'Outbound sync failed', array( 'url' => $endpoint, 'error' => $response->get_error_message() ) );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 ) {
			WCSS_Logger::log( 'Outbound sync rejected', array( 'url' => $endpoint, 'code' => $code, 'body' => wp_remote_retrieve_body( $response ) ) );
			return false;
		}

		return true;
	}

	/**
	 * Called by a spoke: report a local stock change to the hub.
	 */
	public function report_to_hub( $sku, $qty, $type ) {
		$settings = WCSS_Settings::get();
		if ( 'spoke' !== $settings['role'] || empty( $settings['hub_url'] ) ) {
			return;
		}

		WCSS_Queue::enqueue( $settings['hub_url'], $sku, $qty, $type );
	}

	/**
	 * Called by the hub: push the authoritative absolute stock value to
	 * every configured spoke.
	 */
	public function broadcast_to_spokes( $sku, $absolute_qty ) {
		$settings = WCSS_Settings::get();
		if ( 'hub' !== $settings['role'] ) {
			return;
		}

		$urls = array_filter( array_map( 'trim', explode( "\n", $settings['spoke_urls'] ) ) );
		foreach ( $urls as $url ) {
			WCSS_Queue::enqueue( $url, $sku, $absolute_qty, 'set' );
		}

		WCSS_Logger::log(
			'Broadcast to spokes queued',
			array( 'sku' => $sku, 'qty' => $absolute_qty, 'spokes' => count( $urls ) )
		);
	}

	/**
	 * Push current stock for every stock-managed product/variation with a
	 * SKU to all spokes. Triggered manually from the settings page.
	 */
	public function resync_all() {
		$settings = WCSS_Settings::get();
		if ( 'hub' !== $settings['role'] ) {
			return 0;
		}

		$count = 0;
		$ids   = wc_get_products( array( 'limit' => -1, 'return' => 'ids', 'type' => array( 'simple', 'variable' ) ) );

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$count += $this->resync_one( $variation_id );
				}
			} else {
				$count += $this->resync_one( $id );
			}
		}

		return $count;
	}

	private function resync_one( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->managing_stock() ) {
			return 0;
		}
		$sku = $product->get_sku();
		if ( ! $sku ) {
			return 0;
		}
		$this->broadcast_to_spokes( $sku, (int) $product->get_stock_quantity() );
		return 1;
	}
}
