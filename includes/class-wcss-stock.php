<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies incoming sync updates to local WooCommerce stock, and resolves
 * a SKU to a local product/variation ID.
 */
class WCSS_Stock {

	/**
	 * Postmeta key holding the stock quantity this site last synced
	 * (sent or received). Used to compute deltas without depending on
	 * WooCommerce's internal before/after hook ordering.
	 */
	const META_LAST_SYNCED = '_wcss_last_synced_stock';

	/**
	 * True while an incoming sync update is being applied locally, so the
	 * outbound hooks in WCSS_Hooks know to skip re-broadcasting (avoids loops).
	 */
	public static $applying_remote = false;

	/**
	 * Resolve a SKU to a local product/variation ID.
	 *
	 * Default matching is by SKU. If sites don't share identical SKUs yet,
	 * a mapping can be supplied via the `wcss_resolve_sku` filter, e.g.:
	 *
	 *   add_filter( 'wcss_resolve_sku', function( $product_id, $sku ) {
	 *       $map = array( 'REMOTE-SKU-1' => 123 );
	 *       return $map[ $sku ] ?? $product_id;
	 *   }, 10, 2 );
	 */
	public static function resolve_sku_to_id( $sku ) {
		$product_id = wc_get_product_id_by_sku( $sku );
		return (int) apply_filters( 'wcss_resolve_sku', $product_id, $sku );
	}

	/**
	 * Apply a delta (can be negative) or an absolute stock value to the
	 * product/variation matching $sku. Returns the resulting absolute
	 * stock quantity, or null if the SKU isn't found on this site or the
	 * product doesn't track stock.
	 *
	 * @param string $sku
	 * @param int    $qty  Delta amount, or absolute value when $type === 'set'.
	 * @param string $type 'delta' or 'set'.
	 */
	public static function apply( $sku, $qty, $type ) {
		$product_id = self::resolve_sku_to_id( $sku );
		if ( ! $product_id ) {
			return null;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->managing_stock() ) {
			return null;
		}

		self::$applying_remote = true;

		if ( 'set' === $type ) {
			wc_update_product_stock( $product, (int) $qty, 'set' );
		} else {
			// Atomic SQL-level increment/decrement, safe under concurrent writes.
			$operation = $qty >= 0 ? 'increase' : 'decrease';
			wc_update_product_stock( $product, abs( (int) $qty ), $operation );
		}

		self::$applying_remote = false;

		$product = wc_get_product( $product_id ); // reload for fresh stock value
		if ( ! $product ) {
			return null;
		}

		$resulting = (int) $product->get_stock_quantity();
		update_post_meta( $product_id, self::META_LAST_SYNCED, $resulting );

		return $resulting;
	}
}
