<?php
/**
 * Runtime dependency checks.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock;

defined( 'ABSPATH' ) || exit;

final class Dependencies {
	/**
	 * Whether WooCommerce is ready for use.
	 */
	public static function woocommerce_available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * Render a scoped missing dependency notice.
	 */
	public static function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__(
				'BORIS Stock requires WooCommerce. Install and activate WooCommerce before using inventory or order functions.',
				'boris-stock-inventory-management'
			)
		);
	}
}
