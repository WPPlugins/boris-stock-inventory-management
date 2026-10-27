<?php
/**
 * BORIS Stock uninstall handler.
 *
 * @package BorisSystems\Stock
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';

define( 'BORIS_STOCK_PATH', plugin_dir_path( __FILE__ ) );

\BorisSystems\Stock\Autoloader::register();

$boris_stock_hooks = array(
	'boris_stock_process_order',
	'boris_stock_recover_orders',
	'boris_stock_reconcile_operations',
);

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( $boris_stock_hooks as $boris_stock_hook ) {
		as_unschedule_all_actions( $boris_stock_hook, array(), 'boris-stock' );
	}
}
foreach ( $boris_stock_hooks as $boris_stock_hook ) {
	wp_clear_scheduled_hook( $boris_stock_hook );
}

if ( 'yes' === get_option( 'boris_stock_delete_data_on_uninstall', 'no' ) ) {
	\BorisSystems\Stock\Infrastructure\Database\Schema::drop_all();

	foreach (
		array(
			'boris_stock_db_version',
			'boris_stock_allow_cod_without_payment',
			'boris_stock_backorder_days',
			'boris_stock_delete_data_on_uninstall',
			'boris_stock_warehouse_email',
			'boris_stock_auto_email_picklist',
			'boris_stock_problem_email',
			'boris_stock_last_recovery_at_gmt',
			'boris_stock_deactivated_at_gmt',
			'boris_stock_needs_recovery',
		) as $boris_stock_option
	) {
		delete_option( $boris_stock_option );
	}
}

foreach ( array( 'administrator', 'shop_manager' ) as $boris_stock_role_name ) {
	$boris_stock_role = get_role( $boris_stock_role_name );
	if ( ! $boris_stock_role ) {
		continue;
	}
	foreach (
		array(
			'boris_stock_view',
			'boris_stock_manage_picklists',
			'boris_stock_manage_inventory',
			'boris_stock_manage_locations',
			'boris_stock_resolve_problems',
			'boris_stock_manage_settings',
		) as $boris_stock_capability
	) {
		$boris_stock_role->remove_cap( $boris_stock_capability );
	}
}
