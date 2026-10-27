<?php
/**
 * Main plugin composition root.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock;

use BorisSystems\Stock\Activation\Activator;
use BorisSystems\Stock\Compatibility\MediaFolderCompatibility;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static bool $booted = false;

	/**
	 * Declare HPOS compatibility before WooCommerce initializes.
	 */
	public static function declare_hpos_compatibility(): void {
		$features = '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
		if ( class_exists( $features ) ) {
			$features::declare_compatibility( 'custom_order_tables', BORIS_STOCK_FILE, true );
		}
	}

	/**
	 * Start the plugin after WordPress plugins are loaded.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Activator::maybe_upgrade();
		if ( is_admin() ) {
			MediaFolderCompatibility::register();
		}

		if ( ! Dependencies::woocommerce_available() ) {
			add_action(
				'admin_notices',
				array( Dependencies::class, 'render_missing_woocommerce_notice' )
			);
			return;
		}

		( new Runtime() )->register();
	}
}
