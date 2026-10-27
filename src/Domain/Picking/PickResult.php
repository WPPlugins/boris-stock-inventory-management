<?php
/**
 * Physical pick result calculation.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Domain\Picking;

use BorisSystems\Stock\Domain\Exception\DomainException;
use BorisSystems\Stock\Domain\Inventory\Quantity;

defined( 'ABSPATH' ) || exit;

// Translated exception messages are data; the receiving UI escapes them for its concrete output context.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class PickResult {
	/**
	 * Validate and calculate one confirmed pick allocation.
	 *
	 * @return array{picked:int,shortage:int,creates_backorder:bool}
	 */
	public static function calculate( int $reserved, mixed $picked ): array {
		$reserved = Quantity::positive( $reserved );
		$picked   = Quantity::non_negative( $picked );
		if ( $picked > $reserved ) {
			throw new DomainException( __( 'The picked quantity cannot exceed the reserved quantity.', 'boris-stock-inventory-management' ) );
		}
		$shortage = $reserved - $picked;

		return array(
			'picked'            => $picked,
			'shortage'          => $shortage,
			'creates_backorder' => 0 < $shortage,
		);
	}
}
