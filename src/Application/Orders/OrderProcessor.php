<?php
/**
 * Idempotent WooCommerce order registration and complete allocation.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Orders;

use BorisSystems\Stock\Application\Products\ProductService;
use BorisSystems\Stock\Domain\Exception\DomainException;
use BorisSystems\Stock\Domain\Inventory\AllocationEngine;
use BorisSystems\Stock\Domain\Inventory\Quantity;
use BorisSystems\Stock\Infrastructure\Database\Repository;
use BorisSystems\Stock\Infrastructure\Database\Tables;
use BorisSystems\Stock\Infrastructure\Database\Transaction;

defined( 'ABSPATH' ) || exit;

// Allocation relies on uncached InnoDB locks; custom table identifiers are internal and data values are prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

final class OrderProcessor {
	private Repository $repository;
	private Transaction $transaction;
	private ProductService $products;
	private AllocationEngine $allocator;

	public function __construct( ?Repository $repository = null, ?Transaction $transaction = null ) {
		$this->repository  = $repository ?? new Repository();
		$this->transaction = $transaction ?? new Transaction();
		$this->products    = new ProductService( $this->repository );
		$this->allocator   = new AllocationEngine();
	}

	/** Register and allocate an order; safe to call repeatedly. */
	public function process( int $wc_order_id ): void {
		$order = wc_get_order( $wc_order_id );
		if ( ! $order ) {
			return;
		}

		$lines     = $this->read_lines( $order );
		$resources = array( 'order:' . $wc_order_id );
		foreach ( $lines as $line ) {
			$resources[] = 'product:' . $line['wc_product_id'];
		}

		$picklist_id = $this->transaction->run(
			function () use ( $order, $lines, $resources ): int {
				$this->transaction->lock_resources( $resources );
				return $this->process_locked( $order, $lines );
			}
		);
		if ( 0 < $picklist_id ) {
			do_action( 'boris_stock_picklist_created', $picklist_id );
		}
	}

	/** @param \WC_Order $order WooCommerce order. */
	private function process_locked( $order, array $lines ): int {
		global $wpdb;
		$t      = Tables::all();
		$now    = Repository::now();
		$wc_id  = (int) $order->get_id();
		$exists = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE wc_order_id=%d FOR UPDATE', $t['orders'], $wc_id )
		);
		if ( $exists && $exists->picklist_id && in_array( $exists->boris_status, array( 'PICKABLE', 'PICKING', 'COMPLETED', 'BACKORDER' ), true ) ) {
			return 0;
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (wc_order_id,order_number_snapshot,wc_status,boris_status,source_modified_at_gmt,payload_hash,last_processed_at_gmt,created_at_gmt,updated_at_gmt)
				VALUES (%d,%s,%s,'PENDING',%s,%s,%s,%s,%s)
				ON DUPLICATE KEY UPDATE order_number_snapshot=VALUES(order_number_snapshot),wc_status=VALUES(wc_status),source_modified_at_gmt=VALUES(source_modified_at_gmt),payload_hash=VALUES(payload_hash),last_processed_at_gmt=VALUES(last_processed_at_gmt),updated_at_gmt=VALUES(updated_at_gmt),row_version=row_version+1",
				$t['orders'],
				$wc_id,
				(string) $order->get_order_number(),
				(string) $order->get_status(),
				$order->get_date_modified() ? $order->get_date_modified()->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : $now,
				hash( 'sha256', wp_json_encode( $lines ) ),
				$now,
				$now,
				$now
			)
		);
		$boris_order_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE wc_order_id=%d', $t['orders'], $wc_id ) );

		$problem_messages = array();
		if ( empty( $lines ) ) {
			$problem_messages[] = __( 'The order contains no processable physical WooCommerce order lines.', 'boris-stock-inventory-management' );
		}
		foreach ( $lines as &$line ) {
			$line['boris_product_id'] = $this->products->synchronize( $line['wc_product_id'] );
			if ( 0 === $line['boris_product_id'] ) {
				/* translators: %s is a product SKU. */
				$problem_messages[] = sprintf( __( 'Product %s has an unsupported configuration.', 'boris-stock-inventory-management' ), $line['sku'] );
			}
			if ( $line['reduced'] < $line['quantity'] ) {
				/* translators: %s is a product SKU. */
				$problem_messages[] = sprintf( __( 'The WooCommerce stock change for %s was not confirmed reliably.', 'boris-stock-inventory-management' ), $line['sku'] );
			}
			$this->save_line( $boris_order_id, $wc_id, $line );
		}
		unset( $line );
		if ( $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) {
			$wpdb->update(
				$t['orders'],
				array(
					'boris_status'   => 'CANCELLED',
					'updated_at_gmt' => $now,
				),
				array( 'id' => $boris_order_id )
			);
			return 0;
		}

		$allocation_sets = array();
		$stock_cache     = array();
		if ( empty( $problem_messages ) ) {
			foreach ( $lines as $line ) {
				$product_id = $line['boris_product_id'];
				if ( ! isset( $stock_cache[ $product_id ] ) ) {
					$stock_cache[ $product_id ] = $this->repository->available_rows( $product_id, true );
				}
				try {
					$allocated                           = $this->allocator->allocate( $line['quantity'], $stock_cache[ $product_id ] );
					$allocation_sets[ $line['item_id'] ] = $allocated;
					foreach ( $allocated as $allocation ) {
						foreach ( $stock_cache[ $product_id ] as &$row ) {
							if ( (int) $row['id'] === $allocation['location_stock_id'] ) {
								$row['reserved'] = (int) $row['reserved'] + $allocation['quantity'];
							}
						}
						unset( $row );
					}
				} catch ( DomainException $exception ) {
					/* translators: %s is a product SKU. */
					$problem_messages[] = sprintf( __( 'Insufficient administrative location stock for %s.', 'boris-stock-inventory-management' ), $line['sku'] );
					break;
				}
			}
		}

		$problem_key = hash( 'sha256', 'order-allocation:' . $wc_id );
		if ( ! empty( $problem_messages ) ) {
			$wpdb->update(
				$t['orders'],
				array(
					'boris_status'   => 'PROBLEM',
					'updated_at_gmt' => $now,
				),
				array( 'id' => $boris_order_id )
			);
			$this->repository->problem(
				$problem_key,
				'INSUFFICIENT_OR_UNCONFIRMED_STOCK',
				implode( ' ', $problem_messages ),
				array( 'wc_order_id' => $wc_id ),
				array( 'boris_order_id' => $boris_order_id )
			);
			return 0;
		}

		$this->repository->resolve_problem( $problem_key );
		$wpdb->insert(
			$t['picklists'],
			array(
				'boris_order_id' => $boris_order_id,
				'display_number' => 'BS-' . $order->get_order_number(),
				'status'         => 'READY',
				'created_at_gmt' => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);
		$picklist_id = (int) $wpdb->insert_id;
		foreach ( $lines as $line ) {
			$order_line_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE wc_order_id=%d AND wc_order_item_id=%d', $t['order_lines'], $wc_id, $line['item_id'] ) );
			foreach ( $allocation_sets[ $line['item_id'] ] as $allocation ) {
				$wpdb->insert(
					$t['picklist_lines'],
					array(
						'picklist_id'            => $picklist_id,
						'order_line_id'          => $order_line_id,
						'location_stock_id'      => $allocation['location_stock_id'],
						'location_id'            => $allocation['location_id'],
						'boris_product_id'       => $line['boris_product_id'],
						'location_code_snapshot' => $allocation['location_code'],
						'sku_snapshot'           => $line['sku'],
						'name_snapshot'          => $line['name'],
						'required_quantity'      => $allocation['quantity'],
						'reserved_quantity'      => $allocation['quantity'],
					),
					array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d' )
				);
			}
		}
		$wpdb->update(
			$t['orders'],
			array(
				'boris_status'   => 'PICKABLE',
				'picklist_id'    => $picklist_id,
				'updated_at_gmt' => $now,
			),
			array( 'id' => $boris_order_id )
		);
		return $picklist_id;
	}

	/** @param \WC_Order $order WooCommerce order. */
	private function read_lines( $order ): array {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$product       = $item->get_product();
			$order_item_id = 0 < (int) $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
			$product_id    = $product ? (int) $product->get_id() : $order_item_id;
			$quantity      = Quantity::positive( $item->get_quantity() );
			$lines[]       = array(
				'item_id'       => (int) $item_id,
				'wc_product_id' => $product_id,
				'variation_id'  => (int) $item->get_variation_id(),
				'sku'           => $product ? (string) $product->get_sku() : '',
				'name'          => (string) $item->get_name(),
				'quantity'      => $quantity,
				'reduced'       => (int) $item->get_meta( '_reduced_stock', true ),
			);
		}
		return $lines;
	}

	private function save_line( int $boris_order_id, int $wc_order_id, array $line ): void {
		global $wpdb;
		$t   = Tables::all();
		$now = Repository::now();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (boris_order_id,wc_order_id,wc_order_item_id,wc_product_id,wc_variation_id,stock_owner_id,boris_product_id,sku_snapshot,sku_normalized,name_snapshot,ordered_quantity,wc_reduced_quantity,wc_stock_state,wc_stock_observed_at_gmt,created_at_gmt,updated_at_gmt)
				VALUES (%d,%d,%d,%d,%d,%d,%d,%s,%s,%s,%d,%d,%s,%s,%s,%s)
				ON DUPLICATE KEY UPDATE boris_product_id=VALUES(boris_product_id),ordered_quantity=VALUES(ordered_quantity),wc_reduced_quantity=VALUES(wc_reduced_quantity),wc_stock_state=VALUES(wc_stock_state),wc_stock_observed_at_gmt=VALUES(wc_stock_observed_at_gmt),updated_at_gmt=VALUES(updated_at_gmt)",
				$t['order_lines'],
				$boris_order_id,
				$wc_order_id,
				$line['item_id'],
				$line['wc_product_id'],
				$line['variation_id'],
				$line['wc_product_id'],
				$line['boris_product_id'],
				$line['sku'],
				strtoupper( trim( $line['sku'] ) ),
				$line['name'],
				$line['quantity'],
				$line['reduced'],
				$line['reduced'] >= $line['quantity'] ? 'REDUCED' : 'UNCONFIRMED',
				$now,
				$now,
				$now
			)
		);
	}
}
