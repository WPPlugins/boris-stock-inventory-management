<?php
/**
 * Inventory identifier normalization.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Domain\Inventory;

use BorisSystems\Stock\Domain\Exception\DomainException;

defined( 'ABSPATH' ) || exit;

// Translated exception messages are data; the receiving UI escapes them for its concrete output context.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class Identifier {
	/** Normalize an SKU or location code for unique comparisons. */
	public static function normalize( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? '' );
		if ( '' === $value ) {
			throw new DomainException( __( 'The identifier cannot be empty.', 'boris-stock-inventory-management' ) );
		}

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
	}
}
