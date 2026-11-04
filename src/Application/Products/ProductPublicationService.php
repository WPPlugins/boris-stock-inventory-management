<?php
/**
 * Change only the WooCommerce publication status of an existing product.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Products;

defined( 'ABSPATH' ) || exit;

// Translated exception messages are data; the receiving UI escapes them for its concrete output context.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class ProductPublicationService {
	/**
	 * Persist Draft or Published through the official WooCommerce CRUD API.
	 *
	 * @return array{product_id:int,status:string}
	 */
	public function set_status( int $product_id, string $status ): array {
		if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
			throw new \RuntimeException( __( 'Choose Draft or Published.', 'boris-stock-inventory-management' ) );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			throw new \RuntimeException( __( 'The WooCommerce product could not be found.', 'boris-stock-inventory-management' ) );
		}
		if ( $status !== $product->get_status() ) {
			$product->set_status( $status );
			if ( $product_id !== (int) $product->save() ) {
				throw new \RuntimeException( __( 'WooCommerce could not save the publication status.', 'boris-stock-inventory-management' ) );
			}
		}
		$fresh = wc_get_product( $product_id );
		if ( ! $fresh || $status !== $fresh->get_status() ) {
			throw new \RuntimeException( __( 'WooCommerce did not confirm the requested publication status.', 'boris-stock-inventory-management' ) );
		}
		return array(
			'product_id' => $product_id,
			'status'     => $status,
		);
	}
}
