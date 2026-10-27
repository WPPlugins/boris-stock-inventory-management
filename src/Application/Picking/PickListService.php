<?php
/**
 * Pick-list state changes and physical stock completion.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Picking;

use BorisSystems\Stock\Domain\Operations\IdempotencyKey;
use BorisSystems\Stock\Domain\Picking\PickResult;
use BorisSystems\Stock\Infrastructure\Database\Repository;
use BorisSystems\Stock\Infrastructure\Database\Tables;
use BorisSystems\Stock\Infrastructure\Database\Transaction;

defined( 'ABSPATH' ) || exit;

// Pick completion uses uncached locked rows; identifiers come only from Tables and values are prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
// Translated exception messages are data; the receiving UI escapes them for its concrete output context.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class PickListService {
	private Repository $repository;
	private Transaction $transaction;

	public function __construct( ?Repository $repository = null, ?Transaction $transaction = null ) {
		$this->repository  = $repository ?? new Repository();
		$this->transaction = $transaction ?? new Transaction();
	}

	/**
	 * Start a ready pick list exactly once without changing stock quantities.
	 *
	 * Repeated requests for an already-started list are intentionally a no-op.
	 *
	 * @param int $picklist_id Pick list.
	 */
	public function start( int $picklist_id ): void {
		global $wpdb;
		if ( 1 > $picklist_id ) {
			throw new \InvalidArgumentException( __( 'Invalid pick list.', 'boris-stock-inventory-management' ) );
		}
		$t       = Tables::all();
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status='PICKING',opened_at_gmt=COALESCE(opened_at_gmt,%s),row_version=row_version+1 WHERE id=%d AND status='READY'",
				$t['picklists'],
				Repository::now(),
				$picklist_id
			)
		);
		if ( 1 === $changed ) {
			return;
		}

		$status = $wpdb->get_var(
			$wpdb->prepare( 'SELECT status FROM %i WHERE id=%d', $t['picklists'], $picklist_id )
		);
		if ( 'PICKING' === $status ) {
			return;
		}
		if ( null === $status ) {
			throw new \RuntimeException( __( 'Pick list not found.', 'boris-stock-inventory-management' ) );
		}
		throw new \RuntimeException( __( 'This pick list cannot be started from its current status.', 'boris-stock-inventory-management' ) );
	}

	/**
	 * Complete all lines exactly once. WooCommerce product stock is never touched.
	 *
	 * @param int            $picklist_id Pick list.
	 * @param array<int,int> $picked_by_line Actual quantities keyed by picklist line ID.
	 * @param int            $user_id Actor.
	 */
	public function complete( int $picklist_id, array $picked_by_line, int $user_id ): void {
		$this->transaction->run(
			function () use ( $picklist_id, $picked_by_line, $user_id ): void {
				global $wpdb;
				$t = Tables::all();
				$this->transaction->lock_resources( array( 'picklist:' . $picklist_id ) );
				$picklist = $wpdb->get_row(
					$wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', $t['picklists'], $picklist_id )
				);
				if ( ! $picklist ) {
					throw new \RuntimeException( __( 'Pick list not found.', 'boris-stock-inventory-management' ) );
				}
				if ( in_array( $picklist->status, array( 'COMPLETED', 'SHORTAGE', 'CANCELLED' ), true ) ) {
					return;
				}
				if ( 'PICKING' !== $picklist->status ) {
					throw new \RuntimeException( __( 'Start the pick list before completing it.', 'boris-stock-inventory-management' ) );
				}
				$lines      = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT pl.*,ls.quantity_on_hand FROM %i pl INNER JOIN %i ls ON ls.id=pl.location_stock_id WHERE pl.picklist_id=%d ORDER BY pl.id FOR UPDATE',
						$t['picklist_lines'],
						$t['location_stock'],
						$picklist_id
					),
					ARRAY_A
				);
				$calculated = array();
				foreach ( $lines as $line ) {
					if ( ! array_key_exists( (int) $line['id'], $picked_by_line ) ) {
						throw new \RuntimeException( __( 'Every pick line must be confirmed.', 'boris-stock-inventory-management' ) );
					}
					$calculated[ (int) $line['id'] ] = PickResult::calculate( (int) $line['reserved_quantity'], $picked_by_line[ (int) $line['id'] ] );
					if ( (int) $line['quantity_on_hand'] < (int) $line['reserved_quantity'] ) {
						throw new \RuntimeException( __( 'Administrative stock changed during picking.', 'boris-stock-inventory-management' ) );
					}
				}

				$shortages = array();
				foreach ( $lines as $line ) {
					$result     = $calculated[ (int) $line['id'] ];
					$before     = (int) $line['quantity_on_hand'];
					$after_pick = $before - $result['picked'];
					$after      = $after_pick - $result['shortage'];
					$this->repository->set_on_hand( (int) $line['location_stock_id'], $before, $after );
					if ( 0 < $result['picked'] ) {
						$this->repository->mutation(
							array(
								'event_key'        => IdempotencyKey::make( 'pick-out', $picklist_id, (int) $line['id'] ),
								'mutation_type'    => 'PICK_OUT',
								'boris_product_id' => (int) $line['boris_product_id'],
								'location_id'      => (int) $line['location_id'],
								'order_line_id'    => (int) $line['order_line_id'],
								'picklist_id'      => $picklist_id,
								'on_hand_before'   => $before,
								'on_hand_after'    => $after_pick,
								'quantity_delta'   => -$result['picked'],
								'actor_user_id'    => $user_id,
								'source'           => 'PICKLIST',
							)
						);
					}
					if ( 0 < $result['shortage'] ) {
						$this->repository->mutation(
							array(
								'event_key'        => IdempotencyKey::make( 'physical-shortage', $picklist_id, (int) $line['id'] ),
								'mutation_type'    => 'VARIANCE_OUT',
								'boris_product_id' => (int) $line['boris_product_id'],
								'location_id'      => (int) $line['location_id'],
								'order_line_id'    => (int) $line['order_line_id'],
								'picklist_id'      => $picklist_id,
								'on_hand_before'   => $after_pick,
								'on_hand_after'    => $after,
								'quantity_delta'   => -$result['shortage'],
								'actor_user_id'    => $user_id,
								'source'           => 'PHYSICAL_CHECK',
								'note'             => __( 'Physical shortage during picking; BORIS backorder opened.', 'boris-stock-inventory-management' ),
							)
						);
						$key               = (int) $line['order_line_id'];
						$shortages[ $key ] = ( $shortages[ $key ] ?? 0 ) + $result['shortage'];
					}
					$wpdb->update(
						$t['picklist_lines'],
						array(
							'picked_quantity'      => $result['picked'],
							'shortage_quantity'    => $result['shortage'],
							'picked_confirmed'     => 1,
							'stock_check_required' => $result['creates_backorder'] ? 1 : 0,
							'picked_at_gmt'        => Repository::now(),
						),
						array( 'id' => (int) $line['id'] )
					);
				}

				foreach ( $shortages as $order_line_id => $quantity ) {
					$product_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT boris_product_id FROM %i WHERE id=%d', $t['order_lines'], $order_line_id ) );
					$wpdb->query(
						$wpdb->prepare(
							"INSERT INTO %i (boris_order_id,order_line_id,picklist_id,boris_product_id,quantity_initial,quantity_open,status,created_at_gmt) VALUES (%d,%d,%d,%d,%d,%d,'OPEN',%s)
							ON DUPLICATE KEY UPDATE quantity_initial=GREATEST(quantity_initial,VALUES(quantity_initial)),quantity_open=GREATEST(quantity_open,VALUES(quantity_open)),status='OPEN',resolved_at_gmt=NULL",
							$t['backorders'],
							(int) $picklist->boris_order_id,
							$order_line_id,
							$picklist_id,
							$product_id,
							$quantity,
							$quantity,
							Repository::now()
						)
					);
				}

				$status       = empty( $shortages ) ? 'COMPLETED' : 'SHORTAGE';
				$order_status = empty( $shortages ) ? 'COMPLETED' : 'BACKORDER';
				$wpdb->update(
					$t['picklists'],
					array(
						'status'               => $status,
						'reservation_state'    => 'RELEASED',
						'completed_by_user_id' => $user_id,
						'completed_at_gmt'     => Repository::now(),
						'row_version'          => (int) $picklist->row_version + 1,
					),
					array( 'id' => $picklist_id )
				);
				$wpdb->update(
					$t['orders'],
					array(
						'boris_status'   => $order_status,
						'updated_at_gmt' => Repository::now(),
					),
					array( 'id' => (int) $picklist->boris_order_id )
				);
			}
		);
	}
}
