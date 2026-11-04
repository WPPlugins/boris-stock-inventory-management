<?php
/**
 * Bounded read models for warehouse products and locations.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Inventory;

use BorisSystems\Stock\Infrastructure\Database\Tables;

defined( 'ABSPATH' ) || exit;

// Read-only uncached queries are required for current inventory and reservation totals.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

final class WarehouseQueryService {
	/**
	 * Paginated location overview.
	 *
	 * @return array{rows:array<int,object>,total:int,page:int,pages:int,per_page:int,counts:array<string,int>}
	 */
	public function locations( string $search = '', string $filter = 'all', int $page = 1, int $per_page = 50, string $sort = 'code' ): array {
		global $wpdb;
		$t        = Tables::all();
		$page     = max( 1, $page );
		$per_page = max( 10, min( 100, $per_page ) );
		$filter   = in_array( $filter, array( 'all', 'empty', 'occupied' ), true ) ? $filter : 'all';
		$search   = trim( $search );
		$order_by = array(
			'code'      => 'code_normalized ASC,id ASC',
			'product'   => 'name_snapshot ASC,code_normalized ASC',
			'stock'     => 'quantity_on_hand DESC,code_normalized ASC',
			'available' => '(quantity_on_hand-reserved) DESC,code_normalized ASC',
		)[ $sort ] ?? 'code_normalized ASC,id ASC';

		$base = " FROM %i l
			LEFT JOIN %i ls ON ls.location_id=l.id
			LEFT JOIN %i p ON p.id=ls.boris_product_id
			LEFT JOIN %i pll ON pll.location_stock_id=ls.id
			LEFT JOIN %i plst ON plst.id=pll.picklist_id
			WHERE l.active=1";
		$args = array( $t['locations'], $t['location_stock'], $t['products'], $t['picklist_lines'], $t['picklists'] );
		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$base .= ' AND (l.code LIKE %s OR l.notes LIKE %s OR p.sku LIKE %s OR p.name_snapshot LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		$group  = ' GROUP BY l.id,l.code,l.code_normalized,l.notes,l.active,ls.id,ls.quantity_on_hand,p.id,p.wc_product_id,p.sku,p.name_snapshot';
		$having = '';
		if ( 'empty' === $filter ) {
			$having = " HAVING COALESCE(ls.quantity_on_hand,0)=0 AND COALESCE(SUM(CASE WHEN plst.reservation_state='ACTIVE' THEN pll.reserved_quantity ELSE 0 END),0)=0";
		} elseif ( 'occupied' === $filter ) {
			$having = " HAVING COALESCE(ls.quantity_on_hand,0)>0 OR COALESCE(SUM(CASE WHEN plst.reservation_state='ACTIVE' THEN pll.reserved_quantity ELSE 0 END),0)>0";
		}
		$select = "SELECT l.id,l.code,l.notes,l.active,ls.id AS location_stock_id,p.id AS boris_product_id,p.wc_product_id,p.sku,p.name_snapshot,COALESCE(ls.quantity_on_hand,0) AS quantity_on_hand,COALESCE(SUM(CASE WHEN plst.reservation_state='ACTIVE' THEN pll.reserved_quantity ELSE 0 END),0) AS reserved";
		$inner  = $wpdb->prepare( $select . $base . $group . $having, ...$args );
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ({$inner}) boris_locations" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Inner query is fully prepared above.
		$offset = ( $page - 1 ) * $per_page;
		$rows   = $wpdb->get_results( $inner . ' ORDER BY ' . $order_by . $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Order clause is selected only from the fixed map above.

		$count_rows = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,SUM(CASE WHEN x.quantity_on_hand=0 AND x.reserved=0 THEN 1 ELSE 0 END) AS empty_count,SUM(CASE WHEN x.quantity_on_hand>0 OR x.reserved>0 THEN 1 ELSE 0 END) AS occupied_count FROM (SELECT l.id,COALESCE(ls.quantity_on_hand,0) AS quantity_on_hand,COALESCE(SUM(CASE WHEN plst.reservation_state='ACTIVE' THEN pll.reserved_quantity ELSE 0 END),0) AS reserved FROM %i l LEFT JOIN %i ls ON ls.location_id=l.id LEFT JOIN %i pll ON pll.location_stock_id=ls.id LEFT JOIN %i plst ON plst.id=pll.picklist_id WHERE l.active=1 GROUP BY l.id,ls.quantity_on_hand) x",
				$t['locations'],
				$t['location_stock'],
				$t['picklist_lines'],
				$t['picklists']
			)
		);

		return array(
			'rows'     => $rows,
			'total'    => $total,
			'page'     => $page,
			'pages'    => max( 1, (int) ceil( $total / $per_page ) ),
			'per_page' => $per_page,
			'counts'   => array(
				'all'      => (int) ( $count_rows->total ?? 0 ),
				'empty'    => (int) ( $count_rows->empty_count ?? 0 ),
				'occupied' => (int) ( $count_rows->occupied_count ?? 0 ),
			),
		);
	}

	/** Return bounded active locations compatible with one product. */
	public function available_locations( int $boris_product_id, string $search = '', int $limit = 50, string $filter = 'all' ): array {
		global $wpdb;
		$t     = Tables::all();
		$limit = max( 1, min( 100, $limit ) );
		$filter = in_array( $filter, array( 'all', 'empty', 'occupied' ), true ) ? $filter : 'all';
		$like  = '%' . $wpdb->esc_like( trim( $search ) ) . '%';
		$having = " HAVING (MAX(ls.id) IS NULL OR MAX(ls.boris_product_id)=%d OR (COALESCE(MAX(ls.quantity_on_hand),0)=0 AND COALESCE(SUM(CASE WHEN p.reservation_state='ACTIVE' THEN pl.reserved_quantity ELSE 0 END),0)=0))";
		if ( 'empty' === $filter ) {
			$having .= " AND COALESCE(MAX(ls.quantity_on_hand),0)=0 AND COALESCE(SUM(CASE WHEN p.reservation_state='ACTIVE' THEN pl.reserved_quantity ELSE 0 END),0)=0";
		} elseif ( 'occupied' === $filter ) {
			$having .= " AND MAX(ls.boris_product_id)=%d AND (COALESCE(MAX(ls.quantity_on_hand),0)>0 OR COALESCE(SUM(CASE WHEN p.reservation_state='ACTIVE' THEN pl.reserved_quantity ELSE 0 END),0)>0)";
		}
		$args = array( $t['locations'], $t['location_stock'], $t['picklist_lines'], $t['picklists'], $like, $boris_product_id );
		if ( 'occupied' === $filter ) {
			$args[] = $boris_product_id;
		}
		$args[] = $limit;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id,l.code,MAX(ls.boris_product_id) AS boris_product_id,COALESCE(MAX(ls.quantity_on_hand),0) AS quantity_on_hand,COALESCE(SUM(CASE WHEN p.reservation_state='ACTIVE' THEN pl.reserved_quantity ELSE 0 END),0) AS reserved FROM %i l LEFT JOIN %i ls ON ls.location_id=l.id LEFT JOIN %i pl ON pl.location_stock_id=ls.id LEFT JOIN %i p ON p.id=pl.picklist_id WHERE l.active=1 AND l.code LIKE %s GROUP BY l.id,l.code,l.code_normalized" . $having . ' ORDER BY l.code_normalized LIMIT %d',
				...$args
			)
		);
	}
}
