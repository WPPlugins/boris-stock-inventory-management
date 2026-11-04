<?php
/**
 * Persistence gateway for BORIS Stock tables.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Infrastructure\Database;

defined( 'ABSPATH' ) || exit;

// Central gateway for uncached inventory tables; direct queries are required for atomic inventory operations.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
// Translated exception messages are data; the receiving UI escapes them for its concrete output context.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class Repository {
	/** @var \wpdb */
	private $db;

	/** @param \wpdb|null $db WordPress database adapter. */
	public function __construct( $db = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
	}

	/** Current UTC SQL timestamp. */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** @return array<string,string> */
	public function tables(): array {
		return Tables::all();
	}

	/** Find a mapped product by WooCommerce ID. */
	public function product_by_wc_id( int $wc_product_id ): ?object {
		$t   = Tables::all();
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE wc_product_id = %d', $t['products'], $wc_product_id )
		);
		return $row ? $row : null;
	}

	/** Find a mapped product by internal ID. */
	public function product_by_id( int $boris_product_id ): ?object {
		$t   = Tables::all();
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE id=%d', $t['products'], $boris_product_id )
		);
		return $row ? $row : null;
	}

	/** Find a mapping by normalized SKU. */
	public function product_by_normalized_sku( string $normalized_sku ): ?object {
		$t   = Tables::all();
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE sku_normalized=%s', $t['products'], $normalized_sku )
		);
		return $row ? $row : null;
	}

	/**
	 * Return a bounded set of active WooCommerce IDs by indexed SKU prefix.
	 *
	 * @return array<int,int>
	 */
	public function product_ids_by_sku_prefix( string $normalized_prefix, int $limit = 30 ): array {
		$t     = Tables::all();
		$limit = max( 1, min( 50, $limit ) );
		$rows  = $this->db->get_col(
			$this->db->prepare(
				"SELECT wc_product_id FROM %i WHERE status='ACTIVE' AND sku_normalized LIKE %s ORDER BY sku_normalized LIMIT %d",
				$t['products'],
				$this->db->esc_like( $normalized_prefix ) . '%',
				$limit
			)
		);
		return array_map( 'intval', $rows );
	}

	/** Create or refresh one validated product mapping. */
	public function save_product( int $wc_product_id, string $sku, string $normalized_sku, string $name, int $wc_quantity ): int {
		$t   = Tables::all();
		$now = self::now();
		$sql = $this->db->prepare(
			"INSERT INTO %i (wc_product_id,stock_owner_id,sku,sku_normalized,name_snapshot,status,last_wc_quantity,last_checked_at_gmt,created_at_gmt,updated_at_gmt)
			VALUES (%d,%d,%s,%s,%s,'ACTIVE',%d,%s,%s,%s)
			ON DUPLICATE KEY UPDATE stock_owner_id=VALUES(stock_owner_id),sku=VALUES(sku),sku_normalized=VALUES(sku_normalized),name_snapshot=VALUES(name_snapshot),status='ACTIVE',last_wc_quantity=VALUES(last_wc_quantity),last_checked_at_gmt=VALUES(last_checked_at_gmt),updated_at_gmt=VALUES(updated_at_gmt),row_version=row_version+1",
			$t['products'],
			$wc_product_id,
			$wc_product_id,
			$sku,
			$normalized_sku,
			$name,
			$wc_quantity,
			$now,
			$now,
			$now
		);
		$this->db->query( $sql );
		$row = $this->product_by_wc_id( $wc_product_id );
		return $row ? (int) $row->id : 0;
	}

	/**
	 * Capture the WooCommerce opening balance exactly once.
	 *
	 * @param int $boris_product_id Internal BORIS product ID.
	 * @param int $wc_quantity      Observed WooCommerce stock quantity.
	 * @return bool Whether a new opening balance was recorded.
	 */
	public function initialize_import_balance( int $boris_product_id, int $wc_quantity ): bool {
		$t       = Tables::all();
		$placed  = (int) $this->db->get_var(
			$this->db->prepare(
				'SELECT COALESCE(SUM(quantity_on_hand),0) FROM %i WHERE boris_product_id=%d',
				$t['location_stock'],
				$boris_product_id
			)
		);
		$changed = $this->db->query(
			$this->db->prepare(
				'UPDATE %i SET initial_stock_quantity=%d,initial_stock_placed=%d,initial_stock_imported_at_gmt=%s,updated_at_gmt=%s,row_version=row_version+1 WHERE id=%d AND initial_stock_quantity IS NULL',
				$t['products'],
				max( 0, $wc_quantity ),
				max( 0, $placed ),
				self::now(),
				self::now(),
				$boris_product_id
			)
		);
		return 1 === $changed;
	}

	/**
	 * Return one product's immutable opening balance and placement progress.
	 *
	 * @param int  $boris_product_id Internal BORIS product ID.
	 * @param bool $for_update       Whether to lock the row for update.
	 * @return object|null
	 */
	public function import_balance( int $boris_product_id, bool $for_update = false ): ?object {
		$t    = Tables::all();
		$row  = $this->db->get_row(
			$this->db->prepare(
				$for_update
					? 'SELECT id,wc_product_id,status,initial_stock_quantity,initial_stock_placed,initial_stock_imported_at_gmt FROM %i WHERE id=%d FOR UPDATE'
					: 'SELECT id,wc_product_id,status,initial_stock_quantity,initial_stock_placed,initial_stock_imported_at_gmt FROM %i WHERE id=%d',
				$t['products'],
				$boris_product_id
			)
		);
		return $row ? $row : null;
	}

	/**
	 * Current physical quantity across every location for one product.
	 *
	 * @param int $boris_product_id Internal BORIS product ID.
	 * @return int
	 */
	public function physical_total( int $boris_product_id ): int {
		$t = Tables::all();
		return (int) $this->db->get_var(
			$this->db->prepare( 'SELECT COALESCE(SUM(quantity_on_hand),0) FROM %i WHERE boris_product_id=%d', $t['location_stock'], $boris_product_id )
		);
	}

	/**
	 * Increase the recorded initial placement with optimistic protection.
	 *
	 * @param int $boris_product_id Internal BORIS product ID.
	 * @param int $before           Expected quantity already placed.
	 * @param int $quantity         Positive quantity to add.
	 * @throws \RuntimeException When concurrent state no longer matches.
	 */
	public function add_initial_stock_placed( int $boris_product_id, int $before, int $quantity ): void {
		$t       = Tables::all();
		$changed = $this->db->query(
			$this->db->prepare(
				'UPDATE %i SET initial_stock_placed=initial_stock_placed+%d,updated_at_gmt=%s,row_version=row_version+1 WHERE id=%d AND initial_stock_placed=%d AND initial_stock_quantity IS NOT NULL',
				$t['products'],
				$quantity,
				self::now(),
				$boris_product_id,
				$before
			)
		);
		if ( 1 !== $changed ) {
			throw new \RuntimeException( __( 'The initial stock changed in the meantime. Try again.', 'boris-stock-inventory-management' ) );
		}
	}

	/** Mark a mapping unsupported and retain the reason in a problem. */
	public function mark_product_unsupported( int $wc_product_id ): void {
		$t = Tables::all();
		$this->db->query(
			$this->db->prepare(
				"UPDATE %i SET status='UNSUPPORTED',updated_at_gmt=%s,row_version=row_version+1 WHERE wc_product_id=%d",
				$t['products'],
				self::now(),
				$wc_product_id
			)
		);
	}

	/**
	 * Find a physical location by normalized code.
	 *
	 * @param string $normalized_code Normalized location code.
	 * @return object|null
	 */
	public function location_by_normalized_code( string $normalized_code ): ?object {
		$t   = Tables::all();
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE code_normalized=%s', $t['locations'], $normalized_code )
		);
		return $row ? $row : null;
	}

	/**
	 * Find an active location that is unused or already assigned to the requested product.
	 *
	 * @param int $location_id      Warehouse location ID.
	 * @param int $boris_product_id Existing BORIS mapping ID during recovery, or zero.
	 * @return object|null Matching location.
	 */
	public function location_for_product( int $location_id, int $boris_product_id = 0 ): ?object {
		$t   = Tables::all();
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT l.id,l.code FROM %i l LEFT JOIN %i ls ON ls.location_id=l.id LEFT JOIN %i pll ON pll.location_stock_id=ls.id LEFT JOIN %i p ON p.id=pll.picklist_id WHERE l.id=%d AND l.active=1 GROUP BY l.id,l.code,ls.id,ls.boris_product_id,ls.quantity_on_hand HAVING MAX(ls.id) IS NULL OR MAX(ls.boris_product_id)=%d OR (COALESCE(MAX(ls.quantity_on_hand),0)=0 AND COALESCE(SUM(CASE WHEN p.reservation_state='ACTIVE' THEN pll.reserved_quantity ELSE 0 END),0)=0)",
				$t['locations'],
				$t['location_stock'],
				$t['picklist_lines'],
				$t['picklists'],
				$location_id,
				$boris_product_id
			)
		);
		return $row ? $row : null;
	}

	/**
	 * Create one unique physical location.
	 *
	 * @param string $code            Human-readable location code.
	 * @param string $normalized_code Normalized location code.
	 * @param string $notes           Optional warehouse note.
	 * @return int New location ID.
	 * @throws \RuntimeException When storage fails or the code is duplicated.
	 */
	public function create_location( string $code, string $normalized_code, string $notes = '' ): int {
		$t        = Tables::all();
		$now      = self::now();
		$inserted = $this->db->insert(
			$t['locations'],
			array(
				'code'            => $code,
				'code_normalized' => $normalized_code,
				'notes'           => $notes,
				'active'          => 1,
				'created_at_gmt'  => $now,
				'updated_at_gmt'  => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new \RuntimeException( __( 'The location code already exists or could not be saved.', 'boris-stock-inventory-management' ) );
		}
		return (int) $this->db->insert_id;
	}

	/**
	 * Assign an empty location to a product, enforcing one item kind per location.
	 *
	 * @param int $location_id      Active target location ID.
	 * @param int $boris_product_id Internal BORIS product ID.
	 * @return int Location-stock row ID.
	 * @throws \RuntimeException When the location is invalid, occupied or cannot be stored.
	 */
	public function ensure_location_stock( int $location_id, int $boris_product_id ): int {
		$t              = Tables::all();
		$valid_location = $this->db->get_var(
			$this->db->prepare( 'SELECT id FROM %i WHERE id=%d AND active=1 FOR UPDATE', $t['locations'], $location_id )
		);
		if ( ! $valid_location ) {
			throw new \RuntimeException( __( 'The selected warehouse location does not exist or is inactive.', 'boris-stock-inventory-management' ) );
		}
		$existing = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE location_id=%d FOR UPDATE', $t['location_stock'], $location_id )
		);
		if ( $existing && (int) $existing->boris_product_id !== $boris_product_id ) {
			$reserved = (int) $this->db->get_var(
				$this->db->prepare(
					"SELECT COALESCE(SUM(CASE WHEN p.reservation_state='ACTIVE' THEN pll.reserved_quantity ELSE 0 END),0) FROM %i pll INNER JOIN %i p ON p.id=pll.picklist_id WHERE pll.location_stock_id=%d",
					$t['picklist_lines'],
					$t['picklists'],
					(int) $existing->id
				)
			);
			if ( 0 !== (int) $existing->quantity_on_hand || 0 !== $reserved ) {
				throw new \RuntimeException( __( 'The location already contains another product.', 'boris-stock-inventory-management' ) );
			}
			$changed = $this->db->query(
				$this->db->prepare(
					'UPDATE %i SET boris_product_id=%d,row_version=row_version+1,updated_at_gmt=%s WHERE id=%d AND boris_product_id=%d AND quantity_on_hand=0',
					$t['location_stock'],
					$boris_product_id,
					self::now(),
					(int) $existing->id,
					(int) $existing->boris_product_id
				)
			);
			if ( 1 !== $changed ) {
				throw new \RuntimeException( __( 'The empty location changed in the meantime. Try again.', 'boris-stock-inventory-management' ) );
			}
			return (int) $existing->id;
		}
		if ( $existing ) {
			return (int) $existing->id;
		}
		$now      = self::now();
		$inserted = $this->db->insert(
			$t['location_stock'],
			array(
				'location_id'      => $location_id,
				'boris_product_id' => $boris_product_id,
				'quantity_on_hand' => 0,
				'created_at_gmt'   => $now,
				'updated_at_gmt'   => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new \RuntimeException( __( 'The location inventory could not be created.', 'boris-stock-inventory-management' ) );
		}
		return (int) $this->db->insert_id;
	}

	/** Get location stock with active reservations, optionally locking the rows. */
	public function available_rows( int $boris_product_id, bool $for_update = false ): array {
		$t    = Tables::all();
		$sql  = $this->db->prepare(
			( $for_update ? "SELECT ls.id,ls.location_id,l.code,ls.quantity_on_hand AS on_hand,
			COALESCE(SUM(CASE WHEN p.reservation_state='ACTIVE' THEN pl.reserved_quantity ELSE 0 END),0) AS reserved
			FROM %i ls
			INNER JOIN %i l ON l.id=ls.location_id AND l.active=1
			LEFT JOIN %i pl ON pl.location_stock_id=ls.id
			LEFT JOIN %i p ON p.id=pl.picklist_id
			WHERE ls.boris_product_id=%d
			GROUP BY ls.id,ls.location_id,l.code,ls.quantity_on_hand
			ORDER BY l.code_normalized,ls.id FOR UPDATE" : "SELECT ls.id,ls.location_id,l.code,ls.quantity_on_hand AS on_hand,
			COALESCE(SUM(CASE WHEN p.reservation_state='ACTIVE' THEN pl.reserved_quantity ELSE 0 END),0) AS reserved
			FROM %i ls
			INNER JOIN %i l ON l.id=ls.location_id AND l.active=1
			LEFT JOIN %i pl ON pl.location_stock_id=ls.id
			LEFT JOIN %i p ON p.id=pl.picklist_id
			WHERE ls.boris_product_id=%d
			GROUP BY ls.id,ls.location_id,l.code,ls.quantity_on_hand
			ORDER BY l.code_normalized,ls.id" ),
			$t['location_stock'],
			$t['locations'],
			$t['picklist_lines'],
			$t['picklists'],
			$boris_product_id
		);
		return $this->db->get_results( $sql, ARRAY_A );
	}

	/** Read a location stock row by ID. */
	public function location_stock( int $id, bool $for_update = false ): ?object {
		$t    = Tables::all();
		$row  = $this->db->get_row(
			$this->db->prepare(
				$for_update ? 'SELECT * FROM %i WHERE id=%d FOR UPDATE' : 'SELECT * FROM %i WHERE id=%d',
				$t['location_stock'],
				$id
			)
		);
		return $row ? $row : null;
	}

	/** Set the physical quantity using optimistic row version protection. */
	public function set_on_hand( int $id, int $before, int $after ): void {
		$t       = Tables::all();
		$changed = $this->db->query(
			$this->db->prepare(
				'UPDATE %i SET quantity_on_hand=%d,row_version=row_version+1,updated_at_gmt=%s WHERE id=%d AND quantity_on_hand=%d',
				$t['location_stock'],
				$after,
				self::now(),
				$id,
				$before
			)
		);
		if ( 1 !== $changed ) {
			throw new \RuntimeException( __( 'A concurrent location inventory change was detected.', 'boris-stock-inventory-management' ) );
		}
	}

	/** Insert one immutable, idempotent ledger event. */
	public function mutation( array $data ): bool {
		$t        = Tables::all();
		$defaults = array(
			'location_id'     => null,
			'boris_order_id'  => null,
			'order_line_id'   => null,
			'picklist_id'     => null,
			'backorder_id'    => null,
			'on_hand_before'  => 0,
			'on_hand_after'   => 0,
			'reserved_before' => 0,
			'reserved_after'  => 0,
			'quantity_delta'  => 0,
			'actor_user_id'   => null,
			'source'          => 'SYSTEM',
			'note'            => '',
			'created_at_gmt'  => self::now(),
		);
		$data     = array_merge( $defaults, $data );
		return false !== $this->db->insert( $t['mutations'], $data );
	}

	/** Create or refresh a persistent problem. */
	public function problem( string $key, string $code, string $message, array $context = array(), array $links = array() ): void {
		$t   = Tables::all();
		$now = self::now();
		$this->db->query(
			$this->db->prepare(
				"INSERT INTO %i (problem_key,code,severity,status,boris_order_id,order_line_id,picklist_id,boris_product_id,message,context_json,first_seen_at_gmt,last_seen_at_gmt)
				VALUES (%s,%s,'ERROR','OPEN',%d,%d,%d,%d,%s,%s,%s,%s)
				ON DUPLICATE KEY UPDATE code=VALUES(code),status='OPEN',message=VALUES(message),context_json=VALUES(context_json),last_seen_at_gmt=VALUES(last_seen_at_gmt),resolved_at_gmt=NULL",
				$t['problems'],
				$key,
				$code,
				(int) ( $links['boris_order_id'] ?? 0 ),
				(int) ( $links['order_line_id'] ?? 0 ),
				(int) ( $links['picklist_id'] ?? 0 ),
				(int) ( $links['boris_product_id'] ?? 0 ),
				$message,
				wp_json_encode( $context ),
				$now,
				$now
			)
		);
	}

	/** Resolve one persistent problem. */
	public function resolve_problem( string $key ): void {
		$t = Tables::all();
		$this->db->query(
			$this->db->prepare( "UPDATE %i SET status='RESOLVED',resolved_at_gmt=%s,last_seen_at_gmt=%s WHERE problem_key=%s AND status='OPEN'", $t['problems'], self::now(), self::now(), $key )
		);
	}

	/** Return a named operation or create it exactly once. */
	public function operation( string $key, string $type, string $entity_type, int $entity_id, array $payload ): object {
		$t    = Tables::all();
		$now  = self::now();
		$json = wp_json_encode( $payload );
		$hash = hash( 'sha256', $json );
		$this->db->query(
			$this->db->prepare(
				"INSERT IGNORE INTO %i (operation_key,operation_type,status,entity_type,entity_id,payload_hash,payload_json,created_at_gmt,updated_at_gmt)
				VALUES (%s,%s,'PENDING',%s,%d,%s,%s,%s,%s)",
				$t['operations'],
				$key,
				$type,
				$entity_type,
				$entity_id,
				$hash,
				$json,
				$now,
				$now
			)
		);
		return $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE operation_key=%s', $t['operations'], $key )
		);
	}

	/** Update operation state and optional observations. */
	public function update_operation( int $id, string $status, array $result = array(), string $error = '' ): void {
		$t = Tables::all();
		$this->db->update(
			$t['operations'],
			array(
				'status'         => $status,
				'result_json'    => wp_json_encode( $result ),
				'last_error'     => $error,
				'attempt_count'  => 1,
				'updated_at_gmt' => self::now(),
			),
			array( 'id' => $id )
		);
	}
}
