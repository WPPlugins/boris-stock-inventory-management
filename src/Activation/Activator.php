<?php
/**
 * Plugin activation and schema upgrades.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Activation;

use BorisSystems\Stock\Compatibility\MediaFolderCompatibility;
use BorisSystems\Stock\Infrastructure\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Activator {
	/**
	 * Install database and capabilities.
	 */
	public static function activate(): void {
		Schema::install();
		self::install_defaults();
		self::install_capabilities();
		MediaFolderCompatibility::refresh_snapshot();
		update_option( 'boris_stock_needs_recovery', 'yes', false );
	}

	/**
	 * Upgrade after a normal plugin update.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( 'boris_stock_db_version', 0 ) !== BORIS_STOCK_DB_VERSION ) {
			Schema::install();
			self::install_defaults();
			self::install_capabilities();
		}
	}

	/**
	 * Add safe defaults without overwriting existing settings.
	 */
	private static function install_defaults(): void {
		add_option( 'boris_stock_allow_cod_without_payment', 'no', '', false );
		add_option( 'boris_stock_backorder_days', 7, '', false );
		add_option( 'boris_stock_delete_data_on_uninstall', 'no', '', false );
		add_option( 'boris_stock_warehouse_email', '', '', false );
		add_option( 'boris_stock_auto_email_picklist', 'no', '', false );
		add_option( 'boris_stock_problem_email', '', '', false );
		add_option( 'boris_stock_last_recovery_at_gmt', '', '', false );
	}

	/**
	 * Grant custom capabilities to administrators and shop managers.
	 */
	private static function install_capabilities(): void {
		$all = array(
			'boris_stock_view',
			'boris_stock_manage_picklists',
			'boris_stock_manage_inventory',
			'boris_stock_manage_locations',
			'boris_stock_resolve_problems',
			'boris_stock_manage_settings',
		);

		$administrator = get_role( 'administrator' );
		$shop_manager  = get_role( 'shop_manager' );

		if ( $administrator ) {
			foreach ( $all as $capability ) {
				$administrator->add_cap( $capability );
			}
		}

		if ( $shop_manager ) {
			foreach ( array_diff( $all, array( 'boris_stock_manage_settings' ) ) as $capability ) {
				$shop_manager->add_cap( $capability );
			}
		}
	}
}
