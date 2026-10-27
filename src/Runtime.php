<?php
/**
 * Runtime service registration.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock;

use BorisSystems\Stock\Admin\AdminController;
use BorisSystems\Stock\Application\Email\PickListMailer;
use BorisSystems\Stock\Application\Media\MediaFolderService;
use BorisSystems\Stock\Application\Orders\CancellationService;
use BorisSystems\Stock\Application\Products\ProductService;
use BorisSystems\Stock\Application\Recovery\RecoveryService;

defined( 'ABSPATH' ) || exit;

final class Runtime {
	/**
	 * Register runtime services and integration hooks.
	 */
	public function register(): void {
		$recovery     = new RecoveryService();
		$cancellation = new CancellationService();
		$product      = new ProductService();
		$mailer       = new PickListMailer();
		$media        = new MediaFolderService();
		$media->register();

		add_filter( 'cron_schedules', array( $recovery, 'cron_schedules' ) );
		add_action( 'init', array( $recovery, 'ensure_scheduled' ), 20 );
		add_action( 'boris_stock_process_order', array( $recovery, 'process_order' ) );
		add_action( 'boris_stock_recover_orders', array( $recovery, 'recover_orders' ) );
		add_action( 'boris_stock_reconcile_operations', array( $recovery, 'reconcile_operations' ) );
		add_action( 'boris_stock_reconcile_operations', array( $mailer, 'recover_stale_sends' ), 20 );
		add_action( 'boris_stock_picklist_created', array( $mailer, 'maybe_send_automatically' ) );

		$order_enqueue = static function ( $order ) use ( $recovery ): void {
			$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : (int) $order;
			if ( 0 < $order_id ) {
				$recovery->enqueue_order( $order_id );
			}
		};
		add_action( 'woocommerce_order_status_processing', $order_enqueue );
		add_action( 'woocommerce_order_status_on-hold', $order_enqueue );
		add_action( 'woocommerce_reduce_order_stock', $order_enqueue );
		add_action( 'woocommerce_order_status_cancelled', array( $cancellation, 'cancelled' ) );
		add_action( 'woocommerce_order_refunded', array( $cancellation, 'refunded' ), 10, 2 );
		add_action( 'woocommerce_update_product', array( $product, 'product_saved' ) );

		if ( is_admin() ) {
			( new AdminController() )->register();
		}
		do_action( 'boris_stock_loaded' );
	}
}
