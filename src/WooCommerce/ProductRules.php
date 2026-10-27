<?php
/**
 * Supported WooCommerce product rules for version 1.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\WooCommerce;

use BorisSystems\Stock\Domain\Inventory\Identifier;

defined( 'ABSPATH' ) || exit;

final class ProductRules {
	/**
	 * @param \WC_Product $product WooCommerce product.
	 * @return array{supported:bool,reasons:array<int,string>,sku_normalized:string,stock_quantity:int}
	 */
	public function inspect( $product ): array {
		$reasons = array();
		if ( ! $product || ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variation' ) ) ) {
			$reasons[] = __( 'Only simple products and variations with their own stock are supported.', 'boris-stock-inventory-management' );
		}
		if ( $product && ! $product->managing_stock() ) {
			$reasons[] = __( 'WooCommerce stock management must be enabled for this product.', 'boris-stock-inventory-management' );
		}
		if ( $product && $product->is_type( 'variation' ) && method_exists( $product, 'get_stock_managed_by_id' ) && (int) $product->get_stock_managed_by_id() !== (int) $product->get_id() ) {
			$reasons[] = __( 'Variations that inherit stock from the parent product are not supported.', 'boris-stock-inventory-management' );
		}
		if ( $product && 'no' !== $product->get_backorders() ) {
			$reasons[] = __( 'WooCommerce backorders must be set to Do not allow.', 'boris-stock-inventory-management' );
		}
		$sku = $product ? (string) $product->get_sku() : '';
		try {
			$sku_normalized = Identifier::normalize( $sku );
		} catch ( \RuntimeException $exception ) {
			$sku_normalized = '';
			$reasons[]      = __( 'A unique SKU is required.', 'boris-stock-inventory-management' );
		}
		$raw_quantity = $product ? $product->get_stock_quantity() : null;
		if ( null === $raw_quantity || 0.0 !== fmod( (float) $raw_quantity, 1.0 ) || 0 > (int) $raw_quantity ) {
			$reasons[] = __( 'Stock must be a non-negative integer.', 'boris-stock-inventory-management' );
		}

		return array(
			'supported'      => empty( $reasons ),
			'reasons'        => $reasons,
			'sku_normalized' => $sku_normalized,
			'stock_quantity' => (int) ( $raw_quantity ?? 0 ),
		);
	}
}
