<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom REST endpoint peers use to talk to each other, authenticated with
 * an HMAC signature over a shared secret (not the general WooCommerce REST
 * API, so no per-site consumer keys are needed).
 */
class WCSS_Rest {

	const MAX_CLOCK_SKEW = 300; // seconds

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'stock-sync/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'verify_signature' ),
			)
		);
	}

	public function verify_signature( WP_REST_Request $request ) {
		$settings = WCSS_Settings::get();
		if ( empty( $settings['shared_secret'] ) ) {
			return false;
		}

		$timestamp = $request->get_header( 'x-stock-sync-timestamp' );
		$signature = $request->get_header( 'x-stock-sync-signature' );
		if ( ! $timestamp || ! $signature ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > self::MAX_CLOCK_SKEW ) {
			return false;
		}

		$body     = $request->get_body();
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $settings['shared_secret'] );

		return hash_equals( $expected, $signature );
	}

	public function handle_webhook( WP_REST_Request $request ) {
		$data = $request->get_json_params();

		$sku    = isset( $data['sku'] ) ? sanitize_text_field( $data['sku'] ) : '';
		$type   = isset( $data['type'] ) && in_array( $data['type'], array( 'delta', 'set' ), true ) ? $data['type'] : '';
		$qty    = isset( $data['qty'] ) ? intval( $data['qty'] ) : null;
		$msg_id = isset( $data['msg_id'] ) ? sanitize_text_field( $data['msg_id'] ) : '';

		if ( '' === $sku || '' === $type || null === $qty || '' === $msg_id ) {
			return new WP_REST_Response( array( 'error' => 'invalid payload' ), 400 );
		}

		// Replay/duplicate protection: each message carries a unique id. If
		// we've already processed it (a replayed request, or a legitimate
		// retry of a delivery whose response got lost after we applied it),
		// acknowledge without applying it again.
		$seen_key = 'wcss_seen_' . md5( $msg_id );
		if ( get_transient( $seen_key ) ) {
			return new WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
		}
		set_transient( $seen_key, 1, DAY_IN_SECONDS );

		$resulting = WCSS_Stock::apply( $sku, $qty, $type );

		if ( null === $resulting ) {
			WCSS_Logger::log( 'Webhook received, but SKU not found / not stock-managed here', array( 'sku' => $sku ) );
			return new WP_REST_Response( array( 'status' => 'ignored' ), 200 );
		}

		WCSS_Logger::log(
			'Webhook received and applied',
			array(
				'sku'     => $sku,
				'type'    => $type,
				'qty'     => $qty,
				'new_qty' => $resulting,
				'from'    => $data['site_id'] ?? '',
			)
		);

		$settings = WCSS_Settings::get();
		if ( 'hub' === $settings['role'] ) {
			WCSS_Client::instance()->broadcast_to_spokes( $sku, $resulting );
		}

		return new WP_REST_Response(
			array(
				'status'         => 'ok',
				'stock_quantity' => $resulting,
			),
			200
		);
	}
}
