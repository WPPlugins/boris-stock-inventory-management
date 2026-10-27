<?php
/**
 * Deterministic all-or-nothing location allocation.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Domain\Inventory;

use BorisSystems\Stock\Domain\Exception\DomainException;

defined( 'ABSPATH' ) || exit;

// Translated exception messages are data; the receiving UI escapes them for its concrete output context.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class AllocationEngine {
	/**
	 * Allocate a complete quantity from available location rows.
	 *
	 * Rows require: id, location_id, code, on_hand and reserved.
	 *
	 * @param int                            $required Required amount.
	 * @param array<int,array<string,mixed>> $rows Location stock rows.
	 * @return array<int,array<string,int|string>>
	 */
	public function allocate( int $required, array $rows ): array {
		$required = Quantity::positive( $required );
		usort(
			$rows,
			static fn( array $left, array $right ): int => array( (string) $left['code'], (int) $left['id'] ) <=> array( (string) $right['code'], (int) $right['id'] )
		);

		$remaining   = $required;
		$allocations = array();
		foreach ( $rows as $row ) {
			$available = max( 0, (int) $row['on_hand'] - (int) $row['reserved'] );
			if ( 0 === $available ) {
				continue;
			}
			$taken         = min( $remaining, $available );
			$allocations[] = array(
				'location_stock_id' => (int) $row['id'],
				'location_id'       => (int) $row['location_id'],
				'location_code'     => (string) $row['code'],
				'quantity'          => $taken,
			);
			$remaining    -= $taken;
			if ( 0 === $remaining ) {
				return $allocations;
			}
		}

		throw new DomainException( __( 'Insufficient administrative location stock.', 'boris-stock-inventory-management' ) );
	}
}
