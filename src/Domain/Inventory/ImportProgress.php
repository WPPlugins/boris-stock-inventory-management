<?php
/**
 * Pure calculations for the initial stock placement flow.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Domain\Inventory;

defined( 'ABSPATH' ) || exit;

/** Initial-import progress states and calculations. */
final class ImportProgress {
	public const NOT_PLACED = 'NOT_PLACED';
	public const PARTIAL    = 'PARTIAL';
	public const READY      = 'READY';
	public const CONFLICT   = 'CONFLICT';
	public const ERROR      = 'ERROR';

	/**
	 * Remaining quantity, never below zero.
	 *
	 * @param int $opening Imported opening quantity.
	 * @param int $placed  Quantity already placed.
	 * @return int
	 */
	public static function remaining( int $opening, int $placed ): int {
		return max( 0, $opening - $placed );
	}

	/**
	 * Classify the placement state for one imported product.
	 *
	 * @param int|null $opening   Imported opening quantity, or null before import.
	 * @param int      $placed    Quantity already placed.
	 * @param bool     $has_error Whether validation failed.
	 * @return string
	 */
	public static function status( ?int $opening, int $placed, bool $has_error = false ): string {
		if ( $has_error || null === $opening ) {
			return self::ERROR;
		}
		if ( $placed > $opening ) {
			return self::CONFLICT;
		}
		if ( 0 === $placed && 0 < $opening ) {
			return self::NOT_PLACED;
		}
		if ( $placed < $opening ) {
			return self::PARTIAL;
		}
		return self::READY;
	}
}
