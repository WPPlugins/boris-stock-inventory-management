<?php
/**
 * InnoDB transaction and row-lock helper.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Infrastructure\Database;

defined( 'ABSPATH' ) || exit;

// Explicit transactions and row locks must bypass caching; lock-table identifiers are generated internally.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

final class Transaction {
	/**
	 * Execute a callback inside a transaction.
	 *
	 * @template T
	 * @param callable():T $callback Transactional callback.
	 * @return T
	 */
	public function run( callable $callback ): mixed {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		try {
			$result = $callback();
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $result;
		} catch ( \Throwable $throwable ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $throwable;
		}
	}

	/**
	 * Lock stable resources in deterministic order.
	 *
	 * @param string[] $resource_keys Resource keys.
	 */
	public function lock_resources( array $resource_keys ): void {
		global $wpdb;

		$table = Tables::get( 'locks' );
		$keys  = array_values( array_unique( array_map( 'strval', $resource_keys ) ) );
		sort( $keys, SORT_STRING );

		foreach ( $keys as $key ) {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (resource_key, touched_at_gmt) VALUES (%s, %s)',
					$table,
					$key,
					gmdate( 'Y-m-d H:i:s' )
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		foreach ( $keys as $key ) {
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT resource_key FROM %i WHERE resource_key = %s FOR UPDATE',
					$table,
					$key
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
}
