<?php
/**
 * Pure location-suggestion rules for receiving stock.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Domain\Inventory;

defined( 'ABSPATH' ) || exit;

/** Select a safe receiving suggestion from known location state. */
final class LocationSuggestion {
	/**
	 * Pick the safest suggested location for a product.
	 *
	 * @param int                            $boris_product_id Internal BORIS product ID.
	 * @param array<int,array<string,mixed>> $locations       Available locations.
	 * @return int Suggested location ID, or zero when no safe suggestion exists.
	 */
	public static function choose( int $boris_product_id, array $locations ): int {
		$best_id       = 0;
		$best_quantity = 0;
		foreach ( $locations as $location ) {
			$quantity = (int) ( $location['quantity_on_hand'] ?? 0 );
			if ( (int) ( $location['boris_product_id'] ?? 0 ) === $boris_product_id && $best_quantity < $quantity ) {
				$best_id       = (int) $location['id'];
				$best_quantity = $quantity;
			}
		}
		if ( 0 < $best_id ) {
			return $best_id;
		}

		$last_id   = 0;
		$last_used = '';
		foreach ( $locations as $location ) {
			$used_at = (string) ( $location['last_used_at'] ?? '' );
			if ( (int) ( $location['boris_product_id'] ?? 0 ) === $boris_product_id && 0 === (int) ( $location['quantity_on_hand'] ?? 0 ) && ( 0 === $last_id || $last_used < $used_at ) ) {
				$last_id   = (int) $location['id'];
				$last_used = $used_at;
			}
		}
		return $last_id;
	}
}
