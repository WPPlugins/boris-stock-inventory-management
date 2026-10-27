<?php
/**
 * Integer-only quantity rules.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Domain\Inventory;

use BorisSystems\Stock\Domain\Exception\DomainException;

defined( 'ABSPATH' ) || exit;

// Translated exception messages are data; the receiving UI escapes them for its concrete output context.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class Quantity {
	/** Parse a non-negative integer without silently rounding. */
	public static function non_negative( mixed $value ): int {
		if ( is_int( $value ) ) {
			$quantity = $value;
		} elseif ( is_string( $value ) && preg_match( '/^\d+$/D', $value ) ) {
			$quantity = (int) $value;
		} else {
			throw new DomainException( __( 'The quantity must be an integer.', 'boris-stock-inventory-management' ) );
		}

		if ( $quantity < 0 ) {
			throw new DomainException( __( 'The quantity cannot be negative.', 'boris-stock-inventory-management' ) );
		}

		return $quantity;
	}

	/** Parse a strictly positive integer. */
	public static function positive( mixed $value ): int {
		$quantity = self::non_negative( $value );
		if ( 0 === $quantity ) {
			throw new DomainException( __( 'The quantity must be greater than zero.', 'boris-stock-inventory-management' ) );
		}

		return $quantity;
	}
}
