<?php
/**
 * HPOS-safe customer and line data for warehouse documents.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Picking;

defined( 'ABSPATH' ) || exit;

final class OrderDocumentService {
	/** @return array<string,mixed> */
	public function for_order( int $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array();
		}
		$use_shipping = '' !== trim( (string) $order->get_shipping_address_1() . (string) $order->get_shipping_city() );
		$prefix       = $use_shipping ? 'shipping' : 'billing';
		$get          = static function ( string $field ) use ( $order, $prefix ): string {
			$method = 'get_' . $prefix . '_' . $field;
			return method_exists( $order, $method ) ? (string) $order->{$method}() : '';
		};
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			$lines[] = array(
				'name'     => (string) $item->get_name(),
				'sku'      => $product ? (string) $product->get_sku() : '',
				'quantity' => (int) $item->get_quantity(),
			);
		}
		return array(
			'customer' => trim( $get( 'first_name' ) . ' ' . $get( 'last_name' ) ),
			'company'  => $get( 'company' ),
			'address_1'=> $get( 'address_1' ),
			'address_2'=> $get( 'address_2' ),
			'postcode' => $get( 'postcode' ),
			'city'     => $get( 'city' ),
			'country'  => $get( 'country' ),
			'phone'    => $use_shipping && method_exists( $order, 'get_shipping_phone' ) && '' !== (string) $order->get_shipping_phone() ? (string) $order->get_shipping_phone() : (string) $order->get_billing_phone(),
			'uses_shipping' => $use_shipping,
			'lines'     => $lines,
		);
	}
}
