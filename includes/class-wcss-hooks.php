<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens for local WooCommerce stock changes (order sales/restocks and
 * manual edits alike) and forwards them to the hub, or - if this site IS
 * the hub - broadcasts them straight out to the spokes.
 *
 * Deltas are computed against a per-product "last synced" checkpoint
 * (WCSS_Stock::META_LAST_SYNCED) rather than by hooking WooCommerce's
 * internal order-processing actions directly. This keeps the logic
 * identical for order-driven and manually-edited stock changes, and
 * avoids depending on hook-firing order that differs across WooCommerce
 * versions.
 */
class WCSS_Hooks {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_set' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_set' ) );
	}

	public function on_stock_set( $product ) {
		// This change is us applying an update that just came in from a
		// peer site - don't report it straight back out again.
		if ( WCSS_Stock::$applying_remote ) {
			return;
		}

		if ( ! $product instanceof WC_Product || ! $product->managing_stock() ) {
			return;
		}

		$sku = $product->get_sku();
		if ( ! $sku ) {
			return;
		}

		$product_id  = $product->get_id();
		$new_qty     = (int) $product->get_stock_quantity();
		$last_synced = get_post_meta( $product_id, WCSS_Stock::META_LAST_SYNCED, true );
		$settings    = WCSS_Settings::get();

		if ( '' === $last_synced ) {
			// No baseline yet for this product on this site (just installed,
			// or a brand-new product). Send this first observed value as an
			// absolute "set" rather than computing a delta against nothing -
			// that way the very first genuine edit still propagates instead
			// of silently being swallowed to "establish a baseline".
			update_post_meta( $product_id, WCSS_Stock::META_LAST_SYNCED, $new_qty );

			if ( 'hub' === $settings['role'] ) {
				WCSS_Client::instance()->broadcast_to_spokes( $sku, $new_qty );
				WCSS_Logger::log( 'First observation on hub, broadcast as absolute value', array( 'sku' => $sku, 'new_qty' => $new_qty ) );
			} else {
				WCSS_Client::instance()->report_to_hub( $sku, $new_qty, 'set' );
				WCSS_Logger::log( 'First observation, reported to hub as absolute value', array( 'sku' => $sku, 'new_qty' => $new_qty ) );
			}
			return;
		}

		$delta = $new_qty - (int) $last_synced;
		if ( 0 === $delta ) {
			return;
		}

		update_post_meta( $product_id, WCSS_Stock::META_LAST_SYNCED, $new_qty );

		if ( 'hub' === $settings['role'] ) {
			WCSS_Client::instance()->broadcast_to_spokes( $sku, $new_qty );
			WCSS_Logger::log( 'Local change on hub, broadcast', array( 'sku' => $sku, 'delta' => $delta, 'new_qty' => $new_qty ) );
		} else {
			WCSS_Client::instance()->report_to_hub( $sku, $delta, 'delta' );
			WCSS_Logger::log( 'Local change reported to hub', array( 'sku' => $sku, 'delta' => $delta ) );
		}
	}
}
