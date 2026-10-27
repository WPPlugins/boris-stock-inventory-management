<?php
/**
 * Plugin Name:       BORIS Stock – Inventory Management for WooCommerce
 * Description:       Physical warehouse locations, pick lists and inventory control for WooCommerce.
 * Version:           1.0.2
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            BORIS Systems
 * Author URI:        https://borissystems.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       boris-stock-inventory-management
 * WC requires at least: 10.0
 * WC tested up to:   11.0
 *
 * @package BorisSystems\Stock
 */

defined( 'ABSPATH' ) || exit;

define( 'BORIS_STOCK_VERSION', '1.0.2' );
define( 'BORIS_STOCK_DB_VERSION', 2 );
define( 'BORIS_STOCK_FILE', __FILE__ );
define( 'BORIS_STOCK_PATH', plugin_dir_path( __FILE__ ) );
define( 'BORIS_STOCK_URL', plugin_dir_url( __FILE__ ) );
// Central destination for the optional product and documentation links.
define( 'BORIS_STOCK_PRODUCT_URL', 'https://borissystems.com/boris-stock/' );

require_once BORIS_STOCK_PATH . 'src/Autoloader.php';

\BorisSystems\Stock\Autoloader::register();

register_activation_hook(
	__FILE__,
	array( \BorisSystems\Stock\Activation\Activator::class, 'activate' )
);

register_deactivation_hook(
	__FILE__,
	array( \BorisSystems\Stock\Activation\Deactivator::class, 'deactivate' )
);

add_action(
	'before_woocommerce_init',
	array( \BorisSystems\Stock\Plugin::class, 'declare_hpos_compatibility' )
);

add_action(
	'plugins_loaded',
	array( \BorisSystems\Stock\Plugin::class, 'boot' ),
	20
);
