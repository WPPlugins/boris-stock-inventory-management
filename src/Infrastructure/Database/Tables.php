<?php
/**
 * Plugin table names.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Infrastructure\Database;

defined( 'ABSPATH' ) || exit;

// Translated exception messages are data; the receiving UI escapes them for its concrete output context.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class Tables {
	/**
	 * Return all logical table names mapped to prefixed database names.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		global $wpdb;

		return array(
			'products'       => $wpdb->prefix . 'boris_stock_products',
			'locations'      => $wpdb->prefix . 'boris_stock_locations',
			'location_stock' => $wpdb->prefix . 'boris_stock_location_stock',
			'orders'         => $wpdb->prefix . 'boris_stock_orders',
			'order_lines'    => $wpdb->prefix . 'boris_stock_order_lines',
			'picklists'      => $wpdb->prefix . 'boris_stock_picklists',
			'picklist_lines' => $wpdb->prefix . 'boris_stock_picklist_lines',
			'problems'       => $wpdb->prefix . 'boris_stock_problems',
			'backorders'     => $wpdb->prefix . 'boris_stock_backorders',
			'mutations'      => $wpdb->prefix . 'boris_stock_mutations',
			'operations'     => $wpdb->prefix . 'boris_stock_operations',
			'locks'          => $wpdb->prefix . 'boris_stock_locks',
		);
	}

	/**
	 * Get one table name.
	 *
	 * @param string $logical_name Logical name.
	 */
	public static function get( string $logical_name ): string {
		$tables = self::all();
		if ( ! isset( $tables[ $logical_name ] ) ) {
			throw new \InvalidArgumentException( __( 'Unknown BORIS Stock table.', 'boris-stock-inventory-management' ) );
		}

		return $tables[ $logical_name ];
	}
}
