<?php
/**
 * Manual physical stock operations with explicit cross-system reconciliation.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Inventory;

use BorisSystems\Stock\Application\Products\ProductService;
use BorisSystems\Stock\Domain\Backorders\FifoAllocator;
use BorisSystems\Stock\Domain\Inventory\Identifier;
use BorisSystems\Stock\Domain\Inventory\Quantity;
use BorisSystems\Stock\Domain\Operations\IdempotencyKey;
use BorisSystems\Stock\Infrastructure\Database\Repository;
use BorisSystems\Stock\Infrastructure\Database\Tables;
use BorisSystems\Stock\Infrastructure\Database\Transaction;

defined( 'ABSPATH' ) || exit;

// Atomic inventory locks require uncached custom-table SQL; table identifiers come only from Tables and values use prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery
// Translated exception messages are data; the receiving UI escapes them for its concrete output context.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class InventoryService {
	private Repository $repository;
	private Transaction $transaction;
	private ProductService $products;

	public function __construct() {
		$this->repository  = new Repository();
		$this->transaction = new Transaction();
		$this->products    = new ProductService( $this->repository );
	}

	/**
	 * Create a unique warehouse location.
	 *
	 * @param string $code  Human-readable location code.
	 * @param string $notes Optional warehouse note.
	 * @return int New location ID.
	 * @throws \RuntimeException When the location code is invalid or already exists.
	 */
	public function create_location( string $code, string $notes = '' ): int {
		$code       = trim( $code );
		$normalized = Identifier::normalize( $code );
		if ( '' === $normalized ) {
			throw new \RuntimeException( __( 'Enter a valid location code.', 'boris-stock-inventory-management' ) );
		}
		if ( $this->repository->location_by_normalized_code( $normalized ) ) {
			throw new \RuntimeException( __( 'This location code already exists.', 'boris-stock-inventory-management' ) );
		}
		return $this->repository->create_location( $code, $normalized, $notes );
	}

	/**
	 * Receive physical stock. Open physical shortages are fulfilled FIFO first.
	 *
	 * @return array{backorder_quantity:int,free_quantity:int,wc_status:string}
	 */
	public function stock_in( string $request_key, int $wc_product_id, int $location_id, mixed $quantity, int $user_id, string $note = '' ): array {
		$quantity         = Quantity::positive( $quantity );
		$boris_product_id = $this->products->synchronize( $wc_product_id );
		if ( 0 === $boris_product_id ) {
			throw new \RuntimeException( __( 'Unsupported WooCommerce product configuration.', 'boris-stock-inventory-management' ) );
		}
		$product   = wc_get_product( $wc_product_id );
		$wc_before = (int) $product->get_stock_quantity();
		$payload   = compact( 'wc_product_id', 'boris_product_id', 'location_id', 'quantity', 'user_id', 'note' );
		$key       = IdempotencyKey::make( 'manual-stock-in', $request_key );
		$result    = $this->transaction->run(
			function () use ( $key, $payload, $boris_product_id, $location_id, $quantity, $user_id, $note, $wc_before ): array {
				global $wpdb;
				$t = Tables::all();
				$this->transaction->lock_resources( array( 'operation:' . $key, 'product:' . $boris_product_id, 'location:' . $location_id ) );
				$operation = $this->repository->operation( $key, 'MANUAL_STOCK_IN', 'product', $boris_product_id, $payload );
				if ( hash( 'sha256', wp_json_encode( $payload ) ) !== $operation->payload_hash ) {
					throw new \RuntimeException( __( 'The idempotency key has already been used with different input.', 'boris-stock-inventory-management' ) );
				}
				if ( 'COMMITTED' === $operation->status ) {
					$committed             = (array) json_decode( (string) $operation->result_json, true );
					$committed['replayed'] = true;
					return $committed;
				}
				if ( 'PENDING' !== $operation->status ) {
					throw new \RuntimeException( __( 'The operation must be reconciled before it can continue.', 'boris-stock-inventory-management' ) );
				}
				$stock_id = $this->repository->ensure_location_stock( $location_id, $boris_product_id );
				$stock    = $this->repository->location_stock( $stock_id, true );
				$before   = (int) $stock->quantity_on_hand;
				$after_in = $before + $quantity;
				$this->repository->set_on_hand( $stock_id, $before, $after_in );
				$this->repository->mutation(
					array(
						'event_key'        => IdempotencyKey::make( 'in', $key ),
						'mutation_type'    => 'IN',
						'boris_product_id' => $boris_product_id,
						'location_id'      => $location_id,
						'on_hand_before'   => $before,
						'on_hand_after'    => $after_in,
						'quantity_delta'   => $quantity,
						'actor_user_id'    => $user_id,
						'source'           => 'MANUAL',
						'note'             => $note,
					)
				);
				$backorders = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM %i WHERE boris_product_id=%d AND status='OPEN' AND quantity_open>0 ORDER BY created_at_gmt,id FOR UPDATE", $t['backorders'], $boris_product_id ),
					ARRAY_A
				);
				$fifo       = ( new FifoAllocator() )->allocate( $quantity, $backorders );
				$current    = $after_in;
				$used       = 0;
				foreach ( $fifo['assignments'] as $assignment ) {
					$used += $assignment['quantity'];
					$next  = $current - $assignment['quantity'];
					$this->repository->set_on_hand( $stock_id, $current, $next );
					$this->repository->mutation(
						array(
							'event_key'        => IdempotencyKey::make( 'backorder-out', $key, $assignment['backorder_id'] ),
							'mutation_type'    => 'BACKORDER_OUT',
							'boris_product_id' => $boris_product_id,
							'location_id'      => $location_id,
							'backorder_id'     => $assignment['backorder_id'],
							'on_hand_before'   => $current,
							'on_hand_after'    => $next,
							'quantity_delta'   => -$assignment['quantity'],
							'actor_user_id'    => $user_id,
							'source'           => 'FIFO_BACKORDER',
							'note'             => __( 'Receipt allocated FIFO to the oldest physical picking shortage.', 'boris-stock-inventory-management' ),
						)
					);
					if ( 0 === $assignment['remaining_open'] ) {
						$wpdb->update(
							$t['backorders'],
							array(
								'quantity_open'   => 0,
								'status'          => 'RESOLVED',
								'resolved_at_gmt' => Repository::now(),
							),
							array( 'id' => $assignment['backorder_id'] )
						);
					} else {
						$wpdb->update( $t['backorders'], array( 'quantity_open' => $assignment['remaining_open'] ), array( 'id' => $assignment['backorder_id'] ) );
					}
					$current = $next;
				}
				$result = array(
					'backorder_quantity' => $used,
					'free_quantity'      => $fifo['free_quantity'],
					'wc_before'          => $wc_before,
					'wc_expected'        => $wc_before + $fifo['free_quantity'],
					'wc_status'          => 0 === $fifo['free_quantity'] ? 'NOT_REQUIRED' : 'PENDING',
				);
				$this->repository->update_operation( (int) $operation->id, 'BORIS_COMMITTED', $result );
				return array_merge( $result, array( 'operation_id' => (int) $operation->id ) );
			}
		);

		if ( ! empty( $result['replayed'] ) ) {
			return $result;
		}
		if ( isset( $result['operation_id'] ) ) {
			$result = $this->apply_wc_delta( $result['operation_id'], $key, $product, (int) $result['free_quantity'], 'increase', $result );
		}
		return $result;
	}

	/** Remove physical and WooCommerce sellable stock through an observable operation. */
	public function stock_out( string $request_key, int $location_stock_id, mixed $quantity, int $user_id, string $note = '' ): array {
		$quantity = Quantity::positive( $quantity );
		$stock    = $this->repository->location_stock( $location_stock_id );
		$mapping  = $stock ? $this->repository->product_by_id( (int) $stock->boris_product_id ) : null;
		if ( ! $mapping ) {
			throw new \RuntimeException( __( 'The location inventory is not linked to a supported product.', 'boris-stock-inventory-management' ) );
		}
		$wc_product_id = (int) $mapping->wc_product_id;
		$product       = wc_get_product( $wc_product_id );
		if ( ! $product || (int) $product->get_stock_quantity() < $quantity ) {
			throw new \RuntimeException( __( 'WooCommerce stock is insufficient for this stock reduction.', 'boris-stock-inventory-management' ) );
		}
		$wc_before = (int) $product->get_stock_quantity();
		$key       = IdempotencyKey::make( 'manual-stock-out', $request_key );
		$payload   = compact( 'wc_product_id', 'location_stock_id', 'quantity', 'user_id', 'note' );
		$result    = $this->transaction->run(
			function () use ( $key, $payload, $mapping, $location_stock_id, $quantity, $user_id, $note, $wc_before ): array {
				$this->transaction->lock_resources( array( 'operation:' . $key, 'product:' . $mapping->id, 'location-stock:' . $location_stock_id ) );
				$operation = $this->repository->operation( $key, 'MANUAL_STOCK_OUT', 'product', (int) $mapping->id, $payload );
				if ( hash( 'sha256', wp_json_encode( $payload ) ) !== $operation->payload_hash ) {
					throw new \RuntimeException( __( 'The idempotency key has already been used with different input.', 'boris-stock-inventory-management' ) );
				}
				if ( 'COMMITTED' === $operation->status ) {
					$committed             = (array) json_decode( (string) $operation->result_json, true );
					$committed['replayed'] = true;
					return $committed;
				}
				if ( 'PENDING' !== $operation->status ) {
					throw new \RuntimeException( __( 'The operation has already been processed or must be reconciled.', 'boris-stock-inventory-management' ) );
				}
				$stock = $this->repository->location_stock( $location_stock_id, true );
				if ( ! $stock || (int) $stock->boris_product_id !== (int) $mapping->id ) {
					throw new \RuntimeException( __( 'The location does not contain this product.', 'boris-stock-inventory-management' ) );
				}
				$available = $this->available_for_stock_row( $location_stock_id );
				if ( $available < $quantity ) {
					throw new \RuntimeException( __( 'Unreserved location stock is insufficient.', 'boris-stock-inventory-management' ) );
				}
				$before = (int) $stock->quantity_on_hand;
				$after  = $before - $quantity;
				$this->repository->set_on_hand( $location_stock_id, $before, $after );
				$this->repository->mutation(
					array(
						'event_key'        => IdempotencyKey::make( 'out', $key ),
						'mutation_type'    => 'OUT',
						'boris_product_id' => (int) $mapping->id,
						'location_id'      => (int) $stock->location_id,
						'on_hand_before'   => $before,
						'on_hand_after'    => $after,
						'quantity_delta'   => -$quantity,
						'actor_user_id'    => $user_id,
						'source'           => 'MANUAL',
						'note'             => $note,
					)
				);
				$result = array(
					'free_quantity' => $quantity,
					'wc_before'     => $wc_before,
					'wc_expected'   => $wc_before - $quantity,
					'wc_status'     => 'PENDING',
				);
				$this->repository->update_operation( (int) $operation->id, 'BORIS_COMMITTED', $result );
				return array_merge( $result, array( 'operation_id' => (int) $operation->id ) );
			}
		);
		if ( ! empty( $result['replayed'] ) || ! isset( $result['operation_id'] ) ) {
			return $result;
		}
		return $this->apply_wc_delta( $result['operation_id'], $key, $product, $quantity, 'decrease', $result );
	}

	/** Transfer unreserved physical stock without changing WooCommerce. */
	public function transfer( string $request_key, int $from_stock_id, int $to_location_id, mixed $quantity, int $user_id, string $note = '' ): void {
		$quantity = Quantity::positive( $quantity );
		$key      = IdempotencyKey::make( 'transfer', $request_key );
		$this->transaction->run(
			function () use ( $key, $from_stock_id, $to_location_id, $quantity, $user_id, $note ): void {
				$this->transaction->lock_resources( array( 'operation:' . $key, 'location-stock:' . $from_stock_id, 'location:' . $to_location_id ) );
				$from = $this->repository->location_stock( $from_stock_id, true );
				if ( ! $from ) {
					throw new \RuntimeException( __( 'Stock at the source location was not found.', 'boris-stock-inventory-management' ) );
				}
				$operation = $this->repository->operation( $key, 'TRANSFER', 'product', (int) $from->boris_product_id, compact( 'from_stock_id', 'to_location_id', 'quantity', 'user_id', 'note' ) );
				if ( 'COMMITTED' === $operation->status ) {
					return;
				}
				if ( 'PENDING' !== $operation->status || $this->available_for_stock_row( $from_stock_id ) < $quantity ) {
					throw new \RuntimeException( __( 'The transfer cannot continue safely.', 'boris-stock-inventory-management' ) );
				}
				$to_id = $this->repository->ensure_location_stock( $to_location_id, (int) $from->boris_product_id );
				$to    = $this->repository->location_stock( $to_id, true );
				$this->repository->set_on_hand( $from_stock_id, (int) $from->quantity_on_hand, (int) $from->quantity_on_hand - $quantity );
				$this->repository->set_on_hand( $to_id, (int) $to->quantity_on_hand, (int) $to->quantity_on_hand + $quantity );
				$this->repository->mutation(
					array(
						'event_key'        => IdempotencyKey::make( 'transfer-out', $key ),
						'mutation_type'    => 'TRANSFER_OUT',
						'boris_product_id' => (int) $from->boris_product_id,
						'location_id'      => (int) $from->location_id,
						'on_hand_before'   => (int) $from->quantity_on_hand,
						'on_hand_after'    => (int) $from->quantity_on_hand - $quantity,
						'quantity_delta'   => -$quantity,
						'actor_user_id'    => $user_id,
						'source'           => 'MANUAL',
						'note'             => $note,
					)
				);
				$this->repository->mutation(
					array(
						'event_key'        => IdempotencyKey::make( 'transfer-in', $key ),
						'mutation_type'    => 'TRANSFER_IN',
						'boris_product_id' => (int) $from->boris_product_id,
						'location_id'      => $to_location_id,
						'on_hand_before'   => (int) $to->quantity_on_hand,
						'on_hand_after'    => (int) $to->quantity_on_hand + $quantity,
						'quantity_delta'   => $quantity,
						'actor_user_id'    => $user_id,
						'source'           => 'MANUAL',
						'note'             => $note,
					)
				);
				$this->repository->update_operation( (int) $operation->id, 'COMMITTED', array( 'wc_status' => 'NOT_REQUIRED' ) );
			}
		);
	}

	/** Apply one WooCommerce delta then verify the observed absolute result. */
	private function apply_wc_delta( int $operation_id, string $key, $product, int $quantity, string $direction, array $result ): array {
		if ( 0 === $quantity ) {
			$result['wc_status'] = 'NOT_REQUIRED';
			$this->repository->update_operation( $operation_id, 'COMMITTED', $result );
			return $result;
		}
		try {
			$reported              = (int) wc_update_product_stock( $product, $quantity, $direction, true );
			$product               = wc_get_product( $product->get_id() );
			$observed              = (int) $product->get_stock_quantity();
			$result['wc_reported'] = $reported;
			$result['wc_observed'] = $observed;
			if ( $observed !== (int) $result['wc_expected'] ) {
				throw new \RuntimeException( __( 'Observed WooCommerce stock differs from the expected value.', 'boris-stock-inventory-management' ) );
			}
			$result['wc_status'] = 'CONFIRMED';
			$this->repository->update_operation( $operation_id, 'COMMITTED', $result );
			return $result;
		} catch ( \Throwable $throwable ) {
			$result['wc_status'] = 'UNCERTAIN';
			$this->repository->update_operation( $operation_id, 'UNCERTAIN', $result, $throwable->getMessage() );
			$this->repository->problem( hash( 'sha256', 'uncertain-operation:' . $key ), 'UNCERTAIN_WOOCOMMERCE_MUTATION', __( 'The WooCommerce stock change could not be confirmed safely. The operation was stopped and must be reconciled.', 'boris-stock-inventory-management' ), $result );
			throw new \RuntimeException( __( 'The WooCommerce stock status is uncertain; the operation will not be retried automatically.', 'boris-stock-inventory-management' ) );
		}
	}

	/** Available unreserved quantity for one location stock row. */
	private function available_for_stock_row( int $location_stock_id ): int {
		global $wpdb;
		$t = Tables::all();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ls.quantity_on_hand-COALESCE(SUM(CASE WHEN p.reservation_state='ACTIVE' THEN pl.reserved_quantity ELSE 0 END),0) FROM %i ls LEFT JOIN %i pl ON pl.location_stock_id=ls.id LEFT JOIN %i p ON p.id=pl.picklist_id WHERE ls.id=%d GROUP BY ls.id,ls.quantity_on_hand",
				$t['location_stock'],
				$t['picklist_lines'],
				$t['picklists'],
				$location_stock_id
			)
		);
	}
}
