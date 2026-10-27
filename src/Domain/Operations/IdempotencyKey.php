<?php
/**
 * Stable event and operation keys.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Domain\Operations;

defined( 'ABSPATH' ) || exit;

final class IdempotencyKey {
	/** Build a bounded stable key from canonical scalar components. */
	public static function make( string $type, int|string ...$parts ): string {
		$canonical = strtolower( trim( $type ) ) . ':' . implode( ':', array_map( 'strval', $parts ) );
		return substr( $canonical, 0, 120 ) . ':' . substr( hash( 'sha256', $canonical ), 0, 32 );
	}
}
