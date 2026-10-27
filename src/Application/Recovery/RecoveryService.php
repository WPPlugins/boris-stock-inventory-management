<?php
/**
 * Fallback processing and uncertain-operation reconciliation.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Recovery;

use BorisSystems\Stock\Application\Orders\OrderProcessor;
use BorisSystems\Stock\Infrastructure\Database\Repository;
use BorisSystems\Stock\Infrastructure\Database\Tables;

defined( 'ABSPATH' ) || exit;

// Recovery reads durable custom-table queues directly; no request value enters table identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

final class RecoveryService {
	/** Schedule recurring repair work. */
	public function ensure_scheduled(): void {
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( 'boris_stock_recover_orders', array(), 'boris-stock' ) ) {
				as_schedule_recurring_action( time() + 60, 300, 'boris_stock_recover_orders', array(), 'boris-stock', true );
			}
			if ( ! as_has_scheduled_action( 'boris_stock_reconcile_operations', array(), 'boris-stock' ) ) {
				as_schedule_recurring_action( time() + 90, 300, 'boris_stock_reconcile_operations', array(), 'boris-stock', true );
			}
			return;
		}
		if ( ! wp_next_scheduled( 'boris_stock_recover_orders' ) ) {
			wp_schedule_event( time() + 60, 'boris_stock_five_minutes', 'boris_stock_recover_orders' );
		}
		if ( ! wp_next_scheduled( 'boris_stock_reconcile_operations' ) ) {
			wp_schedule_event( time() + 90, 'boris_stock_five_minutes', 'boris_stock_reconcile_operations' );
		}
	}

	/** Add a fallback WP-Cron interval. */
	public function cron_schedules( array $schedules ): array {
		$schedules['boris_stock_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every five minutes (BORIS Stock)', 'boris-stock-inventory-management' ),
		);
		return $schedules;
	}

	/** Enqueue one order once. */
	public function enqueue_order( int $order_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'boris_stock_process_order', array( $order_id ), 'boris-stock', true );
			return;
		}
		if ( ! wp_next_scheduled( 'boris_stock_process_order', array( $order_id ) ) ) {
			wp_schedule_single_event( time() + 1, 'boris_stock_process_order', array( $order_id ) );
		}
	}

	/** Action callback for one order. */
	public function process_order( int $order_id ): void {
		try {
			( new OrderProcessor() )->process( $order_id );
		} catch ( \Throwable $throwable ) {
			( new Repository() )->problem(
				hash( 'sha256', 'order-processing-failed:' . $order_id ),
				'ORDER_PROCESSING_FAILED',
				__( 'Order processing stopped safely and will be reviewed again later.', 'boris-stock-inventory-management' ),
				array(
					'wc_order_id' => $order_id,
					'error'       => $throwable->getMessage(),
				)
			);
		}
	}

	/** Re-evaluate problem orders. */
	public function recover_orders(): void {
		global $wpdb;
		$t   = Tables::all();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT wc_order_id FROM %i WHERE boris_status IN ('PENDING','PROBLEM') ORDER BY updated_at_gmt LIMIT 25", $t['orders'] ) );
		foreach ( $ids as $id ) {
			$this->process_order( (int) $id );
		}
	}

	/** Observe uncertain operations; never repeat a relative stock delta. */
	public function reconcile_operations(): void {
		global $wpdb;
		$t          = Tables::all();
		$repository = new Repository();
		$operations = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status IN ('BORIS_COMMITTED','UNCERTAIN') ORDER BY updated_at_gmt LIMIT 25", $t['operations'] ) );
		foreach ( $operations as $operation ) {
			$payload = json_decode( (string) $operation->payload_json, true );
			$result  = json_decode( (string) $operation->result_json, true );
			$product = isset( $payload['wc_product_id'] ) ? wc_get_product( (int) $payload['wc_product_id'] ) : null;
			if ( ! $product || ! isset( $result['wc_expected'], $result['wc_before'] ) ) {
				continue;
			}
			$observed                       = (int) $product->get_stock_quantity();
			$result['wc_observed_recovery'] = $observed;
			if ( $observed === (int) $result['wc_expected'] ) {
				$result['wc_status'] = 'CONFIRMED_BY_RECOVERY';
				$repository->update_operation( (int) $operation->id, 'COMMITTED', $result );
				$repository->resolve_problem( hash( 'sha256', 'uncertain-operation:' . $operation->operation_key ) );
				continue;
			}
			$repository->update_operation( (int) $operation->id, 'UNCERTAIN', $result, 'Observed stock does not prove whether the relative mutation occurred.' );
			$repository->problem(
				hash( 'sha256', 'uncertain-operation:' . $operation->operation_key ),
				'UNCERTAIN_WOOCOMMERCE_MUTATION',
				__( 'WooCommerce stock cannot be reconciled automatically and safely. No relative change was repeated.', 'boris-stock-inventory-management' ),
				array(
					'operation_key' => $operation->operation_key,
					'wc_before'     => $result['wc_before'],
					'wc_expected'   => $result['wc_expected'],
					'wc_observed'   => $observed,
				)
			);
		}
	}
}
