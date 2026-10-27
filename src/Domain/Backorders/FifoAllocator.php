<?php
/**
 * FIFO allocation of received physical stock to pick shortages.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Domain\Backorders;

use BorisSystems\Stock\Domain\Inventory\Quantity;

defined( 'ABSPATH' ) || exit;

final class FifoAllocator {
	/**
	 * @param int                            $received Received amount.
	 * @param array<int,array<string,mixed>> $backorders Open backorders.
	 * @return array{assignments:array<int,array{backorder_id:int,quantity:int,remaining_open:int}>,free_quantity:int}
	 */
	public function allocate( int $received, array $backorders ): array {
		$received = Quantity::positive( $received );
		usort(
			$backorders,
			static fn( array $left, array $right ): int => array( (string) $left['created_at_gmt'], (int) $left['id'] ) <=> array( (string) $right['created_at_gmt'], (int) $right['id'] )
		);

		$assignments = array();
		foreach ( $backorders as $backorder ) {
			$open = Quantity::positive( (int) $backorder['quantity_open'] );
			$used = min( $received, $open );
			if ( 0 === $used ) {
				break;
			}
			$assignments[] = array(
				'backorder_id'   => (int) $backorder['id'],
				'quantity'       => $used,
				'remaining_open' => $open - $used,
			);
			$received     -= $used;
		}

		return array(
			'assignments'   => $assignments,
			'free_quantity' => $received,
		);
	}
}
