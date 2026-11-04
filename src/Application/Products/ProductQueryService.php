<?php
/**
 * Bounded product search and warehouse detail queries.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Products;

use BorisSystems\Stock\Domain\Inventory\Identifier;
use BorisSystems\Stock\Infrastructure\Database\Repository;
use BorisSystems\Stock\Infrastructure\Database\Tables;

defined( 'ABSPATH' ) || exit;

final class ProductQueryService {
	private Repository $repository;

	public function __construct( ?Repository $repository = null ) {
		$this->repository = $repository ?? new Repository();
	}

	/**
	 * Search only a bounded number of WooCommerce products and retain active BORIS mappings.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function search( string $term, int $limit = 30 ): array {
		$term  = trim( $term );
		$limit = max( 1, min( 50, $limit ) );
		if ( '' === $term ) {
			return array();
		}

		$ids = array();
		try {
			$normalized = Identifier::normalize( $term );
			foreach ( $this->repository->product_ids_by_sku_prefix( $normalized, $limit ) as $id ) {
				$ids[] = $id;
			}
		} catch ( \Throwable ) {
			// The WooCommerce search below remains authoritative for non-identifier terms.
		}
		if ( ctype_digit( $term ) && 0 < (int) $term ) {
			$ids[] = (int) $term;
		}
		$store = \WC_Data_Store::load( 'product' );
		foreach ( (array) $store->search_products( $term, '', true, false, $limit ) as $id ) {
			$ids[] = (int) $id;
		}
		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, $limit );

		$results = array();
		foreach ( $ids as $id ) {
			$mapping = $this->repository->product_by_wc_id( $id );
			$product = $mapping && 'ACTIVE' === (string) $mapping->status ? wc_get_product( $id ) : false;
			if ( ! $product ) {
				continue;
			}
			$results[] = $this->summary( $product, $mapping );
		}
		return $results;
	}

	/**
	 * Return one filtered, sorted catalogue page of active BORIS Stock products.
	 *
	 * @param array<string,mixed> $filters Catalogue filters.
	 * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
	 */
	public function catalogue( array $filters, int $page = 1, int $per_page = 25 ): array {
		global $wpdb;

		$t            = Tables::all();
		$lookup       = $wpdb->prefix . 'wc_product_meta_lookup';
		$page         = max( 1, $page );
		$per_page     = in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : 25;
		$search       = trim( (string) ( $filters['search'] ?? '' ) );
		$sku          = trim( (string) ( $filters['sku'] ?? '' ) );
		$location     = trim( (string) ( $filters['location'] ?? '' ) );
		$availability = (string) ( $filters['availability'] ?? 'all' );
		$availability = in_array( $availability, array( 'all', 'available', 'occupied' ), true ) ? $availability : 'all';
		$min_price = isset( $filters['min_price'] ) && is_numeric( $filters['min_price'] ) ? max( 0.0, (float) $filters['min_price'] ) : null;
		$max_price = isset( $filters['max_price'] ) && is_numeric( $filters['max_price'] ) ? max( 0.0, (float) $filters['max_price'] ) : null;
		$sort      = (string) ( $filters['sort'] ?? 'name_asc' );
		$order_by  = array(
			'name_asc'   => 'p.name_snapshot ASC,p.id ASC',
			'name_desc'  => 'p.name_snapshot DESC,p.id DESC',
			'sku_asc'    => 'p.sku_normalized ASC,p.id ASC',
			'sku_desc'   => 'p.sku_normalized DESC,p.id DESC',
			'price_asc'  => 'ml.min_price ASC,p.name_snapshot ASC',
			'price_desc' => 'ml.min_price DESC,p.name_snapshot ASC',
			'stock_asc'  => 'ml.stock_quantity ASC,p.name_snapshot ASC',
			'stock_desc' => 'ml.stock_quantity DESC,p.name_snapshot ASC',
		)[ $sort ] ?? 'p.name_snapshot ASC,p.id ASC';

		$from = " FROM %i p
			INNER JOIN %i posts ON posts.ID=p.wc_product_id AND posts.post_type='product' AND posts.post_status NOT IN ('trash','auto-draft')
			LEFT JOIN %i ml ON ml.product_id=p.wc_product_id
			WHERE p.status=%s";
		$args = array( $t['products'], $wpdb->posts, $lookup, 'ACTIVE' );
		$occupied = "EXISTS (SELECT 1 FROM {$t['location_stock']} os LEFT JOIN {$t['picklist_lines']} opl ON opl.location_stock_id=os.id LEFT JOIN {$t['picklists']} op ON op.id=opl.picklist_id WHERE os.boris_product_id=p.id GROUP BY os.boris_product_id HAVING COALESCE(SUM(os.quantity_on_hand),0)>0 OR COALESCE(SUM(CASE WHEN op.reservation_state='ACTIVE' THEN opl.reserved_quantity ELSE 0 END),0)>0)";

		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$from .= " AND (p.name_snapshot LIKE %s OR p.sku LIKE %s OR ml.global_unique_id LIKE %s OR EXISTS (SELECT 1 FROM %i sls INNER JOIN %i sl ON sl.id=sls.location_id AND sl.active=1 WHERE sls.boris_product_id=p.id AND sl.code LIKE %s)";
			array_push( $args, $like, $like, $like, $t['location_stock'], $t['locations'], $like );
			if ( ctype_digit( $search ) && 0 < (int) $search ) {
				$from  .= ' OR p.wc_product_id=%d';
				$args[] = (int) $search;
			}
			$from .= ')';
		}
		if ( '' !== $location ) {
			$location_like = '%' . $wpdb->esc_like( $location ) . '%';
			$from         .= ' AND EXISTS (SELECT 1 FROM %i fls INNER JOIN %i fl ON fl.id=fls.location_id AND fl.active=1 WHERE fls.boris_product_id=p.id AND fl.code LIKE %s)';
			array_push( $args, $t['location_stock'], $t['locations'], $location_like );
		}
		if ( 'occupied' === $availability ) {
			$from .= ' AND ' . $occupied;
		} elseif ( 'available' === $availability ) {
			$from .= ' AND NOT ' . $occupied;
		}

		if ( '' !== $sku ) {
			try {
				$sku = Identifier::normalize( $sku );
			} catch ( \Throwable ) {
				$sku = '';
			}
			if ( '' !== $sku ) {
				$from  .= ' AND p.sku_normalized LIKE %s';
				$args[] = $wpdb->esc_like( $sku ) . '%';
			}
		}

		if ( null !== $min_price ) {
			$from  .= ' AND ml.min_price >= %f';
			$args[] = $min_price;
		}
		if ( null !== $max_price ) {
			$from  .= ' AND ml.min_price <= %f';
			$args[] = $max_price;
		}

		$prepared      = $wpdb->prepare( $from, ...$args );
		$product_total = (int) $wpdb->get_var( 'SELECT COUNT(*)' . $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The complete WHERE clause is prepared above.
		$empty_where   = " FROM {$t['locations']} el WHERE el.active=1 AND NOT EXISTS (SELECT 1 FROM {$t['location_stock']} els LEFT JOIN {$t['picklist_lines']} epl ON epl.location_stock_id=els.id LEFT JOIN {$t['picklists']} ep ON ep.id=epl.picklist_id WHERE els.location_id=el.id GROUP BY els.location_id HAVING COALESCE(SUM(els.quantity_on_hand),0)>0 OR COALESCE(SUM(CASE WHEN ep.reservation_state='ACTIVE' THEN epl.reserved_quantity ELSE 0 END),0)>0)";
		$empty_args    = array();
		$include_empty = 'occupied' !== $availability && '' === $sku && null === $min_price && null === $max_price;
		if ( $include_empty && '' !== $search ) {
			$empty_where .= ' AND el.code LIKE %s';
			$empty_args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		if ( $include_empty && '' !== $location ) {
			$empty_where .= ' AND el.code LIKE %s';
			$empty_args[] = '%' . $wpdb->esc_like( $location ) . '%';
		}
		$prepared_empty = empty( $empty_args ) ? $empty_where : $wpdb->prepare( $empty_where, ...$empty_args );
		$empty_total    = $include_empty ? (int) $wpdb->get_var( 'SELECT COUNT(*)' . $prepared_empty ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic values are prepared above.
		$total          = $product_total + $empty_total;
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( $page, $pages );
		$offset   = ( $page - 1 ) * $per_page;
		$product_limit = min( $per_page, max( 0, $product_total - $offset ) );
		$product_rows  = array();
		if ( 0 < $product_limit ) {
			$product_rows = $wpdb->get_results( 'SELECT p.id AS boris_product_id,p.wc_product_id' . $prepared . ' ORDER BY ' . $order_by . $wpdb->prepare( ' LIMIT %d OFFSET %d', $product_limit, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE is prepared and ORDER BY comes from the fixed map above.
		}
		$boris_ids = array_map( static fn( $row ): int => (int) $row->boris_product_id, $product_rows );
		$locations_by_product = $this->catalogue_locations( $boris_ids );

		$rows = array();
		foreach ( $product_rows as $result ) {
			$mapping = $this->repository->product_by_wc_id( (int) $result->wc_product_id );
			$product = $mapping ? wc_get_product( (int) $result->wc_product_id ) : false;
			if ( ! $product ) {
				continue;
			}
			$row                   = $this->summary( $product, $mapping );
			$row['row_type']       = 'product';
			$row['price']          = (string) $product->get_price();
			$row['price_html']     = (string) $product->get_price_html();
			$row['stock_quantity'] = null === $product->get_stock_quantity() ? null : (int) $product->get_stock_quantity();
			$row['locations']      = $locations_by_product[ (int) $mapping->id ] ?? array();
			$row['boris_stock_quantity'] = array_sum( array_column( $row['locations'], 'quantity' ) );
			$row['stock_status']   = (string) $product->get_stock_status();
			$rows[]                = $row;
		}
		$remaining = $per_page - count( $rows );
		if ( $include_empty && 0 < $remaining ) {
			$empty_offset = max( 0, $offset - $product_total );
			$empty_rows   = $wpdb->get_results( 'SELECT el.id,el.code,el.notes' . $prepared_empty . ' ORDER BY el.code_normalized,el.id' . $wpdb->prepare( ' LIMIT %d OFFSET %d', $remaining, $empty_offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic values are prepared above.
			foreach ( $empty_rows as $empty_row ) {
				$rows[] = array(
					'row_type'   => 'location',
					'location_id' => (int) $empty_row['id'],
					'code'        => (string) $empty_row['code'],
					'notes'       => (string) $empty_row['notes'],
				);
			}
		}

		return array(
			'rows'     => $rows,
			'total'    => $total,
			'page'     => $page,
			'pages'    => $pages,
			'per_page' => $per_page,
		);
	}

	/** Load all active location allocations for the products on the current page in one query. */
	private function catalogue_locations( array $boris_product_ids ): array {
		global $wpdb;
		if ( empty( $boris_product_ids ) ) {
			return array();
		}
		$t            = Tables::all();
		$placeholders = implode( ',', array_fill( 0, count( $boris_product_ids ), '%d' ) );
		$sql          = "SELECT ls.id,ls.boris_product_id,l.id AS location_id,l.code,ls.quantity_on_hand,COALESCE(SUM(CASE WHEN plst.reservation_state='ACTIVE' THEN pll.reserved_quantity ELSE 0 END),0) AS reserved FROM %i ls INNER JOIN %i l ON l.id=ls.location_id AND l.active=1 LEFT JOIN %i pll ON pll.location_stock_id=ls.id LEFT JOIN %i plst ON plst.id=pll.picklist_id WHERE ls.boris_product_id IN ({$placeholders}) GROUP BY ls.id,ls.boris_product_id,l.id,l.code,l.code_normalized,ls.quantity_on_hand ORDER BY l.code_normalized,ls.id";
		$args         = array_merge( array( $t['location_stock'], $t['locations'], $t['picklist_lines'], $t['picklists'] ), $boris_product_ids );
		$results      = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ) as $row ) {
			$product_id = (int) $row->boris_product_id;
			$quantity   = (int) $row->quantity_on_hand;
			$reserved   = (int) $row->reserved;
			$results[ $product_id ][] = array(
				'location_stock_id' => (int) $row->id,
				'location_id'       => (int) $row->location_id,
				'code'              => (string) $row->code,
				'quantity'          => $quantity,
				'reserved'          => $reserved,
				'available'         => max( 0, $quantity - $reserved ),
			);
		}
		return $results;
	}

	/** Return one selected product with location and reservation totals. */
	public function detail( int $wc_product_id ): ?array {
		$mapping = $this->repository->product_by_wc_id( $wc_product_id );
		$product = $mapping && 'ACTIVE' === (string) $mapping->status ? wc_get_product( $wc_product_id ) : false;
		if ( ! $product ) {
			return null;
		}
		$rows     = $this->repository->available_rows( (int) $mapping->id );
		$physical = 0;
		$reserved = 0;
		foreach ( $rows as &$row ) {
			$row['on_hand']  = (int) $row['on_hand'];
			$row['reserved'] = (int) $row['reserved'];
			$row['available'] = max( 0, $row['on_hand'] - $row['reserved'] );
			$physical += $row['on_hand'];
			$reserved += $row['reserved'];
		}
		unset( $row );
		return array_merge(
			$this->summary( $product, $mapping ),
			array(
				'physical'  => $physical,
				'reserved'  => $reserved,
				'available' => max( 0, $physical - $reserved ),
				'locations' => $rows,
				'product'   => $product,
			)
		);
	}

	/** @return array<string,mixed> */
	private function summary( $product, object $mapping ): array {
		$gtin = method_exists( $product, 'get_global_unique_id' ) ? (string) $product->get_global_unique_id() : '';
		$cost = method_exists( $product, 'get_cogs_value' ) ? $product->get_cogs_value() : '';
		return array(
			'wc_product_id'    => (int) $product->get_id(),
			'boris_product_id' => (int) $mapping->id,
			'name'             => (string) $product->get_name(),
			'sku'              => (string) $product->get_sku(),
			'gtin'             => $gtin,
			'image_id'         => (int) $product->get_image_id(),
			'regular_price'    => (string) $product->get_regular_price(),
			'sale_price'       => (string) $product->get_sale_price(),
			'cost'             => '' === $cost || null === $cost ? '' : (string) $cost,
			'status'           => (string) $product->get_status(),
		);
	}
}
