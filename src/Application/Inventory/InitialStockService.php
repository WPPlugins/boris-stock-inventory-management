<?php
/**
 * Idempotent initial stock placement without changing WooCommerce stock.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Inventory;

use BorisSystems\Stock\Domain\Inventory\ImportProgress;
use BorisSystems\Stock\Domain\Inventory\Quantity;
use BorisSystems\Stock\Domain\Operations\IdempotencyKey;
use BorisSystems\Stock\Infrastructure\Database\Repository;
use BorisSystems\Stock\Infrastructure\Database\Transaction;

defined( 'ABSPATH' ) || exit;

// Translated exception messages are data; the receiving UI escapes them for its concrete output context.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/** Places an immutable imported opening balance on physical locations. */
final class InitialStockService {
	/** @var Repository Persistence gateway. */
	private Repository $repository;

	/** @var Transaction Transaction and locking gateway. */
	private Transaction $transaction;

	/**
	 * Create the service.
	 *
	 * @param Repository|null  $repository  Optional persistence gateway.
	 * @param Transaction|null $transaction Optional transaction gateway.
	 */
	public function __construct( ?Repository $repository = null, ?Transaction $transaction = null ) {
		$this->repository  = $repository ?? new Repository();
		$this->transaction = $transaction ?? new Transaction();
	}

	/**
	 * Place part of an imported opening balance in one location.
	 *
	 * @param string $request_key      Unique browser-generated request key.
	 * @param int    $boris_product_id Internal BORIS product ID.
	 * @param int    $location_id      Target location ID.
	 * @param mixed  $quantity         Positive whole quantity.
	 * @param int    $user_id          Acting WordPress user ID.
	 * @return array{placed:int,remaining:int}
	 * @throws \RuntimeException When the placement is invalid or cannot be committed.
	 */
	public function place( string $request_key, int $boris_product_id, int $location_id, mixed $quantity, int $user_id ): array {
		$quantity = Quantity::positive( $quantity );
		$key      = IdempotencyKey::make( 'initial-placement', $request_key );
		$payload  = compact( 'boris_product_id', 'location_id', 'quantity', 'user_id' );

		return $this->transaction->run(
			function () use ( $key, $payload, $boris_product_id, $location_id, $quantity, $user_id ): array {
				$this->transaction->lock_resources( array( 'operation:' . $key, 'product:' . $boris_product_id, 'location:' . $location_id ) );
				$operation = $this->repository->operation( $key, 'INITIAL_PLACEMENT', 'product', $boris_product_id, $payload );
				if ( hash( 'sha256', wp_json_encode( $payload ) ) !== $operation->payload_hash ) {
					throw new \RuntimeException( __( 'The idempotency key has already been used with different input.', 'boris-stock-inventory-management' ) );
				}
				if ( 'COMMITTED' === $operation->status ) {
					return (array) json_decode( (string) $operation->result_json, true );
				}
				if ( 'PENDING' !== $operation->status ) {
					throw new \RuntimeException( __( 'The placement must be checked first.', 'boris-stock-inventory-management' ) );
				}

				$balance = $this->repository->import_balance( $boris_product_id, true );
				if ( ! $balance || null === $balance->initial_stock_quantity ) {
					throw new \RuntimeException( __( 'Import this product before placing initial stock.', 'boris-stock-inventory-management' ) );
				}
				$opening   = (int) $balance->initial_stock_quantity;
				$placed    = (int) $balance->initial_stock_placed;
				$remaining = ImportProgress::remaining( $opening, $placed );
				if ( $placed > $opening ) {
					throw new \RuntimeException( __( 'Placed stock exceeds the imported initial stock. Resolve this conflict first.', 'boris-stock-inventory-management' ) );
				}
				if ( $quantity > $remaining ) {
					throw new \RuntimeException( __( 'The quantity exceeds the initial stock still to be placed.', 'boris-stock-inventory-management' ) );
				}

				$stock_id = $this->repository->ensure_location_stock( $location_id, $boris_product_id );
				$stock    = $this->repository->location_stock( $stock_id, true );
				$before   = (int) $stock->quantity_on_hand;
				$after    = $before + $quantity;
				$this->repository->set_on_hand( $stock_id, $before, $after );
				$this->repository->add_initial_stock_placed( $boris_product_id, $placed, $quantity );
				$this->repository->mutation(
					array(
						'event_key'        => IdempotencyKey::make( 'initial-placement-ledger', $key ),
						'mutation_type'    => 'INITIAL_PLACEMENT',
						'boris_product_id' => $boris_product_id,
						'location_id'      => $location_id,
						'on_hand_before'   => $before,
						'on_hand_after'    => $after,
						'quantity_delta'   => $quantity,
						'actor_user_id'    => $user_id,
						'source'           => 'IMPORT',
						'note'             => __( 'Allocation of imported WooCommerce initial stock.', 'boris-stock-inventory-management' ),
					)
				);
				$result = array(
					'placed'    => $placed + $quantity,
					'remaining' => ImportProgress::remaining( $opening, $placed + $quantity ),
				);
				$this->repository->update_operation( (int) $operation->id, 'COMMITTED', $result );
				return $result;
			}
		);
	}
}
