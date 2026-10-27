<?php
/**
 * Deactivation behavior.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Activation;

defined( 'ABSPATH' ) || exit;

final class Deactivator {
	/**
	 * Stop future jobs without deleting inventory data.
	 */
	public static function deactivate(): void {
		update_option( 'boris_stock_deactivated_at_gmt', gmdate( 'Y-m-d H:i:s' ), false );

		$hooks = array(
			'boris_stock_process_order',
			'boris_stock_recover_orders',
			'boris_stock_reconcile_operations',
		);
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( $hooks as $hook ) {
				as_unschedule_all_actions( $hook, array(), 'boris-stock' );
			}
		}
		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
