<?php
/**
 * WooCommerce cancellation/refund handling without implicit physical returns.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Orders;

use BorisSystems\Stock\Infrastructure\Database\Repository;
use BorisSystems\Stock\Infrastructure\Database\Tables;
use BorisSystems\Stock\Infrastructure\Database\Transaction;

defined( 'ABSPATH' ) || exit;

// Transactional custom-table queries cannot use the object cache; identifiers come only from Tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

final class CancellationService {
	private Repository $repository;
	private Transaction $transaction;

	public function __construct() {
		$this->repository  = new Repository();
		$this->transaction = new Transaction();
	}

	/** Cancel an uncompleted pick list or flag a completed shipment. */
	public function cancelled( int $wc_order_id ): void {
		$this->transaction->run(
			function () use ( $wc_order_id ): void {
				global $wpdb;
				$t = Tables::all();
				$this->transaction->lock_resources( array( 'order:' . $wc_order_id ) );
				$row = $wpdb->get_row(
					$wpdb->prepare( 'SELECT o.*,p.status AS pick_status FROM %i o LEFT JOIN %i p ON p.id=o.picklist_id WHERE o.wc_order_id=%d FOR UPDATE', $t['orders'], $t['picklists'], $wc_order_id )
				);
				if ( ! $row ) {
					return;
				}
				if ( $row->picklist_id && in_array( $row->pick_status, array( 'READY', 'PICKING' ), true ) ) {
					$wpdb->update(
						$t['picklists'],
						array(
							'status'            => 'CANCELLED',
							'reservation_state' => 'RELEASED',
							'cancelled_at_gmt'  => Repository::now(),
						),
						array( 'id' => (int) $row->picklist_id )
					);
					$wpdb->update(
						$t['orders'],
						array(
							'boris_status'   => 'CANCELLED',
							'wc_status'      => 'cancelled',
							'updated_at_gmt' => Repository::now(),
						),
						array( 'id' => (int) $row->id )
					);
					return;
				}
				if ( in_array( $row->pick_status, array( 'COMPLETED', 'SHORTAGE' ), true ) ) {
					$this->repository->problem(
						hash( 'sha256', 'cancelled-after-pick:' . $wc_order_id ),
						'CANCELLED_AFTER_PICK_RETURN_CHECK',
						__( 'The WooCommerce order was cancelled after completion. Stock was not restored; check the physical return.', 'boris-stock-inventory-management' ),
						array( 'wc_order_id' => $wc_order_id ),
						array(
							'boris_order_id' => (int) $row->id,
							'picklist_id'    => (int) $row->picklist_id,
						)
					);
				}
			}
		);
	}

	/** A financial refund never books a physical return. */
	public function refunded( int $wc_order_id, int $refund_id = 0 ): void {
		global $wpdb;
		$t   = Tables::all();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE wc_order_id=%d', $t['orders'], $wc_order_id ) );
		if ( ! $row ) {
			return;
		}
		$this->repository->problem(
			hash( 'sha256', 'refund-return-check:' . $wc_order_id . ':' . $refund_id ),
			'REFUND_IS_NOT_PHYSICAL_RETURN',
			__( 'Refund recorded. No physical stock was restored; process returned goods explicitly.', 'boris-stock-inventory-management' ),
			array(
				'wc_order_id' => $wc_order_id,
				'refund_id'   => $refund_id,
			),
			array(
				'boris_order_id' => (int) $row->id,
				'picklist_id'    => (int) $row->picklist_id,
			)
		);
	}
}
