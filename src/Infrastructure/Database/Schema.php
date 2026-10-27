<?php
/**
 * Versioned database schema.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Infrastructure\Database;

defined( 'ABSPATH' ) || exit;

// Install/uninstall necessarily changes this plugin's schema; table names come exclusively from Tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

final class Schema {
	/**
	 * Install or update all plugin tables.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t       = Tables::all();
		$c       = $wpdb->get_charset_collate();
		$queries = array();

		$queries[] = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wc_product_id bigint(20) unsigned NOT NULL,
			stock_owner_id bigint(20) unsigned NOT NULL,
			sku varchar(100) NOT NULL,
			sku_normalized varchar(100) NOT NULL,
			name_snapshot varchar(255) NOT NULL,
			status varchar(24) NOT NULL DEFAULT 'UNCONFIGURED',
			last_wc_quantity bigint(20) DEFAULT NULL,
			last_checked_at_gmt datetime DEFAULT NULL,
			initial_stock_quantity bigint(20) unsigned DEFAULT NULL,
			initial_stock_placed bigint(20) unsigned NOT NULL DEFAULT 0,
			initial_stock_imported_at_gmt datetime DEFAULT NULL,
			row_version bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wc_product (wc_product_id),
			UNIQUE KEY sku_norm (sku_normalized),
			KEY status_check (status,last_checked_at_gmt),
			KEY initial_stock (initial_stock_imported_at_gmt,initial_stock_placed)
		) ENGINE=InnoDB", $t['products'] ) . ' ' . $c . ';';

		$queries[] = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(120) NOT NULL,
			code_normalized varchar(120) NOT NULL,
			notes text DEFAULT NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code_norm (code_normalized),
			KEY active_code (active,code_normalized)
		) ENGINE=InnoDB", $t['locations'] ) . ' ' . $c . ';';

		$queries[] = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			location_id bigint(20) unsigned NOT NULL,
			boris_product_id bigint(20) unsigned NOT NULL,
			quantity_on_hand bigint(20) unsigned NOT NULL DEFAULT 0,
			row_version bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY one_item_location (location_id),
			KEY product_location (boris_product_id,location_id)
		) ENGINE=InnoDB", $t['location_stock'] ) . ' ' . $c . ';';

		$queries[] = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wc_order_id bigint(20) unsigned NOT NULL,
			order_number_snapshot varchar(100) NOT NULL,
			wc_status varchar(40) NOT NULL,
			boris_status varchar(32) NOT NULL DEFAULT 'PENDING',
			picklist_id bigint(20) unsigned DEFAULT NULL,
			source_modified_at_gmt datetime DEFAULT NULL,
			payload_hash char(64) DEFAULT NULL,
			retry_count int(10) unsigned NOT NULL DEFAULT 0,
			next_retry_at_gmt datetime DEFAULT NULL,
			last_processed_at_gmt datetime DEFAULT NULL,
			row_version bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wc_order (wc_order_id),
			KEY work_queue (boris_status,next_retry_at_gmt),
			KEY wc_status_modified (wc_status,source_modified_at_gmt)
		) ENGINE=InnoDB", $t['orders'] ) . ' ' . $c . ';';

		$queries[] = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			boris_order_id bigint(20) unsigned NOT NULL,
			wc_order_id bigint(20) unsigned NOT NULL,
			wc_order_item_id bigint(20) unsigned NOT NULL,
			wc_product_id bigint(20) unsigned NOT NULL,
			wc_variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			stock_owner_id bigint(20) unsigned NOT NULL,
			boris_product_id bigint(20) unsigned DEFAULT NULL,
			sku_snapshot varchar(100) NOT NULL,
			sku_normalized varchar(100) NOT NULL,
			name_snapshot varchar(255) NOT NULL,
			ordered_quantity bigint(20) unsigned NOT NULL,
			wc_reduced_quantity bigint(20) unsigned NOT NULL DEFAULT 0,
			wc_stock_state varchar(24) NOT NULL DEFAULT 'UNKNOWN',
			wc_stock_observed_at_gmt datetime DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wc_order_item (wc_order_id,wc_order_item_id),
			KEY order_lines (boris_order_id,id),
			KEY product_state (boris_product_id,wc_stock_state),
			KEY sku_norm (sku_normalized)
		) ENGINE=InnoDB", $t['order_lines'] ) . ' ' . $c . ';';

		$queries[] = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			boris_order_id bigint(20) unsigned NOT NULL,
			display_number varchar(120) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'DRAFT',
			reservation_state varchar(20) NOT NULL DEFAULT 'ACTIVE',
			completed_by_user_id bigint(20) unsigned DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			opened_at_gmt datetime DEFAULT NULL,
			completed_at_gmt datetime DEFAULT NULL,
			cancelled_at_gmt datetime DEFAULT NULL,
			row_version bigint(20) unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY one_order_picklist (boris_order_id),
			KEY status_created (status,created_at_gmt)
		) ENGINE=InnoDB", $t['picklists'] ) . ' ' . $c . ';';

		$queries[] = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			picklist_id bigint(20) unsigned NOT NULL,
			order_line_id bigint(20) unsigned NOT NULL,
			location_stock_id bigint(20) unsigned NOT NULL,
			location_id bigint(20) unsigned NOT NULL,
			boris_product_id bigint(20) unsigned NOT NULL,
			location_code_snapshot varchar(120) NOT NULL,
			sku_snapshot varchar(100) NOT NULL,
			name_snapshot varchar(255) NOT NULL,
			required_quantity bigint(20) unsigned NOT NULL,
			reserved_quantity bigint(20) unsigned NOT NULL,
			picked_quantity bigint(20) unsigned NOT NULL DEFAULT 0,
			shortage_quantity bigint(20) unsigned NOT NULL DEFAULT 0,
			picked_confirmed tinyint(1) NOT NULL DEFAULT 0,
			stock_check_required tinyint(1) NOT NULL DEFAULT 0,
			picked_at_gmt datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY allocation (picklist_id,order_line_id,location_id),
			KEY picklist_order (picklist_id,id),
			KEY active_location (location_stock_id,reserved_quantity),
			KEY product_reservation (boris_product_id,reserved_quantity)
		) ENGINE=InnoDB", $t['picklist_lines'] ) . ' ' . $c . ';';

		$queries[] = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			problem_key char(64) NOT NULL,
			code varchar(64) NOT NULL,
			severity varchar(16) NOT NULL DEFAULT 'ERROR',
			status varchar(16) NOT NULL DEFAULT 'OPEN',
			boris_order_id bigint(20) unsigned DEFAULT NULL,
			order_line_id bigint(20) unsigned DEFAULT NULL,
			picklist_id bigint(20) unsigned DEFAULT NULL,
			boris_product_id bigint(20) unsigned DEFAULT NULL,
			message text NOT NULL,
			context_json longtext DEFAULT NULL,
			first_seen_at_gmt datetime NOT NULL,
			last_seen_at_gmt datetime NOT NULL,
			resolved_at_gmt datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY problem_key (problem_key),
			KEY open_problems (status,severity,last_seen_at_gmt),
			KEY order_problems (boris_order_id,status),
			KEY product_problems (boris_product_id,status)
		) ENGINE=InnoDB", $t['problems'] ) . ' ' . $c . ';';

		$queries[] = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			boris_order_id bigint(20) unsigned NOT NULL,
			order_line_id bigint(20) unsigned NOT NULL,
			picklist_id bigint(20) unsigned NOT NULL,
			boris_product_id bigint(20) unsigned NOT NULL,
			quantity_initial bigint(20) unsigned NOT NULL,
			quantity_open bigint(20) unsigned NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'OPEN',
			due_at_gmt datetime DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			resolved_at_gmt datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_line_shortage (order_line_id),
			KEY fifo_open (boris_product_id,status,created_at_gmt),
			KEY due_open (status,due_at_gmt)
		) ENGINE=InnoDB", $t['backorders'] ) . ' ' . $c . ';';

		$queries[] = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_key varchar(191) NOT NULL,
			mutation_type varchar(32) NOT NULL,
			boris_product_id bigint(20) unsigned NOT NULL,
			location_id bigint(20) unsigned DEFAULT NULL,
			boris_order_id bigint(20) unsigned DEFAULT NULL,
			order_line_id bigint(20) unsigned DEFAULT NULL,
			picklist_id bigint(20) unsigned DEFAULT NULL,
			backorder_id bigint(20) unsigned DEFAULT NULL,
			on_hand_before bigint(20) unsigned NOT NULL DEFAULT 0,
			on_hand_after bigint(20) unsigned NOT NULL DEFAULT 0,
			reserved_before bigint(20) unsigned NOT NULL DEFAULT 0,
			reserved_after bigint(20) unsigned NOT NULL DEFAULT 0,
			quantity_delta bigint(20) NOT NULL DEFAULT 0,
			actor_user_id bigint(20) unsigned DEFAULT NULL,
			source varchar(24) NOT NULL,
			note text DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY product_history (boris_product_id,created_at_gmt),
			KEY location_history (location_id,created_at_gmt),
			KEY order_history (boris_order_id,created_at_gmt),
			KEY picklist_history (picklist_id,created_at_gmt)
		) ENGINE=InnoDB", $t['mutations'] ) . ' ' . $c . ';';

		$queries[] = $wpdb->prepare( "CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation_key varchar(191) NOT NULL,
			operation_type varchar(40) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'PENDING',
			entity_type varchar(40) NOT NULL,
			entity_id bigint(20) unsigned NOT NULL,
			payload_hash char(64) NOT NULL,
			payload_json longtext DEFAULT NULL,
			result_json longtext DEFAULT NULL,
			attempt_count int(10) unsigned NOT NULL DEFAULT 0,
			next_attempt_at_gmt datetime DEFAULT NULL,
			last_error text DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY operation_key (operation_key),
			KEY pending_work (status,next_attempt_at_gmt)
		) ENGINE=InnoDB", $t['operations'] ) . ' ' . $c . ';';

		$queries[] = $wpdb->prepare( "CREATE TABLE %i (
			resource_key varchar(191) NOT NULL,
			touched_at_gmt datetime NOT NULL,
			PRIMARY KEY  (resource_key)
		) ENGINE=InnoDB", $t['locks'] ) . ' ' . $c . ';';

		foreach ( $queries as $query ) {
			dbDelta( $query );
		}

		update_option( 'boris_stock_db_version', BORIS_STOCK_DB_VERSION, false );
	}

	/**
	 * Remove every plugin table after explicit uninstall opt-in.
	 */
	public static function drop_all(): void {
		global $wpdb;

		foreach ( array_reverse( Tables::all() ) as $table ) {
			// Table names are generated internally from $wpdb->prefix.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}
