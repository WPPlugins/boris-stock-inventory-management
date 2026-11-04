<?php
/**
 * BORIS Stock warehouse administration.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Admin;

use BorisSystems\Stock\Application\Email\PickListMailer;
use BorisSystems\Stock\Application\Inventory\InitialStockService;
use BorisSystems\Stock\Application\Inventory\InventoryService;
use BorisSystems\Stock\Application\Inventory\WarehouseQueryService;
use BorisSystems\Stock\Application\Media\CatalogMediaOrganizer;
use BorisSystems\Stock\Application\Media\MediaFolderService;
use BorisSystems\Stock\Application\Picking\PickListService;
use BorisSystems\Stock\Application\Picking\OrderDocumentService;
use BorisSystems\Stock\Application\Products\ProductCreationService;
use BorisSystems\Stock\Application\Products\ProductPublicationService;
use BorisSystems\Stock\Application\Products\ProductQueryService;
use BorisSystems\Stock\Application\Products\ProductService;
use BorisSystems\Stock\Application\Products\ProductUpdateService;
use BorisSystems\Stock\Compatibility\MediaFolderCompatibility;
use BorisSystems\Stock\Domain\Inventory\ImportProgress;
use BorisSystems\Stock\Domain\Inventory\LocationSuggestion;
use BorisSystems\Stock\Infrastructure\Database\Tables;

defined( 'ABSPATH' ) || exit;

// Custom inventory tables require uncached direct reads; names come only from Tables. Form handlers call verify() before reading POST.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/** Registers and renders the complete local warehouse administration. */
final class AdminController {
	/** Register menus, assets and form handlers. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_boris_stock_create_location', array( $this, 'create_location' ) );
		add_action( 'wp_ajax_boris_stock_create_location', array( $this, 'create_location_ajax' ) );
		add_action( 'wp_ajax_boris_stock_search_locations', array( $this, 'search_locations_ajax' ) );
		add_action( 'wp_ajax_boris_stock_create_media_folder', array( $this, 'create_media_folder_ajax' ) );
		add_action( 'wp_ajax_boris_stock_get_media_folders', array( $this, 'get_media_folders_ajax' ) );
		add_action( 'wp_ajax_boris_stock_assign_media_folder', array( $this, 'assign_media_folder_ajax' ) );
		add_action( 'wp_ajax_boris_stock_delete_media_folder', array( $this, 'delete_media_folder_ajax' ) );
		add_action( 'admin_post_boris_stock_import_catalogue', array( $this, 'import_catalogue' ) );
		add_action( 'admin_post_boris_stock_analyze_product_photos', array( $this, 'analyze_product_photos' ) );
		add_action( 'admin_post_boris_stock_start_product_photo_organization', array( $this, 'start_product_photo_organization' ) );
		add_action( 'wp_ajax_boris_stock_process_product_photo_organization', array( $this, 'process_product_photo_organization_ajax' ) );
		add_action( 'admin_post_boris_stock_create_product', array( $this, 'create_product' ) );
		add_action( 'admin_post_boris_stock_update_product_status', array( $this, 'update_product_status' ) );
		add_action( 'admin_post_boris_stock_update_product', array( $this, 'update_product' ) );
		add_action( 'admin_post_boris_stock_place_initial_stock', array( $this, 'place_initial_stock' ) );
		add_action( 'admin_post_boris_stock_stock_in', array( $this, 'stock_in' ) );
		add_action( 'admin_post_boris_stock_stock_out', array( $this, 'stock_out' ) );
		add_action( 'admin_post_boris_stock_transfer', array( $this, 'transfer' ) );
		add_action( 'admin_post_boris_stock_start_picklist', array( $this, 'start_picklist' ) );
		add_action( 'admin_post_boris_stock_complete_picklist', array( $this, 'complete_picklist' ) );
		add_action( 'admin_post_boris_stock_mail_picklist', array( $this, 'mail_picklist' ) );
		add_action( 'admin_post_boris_stock_sync_products', array( $this, 'sync_products' ) );
		add_action( 'admin_post_boris_stock_save_settings', array( $this, 'save_settings' ) );
	}

	/** Add the BORIS Stock menu tree. */
	public function menus(): void {
		add_menu_page( 'BORIS Stock', 'BORIS Stock', 'boris_stock_view', 'boris-stock', array( $this, 'dashboard' ), 'dashicons-archive', 56 );
		add_submenu_page( 'boris-stock', __( 'Dashboard', 'boris-stock-inventory-management' ), __( 'Dashboard', 'boris-stock-inventory-management' ), 'boris_stock_view', 'boris-stock', array( $this, 'dashboard' ) );
		add_submenu_page( 'boris-stock', __( 'Location inventory', 'boris-stock-inventory-management' ), __( 'Inventory', 'boris-stock-inventory-management' ), 'boris_stock_view', 'boris-stock-inventory', array( $this, 'inventory' ) );
		add_submenu_page( null, __( 'Warehouse locations', 'boris-stock-inventory-management' ), __( 'Locations', 'boris-stock-inventory-management' ), 'boris_stock_view', 'boris-stock-locations', array( $this, 'locations' ) );
		add_submenu_page( 'boris-stock', __( 'WooCommerce import', 'boris-stock-inventory-management' ), __( 'WooCommerce import', 'boris-stock-inventory-management' ), 'boris_stock_manage_inventory', 'boris-stock-import', array( $this, 'import' ) );
		add_submenu_page( 'boris-stock', __( 'New product', 'boris-stock-inventory-management' ), __( 'New product', 'boris-stock-inventory-management' ), 'boris_stock_manage_inventory', 'boris-stock-new-product', array( $this, 'new_product' ) ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Activator.
		add_submenu_page( 'boris-stock', __( 'Pick lists', 'boris-stock-inventory-management' ), __( 'Pick lists', 'boris-stock-inventory-management' ), 'boris_stock_manage_picklists', 'boris-stock-picklists', array( $this, 'picklists' ) );
		add_submenu_page( 'boris-stock', __( 'Issues', 'boris-stock-inventory-management' ), __( 'Issues', 'boris-stock-inventory-management' ), 'boris_stock_view', 'boris-stock-problems', array( $this, 'problems' ) );
		add_submenu_page( 'boris-stock', __( 'Inventory history', 'boris-stock-inventory-management' ), __( 'Movements', 'boris-stock-inventory-management' ), 'boris_stock_view', 'boris-stock-ledger', array( $this, 'ledger' ) );
		add_submenu_page( 'boris-stock', __( 'Settings', 'boris-stock-inventory-management' ), __( 'Settings', 'boris-stock-inventory-management' ), 'boris_stock_manage_settings', 'boris-stock-settings', array( $this, 'settings' ) );
	}

	/**
	 * Load the isolated admin design only on plugin pages.
	 *
	 * @param string $hook Current WordPress admin hook suffix.
	 */
	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'boris-stock' ) ) {
			return;
		}
		$css_version = (string) filemtime( BORIS_STOCK_PATH . 'assets/css/admin.css' );
		$js_version  = (string) filemtime( BORIS_STOCK_PATH . 'assets/js/admin.js' );
		wp_enqueue_style( 'boris-stock-admin', BORIS_STOCK_URL . 'assets/css/admin.css', array(), $css_version );
		wp_enqueue_script( 'boris-stock-admin', BORIS_STOCK_URL . 'assets/js/admin.js', array(), $js_version, true );
		if ( str_contains( $hook, 'boris-stock-new-product' ) || str_contains( $hook, 'boris-stock-inventory' ) ) {
			wp_enqueue_media();
		}
		$media_tree = array();
		if ( str_contains( $hook, 'boris-stock-new-product' ) && MediaFolderCompatibility::folders_available() ) {
			$media_tree = ( new MediaFolderService() )->folder_tree();
		}
		wp_localize_script(
			'boris-stock-admin',
			'borisStockAdmin',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'locationNonce'  => wp_create_nonce( 'boris_stock_create_location_ajax' ),
				'creating'       => __( 'Creating location…', 'boris-stock-inventory-management' ),
				'createFailed'   => __( 'The location could not be created.', 'boris-stock-inventory-management' ),
				'locationSearchFailed' => __( 'Locations could not be loaded. Try again.', 'boris-stock-inventory-management' ),
				'currentStock'   => __( 'Current BORIS inventory:', 'boris-stock-inventory-management' ),
				'freeStock'      => __( 'available', 'boris-stock-inventory-management' ),
				'suggestedStock' => __( 'Suggested location: this product is already stored here.', 'boris-stock-inventory-management' ),
				'suggestedLast'  => __( 'Suggested location: most recently used location, currently empty.', 'boris-stock-inventory-management' ),
				'chooseImage'    => __( 'Choose product image', 'boris-stock-inventory-management' ),
				'chooseGallery'  => __( 'Choose gallery images', 'boris-stock-inventory-management' ),
				'choosePhotos'   => __( 'Choose or upload product photos', 'boris-stock-inventory-management' ),
				'useImage'       => __( 'Use image', 'boris-stock-inventory-management' ),
				'useGallery'     => __( 'Use selected images', 'boris-stock-inventory-management' ),
				'usePhotos'      => __( 'Use selected photos', 'boris-stock-inventory-management' ),
				'remove'         => __( 'Remove', 'boris-stock-inventory-management' ),
				'folderNonce'    => wp_create_nonce( 'boris_stock_create_media_folder' ),
				'mediaTreeNonce' => wp_create_nonce( 'boris_stock_media_tree' ),
				'catalogMediaNonce' => wp_create_nonce( 'boris_stock_catalog_media' ),
				'mediaTree'      => $media_tree,
				'creatingFolder' => __( 'Creating folder…', 'boris-stock-inventory-management' ),
				'folderFailed'   => __( 'The photo folder could not be created.', 'boris-stock-inventory-management' ),
				'allMedia'       => __( 'All media', 'boris-stock-inventory-management' ),
				'photoFolders'   => __( 'BORIS photo folders', 'boris-stock-inventory-management' ),
				'newRootFolder'  => __( 'New root folder', 'boris-stock-inventory-management' ),
				'newSubfolder'   => __( 'New subfolder', 'boris-stock-inventory-management' ),
				'folderName'     => __( 'Folder name', 'boris-stock-inventory-management' ),
				'createFolder'   => __( 'Create folder', 'boris-stock-inventory-management' ),
				'cancel'         => __( 'Cancel', 'boris-stock-inventory-management' ),
				'moveSelected'   => __( 'Move selected here', 'boris-stock-inventory-management' ),
				'deleteFolder'   => __( 'Delete selected folder', 'boris-stock-inventory-management' ),
				'folderEmpty'    => __( 'Create a folder to start organizing product photos.', 'boris-stock-inventory-management' ),
				'folderLoading'  => __( 'Loading photo folders…', 'boris-stock-inventory-management' ),
				'folderMoved'    => __( 'The selected media were moved to this BORIS folder.', 'boris-stock-inventory-management' ),
				'folderUploaded' => __( 'The uploaded image was added to the selected BORIS folder.', 'boris-stock-inventory-management' ),
				'folderDeleted'  => __( 'The BORIS folder was deleted. Its media remain in the WordPress Media Library.', 'boris-stock-inventory-management' ),
				'folderDeleteConfirm' => __( 'Delete this BORIS folder? Its media files will remain available under All media.', 'boris-stock-inventory-management' ),
				'folderChooseFirst' => __( 'Select a BORIS folder first.', 'boris-stock-inventory-management' ),
				'mediaChooseFirst' => __( 'Select one or more media items first.', 'boris-stock-inventory-management' ),
				'folderActionFailed' => __( 'The BORIS media-folder action could not be completed.', 'boris-stock-inventory-management' ),
				'uploadDestination' => __( 'Upload destination:', 'boris-stock-inventory-management' ),
				'siteName'       => get_bloginfo( 'name' ),
				'mainPhoto'      => __( 'Main photo', 'boris-stock-inventory-management' ),
				'makeMainPhoto'  => __( 'Make main photo', 'boris-stock-inventory-management' ),
				'dragPhoto'      => __( 'Drag to change gallery order', 'boris-stock-inventory-management' ),
				'photoRequired'  => __( 'Select at least one photo before choosing a main photo.', 'boris-stock-inventory-management' ),
				'suggestedPath'  => __( 'Suggested folder:', 'boris-stock-inventory-management' ),
				'folderCreated'  => __( 'The folder path is ready and selected.', 'boris-stock-inventory-management' ),
				'locationTotal'  => __( 'Allocated across locations:', 'boris-stock-inventory-management' ),
				'allocationMatches' => __( 'The location allocation matches the opening stock.', 'boris-stock-inventory-management' ),
				'allocationTooHigh' => __( 'The location allocation exceeds the opening stock.', 'boris-stock-inventory-management' ),
				'allocationTooLow' => __( 'Allocate the complete opening stock across locations.', 'boris-stock-inventory-management' ),
				'savingProduct'  => __( 'Saving product…', 'boris-stock-inventory-management' ),
				'organizingPhotos' => __( 'Organizing product photos…', 'boris-stock-inventory-management' ),
				'organizeComplete' => __( 'Product-photo organization is complete.', 'boris-stock-inventory-management' ),
				'organizeFailed'  => __( 'Product-photo organization could not continue.', 'boris-stock-inventory-management' ),
			)
		);
	}

	/** Dashboard overview. */
	public function dashboard(): void {
		global $wpdb;
		$this->guard( 'boris_stock_view' );
		$t              = Tables::all();
		$counts         = array(
			'pickable'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status IN ('READY','PICKING')", $t['picklists'] ) ),
			'problems'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='OPEN'", $t['problems'] ) ),
			'backorders' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='OPEN'", $t['backorders'] ) ),
			'locations'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE active=1', $t['locations'] ) ),
		);
		$imported_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE initial_stock_quantity IS NOT NULL', $t['products'] ) );
		$remaining      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(GREATEST(initial_stock_quantity-initial_stock_placed,0)),0) FROM %i WHERE initial_stock_quantity IS NOT NULL', $t['products'] ) );
		$conflicts      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE initial_stock_quantity IS NOT NULL AND initial_stock_placed>initial_stock_quantity', $t['products'] ) );
		$woo_active     = class_exists( 'WooCommerce' );
		$import_run     = '' !== (string) get_option( 'boris_stock_catalogue_imported_at_gmt', '' );
		$placement_done = $import_run && 0 === $remaining && 0 === $conflicts;
		$this->header( __( 'Warehouse overview', 'boris-stock-inventory-management' ), __( 'Physical location inventory, picking work and persistent checks.', 'boris-stock-inventory-management' ) );
		$this->messages();
		echo '<section class="boris-onboarding"><h2>' . esc_html__( 'Get started with BORIS Stock', 'boris-stock-inventory-management' ) . '</h2><p class="boris-muted">' . esc_html__( 'Complete these steps once. Completed steps collapse automatically.', 'boris-stock-inventory-management' ) . '</p>';
		$this->onboarding_step( 1, __( 'Plugin installed', 'boris-stock-inventory-management' ), true, sprintf( /* translators: %s is the active BORIS Stock version. */ __( 'Version %s is active and stores warehouse data locally in WordPress.', 'boris-stock-inventory-management' ), BORIS_STOCK_VERSION ), '' );
		$this->onboarding_step( 2, __( 'Create warehouse locations', 'boris-stock-inventory-management' ), 0 < $counts['locations'], __( 'First create the bins, shelves or other physical locations.', 'boris-stock-inventory-management' ), add_query_arg( 'page', 'boris-stock-locations', admin_url( 'admin.php' ) ) );
		$this->onboarding_step( 3, __( 'Connect WooCommerce', 'boris-stock-inventory-management' ), $woo_active, $woo_active ? __( 'WooCommerce is active and available to BORIS Stock.', 'boris-stock-inventory-management' ) : __( 'Activate WooCommerce before importing the product catalogue.', 'boris-stock-inventory-management' ), admin_url( 'plugins.php' ) );
		$this->onboarding_step( 4, __( 'Import the product catalogue', 'boris-stock-inventory-management' ), $import_run, sprintf( /* translators: %d is the number of imported products. */ __( '%d products have a recorded initial stock level.', 'boris-stock-inventory-management' ), $imported_count ), add_query_arg( 'page', 'boris-stock-import', admin_url( 'admin.php' ) ) );
		$this->onboarding_step( 5, __( 'Allocate initial stock', 'boris-stock-inventory-management' ), $placement_done, sprintf( /* translators: 1: remaining quantity, 2: conflict count. */ __( 'Still to place: %1$d. Conflicts: %2$d.', 'boris-stock-inventory-management' ), $remaining, $conflicts ), add_query_arg( 'page', 'boris-stock-import', admin_url( 'admin.php' ) ) );
		$this->onboarding_step( 6, __( 'Start the daily workflow', 'boris-stock-inventory-management' ), $placement_done, __( 'Receive, book out, move and pick stock from BORIS Stock.', 'boris-stock-inventory-management' ), add_query_arg( 'page', 'boris-stock-inventory', admin_url( 'admin.php' ) ) );
		echo '</section>';
		if ( current_user_can( 'boris_stock_manage_inventory' ) && current_user_can( 'edit_products' ) && current_user_can( 'upload_files' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BORIS and WooCommerce capabilities are registered by their plugins.
			echo '<div class="boris-card"><h2>' . esc_html__( 'Add a product', 'boris-stock-inventory-management' ) . '</h2><p>' . esc_html__( 'Create a normal WooCommerce product, add its photos and divide its opening stock across warehouse locations.', 'boris-stock-inventory-management' ) . '</p><a class="boris-btn boris-btn--primary" href="' . esc_url( add_query_arg( 'page', 'boris-stock-new-product', admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'New product', 'boris-stock-inventory-management' ) . '</a></div>';
		}
		echo '<div class="boris-grid boris-grid--stats">';
		$this->stat( __( 'Ready to pick', 'boris-stock-inventory-management' ), $counts['pickable'], 'success' );
		$this->stat( __( 'Issues', 'boris-stock-inventory-management' ), $counts['problems'], 'danger' );
		$this->stat( __( 'BORIS backorders', 'boris-stock-inventory-management' ), $counts['backorders'], 'warning' );
		$this->stat( __( 'Locations', 'boris-stock-inventory-management' ), $counts['locations'], 'neutral' );
		echo '</div><div class="boris-card"><h2>' . esc_html__( 'Safe system boundary', 'boris-stock-inventory-management' ) . '</h2><p>' . esc_html__( 'WooCommerce manages sellable stock. BORIS Stock manages physical locations, reservations and actual picked quantities. Completing a pick list never reduces WooCommerce stock again.', 'boris-stock-inventory-management' ) . '</p></div>';
		echo '<div class="boris-card"><h2>' . esc_html__( 'Documentation', 'boris-stock-inventory-management' ) . '</h2><p><a href="' . esc_url( BORIS_STOCK_PRODUCT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'User Manual', 'boris-stock-inventory-management' ) . '</a></p></div>';
		echo '<div class="boris-card boris-product-card"><h2>' . esc_html__( 'Need more from BORIS Stock?', 'boris-stock-inventory-management' ) . '</h2><p>' . esc_html__( 'Manage multiple webshops, staff accounts and permissions with BORIS Stock Local or BORIS Stock Cloud.', 'boris-stock-inventory-management' ) . '</p><a class="boris-btn" href="' . esc_url( BORIS_STOCK_PRODUCT_URL ) . '">' . esc_html__( 'Explore BORIS Stock', 'boris-stock-inventory-management' ) . '</a></div>';
		$this->footer();
	}

	/** Location stock and manual operations. */
	public function inventory(): void {
		$this->guard( 'boris_stock_view' );
		$term          = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$sku           = isset( $_GET['sku'] ) ? sanitize_text_field( wp_unslash( $_GET['sku'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$location      = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$availability  = isset( $_GET['availability'] ) ? sanitize_key( wp_unslash( $_GET['availability'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$availability  = in_array( $availability, array( 'all', 'available', 'occupied' ), true ) ? $availability : 'all';
		$min_price_raw = isset( $_GET['price_min'] ) ? sanitize_text_field( wp_unslash( $_GET['price_min'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$max_price_raw = isset( $_GET['price_max'] ) ? sanitize_text_field( wp_unslash( $_GET['price_max'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$min_price     = '' === $min_price_raw ? '' : wc_format_decimal( $min_price_raw );
		$max_price     = '' === $max_price_raw ? '' : wc_format_decimal( $max_price_raw );
		$sort          = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'name_asc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sorting.
		$per_page      = isset( $_GET['per_page'] ) ? absint( wp_unslash( $_GET['per_page'] ) ) : 25; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination setting.
		$per_page      = in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : 25;
		$page          = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$product_id    = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection.
		$query         = new ProductQueryService();
		$filters       = array(
			'search'    => $term,
			'sku'       => $sku,
			'location'  => $location,
			'availability' => $availability,
			'min_price' => $min_price,
			'max_price' => $max_price,
			'sort'      => $sort,
		);
		$catalogue     = $query->catalogue( $filters, $page, $per_page );
		$detail        = 0 < $product_id ? $query->detail( $product_id ) : null;
		$this->header( __( 'Inventory', 'boris-stock-inventory-management' ), __( 'Manage products and warehouse locations from one place.', 'boris-stock-inventory-management' ) );
		$this->messages();
		echo '<form class="boris-card boris-product-filters" method="get"><input type="hidden" name="page" value="boris-stock-inventory"><div class="boris-filter-grid">';
		echo '<label>' . esc_html__( 'Search', 'boris-stock-inventory-management' ) . '<input type="search" name="s" value="' . esc_attr( $term ) . '" placeholder="' . esc_attr__( 'Product, SKU, barcode, ID or location', 'boris-stock-inventory-management' ) . '"></label>';
		echo '<label>' . esc_html__( 'Article number / SKU', 'boris-stock-inventory-management' ) . '<input type="search" name="sku" value="' . esc_attr( $sku ) . '" placeholder="' . esc_attr__( 'Starts with…', 'boris-stock-inventory-management' ) . '"></label>';
		echo '<label>' . esc_html__( 'Location code', 'boris-stock-inventory-management' ) . '<input type="search" name="location" value="' . esc_attr( $location ) . '" placeholder="' . esc_attr__( 'Contains…', 'boris-stock-inventory-management' ) . '"></label>';
		echo '<label>' . esc_html__( 'Minimum price', 'boris-stock-inventory-management' ) . '<input type="number" inputmode="decimal" min="0" step="0.01" name="price_min" value="' . esc_attr( $min_price ) . '" placeholder="0.00"></label>';
		echo '<label>' . esc_html__( 'Maximum price', 'boris-stock-inventory-management' ) . '<input type="number" inputmode="decimal" min="0" step="0.01" name="price_max" value="' . esc_attr( $max_price ) . '" placeholder="999.00"></label>';
		echo '<label>' . esc_html__( 'Sort by', 'boris-stock-inventory-management' ) . '<select name="sort">';
		$sort_options = array(
			'name_asc'   => __( 'Product name (A–Z)', 'boris-stock-inventory-management' ),
			'name_desc'  => __( 'Product name (Z–A)', 'boris-stock-inventory-management' ),
			'sku_asc'    => __( 'Article number (ascending)', 'boris-stock-inventory-management' ),
			'sku_desc'   => __( 'Article number (descending)', 'boris-stock-inventory-management' ),
			'price_asc'  => __( 'Price (low to high)', 'boris-stock-inventory-management' ),
			'price_desc' => __( 'Price (high to low)', 'boris-stock-inventory-management' ),
			'stock_asc'  => __( 'Stock (low to high)', 'boris-stock-inventory-management' ),
			'stock_desc' => __( 'Stock (high to low)', 'boris-stock-inventory-management' ),
		);
		foreach ( $sort_options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $sort, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label><label>' . esc_html__( 'Availability', 'boris-stock-inventory-management' ) . '<select name="availability"><option value="all" ' . selected( $availability, 'all', false ) . '>' . esc_html__( 'All', 'boris-stock-inventory-management' ) . '</option><option value="available" ' . selected( $availability, 'available', false ) . '>' . esc_html__( 'Available', 'boris-stock-inventory-management' ) . '</option><option value="occupied" ' . selected( $availability, 'occupied', false ) . '>' . esc_html__( 'Occupied', 'boris-stock-inventory-management' ) . '</option></select></label><label>' . esc_html__( 'Rows per page', 'boris-stock-inventory-management' ) . '<select name="per_page"><option value="25" ' . selected( $per_page, 25, false ) . '>25</option><option value="50" ' . selected( $per_page, 50, false ) . '>50</option><option value="100" ' . selected( $per_page, 100, false ) . '>100</option></select></label></div><div class="boris-filter-actions"><button class="boris-btn boris-btn--primary">' . esc_html__( 'Apply filters', 'boris-stock-inventory-management' ) . '</button><a class="boris-btn" href="' . esc_url( add_query_arg( 'page', 'boris-stock-inventory', admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Clear filters', 'boris-stock-inventory-management' ) . '</a></div></form>';
		if ( current_user_can( 'boris_stock_manage_locations' ) ) {
			echo '<p class="boris-inventory-actions"><button class="boris-btn boris-btn--primary boris-new-location" type="button" data-location-select="">' . esc_html__( '+ New location', 'boris-stock-inventory-management' ) . '</button></p>';
		}

		if ( $detail ) {
			$overview_args = array(
				'page'      => 'boris-stock-inventory',
				's'         => $term,
				'sku'       => $sku,
				'location'  => $location,
				'availability' => $availability,
				'price_min' => $min_price,
				'price_max' => $max_price,
				'sort'      => $sort,
				'paged'     => $catalogue['page'],
				'per_page'  => $per_page,
			);
			echo '<p><a class="boris-btn" href="' . esc_url( add_query_arg( $overview_args, admin_url( 'admin.php' ) ) ) . '">← ' . esc_html__( 'Back to product overview', 'boris-stock-inventory-management' ) . '</a></p>';
			$this->inventory_product_detail( $detail );
		} else {
			echo '<div class="boris-card boris-table-wrap boris-product-overview"><table class="boris-table"><thead><tr><th>' . esc_html__( 'Product', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Article number / SKU', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Barcode / GTIN', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Locations', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Price', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'WooCommerce stock', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'BORIS stock', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Publication', 'boris-stock-inventory-management' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Action', 'boris-stock-inventory-management' ) . '</span></th></tr></thead><tbody>';
			foreach ( $catalogue['rows'] as $row ) {
				if ( 'location' === $row['row_type'] ) {
					echo '<tr class="boris-empty-location-row"><td><strong>' . esc_html__( 'Available warehouse location', 'boris-stock-inventory-management' ) . '</strong></td><td>—</td><td>—</td><td><span class="boris-location-allocation"><span class="boris-location">' . esc_html( $row['code'] ) . '</span><span>0</span></span>' . ( $row['notes'] ? '<small>' . esc_html( $row['notes'] ) . '</small>' : '' ) . '</td><td>—</td><td>—</td><td>0</td><td>—</td><td>—</td></tr>';
					continue;
				}
				$url = add_query_arg(
					array(
						'page'       => 'boris-stock-inventory',
						'product_id' => $row['wc_product_id'],
						's'          => $term,
						'sku'        => $sku,
						'location'   => $location,
						'availability' => $availability,
						'price_min'  => $min_price,
						'price_max'  => $max_price,
						'sort'       => $sort,
						'paged'      => $catalogue['page'],
						'per_page'   => $per_page,
					),
					admin_url( 'admin.php' )
				);
				echo '<tr><td><a class="boris-product-cell" href="' . esc_url( $url ) . '">';
				if ( $row['image_id'] ) {
					echo wp_get_attachment_image( $row['image_id'], 'thumbnail', false, array( 'class' => 'boris-product-thumb' ) );
				} else {
					echo '<span class="boris-product-thumb boris-product-thumb--empty" aria-hidden="true">—</span>';
				}
				echo '<span><strong>' . esc_html( $row['name'] ) . '</strong><small>#' . esc_html( (string) $row['wc_product_id'] ) . '</small></span></a></td><td><code>' . esc_html( $row['sku'] ) . '</code></td><td>' . ( $row['gtin'] ? '<code>' . esc_html( $row['gtin'] ) . '</code>' : '—' ) . '</td><td><div class="boris-location-allocations">';
				if ( empty( $row['locations'] ) ) {
					echo '<span class="boris-muted">' . esc_html__( 'No location', 'boris-stock-inventory-management' ) . '</span>';
				} else {
					foreach ( $row['locations'] as $allocation ) {
						echo '<span class="boris-location-allocation"><span class="boris-location">' . esc_html( $allocation['code'] ) . '</span><span>' . esc_html( number_format_i18n( $allocation['quantity'] ) ) . '</span></span>';
					}
				}
				echo '</div></td><td>' . ( $row['price_html'] ? wp_kses_post( $row['price_html'] ) : '—' ) . '</td><td>' . ( null === $row['stock_quantity'] ? '—' : esc_html( number_format_i18n( $row['stock_quantity'] ) ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['boris_stock_quantity'] ) ) . '</td><td>';
				$this->product_status_form( (int) $row['wc_product_id'], (string) $row['status'] );
				echo '</td><td><a class="boris-btn boris-btn--small" href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'boris-stock-inventory-management' ) . '</a></td></tr>';
			}
			if ( empty( $catalogue['rows'] ) ) {
				echo '<tr><td colspan="9">' . esc_html__( 'No products or locations match these filters.', 'boris-stock-inventory-management' ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
			$this->product_catalogue_pagination(
				$catalogue,
				array(
					's'         => $term,
					'sku'       => $sku,
					'location'  => $location,
					'availability' => $availability,
					'price_min' => $min_price,
					'price_max' => $max_price,
					'sort'      => $sort,
					'per_page'  => $per_page,
				)
			);
		}
		$this->location_dialog();
		$this->footer();
	}

	/** Searchable location management page. */
	public function locations(): void {
		$this->inventory();
	}

	/** WooCommerce catalogue import and initial placement. */
	public function import(): void {
		$this->guard( 'boris_stock_manage_inventory' );
		$products  = new ProductService();
		$page      = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$catalogue = $products->catalogue_page( $page, 50 );
		$rows      = $catalogue['rows'];
		$summary   = get_transient( 'boris_stock_import_result_' . get_current_user_id() );
		$ready     = count( array_filter( $rows, static fn( $row ) => ImportProgress::READY === $row['status'] ) );
		$to_place  = count( array_filter( $rows, static fn( $row ) => in_array( $row['status'], array( ImportProgress::NOT_PLACED, ImportProgress::PARTIAL ), true ) ) );
		$this->header( __( 'WooCommerce import', 'boris-stock-inventory-management' ), __( 'Record current WooCommerce stock once, then allocate it across locations.', 'boris-stock-inventory-management' ) );
		$this->messages();
		if ( is_array( $summary ) ) {
			echo '<div class="boris-card"><h2>' . esc_html__( 'Latest import result', 'boris-stock-inventory-management' ) . '</h2><div class="boris-summary">';
			$this->summary_value( __( 'Found', 'boris-stock-inventory-management' ), (int) $summary['found'] );
			$this->summary_value( __( 'Already linked', 'boris-stock-inventory-management' ), (int) $summary['already_linked'] );
			$this->summary_value( __( 'Newly imported', 'boris-stock-inventory-management' ), (int) $summary['imported'] );
			$this->summary_value( __( 'Conflicts', 'boris-stock-inventory-management' ), (int) $summary['conflicts'] );
			$this->summary_value( __( 'Still to place on this page', 'boris-stock-inventory-management' ), $to_place );
			$this->summary_value( __( 'Complete on this page', 'boris-stock-inventory-management' ), $ready );
			echo '</div></div>';
		}
		$this->catalogue_media_organization();
		echo '<form class="boris-card boris-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><h2>' . esc_html__( 'Import product catalogue', 'boris-stock-inventory-management' ) . '</h2><p>' . esc_html__( 'The initial stock level is recorded only once per product. Importing again does not duplicate or reset stock.', 'boris-stock-inventory-management' ) . '</p><input type="hidden" name="action" value="boris_stock_import_catalogue">';
		wp_nonce_field( 'boris_stock_import_catalogue' );
		echo '<button class="boris-btn boris-btn--primary">' . esc_html__( 'Run import', 'boris-stock-inventory-management' ) . '</button></form>';

		foreach ( $rows as $row ) {
			$compatible = array_map( static fn( $location ): array => (array) $location, ( new WarehouseQueryService() )->available_locations( (int) $row['boris_product_id'], '', 50, 'all' ) );
			$this->import_product_card( $row, $compatible );
		}
		if ( empty( $rows ) ) {
			echo '<div class="boris-card"><p>' . esc_html__( 'No WooCommerce products with their own stock management were found.', 'boris-stock-inventory-management' ) . '</p></div>';
		}
		if ( 1 < $catalogue['pages'] ) {
			$base = add_query_arg( array( 'page' => 'boris-stock-import', 'paged' => '%#%' ), admin_url( 'admin.php' ) );
			echo '<nav class="boris-pagination">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $catalogue['page'], 'total' => $catalogue['pages'] ) ) ) . '</nav>';
		}
		$this->location_dialog();
		$this->footer();
	}

	/** Create a normal WooCommerce product and allocate its opening warehouse stock. */
	public function new_product(): void {
		global $wpdb;
		$this->guard( 'boris_stock_manage_inventory' );
		$this->guard( 'edit_products' );
		$this->guard( 'upload_files' );

		$retry_key         = isset( $_GET['boris_retry'] ) ? sanitize_text_field( wp_unslash( $_GET['boris_retry'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only recovery key.
		$stored            = wp_is_uuid( $retry_key, 4 ) ? get_transient( $this->product_form_transient_key( $retry_key ) ) : false;
		$form              = is_array( $stored ) ? $stored : array();
		$data              = isset( $form['data'] ) && is_array( $form['data'] ) ? $form['data'] : array();
		$allocated         = isset( $form['allocations'] ) && is_array( $form['allocations'] ) ? $form['allocations'] : array(
			array(
				'location_id' => 0,
				'location_code' => '',
				'quantity'    => '',
			),
		);
		$request           = is_array( $stored ) ? $retry_key : wp_generate_uuid4();
		$product_creation  = new ProductCreationService();
		$retry_product_id  = is_array( $stored ) ? $product_creation->boris_product_id_for_request( $retry_key ) : 0;
		$locations         = ( new WarehouseQueryService() )->available_locations( $retry_product_id, '', 50, 'all' );
		$terms             = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$terms             = is_wp_error( $terms ) ? array() : $terms;
		$folders_available = MediaFolderCompatibility::folders_available();
		$media_folders     = $folders_available ? ( new MediaFolderService() )->folder_choices() : array();

		$value = static fn( string $key, string $fallback = '' ): string => isset( $data[ $key ] ) ? (string) $data[ $key ] : $fallback;
		$ids   = static fn( string $key ): array => isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? array_map( 'absint', $data[ $key ] ) : array();

		$this->header( __( 'New product', 'boris-stock-inventory-management' ), __( 'Create a normal WooCommerce product and place its opening stock in BORIS warehouse locations.', 'boris-stock-inventory-management' ) );
		$this->messages();
		if ( isset( $_GET['boris_product_created'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success state.
			$product_id = absint( $_GET['boris_product_created'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success state.
			echo '<div class="boris-notice boris-notice--success">' . esc_html__( 'The WooCommerce product and BORIS warehouse allocation were created successfully.', 'boris-stock-inventory-management' );
			if ( $product_id && current_user_can( 'edit_post', $product_id ) ) {
				echo ' <a href="' . esc_url( get_edit_post_link( $product_id, 'raw' ) ) . '">' . esc_html__( 'Open product in WooCommerce', 'boris-stock-inventory-management' ) . '</a>';
			}
			echo '</div>';
		}
		if ( is_array( $stored ) ) {
			echo '<div class="boris-notice boris-notice--danger">' . esc_html__( 'Your previous product form was preserved. Correct the problem below and submit the same request again.', 'boris-stock-inventory-management' ) . '</div>';
		}

		echo '<form id="boris-new-product-form" class="boris-product-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="boris_stock_create_product"><input type="hidden" name="request_key" value="' . esc_attr( $request ) . '">';
		wp_nonce_field( 'boris_stock_create_product' );

		echo '<div class="boris-grid boris-grid--product"><section class="boris-card boris-form"><h2>' . esc_html__( 'Product information', 'boris-stock-inventory-management' ) . '</h2>';
		echo '<label>' . esc_html__( 'Product name', 'boris-stock-inventory-management' ) . '<input type="text" name="product_name" maxlength="255" required value="' . esc_attr( $value( 'name' ) ) . '"></label>';
		echo '<div class="boris-field-grid"><label>' . esc_html__( 'SKU / article number', 'boris-stock-inventory-management' ) . '<input type="text" name="sku" maxlength="100" required value="' . esc_attr( $value( 'sku' ) ) . '"></label><label>' . esc_html__( 'GTIN, UPC, EAN or ISBN', 'boris-stock-inventory-management' ) . '<input type="text" name="global_unique_id" inputmode="numeric" maxlength="100" value="' . esc_attr( $value( 'global_unique_id' ) ) . '"></label></div>';
		echo '<div class="boris-field-grid"><label>' . esc_html__( 'Regular price', 'boris-stock-inventory-management' ) . ' (' . wp_kses_post( get_woocommerce_currency_symbol() ) . ')<input type="text" inputmode="decimal" name="regular_price" value="' . esc_attr( $value( 'regular_price' ) ) . '"></label><label>' . esc_html__( 'Sale price', 'boris-stock-inventory-management' ) . ' (' . wp_kses_post( get_woocommerce_currency_symbol() ) . ')<input type="text" inputmode="decimal" name="sale_price" value="' . esc_attr( $value( 'sale_price' ) ) . '"></label></div>';
		echo '<label>' . esc_html__( 'Short description', 'boris-stock-inventory-management' ) . '<textarea name="short_description" rows="4">' . esc_textarea( $value( 'short_description' ) ) . '</textarea></label><label>' . esc_html__( 'Full description', 'boris-stock-inventory-management' ) . '<textarea name="description" rows="8">' . esc_textarea( $value( 'description' ) ) . '</textarea></label>';
		echo '<label>' . esc_html__( 'Product categories', 'boris-stock-inventory-management' ) . '<select id="boris-product-categories" name="category_ids[]" multiple size="6">';
		$selected_categories = $ids( 'category_ids' );
		foreach ( $terms as $term ) {
			echo '<option value="' . esc_attr( (string) $term->term_id ) . '" ' . selected( in_array( (int) $term->term_id, $selected_categories, true ), true, false ) . '>' . esc_html( $term->name ) . '</option>';
		}
		echo '</select></label></section>';

		echo '<section class="boris-card boris-form"><h2>' . esc_html__( 'Product photos', 'boris-stock-inventory-management' ) . '</h2><p class="boris-muted">' . esc_html__( 'Photos remain ordinary WordPress Media Library attachments. BORIS folders are virtual and never move or rename upload files.', 'boris-stock-inventory-management' ) . '</p>';
		$media_folder_id = absint( $value( 'media_folder_id', '0' ) );
		if ( $folders_available ) {
			echo '<div class="boris-photo-folders"><label>' . esc_html__( 'BORIS photo folder', 'boris-stock-inventory-management' ) . '<select id="boris-media-folder" name="media_folder_id"><option value="0">' . esc_html__( 'No BORIS folder', 'boris-stock-inventory-management' ) . '</option>';
			foreach ( $media_folders as $folder ) {
				echo '<option value="' . esc_attr( (string) $folder['id'] ) . '" ' . selected( $media_folder_id, $folder['id'], false ) . '>' . esc_html( $folder['label'] ) . '</option>';
			}
			echo '</select></label><div class="boris-folder-creator"><label>' . esc_html__( 'Create or choose another folder path', 'boris-stock-inventory-management' ) . '<input id="boris-media-folder-path" type="text" maxlength="1000" placeholder="' . esc_attr__( 'Shop / Category / Brand / Product', 'boris-stock-inventory-management' ) . '"></label><div class="boris-inline-actions"><button id="boris-use-folder-suggestion" type="button" class="boris-btn">' . esc_html__( 'Use suggested path', 'boris-stock-inventory-management' ) . '</button><button id="boris-create-media-folder" type="button" class="boris-btn">' . esc_html__( 'Create folder path', 'boris-stock-inventory-management' ) . '</button></div><p id="boris-folder-suggestion" class="boris-muted" aria-live="polite"></p><p id="boris-folder-message" class="boris-muted" aria-live="polite"></p></div></div>';
		} else {
			echo '<input type="hidden" name="media_folder_id" value="0"><div class="boris-notice boris-notice--danger">' . esc_html__( 'BORIS photo folders are unavailable because a confirmed compatibility conflict was detected. You can still select and attach Media Library photos normally.', 'boris-stock-inventory-management' ) . '</div>';
		}
		$image_id    = absint( $value( 'image_id', '0' ) );
		$gallery_ids = array_values( array_diff( $ids( 'gallery_image_ids' ), array( $image_id ) ) );
		$photo_ids   = array_values( array_filter( array_merge( array( $image_id ), $gallery_ids ) ) );
		echo '<div class="boris-media-field boris-product-photos" data-media-mode="product"><input class="boris-main-image-value" type="hidden" name="image_id" value="' . esc_attr( (string) $image_id ) . '"><input class="boris-gallery-value" type="hidden" name="gallery_image_ids" value="' . esc_attr( implode( ',', $gallery_ids ) ) . '"><div class="boris-media-preview boris-media-preview--gallery" aria-live="polite">';
		foreach ( $photo_ids as $photo_id ) {
			$thumbnail = wp_get_attachment_image_url( $photo_id, 'thumbnail' );
			if ( ! $thumbnail ) {
				continue;
			}
			echo '<article class="boris-media-item" draggable="true" data-attachment-id="' . esc_attr( (string) $photo_id ) . '" data-thumbnail="' . esc_url( $thumbnail ) . '" data-alt="' . esc_attr( get_post_meta( $photo_id, '_wp_attachment_image_alt', true ) ) . '"></article>';
		}
		echo '</div><p class="boris-muted">' . esc_html__( 'Select one or more photos, choose exactly one main photo, and drag the remaining photos into gallery order.', 'boris-stock-inventory-management' ) . '</p><div class="boris-inline-actions"><button type="button" class="boris-btn boris-media-choose">' . esc_html__( 'Choose or upload photos', 'boris-stock-inventory-management' ) . '</button><button type="button" class="boris-btn boris-media-clear">' . esc_html__( 'Clear photos', 'boris-stock-inventory-management' ) . '</button></div></div></section>';

		echo '<section class="boris-card boris-form"><h2>' . esc_html__( 'Shipping measurements', 'boris-stock-inventory-management' ) . '</h2><div class="boris-field-grid"><label>' . esc_html__( 'Weight', 'boris-stock-inventory-management' ) . ' (' . esc_html( (string) get_option( 'woocommerce_weight_unit', 'kg' ) ) . ')<input type="text" inputmode="decimal" name="weight" value="' . esc_attr( $value( 'weight' ) ) . '"></label><label>' . esc_html__( 'Length', 'boris-stock-inventory-management' ) . ' (' . esc_html( (string) get_option( 'woocommerce_dimension_unit', 'cm' ) ) . ')<input type="text" inputmode="decimal" name="length" value="' . esc_attr( $value( 'length' ) ) . '"></label><label>' . esc_html__( 'Width', 'boris-stock-inventory-management' ) . ' (' . esc_html( (string) get_option( 'woocommerce_dimension_unit', 'cm' ) ) . ')<input type="text" inputmode="decimal" name="width" value="' . esc_attr( $value( 'width' ) ) . '"></label><label>' . esc_html__( 'Height', 'boris-stock-inventory-management' ) . ' (' . esc_html( (string) get_option( 'woocommerce_dimension_unit', 'cm' ) ) . ')<input type="text" inputmode="decimal" name="height" value="' . esc_attr( $value( 'height' ) ) . '"></label></div></section>';

		echo '<section class="boris-card boris-form"><h2>' . esc_html__( 'Opening warehouse stock', 'boris-stock-inventory-management' ) . '</h2><p class="boris-muted">' . esc_html__( 'WooCommerce receives the total once. BORIS Stock records only how that same total is physically divided across locations.', 'boris-stock-inventory-management' ) . '</p><label>' . esc_html__( 'Total opening stock', 'boris-stock-inventory-management' ) . '<input id="boris-opening-stock" type="number" name="stock_quantity" min="0" step="1" required value="' . esc_attr( $value( 'stock_quantity', '0' ) ) . '"></label><div id="boris-allocation-rows">';
		foreach ( $allocated as $allocation ) {
			$this->allocation_row( $locations, absint( $allocation['location_id'] ?? 0 ), (string) ( $allocation['location_code'] ?? '' ), (string) ( $allocation['quantity'] ?? '' ), $retry_product_id );
		}
		echo '</div><template id="boris-allocation-template">';
		$this->allocation_row( $locations, 0, '', '', $retry_product_id );
		echo '</template><div class="boris-inline-actions"><button id="boris-add-allocation" type="button" class="boris-btn">' . esc_html__( 'Add another location', 'boris-stock-inventory-management' ) . '</button><strong id="boris-allocation-total" class="boris-allocation-total" aria-live="polite"></strong></div></section>';

		echo '<section class="boris-card boris-form"><h2>' . esc_html__( 'Save product', 'boris-stock-inventory-management' ) . '</h2>';
		if ( current_user_can( 'publish_products' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			echo '<label class="boris-checkbox-label"><input type="checkbox" name="publish_immediately" value="yes" ' . checked( $value( 'status', 'draft' ), 'publish', false ) . '> ' . esc_html__( 'Publish product immediately', 'boris-stock-inventory-management' ) . '</label><p class="boris-muted">' . esc_html__( 'Disabled by default. The product is published only after WooCommerce and the complete BORIS opening-stock allocation were saved successfully.', 'boris-stock-inventory-management' ) . '</p>';
		}
		echo '<p class="boris-muted">' . esc_html__( 'If product creation fails, the form is preserved as Draft. If WooCommerce already created a draft, the same request can safely resume it.', 'boris-stock-inventory-management' ) . '</p><button class="boris-btn boris-btn--primary" type="submit">' . esc_html__( 'Create WooCommerce product', 'boris-stock-inventory-management' ) . '</button></section></div></form>';
		$this->location_dialog();
		$this->footer();
	}

	/** Pick-list work queue and detail. */
	public function picklists(): void {
		global $wpdb;
		$this->guard( 'boris_stock_manage_picklists' );
		$t  = Tables::all();
		$id = isset( $_GET['picklist_id'] ) ? absint( wp_unslash( $_GET['picklist_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id ) {
			$this->picklist_detail( $id );
			return;
		}
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT p.*,o.wc_order_id,o.order_number_snapshot FROM %i p INNER JOIN %i o ON o.id=p.boris_order_id ORDER BY FIELD(p.status,'PICKING','READY','SHORTAGE','COMPLETED','CANCELLED'),p.created_at_gmt DESC LIMIT 200", $t['picklists'], $t['orders'] ) );
		$waiting = $wpdb->get_results( $wpdb->prepare( "SELECT o.wc_order_id,o.order_number_snapshot,ol.sku_snapshot,ol.name_snapshot,ol.ordered_quantity FROM %i o INNER JOIN %i ol ON ol.boris_order_id=o.id WHERE o.boris_status='PROBLEM' AND o.picklist_id IS NULL ORDER BY o.created_at_gmt DESC,ol.id LIMIT 200", $t['orders'], $t['order_lines'] ) );
		$this->header( __( 'Pick lists', 'boris-stock-inventory-management' ), __( 'Only fully allocated orders can be processed.', 'boris-stock-inventory-management' ) );
		$this->messages();
		echo '<div class="boris-card boris-table-wrap"><table class="boris-table"><thead><tr><th>' . esc_html__( 'Pick list', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Order', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Status', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Created', 'boris-stock-inventory-management' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$url = add_query_arg(
				array(
					'page'        => 'boris-stock-picklists',
					'picklist_id' => (int) $row->id,
				),
				admin_url( 'admin.php' )
			);
			echo '<tr><td>' . esc_html( $row->display_number ) . '</td><td>#' . esc_html( $row->order_number_snapshot ) . '</td><td>' . wp_kses_post( $this->badge( $row->status ) ) . '</td><td>' . esc_html( get_date_from_gmt( $row->created_at_gmt, 'd-m-Y H:i' ) ) . '</td><td><a class="boris-btn boris-btn--small" href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'boris-stock-inventory-management' ) . '</a></td></tr>';
		}
		echo '</tbody></table></div>';
		if ( ! empty( $waiting ) ) {
			echo '<div class="boris-card boris-table-wrap"><h2 class="boris-table-title">' . esc_html__( 'Waiting for physical location', 'boris-stock-inventory-management' ) . '</h2><table class="boris-table"><thead><tr><th>' . esc_html__( 'Order', 'boris-stock-inventory-management' ) . '</th><th>SKU</th><th>' . esc_html__( 'Product', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Quantity', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Status', 'boris-stock-inventory-management' ) . '</th></tr></thead><tbody>';
			foreach ( $waiting as $line ) {
				echo '<tr><td>#' . esc_html( $line->order_number_snapshot ) . '</td><td>' . esc_html( $line->sku_snapshot ) . '</td><td>' . esc_html( $line->name_snapshot ) . '</td><td>' . esc_html( (string) $line->ordered_quantity ) . '</td><td><span class="boris-badge boris-badge--warning">' . esc_html__( 'No pick location yet', 'boris-stock-inventory-management' ) . '</span></td></tr>';
			}
			echo '</tbody></table><p class="boris-table-note">' . esc_html__( 'These order lines remain visible, but can only be processed after enough physical location stock has been placed.', 'boris-stock-inventory-management' ) . '</p></div>';
		}
		$this->footer();
	}

	/** Persistent problems. */
	public function problems(): void {
		global $wpdb;
		$this->guard( 'boris_stock_view' );
		$t    = Tables::all();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status='OPEN' ORDER BY FIELD(severity,'ERROR','WARNING','INFO'),last_seen_at_gmt DESC LIMIT 250", $t['problems'] ) );
		$this->header( __( 'Persistent issues', 'boris-stock-inventory-management' ), __( 'These notices remain until recovery has been confirmed.', 'boris-stock-inventory-management' ) );
		foreach ( $rows as $row ) {
			echo '<article class="boris-card boris-problem"><div><span class="boris-badge boris-badge--danger">' . esc_html__( 'ISSUE', 'boris-stock-inventory-management' ) . '</span> <code>' . esc_html( $row->code ) . '</code></div><h2>' . esc_html( $row->message ) . '</h2><p class="boris-muted">' . esc_html( get_date_from_gmt( $row->last_seen_at_gmt, 'd-m-Y H:i:s' ) ) . '</p></article>';
		}
		if ( empty( $rows ) ) {
			echo '<div class="boris-card"><p>' . esc_html__( 'No open issues.', 'boris-stock-inventory-management' ) . '</p></div>';
		}
		$this->footer();
	}

	/** Immutable mutation ledger. */
	public function ledger(): void {
		global $wpdb;
		$this->guard( 'boris_stock_view' );
		$t    = Tables::all();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT m.*,p.sku,l.code FROM %i m INNER JOIN %i p ON p.id=m.boris_product_id LEFT JOIN %i l ON l.id=m.location_id ORDER BY m.id DESC LIMIT 500', $t['mutations'], $t['products'], $t['locations'] ) );
		$this->header( __( 'Inventory ledger', 'boris-stock-inventory-management' ), __( 'Immutable audit history of every physical stock movement.', 'boris-stock-inventory-management' ) );
		echo '<div class="boris-card boris-table-wrap"><table class="boris-table"><thead><tr><th>ID</th><th>' . esc_html__( 'Time', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Type', 'boris-stock-inventory-management' ) . '</th><th>SKU</th><th>' . esc_html__( 'Location', 'boris-stock-inventory-management' ) . '</th><th>&Delta;</th><th>' . esc_html__( 'Before → after', 'boris-stock-inventory-management' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( (string) $row->id ) . '</td><td>' . esc_html( get_date_from_gmt( $row->created_at_gmt, 'd-m-Y H:i:s' ) ) . '</td><td>' . esc_html( $row->mutation_type ) . '</td><td>' . esc_html( $row->sku ) . '</td><td>' . esc_html( (string) $row->code ) . '</td><td>' . esc_html( (string) $row->quantity_delta ) . '</td><td>' . esc_html( $row->on_hand_before . ' → ' . $row->on_hand_after ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		$this->footer();
	}

	/** Warehouse mail settings and safe uninstall opt-in. */
	public function settings(): void {
		$this->guard( 'boris_stock_manage_settings' );
		$this->header( __( 'Settings', 'boris-stock-inventory-management' ), __( 'Local plugin settings; there is no BORIS Cloud dependency.', 'boris-stock-inventory-management' ) );
		$this->messages();
		echo '<form class="boris-card boris-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="boris_stock_save_settings">';
		wp_nonce_field( 'boris_stock_save_settings' );
		echo '<label>' . esc_html__( 'Warehouse email address', 'boris-stock-inventory-management' ) . '<input type="email" name="warehouse_email" value="' . esc_attr( (string) get_option( 'boris_stock_warehouse_email', '' ) ) . '" autocomplete="email" placeholder="warehouse@example.com"></label><div class="boris-inline-actions"><button class="boris-btn" type="submit" name="boris_stock_settings_action" value="send_test_email">' . esc_html__( 'Send test email', 'boris-stock-inventory-management' ) . '</button></div>';
		echo '<label><input type="checkbox" name="auto_email_picklist" value="yes" ' . checked( 'yes', get_option( 'boris_stock_auto_email_picklist', 'no' ), false ) . '> ' . esc_html__( 'Email pick lists automatically', 'boris-stock-inventory-management' ) . '</label><p class="boris-muted">' . esc_html__( 'Disabled by default. Email is sent only after a complete, processable pick list has been created.', 'boris-stock-inventory-management' ) . '</p>';
		echo '<label>' . esc_html__( 'Issue notification email address (optional)', 'boris-stock-inventory-management' ) . '<input type="email" name="problem_email" value="' . esc_attr( (string) get_option( 'boris_stock_problem_email', '' ) ) . '" autocomplete="email" placeholder="beheer@example.com"></label><p class="boris-muted">' . esc_html__( 'Receives a short warning when WordPress cannot hand off a warehouse email. No customer data is included.', 'boris-stock-inventory-management' ) . '</p>';
		echo '<label><input type="checkbox" name="delete_data" value="yes" ' . checked( 'yes', get_option( 'boris_stock_delete_data_on_uninstall', 'no' ), false ) . '> ' . esc_html__( 'Delete all BORIS Stock plugin data on uninstall', 'boris-stock-inventory-management' ) . '</label><p class="boris-muted">' . esc_html__( 'Deactivation always preserves all data. This option applies only when the plugin is explicitly deleted.', 'boris-stock-inventory-management' ) . '</p><button class="boris-btn boris-btn--primary">' . esc_html__( 'Save', 'boris-stock-inventory-management' ) . '</button></form>';
		echo '<div class="boris-card"><h2>' . esc_html__( 'Support and documentation', 'boris-stock-inventory-management' ) . '</h2><p><a href="' . esc_url( BORIS_STOCK_PRODUCT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'User Manual', 'boris-stock-inventory-management' ) . '</a> · <a href="' . esc_url( BORIS_STOCK_PRODUCT_URL ) . '" target="_blank" rel="noopener noreferrer">borissystems.com</a> · <a href="' . esc_url( 'mailto:support@borissystems.com' ) . '">support@borissystems.com</a></p></div>';
		$this->footer();
	}

	/** Form handlers. */
	public function create_location(): void {
		$this->verify( 'boris_stock_manage_locations', 'boris_stock_create_location' );
		$this->handle( static fn() => ( new InventoryService() )->create_location( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ), sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ) ), 'boris-stock-inventory' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
	}

	/** Create a location from the import dialog without losing form context. */
	public function create_location_ajax(): void {
		check_ajax_referer( 'boris_stock_create_location_ajax', 'nonce' );
		if ( ! current_user_can( 'boris_stock_manage_locations' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create locations.', 'boris-stock-inventory-management' ) ), 403 );
		}
		try {
			$code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			$result = ( new InventoryService() )->create_or_reuse_empty_location( $code, sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			wp_send_json_success(
				array(
					'id'      => $result['id'],
					'code'    => $code,
					'created' => $result['created'],
				)
			);
		} catch ( \Throwable $throwable ) {
			wp_send_json_error( array( 'message' => $throwable->getMessage() ), 400 );
		}
	}

	/** Return at most 50 compatible locations for one server-side product mapping. */
	public function search_locations_ajax(): void {
		check_ajax_referer( 'boris_stock_create_location_ajax', 'nonce' );
		$this->guard( 'boris_stock_manage_inventory' );
		$product_id = absint( $_GET['boris_product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- AJAX nonce verified above.
		$search     = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- AJAX nonce verified above.
		$filter     = sanitize_key( wp_unslash( $_GET['availability'] ?? 'all' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- AJAX nonce verified above.
		$rows       = ( new WarehouseQueryService() )->available_locations( $product_id, $search, 50, $filter );
		wp_send_json_success(
			array_map(
				static fn( $row ): array => array(
					'id'        => (int) $row->id,
					'code'      => (string) $row->code,
					'quantity'  => (int) $row->quantity_on_hand,
					'reserved'  => (int) $row->reserved,
					'available' => max( 0, (int) $row->quantity_on_hand - (int) $row->reserved ),
				),
				$rows
			)
		);
	}

	/** Create a virtual BORIS photo-folder path without touching upload files. */
	public function create_media_folder_ajax(): void {
		check_ajax_referer( 'boris_stock_create_media_folder', 'nonce' );
		if ( ! current_user_can( 'boris_stock_manage_inventory' ) || ! current_user_can( 'upload_files' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Activator.
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create photo folders.', 'boris-stock-inventory-management' ) ), 403 );
		}
		if ( ! MediaFolderCompatibility::folders_available() ) {
			wp_send_json_error( array( 'message' => __( 'BORIS photo folders are disabled because a confirmed compatibility conflict was detected.', 'boris-stock-inventory-management' ) ), 409 );
		}

		try {
			$service = new MediaFolderService();
			$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			if ( '' !== $name ) {
				$folder = $service->create_folder( $name, absint( $_POST['parent_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			} else {
				$path   = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
				$folder = $service->create_path( $path );
			}
			$folder['tree'] = $service->folder_tree();
			wp_send_json_success( $folder );
		} catch ( \Throwable $throwable ) {
			wp_send_json_error( array( 'message' => $throwable->getMessage() ), 400 );
		}
	}

	/** Return the current BORIS media-folder hierarchy. */
	public function get_media_folders_ajax(): void {
		$this->verify_media_folder_ajax();
		wp_send_json_success( array( 'tree' => ( new MediaFolderService() )->folder_tree() ) );
	}

	/** Replace the BORIS folder assignment for one or more attachments. */
	public function assign_media_folder_ajax(): void {
		$this->verify_media_folder_ajax();
		$raw_ids        = isset( $_POST['attachment_ids'] ) && is_array( $_POST['attachment_ids'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['attachment_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );
		$folder_id      = absint( $_POST['folder_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		if ( empty( $attachment_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Select one or more media items first.', 'boris-stock-inventory-management' ) ), 400 );
		}

		try {
			$service = new MediaFolderService();
			$service->assign( $folder_id, $attachment_ids );
			wp_send_json_success( array( 'tree' => $service->folder_tree() ) );
		} catch ( \Throwable $throwable ) {
			wp_send_json_error( array( 'message' => $throwable->getMessage() ), 400 );
		}
	}

	/** Delete a logical folder while preserving every WordPress attachment. */
	public function delete_media_folder_ajax(): void {
		$this->verify_media_folder_ajax();
		try {
			$service = new MediaFolderService();
			$service->delete_folder( absint( $_POST['folder_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			wp_send_json_success( array( 'tree' => $service->folder_tree() ) );
		} catch ( \Throwable $throwable ) {
			wp_send_json_error( array( 'message' => $throwable->getMessage() ), 400 );
		}
	}

	/** Verify the shared nonce, capabilities and compatibility gate for media-tree actions. */
	private function verify_media_folder_ajax(): void {
		check_ajax_referer( 'boris_stock_media_tree', 'nonce' );
		if ( ! current_user_can( 'boris_stock_manage_inventory' ) || ! current_user_can( 'upload_files' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Activator.
			wp_send_json_error( array( 'message' => __( 'You do not have permission to organize product photos.', 'boris-stock-inventory-management' ) ), 403 );
		}
		if ( ! MediaFolderCompatibility::folders_available() ) {
			wp_send_json_error( array( 'message' => __( 'BORIS photo folders are disabled because a confirmed compatibility conflict was detected.', 'boris-stock-inventory-management' ) ), 409 );
		}
	}

	/** Explicit catalogue import; ordinary product sync never resets its baseline. */
	public function import_catalogue(): void {
		$this->verify( 'boris_stock_manage_inventory', 'boris_stock_import_catalogue' );
		try {
			$result = ( new ProductService() )->import_catalogue();
			set_transient( 'boris_stock_import_result_' . get_current_user_id(), $result, DAY_IN_SECONDS );
			$this->redirect( 'boris-stock-import', array( 'boris_saved' => 1 ) );
		} catch ( \Throwable $throwable ) {
			$this->redirect( 'boris-stock-import', array( 'boris_error' => $throwable->getMessage() ) );
		}
	}

	/** Build and store a read-only preview of catalogue photo organization. */
	public function analyze_product_photos(): void {
		$this->verify( 'boris_stock_manage_inventory', 'boris_stock_analyze_product_photos' );
		$this->guard( 'edit_products' );
		$this->guard( 'upload_files' );
		if ( ! MediaFolderCompatibility::folders_available() ) {
			$this->redirect( 'boris-stock-import', array( 'boris_error' => __( 'BORIS photo folders are disabled because a confirmed compatibility conflict was detected.', 'boris-stock-inventory-management' ) ) );
		}

		try {
			$analysis = ( new CatalogMediaOrganizer() )->analyze();
			set_transient( $this->catalog_media_analysis_key(), $analysis, DAY_IN_SECONDS );
			delete_transient( $this->catalog_media_result_key() );
			$this->redirect( 'boris-stock-import', array( 'boris_media_analyzed' => 1 ) );
		} catch ( \Throwable $throwable ) {
			$this->redirect( 'boris-stock-import', array( 'boris_error' => $throwable->getMessage() ) );
		}
	}

	/** Start a resumable, explicitly approved catalogue-photo organization job. */
	public function start_product_photo_organization(): void {
		$this->verify( 'boris_stock_manage_inventory', 'boris_stock_start_product_photo_organization' );
		$this->guard( 'edit_products' );
		$this->guard( 'upload_files' );
		$analysis = get_transient( $this->catalog_media_analysis_key() );
		if ( ! is_array( $analysis ) ) {
			$this->redirect( 'boris-stock-import', array( 'boris_error' => __( 'The photo analysis has expired. Run the analysis again before organizing.', 'boris-stock-inventory-management' ) ) );
		}

		$raw_choices = isset( $_POST['category_choice'] ) && is_array( $_POST['category_choice'] ) ? map_deep( wp_unslash( $_POST['category_choice'] ), 'absint' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; every key and value is normalized below.
		$choices = array();
		foreach ( $raw_choices as $product_id => $category_id ) {
			$choices[ absint( $product_id ) ] = absint( $category_id );
		}

		try {
			$job = ( new CatalogMediaOrganizer() )->create_job( $analysis, $choices );
			if ( 0 === (int) $job['total'] ) {
				$job['complete']         = true;
				$job['completed_at_gmt'] = gmdate( 'Y-m-d H:i:s' );
				set_transient( $this->catalog_media_result_key(), $job, DAY_IN_SECONDS );
				delete_transient( $this->catalog_media_job_key() );
				$this->redirect( 'boris-stock-import', array( 'boris_media_organized' => 1 ) );
			}
			set_transient( $this->catalog_media_job_key(), $job, DAY_IN_SECONDS );
			delete_transient( $this->catalog_media_result_key() );
			$this->redirect( 'boris-stock-import', array( 'boris_media_organizing' => 1 ) );
		} catch ( \Throwable $throwable ) {
			$this->redirect( 'boris-stock-import', array( 'boris_error' => $throwable->getMessage() ) );
		}
	}

	/** Process one bounded catalogue-photo batch through an authenticated AJAX call. */
	public function process_product_photo_organization_ajax(): void {
		check_ajax_referer( 'boris_stock_catalog_media', 'nonce' );
		if ( ! current_user_can( 'boris_stock_manage_inventory' ) || ! current_user_can( 'edit_products' ) || ! current_user_can( 'upload_files' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Activator/WooCommerce.
			wp_send_json_error( array( 'message' => __( 'You do not have permission to organize product photos.', 'boris-stock-inventory-management' ) ), 403 );
		}

		$job = get_transient( $this->catalog_media_job_key() );
		if ( ! is_array( $job ) ) {
			$result = get_transient( $this->catalog_media_result_key() );
			wp_send_json_success( array( 'complete' => is_array( $result ), 'processed' => absint( $result['processed'] ?? 0 ), 'total' => absint( $result['total'] ?? 0 ) ) );
		}

		$lock_key = $this->catalog_media_lock_key();
		if ( get_transient( $lock_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Another photo-organization batch is still running. Please wait.', 'boris-stock-inventory-management' ) ), 409 );
		}
		set_transient( $lock_key, 1, MINUTE_IN_SECONDS );

		try {
			$job = ( new CatalogMediaOrganizer() )->process_batch( $job, 25 );
			if ( ! empty( $job['complete'] ) ) {
				set_transient( $this->catalog_media_result_key(), $job, DAY_IN_SECONDS );
				delete_transient( $this->catalog_media_job_key() );
			} else {
				set_transient( $this->catalog_media_job_key(), $job, DAY_IN_SECONDS );
			}
			delete_transient( $lock_key );
			wp_send_json_success(
				array(
					'complete'  => ! empty( $job['complete'] ),
					'processed' => absint( $job['processed'] ?? 0 ),
					'total'     => absint( $job['total'] ?? 0 ),
					'errors'    => count( $job['errors'] ?? array() ),
				)
			);
		} catch ( \Throwable $throwable ) {
			delete_transient( $lock_key );
			wp_send_json_error( array( 'message' => $throwable->getMessage() ), 500 );
		}
	}

	/** Handle the protected WooCommerce product-creation form. */
	public function create_product(): void {
		$this->verify( 'boris_stock_manage_inventory', 'boris_stock_create_product' );
		$this->guard( 'edit_products' );
		$this->guard( 'upload_files' );

		$request_key = sanitize_text_field( wp_unslash( $_POST['request_key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$status      = isset( $_POST['publish_immediately'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['publish_immediately'] ) ) ? 'publish' : 'draft'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		if ( 'publish' === $status ) {
			$this->guard( 'publish_products' );
		}

		$raw_categories = isset( $_POST['category_ids'] ) && is_array( $_POST['category_ids'] ) ? map_deep( wp_unslash( $_POST['category_ids'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$gallery        = sanitize_text_field( wp_unslash( $_POST['gallery_image_ids'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$data           = array(
			'name'              => sanitize_text_field( wp_unslash( $_POST['product_name'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'sku'               => sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'global_unique_id'  => sanitize_text_field( wp_unslash( $_POST['global_unique_id'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'regular_price'     => sanitize_text_field( wp_unslash( $_POST['regular_price'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'sale_price'        => sanitize_text_field( wp_unslash( $_POST['sale_price'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'short_description' => wp_kses_post( wp_unslash( $_POST['short_description'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'description'       => wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'category_ids'      => array_map( 'absint', $raw_categories ),
			'image_id'          => absint( $_POST['image_id'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'gallery_image_ids' => '' === $gallery ? array() : array_map( 'absint', explode( ',', $gallery ) ),
			'media_folder_id'   => absint( $_POST['media_folder_id'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'weight'            => sanitize_text_field( wp_unslash( $_POST['weight'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'length'            => sanitize_text_field( wp_unslash( $_POST['length'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'width'             => sanitize_text_field( wp_unslash( $_POST['width'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'height'            => sanitize_text_field( wp_unslash( $_POST['height'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'stock_quantity'    => sanitize_text_field( wp_unslash( $_POST['stock_quantity'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'status'            => $status,
		);
		$location_ids   = isset( $_POST['allocation_location'] ) && is_array( $_POST['allocation_location'] ) ? map_deep( wp_unslash( $_POST['allocation_location'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$location_codes = isset( $_POST['allocation_location_code'] ) && is_array( $_POST['allocation_location_code'] ) ? map_deep( wp_unslash( $_POST['allocation_location_code'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$quantities     = isset( $_POST['allocation_quantity'] ) && is_array( $_POST['allocation_quantity'] ) ? map_deep( wp_unslash( $_POST['allocation_quantity'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$allocations    = array();
		$row_count      = max( count( $location_ids ), count( $location_codes ), count( $quantities ) );
		for ( $index = 0; $index < $row_count; ++$index ) {
			$allocations[] = array(
				'location_id' => absint( $location_ids[ $index ] ?? 0 ),
				'location_code' => sanitize_text_field( (string) ( $location_codes[ $index ] ?? '' ) ),
				'quantity'    => sanitize_text_field( (string) ( $quantities[ $index ] ?? '' ) ),
			);
		}
		$form_state = array(
			'data'        => $data,
			'allocations' => $allocations,
		);
		if ( wp_is_uuid( $request_key, 4 ) ) {
			set_transient( $this->product_form_transient_key( $request_key ), $form_state, DAY_IN_SECONDS );
		}

		try {
			$result = ( new ProductCreationService() )->create( $request_key, $data, $allocations, get_current_user_id() );
			delete_transient( $this->product_form_transient_key( $request_key ) );
			$this->redirect(
				'boris-stock-new-product',
				array(
					'boris_product_created' => $result['wc_product_id'],
				)
			);
		} catch ( \Throwable $throwable ) {
			$this->redirect(
				'boris-stock-new-product',
				array(
					'boris_error' => $throwable->getMessage(),
					'boris_retry' => $request_key,
				)
			);
		}
	}

	/** Change only Draft/Published for one WooCommerce product from the Inventory overview. */
	public function update_product_status(): void {
		$product_id = absint( $_POST['product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below.
		check_admin_referer( 'boris_stock_update_product_status_' . $product_id );
		if ( 1 > $product_id || ! current_user_can( 'edit_post', $product_id ) ) {
			wp_die(
				esc_html__( 'You do not have permission to edit this product.', 'boris-stock-inventory-management' ),
				esc_html__( 'Access denied', 'boris-stock-inventory-management' ),
				array( 'response' => 403 )
			);
		}
		$status = sanitize_key( wp_unslash( $_POST['product_status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		if ( 'publish' === $status && ! current_user_can( 'publish_products' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce product capability.
			wp_die(
				esc_html__( 'You do not have permission to publish products.', 'boris-stock-inventory-management' ),
				esc_html__( 'Access denied', 'boris-stock-inventory-management' ),
				array( 'response' => 403 )
			);
		}
		$fallback = add_query_arg( array( 'page' => 'boris-stock-inventory', 'tab' => 'products' ), admin_url( 'admin.php' ) );
		$redirect = wp_validate_redirect( wp_get_referer(), $fallback );
		$redirect = remove_query_arg( array( 'boris_product_status', 'boris_status_product', 'boris_error' ), $redirect );
		try {
			( new ProductPublicationService() )->set_status( $product_id, $status );
			$redirect = add_query_arg( array( 'boris_product_status' => $status, 'boris_status_product' => $product_id ), $redirect );
		} catch ( \Throwable $throwable ) {
			$redirect = add_query_arg( 'boris_error', $throwable->getMessage(), $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/** Update catalogue fields for an existing product without changing stock. */
	public function update_product(): void {
		$this->verify( 'boris_stock_manage_inventory', 'boris_stock_update_product' );
		$this->guard( 'edit_products' );
		$product_id = absint( $_POST['product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$data       = array(
			'name'              => sanitize_text_field( wp_unslash( $_POST['product_name'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'sku'               => sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'global_unique_id'  => sanitize_text_field( wp_unslash( $_POST['global_unique_id'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'regular_price'     => sanitize_text_field( wp_unslash( $_POST['regular_price'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'sale_price'        => sanitize_text_field( wp_unslash( $_POST['sale_price'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'cost_price'        => sanitize_text_field( wp_unslash( $_POST['cost_price'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'short_description' => wp_kses_post( wp_unslash( $_POST['short_description'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'description'       => wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'category_ids'      => isset( $_POST['category_ids'] ) && is_array( $_POST['category_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['category_ids'] ) ) : array(), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'image_id'          => absint( $_POST['image_id'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			'gallery_image_ids' => array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['gallery_image_ids'] ?? '' ) ) ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		);
		try {
			( new ProductUpdateService() )->update( $product_id, $data );
			$this->redirect( 'boris-stock-inventory', array( 'product_id' => $product_id, 'boris_success' => 1 ) );
		} catch ( \Throwable $throwable ) {
			$this->redirect( 'boris-stock-inventory', array( 'product_id' => $product_id, 'boris_error' => $throwable->getMessage() ) );
		}
	}

	/** Place imported opening stock on a physical location. */
	public function place_initial_stock(): void {
		$this->verify( 'boris_stock_manage_inventory', 'boris_stock_place_initial_stock' );
		$this->handle( static fn() => ( new InitialStockService() )->place( sanitize_text_field( wp_unslash( $_POST['request_key'] ?? '' ) ), absint( $_POST['boris_product_id'] ?? 0 ), absint( $_POST['location_id'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['quantity'] ?? '' ) ), get_current_user_id() ), 'boris-stock-import' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
	}

	public function stock_in(): void {
		$this->verify( 'boris_stock_manage_inventory', 'boris_stock_stock_in' );
		$product_id = absint( $_POST['product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$publish    = isset( $_POST['publish_after_receive'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['publish_after_receive'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		if ( $publish && ( ! current_user_can( 'edit_post', $product_id ) || ! current_user_can( 'publish_products' ) ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce product capability.
			wp_die(
				esc_html__( 'You do not have permission to publish this product.', 'boris-stock-inventory-management' ),
				esc_html__( 'Access denied', 'boris-stock-inventory-management' ),
				array( 'response' => 403 )
			);
		}
		try {
			( new InventoryService() )->stock_in(
				sanitize_text_field( wp_unslash( $_POST['request_key'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
				$product_id,
				absint( $_POST['location_id'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
				sanitize_text_field( wp_unslash( $_POST['quantity'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
				get_current_user_id(),
				sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			);
		} catch ( \Throwable $throwable ) {
			$this->redirect( 'boris-stock-inventory', array( 'product_id' => $product_id, 'boris_error' => $throwable->getMessage() ) );
		}

		if ( $publish ) {
			try {
				( new ProductPublicationService() )->set_status( $product_id, 'publish' );
			} catch ( \Throwable $throwable ) {
				$this->redirect(
					'boris-stock-inventory',
					array(
						'product_id'           => $product_id,
						'boris_saved'          => 1,
						'boris_receive_warning' => $throwable->getMessage(),
					)
				);
			}
		}
		$this->redirect(
			'boris-stock-inventory',
			array(
				'product_id'                   => $product_id,
				'boris_saved'                  => 1,
				'boris_published_after_receive' => $publish ? 1 : 0,
			)
		);
	}

	public function stock_out(): void {
		$this->verify( 'boris_stock_manage_inventory', 'boris_stock_stock_out' );
		$product_id = absint( $_POST['product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$this->handle( static fn() => ( new InventoryService() )->stock_out( sanitize_text_field( wp_unslash( $_POST['request_key'] ?? '' ) ), absint( $_POST['location_stock_id'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['quantity'] ?? '' ) ), get_current_user_id(), sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ) ), 'boris-stock-inventory', array( 'product_id' => $product_id ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
	}

	public function transfer(): void {
		$this->verify( 'boris_stock_manage_inventory', 'boris_stock_transfer' );
		$product_id = absint( $_POST['product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$this->handle( static fn() => ( new InventoryService() )->transfer( sanitize_text_field( wp_unslash( $_POST['request_key'] ?? '' ) ), absint( $_POST['from_stock_id'] ?? 0 ), absint( $_POST['to_location_id'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['quantity'] ?? '' ) ), get_current_user_id(), sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ) ), 'boris-stock-inventory', array( 'product_id' => $product_id ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
	}

	/** Start a ready pick list through an intentional POST request. */
	public function start_picklist(): void {
		$this->verify( 'boris_stock_manage_picklists', 'boris_stock_start_picklist' );
		$id = absint( $_POST['picklist_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		if ( 1 > $id ) {
			$this->redirect(
				'boris-stock-picklists',
				array( 'boris_error' => __( 'Invalid pick list.', 'boris-stock-inventory-management' ) )
			);
		}
		$this->handle( static fn() => ( new PickListService() )->start( $id ), 'boris-stock-picklists', array( 'picklist_id' => $id ) );
	}

	public function complete_picklist(): void {
		$this->verify( 'boris_stock_manage_picklists', 'boris_stock_complete_picklist' );
		$id     = absint( $_POST['picklist_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$raw    = isset( $_POST['picked'] ) && is_array( $_POST['picked'] ) ? map_deep( wp_unslash( $_POST['picked'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$picked = array();
		foreach ( $raw as $line_id => $quantity ) {
			$picked[ absint( $line_id ) ] = sanitize_text_field( $quantity );
		}
		$this->handle( static fn() => ( new PickListService() )->complete( $id, $picked, get_current_user_id() ), 'boris-stock-picklists', array( 'picklist_id' => $id ) );
	}

	public function mail_picklist(): void {
		$this->verify( 'boris_stock_manage_picklists', 'boris_stock_mail_picklist' );
		$id        = absint( $_POST['picklist_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$retry     = isset( $_POST['retry'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$raw_email = sanitize_text_field( wp_unslash( $_POST['alternate_email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$alternate = sanitize_email( $raw_email );
		if ( '' !== $raw_email && ! is_email( $alternate ) ) {
			$this->redirect(
				'boris-stock-picklists',
				array(
					'picklist_id' => $id,
					'boris_error' => __( 'Enter a valid alternative email address.', 'boris-stock-inventory-management' ),
				)
			);
		}
		try {
			$result = ( new PickListMailer() )->send( $id, $retry, $alternate );
			if ( 'failed' === $result ) {
				throw new \RuntimeException( __( 'The warehouse email failed. The pick list and inventory were not changed.', 'boris-stock-inventory-management' ) );
			}
			if ( in_array( $result, array( 'in_progress', 'uncertain' ), true ) ) {
				throw new \RuntimeException( __( 'The previous email operation is still in progress or uncertain and was not retried automatically.', 'boris-stock-inventory-management' ) );
			}
			$this->redirect(
				'boris-stock-picklists',
				array(
					'picklist_id'     => $id,
					'boris_mail_sent' => 1,
				)
			);
		} catch ( \Throwable $throwable ) {
			$this->redirect(
				'boris-stock-picklists',
				array(
					'picklist_id' => $id,
					'boris_error' => $throwable->getMessage(),
				)
			);
		}
	}

	public function sync_products(): void {
		$this->verify( 'boris_stock_manage_inventory', 'boris_stock_sync_products' );
		$this->handle( static fn() => ( new ProductService() )->synchronize_catalogue(), 'boris-stock-import' );
	}

	public function save_settings(): void {
		$this->verify( 'boris_stock_manage_settings', 'boris_stock_save_settings' );
		$warehouse = sanitize_email( wp_unslash( $_POST['warehouse_email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$action    = sanitize_key( wp_unslash( $_POST['boris_stock_settings_action'] ?? 'save' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		if ( 'send_test_email' === $action ) {
			if ( ! is_email( $warehouse ) ) {
				$this->redirect( 'boris-stock-settings', array( 'boris_error' => __( 'Enter a valid warehouse email address.', 'boris-stock-inventory-management' ) ) );
			}
			if ( ( new PickListMailer() )->send_test( $warehouse ) ) {
				$this->redirect( 'boris-stock-settings', array( 'boris_test_email_sent' => 1 ) );
			}
			$this->redirect( 'boris-stock-settings', array( 'boris_error' => __( 'WordPress could not send the test email. Check the site email configuration.', 'boris-stock-inventory-management' ) ) );
		}
		$problem   = sanitize_email( wp_unslash( $_POST['problem_email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$automatic = isset( $_POST['auto_email_picklist'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		if ( ( '' !== $warehouse && ! is_email( $warehouse ) ) || ( '' !== $problem && ! is_email( $problem ) ) ) {
			$this->redirect( 'boris-stock-settings', array( 'boris_error' => __( 'Enter valid email addresses only.', 'boris-stock-inventory-management' ) ) );
		}
		if ( $automatic && ! is_email( $warehouse ) ) {
			$this->redirect( 'boris-stock-settings', array( 'boris_error' => __( 'A valid warehouse email address is required for automatic email.', 'boris-stock-inventory-management' ) ) );
		}
		update_option( 'boris_stock_warehouse_email', $warehouse, false );
		update_option( 'boris_stock_auto_email_picklist', $automatic ? 'yes' : 'no', false );
		update_option( 'boris_stock_problem_email', $problem, false );
		update_option( 'boris_stock_delete_data_on_uninstall', isset( $_POST['delete_data'] ) ? 'yes' : 'no', false ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$this->redirect( 'boris-stock-settings', array( 'boris_saved' => 1 ) );
	}

	/** Render one pick list and printable version. */
	private function picklist_detail( int $id ): void {
		global $wpdb;
		$t        = Tables::all();
		$picklist = $wpdb->get_row( $wpdb->prepare( 'SELECT p.*,o.order_number_snapshot,o.wc_order_id FROM %i p INNER JOIN %i o ON o.id=p.boris_order_id WHERE p.id=%d', $t['picklists'], $t['orders'], $id ) );
		$lines    = $wpdb->get_results( $wpdb->prepare( 'SELECT pll.*,ol.wc_product_id FROM %i pll INNER JOIN %i ol ON ol.id=pll.order_line_id WHERE pll.picklist_id=%d ORDER BY pll.location_code_snapshot,pll.sku_snapshot,pll.id', $t['picklist_lines'], $t['order_lines'], $id ) );
		if ( ! $picklist ) {
			wp_die( esc_html__( 'Pick list not found.', 'boris-stock-inventory-management' ) );
		}
		$document = isset( $_GET['document'] ) && 'packing-slip' === sanitize_key( wp_unslash( $_GET['document'] ) ) ? 'packing-slip' : 'picklist'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only document selection.
		$order_data = ( new OrderDocumentService() )->for_order( (int) $picklist->wc_order_id );
		$title      = 'packing-slip' === $document ? sprintf( /* translators: %s is an order number. */ __( 'Packing slip – order #%s', 'boris-stock-inventory-management' ), $picklist->order_number_snapshot ) : $picklist->display_number;
		/* translators: %s is the WooCommerce order number. */
		$this->picklist_header( $title, sprintf( __( 'WooCommerce order #%s', 'boris-stock-inventory-management' ), $picklist->order_number_snapshot ) );
		$this->messages();
		$pick_url = add_query_arg( array( 'page' => 'boris-stock-picklists', 'picklist_id' => $id, 'document' => 'picklist' ), admin_url( 'admin.php' ) );
		$pack_url = add_query_arg( array( 'page' => 'boris-stock-picklists', 'picklist_id' => $id, 'document' => 'packing-slip' ), admin_url( 'admin.php' ) );
		echo '<div class="boris-print-actions"><a class="boris-btn' . ( 'picklist' === $document ? ' boris-btn--primary' : '' ) . '" href="' . esc_url( $pick_url ) . '">' . esc_html__( 'Pick list', 'boris-stock-inventory-management' ) . '</a><a class="boris-btn' . ( 'packing-slip' === $document ? ' boris-btn--primary' : '' ) . '" href="' . esc_url( $pack_url ) . '">' . esc_html__( 'Packing slip', 'boris-stock-inventory-management' ) . '</a><button class="boris-btn" type="button" onclick="window.print()">' . esc_html__( 'Print / Save as PDF', 'boris-stock-inventory-management' ) . '</button></div><div class="boris-card boris-pick-sheet boris-print-document"><div class="boris-pick-meta"><strong>' . esc_html( $title ) . '</strong>' . wp_kses_post( $this->badge( $picklist->status ) ) . '<span>' . esc_html( get_date_from_gmt( $picklist->created_at_gmt, 'd-m-Y H:i' ) ) . '</span></div>';
		$this->order_address( $order_data );
		$can_complete = 'PICKING' === $picklist->status;
		$is_active    = in_array( $picklist->status, array( 'READY', 'PICKING' ), true );
		if ( 'picklist' === $document && 'READY' === $picklist->status ) {
			echo '<form class="boris-start-picking" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="boris_stock_start_picklist"><input type="hidden" name="picklist_id" value="' . esc_attr( (string) $id ) . '">';
			wp_nonce_field( 'boris_stock_start_picklist' );
			echo '<button class="boris-btn boris-btn--primary">' . esc_html__( 'Start picking', 'boris-stock-inventory-management' ) . '</button><p class="boris-muted">' . esc_html__( 'Start the pick list before confirming actual picked quantities.', 'boris-stock-inventory-management' ) . '</p></form>';
		}
		if ( 'picklist' === $document && $can_complete ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="boris_stock_complete_picklist"><input type="hidden" name="picklist_id" value="' . esc_attr( (string) $id ) . '">';
			wp_nonce_field( 'boris_stock_complete_picklist' );
		}
		if ( 'packing-slip' === $document ) {
			echo '<table class="boris-table boris-pick-table boris-packing-table"><thead><tr><th>SKU</th><th>' . esc_html__( 'Product', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Quantity', 'boris-stock-inventory-management' ) . '</th></tr></thead><tbody>';
			foreach ( $order_data['lines'] ?? array() as $line ) {
				echo '<tr><td>' . esc_html( $line['sku'] ) . '</td><td>' . esc_html( $line['name'] ) . '</td><td class="boris-quantity">' . esc_html( (string) $line['quantity'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<table class="boris-table boris-pick-table"><thead><tr><th>' . esc_html__( 'Location', 'boris-stock-inventory-management' ) . '</th><th>SKU / ' . esc_html__( 'product', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'To pick', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Actually picked', 'boris-stock-inventory-management' ) . '</th></tr></thead><tbody>';
		foreach ( $lines as $line ) {
			$line_product = wc_get_product( (int) $line->wc_product_id );
			$line_gtin    = $line_product && method_exists( $line_product, 'get_global_unique_id' ) ? (string) $line_product->get_global_unique_id() : '';
			echo '<tr><td><span class="boris-location boris-location--large">' . esc_html( $line->location_code_snapshot ) . '</span></td><td><strong>' . esc_html( $line->sku_snapshot ) . '</strong>' . ( $line_gtin ? '<br><code>' . esc_html( $line_gtin ) . '</code>' : '' ) . '<br><span class="boris-muted">' . esc_html( $line->name_snapshot ) . '</span></td><td class="boris-quantity">' . esc_html( (string) $line->reserved_quantity ) . '</td><td>';
			if ( $can_complete ) {
				echo '<input class="boris-picked" type="number" min="0" max="' . esc_attr( (string) $line->reserved_quantity ) . '" step="1" required name="picked[' . esc_attr( (string) $line->id ) . ']" value="' . esc_attr( (string) $line->reserved_quantity ) . '">';
			} elseif ( 'READY' === $picklist->status ) {
				echo '<span class="boris-muted">' . esc_html__( 'Not started', 'boris-stock-inventory-management' ) . '</span>';
			} else {
				echo esc_html( (string) $line->picked_quantity );
				if ( 0 < (int) $line->shortage_quantity ) {
					echo ' <span class="boris-badge boris-badge--danger">-' . esc_html( (string) $line->shortage_quantity ) . '</span>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		}
		if ( 'picklist' === $document && $can_complete ) {
			echo '<div class="boris-form-actions"><button class="boris-btn boris-btn--primary">' . esc_html__( 'Complete pick list safely', 'boris-stock-inventory-management' ) . '</button><p>' . esc_html__( 'A lower actual quantity opens a physical BORIS backorder and inventory check.', 'boris-stock-inventory-management' ) . '</p></div></form>';
		}
		$mail_status = ( new PickListMailer() )->status( $id );
		if ( 'picklist' !== $document ) {
			// A packing slip intentionally contains no warehouse email controls.
		} elseif ( 'COMMITTED' === $mail_status ) {
			echo '<div class="boris-mail-state"><span class="boris-badge boris-badge--success">' . esc_html__( 'Sent by email', 'boris-stock-inventory-management' ) . '</span></div>';
		} elseif ( $is_active && 'SENDING' === $mail_status ) {
			echo '<div class="boris-mail-state"><span class="boris-badge boris-badge--warning">' . esc_html__( 'Email is being processed', 'boris-stock-inventory-management' ) . '</span></div>';
		} elseif ( $is_active ) {
			echo '<form class="boris-mail-actions" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="boris_stock_mail_picklist"><input type="hidden" name="picklist_id" value="' . esc_attr( (string) $id ) . '">';
			wp_nonce_field( 'boris_stock_mail_picklist' );
			if ( in_array( $mail_status, array( 'FAILED', 'UNCERTAIN' ), true ) ) {
				echo '<input type="hidden" name="retry" value="yes"><label>' . esc_html__( 'Alternative email address (optional)', 'boris-stock-inventory-management' ) . '<input type="email" name="alternate_email" value="' . esc_attr( (string) get_option( 'boris_stock_problem_email', '' ) ) . '" placeholder="actie@example.com"></label><button class="boris-btn boris-btn--danger">' . esc_html__( 'Resend pick list', 'boris-stock-inventory-management' ) . '</button>';
			} else {
				echo '<button class="boris-btn">' . esc_html__( 'Email warehouse', 'boris-stock-inventory-management' ) . '</button>';
			}
			echo '</form>';
		}
		/* translators: %s is the current BORIS Stock plugin version. */
		echo '<footer class="boris-document-footer">' . esc_html( sprintf( __( 'Generated with BORIS Stock – Version %s', 'boris-stock-inventory-management' ), BORIS_STOCK_VERSION ) ) . '</footer></div>';
		$this->footer();
	}

	/** Render the shipping address, with billing fallback, without exposing internal order metadata. */
	private function order_address( array $order_data ): void {
		if ( empty( $order_data ) ) {
			return;
		}
		$lines = array_filter(
			array(
				(string) ( $order_data['customer'] ?? '' ),
				(string) ( $order_data['company'] ?? '' ),
				trim( (string) ( $order_data['address_1'] ?? '' ) . ' ' . (string) ( $order_data['address_2'] ?? '' ) ),
				trim( (string) ( $order_data['postcode'] ?? '' ) . ' ' . (string) ( $order_data['city'] ?? '' ) ),
				(string) ( $order_data['country'] ?? '' ),
				! empty( $order_data['phone'] ) ? __( 'Phone:', 'boris-stock-inventory-management' ) . ' ' . (string) $order_data['phone'] : '',
			)
		);
		echo '<section class="boris-order-address"><h2>' . esc_html( ! empty( $order_data['uses_shipping'] ) ? __( 'Delivery address', 'boris-stock-inventory-management' ) : __( 'Customer address', 'boris-stock-inventory-management' ) ) . '</h2><address>';
		foreach ( $lines as $line ) {
			echo esc_html( $line ) . '<br>';
		}
		echo '</address></section>';
	}

	/**
	 * Render one collapsible onboarding step.
	 *
	 * @param int    $number      Visible step number.
	 * @param string $title       Step title.
	 * @param bool   $complete    Whether the step is complete.
	 * @param string $description Short status explanation.
	 * @param string $url         Optional action URL.
	 */
	private function onboarding_step( int $number, string $title, bool $complete, string $description, string $url ): void {
		$label = $complete ? __( 'Completed', 'boris-stock-inventory-management' ) : __( 'Action required', 'boris-stock-inventory-management' );
		$tone  = $complete ? 'success' : 'warning';
		echo '<details class="boris-card boris-step"' . ( $complete ? '' : ' open' ) . '><summary><span class="boris-step__number">' . esc_html( (string) $number ) . '</span><strong>' . esc_html( $title ) . '</strong><span class="boris-badge boris-badge--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span></summary><div class="boris-step__body"><p>' . esc_html( $description ) . '</p>';
		if ( '' !== $url ) {
			echo '<a class="boris-btn boris-btn--small" href="' . esc_url( $url ) . '">' . esc_html( $complete ? __( 'View', 'boris-stock-inventory-management' ) : __( 'Run now', 'boris-stock-inventory-management' ) ) . '</a>';
		}
		echo '</div></details>';
	}

	/**
	 * Render one import and placement card.
	 *
	 * @param array<string,mixed>            $row       Product import row.
	 * @param array<int,array<string,mixed>> $locations Available locations.
	 */
	private function import_product_card( array $row, array $locations ): void {
		$status = (string) $row['status'];
		if ( null === $row['opening'] && empty( $row['reasons'] ) ) {
			$label = __( 'Not yet imported', 'boris-stock-inventory-management' );
			$tone  = 'warning';
		} else {
			$labels = array(
				ImportProgress::NOT_PLACED => __( 'Not yet placed', 'boris-stock-inventory-management' ),
				ImportProgress::PARTIAL    => __( 'Partially placed', 'boris-stock-inventory-management' ),
				ImportProgress::READY      => __( 'Ready', 'boris-stock-inventory-management' ),
				ImportProgress::CONFLICT   => __( 'Conflict', 'boris-stock-inventory-management' ),
				ImportProgress::ERROR      => __( 'Error', 'boris-stock-inventory-management' ),
			);
			$label  = $labels[ $status ] ?? $status;
			$tone   = ImportProgress::READY === $status ? 'success' : ( in_array( $status, array( ImportProgress::CONFLICT, ImportProgress::ERROR ), true ) ? 'danger' : 'warning' );
		}
		echo '<article class="boris-card boris-import-card" data-product="' . esc_attr( (string) $row['boris_product_id'] ) . '"><div class="boris-import-card__heading"><div><h2>' . esc_html( $row['sku'] . ' · ' . $row['name'] ) . '</h2><p class="boris-muted">' . sprintf( /* translators: %d is a WooCommerce product ID. */ esc_html__( 'WooCommerce product #%d', 'boris-stock-inventory-management' ), (int) $row['wc_product_id'] ) . '</p></div><span class="boris-badge boris-badge--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span></div>';
		echo '<div class="boris-summary">';
		$this->summary_value( __( 'WooCommerce now', 'boris-stock-inventory-management' ), (int) $row['wc_quantity'] );
		$this->summary_value( __( 'BORIS at locations', 'boris-stock-inventory-management' ), (int) $row['physical'] );
		$this->summary_value( __( 'Initial stock', 'boris-stock-inventory-management' ), null === $row['opening'] ? '—' : (int) $row['opening'] );
		$this->summary_value( __( 'Placed', 'boris-stock-inventory-management' ), (int) $row['placed'] );
		$this->summary_value( __( 'Remaining', 'boris-stock-inventory-management' ), (int) $row['remaining'] );
		echo '</div>';
		if ( ! empty( $row['reasons'] ) ) {
			echo '<ul class="boris-errors">';
			foreach ( $row['reasons'] as $reason ) {
				echo '<li>' . esc_html( $reason ) . '</li>';
			}
			echo '</ul>';
		}

		if ( 0 < (int) $row['remaining'] && ! in_array( $status, array( ImportProgress::CONFLICT, ImportProgress::ERROR ), true ) ) {
			$select_id = 'boris-import-location-' . (int) $row['boris_product_id'];
			echo '<form class="boris-form boris-placement-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="boris_stock_place_initial_stock"><input type="hidden" name="boris_product_id" value="' . esc_attr( (string) $row['boris_product_id'] ) . '"><input type="hidden" name="request_key" value="' . esc_attr( wp_generate_uuid4() ) . '">';
			wp_nonce_field( 'boris_stock_place_initial_stock' );
			echo '<label>' . esc_html__( 'Location', 'boris-stock-inventory-management' ) . '<select id="' . esc_attr( $select_id ) . '" name="location_id" required><option value="">—</option>';
			foreach ( $locations as $location ) {
				$occupant = (int) $location['boris_product_id'];
				if ( 0 === $occupant || $occupant === (int) $row['boris_product_id'] ) {
					echo '<option value="' . esc_attr( (string) $location['id'] ) . '">' . esc_html( $location['code'] ) . '</option>';
				}
			}
			echo '</select></label><label>' . esc_html__( 'Quantity to place', 'boris-stock-inventory-management' ) . '<input type="number" name="quantity" min="1" max="' . esc_attr( (string) $row['remaining'] ) . '" step="1" value="' . esc_attr( (string) $row['remaining'] ) . '" required></label><div class="boris-inline-actions"><button class="boris-btn boris-btn--primary">' . esc_html__( 'Place at location', 'boris-stock-inventory-management' ) . '</button><button class="boris-btn boris-new-location" type="button" data-location-select="' . esc_attr( $select_id ) . '">' . esc_html__( 'New location', 'boris-stock-inventory-management' ) . '</button></div></form>';
		}
		echo '</article>';
	}

	/** Render the AJAX new-location dialog used during placement. */
	private function location_dialog(): void {
		if ( ! current_user_can( 'boris_stock_manage_locations' ) ) {
			return;
		}
		echo '<dialog id="boris-location-dialog" class="boris-dialog"><form id="boris-location-dialog-form" class="boris-form"><button class="boris-dialog__close" type="button" aria-label="' . esc_attr__( 'Close', 'boris-stock-inventory-management' ) . '">×</button><h2>' . esc_html__( 'New warehouse location', 'boris-stock-inventory-management' ) . '</h2><label>' . esc_html__( 'Location code', 'boris-stock-inventory-management' ) . '<input name="code" required maxlength="120" placeholder="A-01"></label><label>' . esc_html__( 'Note', 'boris-stock-inventory-management' ) . '<textarea name="notes"></textarea></label><p class="boris-dialog__message" role="status"></p><div class="boris-inline-actions"><button class="boris-btn boris-dialog__cancel" type="button">' . esc_html__( 'Cancel', 'boris-stock-inventory-management' ) . '</button><button class="boris-btn boris-btn--primary" type="submit">' . esc_html__( 'Create location', 'boris-stock-inventory-management' ) . '</button></div></form></dialog>';
	}

	/**
	 * Render one compact numeric summary.
	 *
	 * @param string     $label Summary label.
	 * @param int|string $value Summary value.
	 */
	private function summary_value( string $label, int|string $value ): void {
		echo '<div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong></div>';
	}

	/**
	 * Render one opening-stock allocation row.
	 *
	 * @param array<int,object> $locations          Active locations and their current occupant.
	 * @param int               $selected_location Selected location ID.
	 * @param string            $location_code      Manually entered location code.
	 * @param string            $quantity           Entered opening quantity.
	 * @param int               $boris_product_id   Existing product mapping during recovery.
	 */
	private function allocation_row( array $locations, int $selected_location, string $location_code, string $quantity, int $boris_product_id ): void {
		$select_id = wp_unique_id( 'boris-allocation-location-' );
		echo '<div class="boris-allocation-row"><label>' . esc_html__( 'Warehouse location', 'boris-stock-inventory-management' ) . '<input class="boris-location-query" type="search" name="allocation_location_code[]" value="' . esc_attr( $location_code ) . '" data-location-select="' . esc_attr( $select_id ) . '" data-boris-product="' . esc_attr( (string) $boris_product_id ) . '" placeholder="' . esc_attr__( 'Search or enter a location code', 'boris-stock-inventory-management' ) . '"><select id="' . esc_attr( $select_id ) . '" class="boris-location-select" name="allocation_location[]"><option value="">—</option>';
		foreach ( $locations as $location ) {
			$occupant = (int) ( $location->boris_product_id ?? 0 );
			$occupied = 0 < $occupant && $occupant !== $boris_product_id && ( 0 < (int) ( $location->quantity_on_hand ?? 0 ) || 0 < (int) ( $location->reserved ?? 0 ) );
			echo '<option value="' . esc_attr( (string) $location->id ) . '" ' . selected( $selected_location, (int) $location->id, false ) . disabled( $occupied, true, false ) . '>' . esc_html( $location->code . ( $occupied ? ' — ' . __( 'occupied', 'boris-stock-inventory-management' ) : '' ) ) . '</option>';
		}
		echo '</select><button class="boris-btn boris-btn--small boris-new-location" type="button" data-location-select="' . esc_attr( $select_id ) . '">' . esc_html__( '+ New location', 'boris-stock-inventory-management' ) . '</button></label><label>' . esc_html__( 'Quantity at this location', 'boris-stock-inventory-management' ) . '<input class="boris-allocation-quantity" type="number" name="allocation_quantity[]" min="1" step="1" value="' . esc_attr( $quantity ) . '"></label><button type="button" class="boris-btn boris-btn--small boris-remove-allocation">' . esc_html__( 'Remove', 'boris-stock-inventory-management' ) . '</button></div>';
	}

	/** Build a user-scoped transient key without storing the request UUID in the option name. */
	private function product_form_transient_key( string $request_key ): string {
		return 'boris_stock_product_form_' . get_current_user_id() . '_' . substr( hash( 'sha256', $request_key ), 0, 20 );
	}

	/** Render the selected product, its stock and bounded actions. */
	private function inventory_product_detail( array $detail ): void {
		$product   = $detail['product'];
		$locations = ( new WarehouseQueryService() )->available_locations( (int) $detail['boris_product_id'], '', 50 );
		echo '<article class="boris-card boris-product-detail"><div class="boris-product-detail__head">';
		if ( $detail['image_id'] ) {
			echo wp_get_attachment_image( $detail['image_id'], 'medium', false, array( 'class' => 'boris-product-detail__image' ) );
		}
		echo '<div><p class="boris-kicker">SKU ' . esc_html( $detail['sku'] ) . ' · #' . esc_html( (string) $detail['wc_product_id'] ) . '</p><h2>' . esc_html( $detail['name'] ) . '</h2>';
		if ( $detail['gtin'] ) {
			echo '<p>' . esc_html__( 'Barcode/GTIN:', 'boris-stock-inventory-management' ) . ' <code>' . esc_html( $detail['gtin'] ) . '</code></p>';
		}
		echo '<p>' . wp_kses_post( wc_price( $product->get_price() ) ) . '</p></div></div><div class="boris-summary">';
		$this->summary_value( __( 'Physical', 'boris-stock-inventory-management' ), $detail['physical'] );
		$this->summary_value( __( 'Reserved', 'boris-stock-inventory-management' ), $detail['reserved'] );
		$this->summary_value( __( 'Available', 'boris-stock-inventory-management' ), $detail['available'] );
		$this->summary_value( __( 'WooCommerce stock', 'boris-stock-inventory-management' ), (int) $product->get_stock_quantity() );
		echo '</div>';

		echo '<div class="boris-table-wrap"><table class="boris-table"><thead><tr><th>' . esc_html__( 'Location', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Physical', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Reserved', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Available', 'boris-stock-inventory-management' ) . '</th></tr></thead><tbody>';
		foreach ( $detail['locations'] as $row ) {
			echo '<tr><td><span class="boris-location">' . esc_html( $row['code'] ) . '</span></td><td>' . esc_html( (string) $row['on_hand'] ) . '</td><td>' . esc_html( (string) $row['reserved'] ) . '</td><td>' . esc_html( (string) $row['available'] ) . '</td></tr>';
		}
		if ( empty( $detail['locations'] ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'This product has not yet been placed at a warehouse location.', 'boris-stock-inventory-management' ) . '</td></tr>';
		}
		echo '</tbody></table></div></article>';

		if ( ! current_user_can( 'boris_stock_manage_inventory' ) ) {
			return;
		}
		echo '<nav class="boris-product-actions" aria-label="' . esc_attr__( 'Product actions', 'boris-stock-inventory-management' ) . '"><a class="boris-btn boris-btn--primary" href="#boris_stock_stock_in">' . esc_html__( 'Receive stock', 'boris-stock-inventory-management' ) . '</a><a class="boris-btn" href="#boris_stock_stock_out">' . esc_html__( 'Book out stock', 'boris-stock-inventory-management' ) . '</a><a class="boris-btn" href="#boris_stock_transfer">' . esc_html__( 'Move stock', 'boris-stock-inventory-management' ) . '</a><a class="boris-btn" href="#boris-product-edit">' . esc_html__( 'Edit product', 'boris-stock-inventory-management' ) . '</a></nav><div class="boris-grid boris-grid--forms">';
		$this->form_start( 'boris_stock_stock_in', __( 'Replenish / receive', 'boris-stock-inventory-management' ), true );
		echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $detail['wc_product_id'] ) . '">';
		$this->location_select( 'location_id', $locations, 'boris-receiving-location', (int) $detail['boris_product_id'] );
		echo '<button class="boris-btn boris-new-location" type="button" data-location-select="location_id">' . esc_html__( 'New location', 'boris-stock-inventory-management' ) . '</button>';
		$this->quantity_note_fields();
		if ( current_user_can( 'edit_post', (int) $detail['wc_product_id'] ) && current_user_can( 'publish_products' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce product capability.
			echo '<label class="boris-checkbox-label"><input type="checkbox" name="publish_after_receive" value="yes"> ' . esc_html__( 'Publish product after receiving stock', 'boris-stock-inventory-management' ) . '</label><p class="boris-muted">' . esc_html__( 'The product is published only after the stock receipt completes successfully.', 'boris-stock-inventory-management' ) . '</p>';
		}
		echo '<button class="boris-btn boris-btn--primary">' . esc_html__( 'Receive stock', 'boris-stock-inventory-management' ) . '</button></form>';

		if ( ! empty( $detail['locations'] ) ) {
			$this->form_start( 'boris_stock_stock_out', __( 'Book out stock', 'boris-stock-inventory-management' ), true );
			echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $detail['wc_product_id'] ) . '">';
			echo '<label>' . esc_html__( 'Source location', 'boris-stock-inventory-management' ) . '<select name="location_stock_id" required><option value="">—</option>';
			foreach ( $detail['locations'] as $row ) {
				echo '<option value="' . esc_attr( (string) $row['id'] ) . '">' . esc_html( $row['code'] . ' · ' . $row['available'] . ' ' . __( 'available', 'boris-stock-inventory-management' ) ) . '</option>';
			}
			echo '</select></label>';
			$this->quantity_note_fields();
			echo '<button class="boris-btn boris-btn--danger">' . esc_html__( 'Book out', 'boris-stock-inventory-management' ) . '</button></form>';

			$this->form_start( 'boris_stock_transfer', __( 'Move stock', 'boris-stock-inventory-management' ), true );
			echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $detail['wc_product_id'] ) . '">';
			echo '<label>' . esc_html__( 'From location', 'boris-stock-inventory-management' ) . '<select name="from_stock_id" required><option value="">—</option>';
			foreach ( $detail['locations'] as $row ) {
				echo '<option value="' . esc_attr( (string) $row['id'] ) . '">' . esc_html( $row['code'] . ' · ' . $row['available'] . ' ' . __( 'available', 'boris-stock-inventory-management' ) ) . '</option>';
			}
			echo '</select></label>';
			$this->location_select( 'to_location_id', $locations, 'boris-transfer-location', (int) $detail['boris_product_id'] );
			echo '<button class="boris-btn boris-new-location" type="button" data-location-select="to_location_id">' . esc_html__( 'New location', 'boris-stock-inventory-management' ) . '</button>';
			$this->quantity_note_fields();
			echo '<button class="boris-btn">' . esc_html__( 'Move', 'boris-stock-inventory-management' ) . '</button></form>';
		}
		echo '</div>';

		if ( current_user_can( 'edit_products' ) ) {
			$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
			$terms = is_wp_error( $terms ) ? array() : $terms;
			$selected_categories = array_map( 'intval', $product->get_category_ids() );
			$gallery_ids = array_map( 'intval', $product->get_gallery_image_ids() );
			echo '<details id="boris-product-edit" class="boris-card boris-product-edit"><summary class="boris-btn">' . esc_html__( 'Edit product details', 'boris-stock-inventory-management' ) . '</summary><form class="boris-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="boris_stock_update_product"><input type="hidden" name="product_id" value="' . esc_attr( (string) $detail['wc_product_id'] ) . '">';
			wp_nonce_field( 'boris_stock_update_product' );
			echo '<label>' . esc_html__( 'Product name', 'boris-stock-inventory-management' ) . '<input name="product_name" required value="' . esc_attr( $product->get_name() ) . '"></label><label>SKU<input name="sku" required value="' . esc_attr( $product->get_sku() ) . '"></label><label>' . esc_html__( 'Barcode/GTIN', 'boris-stock-inventory-management' ) . '<input name="global_unique_id" value="' . esc_attr( $detail['gtin'] ) . '"></label><div class="boris-form-row"><label>' . esc_html__( 'Regular price', 'boris-stock-inventory-management' ) . '<input inputmode="decimal" name="regular_price" value="' . esc_attr( $product->get_regular_price() ) . '"></label><label>' . esc_html__( 'Sale price', 'boris-stock-inventory-management' ) . '<input inputmode="decimal" name="sale_price" value="' . esc_attr( $product->get_sale_price() ) . '"></label>';
			if ( '' !== $detail['cost'] ) {
				echo '<label>' . esc_html__( 'Cost price', 'boris-stock-inventory-management' ) . '<input inputmode="decimal" name="cost_price" value="' . esc_attr( $detail['cost'] ) . '"></label>';
			} else {
				echo '<input type="hidden" name="cost_price" value="">';
			}
			echo '</div><label>' . esc_html__( 'Short description', 'boris-stock-inventory-management' ) . '<textarea name="short_description">' . esc_textarea( $product->get_short_description() ) . '</textarea></label><label>' . esc_html__( 'Description', 'boris-stock-inventory-management' ) . '<textarea name="description" rows="8">' . esc_textarea( $product->get_description() ) . '</textarea></label><label>' . esc_html__( 'Product categories', 'boris-stock-inventory-management' ) . '<select name="category_ids[]" multiple size="6">';
			foreach ( $terms as $term ) {
				echo '<option value="' . esc_attr( (string) $term->term_id ) . '" ' . selected( in_array( (int) $term->term_id, $selected_categories, true ), true, false ) . '>' . esc_html( $term->name ) . '</option>';
			}
			echo '</select></label><div class="boris-media-field boris-product-photos" data-media-mode="product"><input class="boris-main-image-value" type="hidden" name="image_id" value="' . esc_attr( (string) $product->get_image_id() ) . '"><input class="boris-gallery-value" type="hidden" name="gallery_image_ids" value="' . esc_attr( implode( ',', $gallery_ids ) ) . '"><div class="boris-media-preview boris-media-preview--gallery" aria-live="polite">';
			foreach ( array_values( array_filter( array_merge( array( (int) $product->get_image_id() ), $gallery_ids ) ) ) as $photo_id ) {
				$thumbnail = wp_get_attachment_image_url( $photo_id, 'thumbnail' );
				if ( $thumbnail ) {
					echo '<article class="boris-media-item" draggable="true" data-attachment-id="' . esc_attr( (string) $photo_id ) . '" data-thumbnail="' . esc_url( $thumbnail ) . '" data-alt="' . esc_attr( get_post_meta( $photo_id, '_wp_attachment_image_alt', true ) ) . '"></article>';
				}
			}
			echo '</div><div class="boris-inline-actions"><button type="button" class="boris-btn boris-media-choose">' . esc_html__( 'Choose or upload photos', 'boris-stock-inventory-management' ) . '</button><button type="button" class="boris-btn boris-media-clear">' . esc_html__( 'Clear photos', 'boris-stock-inventory-management' ) . '</button></div></div><p class="boris-muted">' . esc_html__( 'Stock quantities, reservations and location allocations are deliberately not changed by this form.', 'boris-stock-inventory-management' ) . '</p><button class="boris-btn boris-btn--primary">' . esc_html__( 'Save product details', 'boris-stock-inventory-management' ) . '</button></form></details>';
		}
	}

	/** Render the standard POST location form. */
	private function location_form(): void {
		$this->form_start( 'boris_stock_create_location', __( 'Add location', 'boris-stock-inventory-management' ) );
		echo '<label>' . esc_html__( 'Location code', 'boris-stock-inventory-management' ) . '<input name="code" required maxlength="120" placeholder="A-01"></label><label>' . esc_html__( 'Note', 'boris-stock-inventory-management' ) . '<textarea name="notes"></textarea></label><button class="boris-btn boris-btn--primary">' . esc_html__( 'Add', 'boris-stock-inventory-management' ) . '</button></form>';
	}

	/**
	 * Render the daily receiving form.
	 *
	 * @param array<int,object> $products  Active products.
	 * @param array<int,object> $locations Active locations and stock state.
	 */
	private function stock_in_form( array $products, array $locations ): void {
		$this->form_start( 'boris_stock_stock_in', __( 'Receive stock', 'boris-stock-inventory-management' ), true );
		$location_data = array_map( static fn( $location ) => (array) $location, $locations );
		$suggestions   = array();
		foreach ( $products as $product ) {
			$suggestions[ (int) $product->boris_product_id ] = LocationSuggestion::choose( (int) $product->boris_product_id, $location_data );
		}
		$this->product_select( $products, $suggestions );
		$this->location_select( 'location_id', $locations, 'boris-receiving-location' );
		echo '<div class="boris-receiving-overview" hidden><strong class="boris-receiving-total"></strong><ul class="boris-receiving-locations"></ul></div><p class="boris-suggestion" role="status"></p>';
		$this->quantity_note_fields();
		echo '<p class="boris-muted">' . esc_html__( 'Open physical shortages are resolved FIFO first; only the remainder becomes sellable.', 'boris-stock-inventory-management' ) . '</p><button class="boris-btn boris-btn--primary">' . esc_html__( 'Receive', 'boris-stock-inventory-management' ) . '</button></form>';
	}

	/**
	 * Render the daily stock-out form.
	 *
	 * @param array<int,object> $rows Location-stock rows.
	 */
	private function stock_out_form( array $rows ): void {
		$this->form_start( 'boris_stock_stock_out', __( 'Book out stock', 'boris-stock-inventory-management' ), true );
		echo '<label>' . esc_html__( 'Location / product', 'boris-stock-inventory-management' ) . '<select name="location_stock_id" required><option value="">—</option>';
		foreach ( $rows as $row ) {
			echo '<option data-product="' . esc_attr( (string) $row->wc_product_id ) . '" value="' . esc_attr( (string) $row->location_stock_id ) . '">' . esc_html( $row->code . ' · ' . $row->sku ) . '</option>';
		}
		echo '</select></label>';
		$this->quantity_note_fields();
		echo '<button class="boris-btn boris-btn--danger">' . esc_html__( 'Book out', 'boris-stock-inventory-management' ) . '</button></form>';
	}

	/**
	 * Render the daily transfer form.
	 *
	 * @param array<int,object> $rows      Location-stock rows.
	 * @param array<int,object> $locations Active locations.
	 */
	private function transfer_form( array $rows, array $locations ): void {
		$this->form_start( 'boris_stock_transfer', __( 'Move stock', 'boris-stock-inventory-management' ), true );
		echo '<label>' . esc_html__( 'From location', 'boris-stock-inventory-management' ) . '<select name="from_stock_id" required><option value="">—</option>';
		foreach ( $rows as $row ) {
			echo '<option value="' . esc_attr( (string) $row->location_stock_id ) . '">' . esc_html( $row->code . ' · ' . $row->sku ) . '</option>';
		}
		echo '</select></label>';
		$this->location_select( 'to_location_id', $locations );
		$this->quantity_note_fields();
		echo '<button class="boris-btn">' . esc_html__( 'Move', 'boris-stock-inventory-management' ) . '</button></form>';
	}

	/**
	 * Open a protected admin POST form.
	 *
	 * @param string $action      WordPress admin-post action.
	 * @param string $title       Form heading.
	 * @param bool   $request_key Whether to add an idempotency key.
	 */
	private function form_start( string $action, string $title, bool $request_key = false ): void {
		echo '<form id="' . esc_attr( $action ) . '" class="boris-card boris-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><h2>' . esc_html( $title ) . '</h2><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		if ( $request_key ) {
			echo '<input type="hidden" name="request_key" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		}
	}

	/**
	 * Render a product selector with optional receiving suggestions.
	 *
	 * @param array<int,object> $products    Active products.
	 * @param array<int,int>    $suggestions Suggested location by BORIS product ID.
	 */
	private function product_select( array $products, array $suggestions = array() ): void {
		echo '<label>' . esc_html__( 'Product', 'boris-stock-inventory-management' ) . '<select class="boris-receiving-product" name="product_id" required><option value="">—</option>';
		foreach ( $products as $product ) {
			$boris_product_id = (int) ( $product->boris_product_id ?? 0 );
			echo '<option data-boris-product="' . esc_attr( (string) $boris_product_id ) . '" data-suggestion="' . esc_attr( (string) ( $suggestions[ $boris_product_id ] ?? 0 ) ) . '" value="' . esc_attr( (string) $product->wc_product_id ) . '">' . esc_html( $product->sku . ' · ' . $product->name_snapshot ) . '</option>';
		}
		echo '</select></label>';
	}

	/**
	 * Render a location selector.
	 *
	 * @param string            $name       Form field name.
	 * @param array<int,object> $locations  Active locations.
	 * @param string            $css_class  Optional selector CSS class.
	 */
	private function location_select( string $name, array $locations, string $css_class = '', int $boris_product_id = 0 ): void {
		echo '<label>' . esc_html__( 'Location', 'boris-stock-inventory-management' ) . '<input class="boris-location-query" type="search" data-location-select="' . esc_attr( $name ) . '" data-boris-product="' . esc_attr( (string) $boris_product_id ) . '" placeholder="' . esc_attr__( 'Search location code', 'boris-stock-inventory-management' ) . '"><select class="boris-location-availability" data-location-select="' . esc_attr( $name ) . '"><option value="all">' . esc_html__( 'Available and same product', 'boris-stock-inventory-management' ) . '</option><option value="empty">' . esc_html__( 'Only empty locations', 'boris-stock-inventory-management' ) . '</option><option value="occupied">' . esc_html__( 'Only locations with this product', 'boris-stock-inventory-management' ) . '</option></select><select id="' . esc_attr( $name ) . '" class="boris-location-select ' . esc_attr( $css_class ) . '" name="' . esc_attr( $name ) . '" required><option value="">—</option>';
		foreach ( $locations as $location ) {
			$quantity = (int) ( $location->quantity_on_hand ?? 0 );
			$reserved = (int) ( $location->reserved ?? 0 );
			echo '<option data-boris-product="' . esc_attr( (string) ( $location->boris_product_id ?? 0 ) ) . '" data-quantity="' . esc_attr( (string) $quantity ) . '" data-free="' . esc_attr( (string) max( 0, $quantity - $reserved ) ) . '" value="' . esc_attr( (string) $location->id ) . '">' . esc_html( $location->code ) . '</option>';
		}
		echo '</select></label>';
	}

	/** Render shared quantity and note fields. */
	private function quantity_note_fields(): void {
		echo '<label>' . esc_html__( 'Quantity', 'boris-stock-inventory-management' ) . '<input type="number" name="quantity" min="1" step="1" required></label><label>' . esc_html__( 'Note', 'boris-stock-inventory-management' ) . '<textarea name="note"></textarea></label>';
	}

	/** Render one nonce-protected Draft/Published form without editing other product fields. */
	private function product_status_form( int $product_id, string $status ): void {
		$label = 'publish' === $status ? __( 'Published', 'boris-stock-inventory-management' ) : __( 'Draft', 'boris-stock-inventory-management' );
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			echo '<span class="boris-badge boris-badge--' . ( 'publish' === $status ? 'success' : 'warning' ) . '">' . esc_html( $label ) . '</span>';
			return;
		}
		echo '<form class="boris-status-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="boris_stock_update_product_status"><input type="hidden" name="product_id" value="' . esc_attr( (string) $product_id ) . '">';
		wp_nonce_field( 'boris_stock_update_product_status_' . $product_id, '_wpnonce', false );
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Publication status', 'boris-stock-inventory-management' ) . '</span><select name="product_status"><option value="draft" ' . selected( $status, 'draft', false ) . '>' . esc_html__( 'Draft', 'boris-stock-inventory-management' ) . '</option>';
		if ( current_user_can( 'publish_products' ) || 'publish' === $status ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce product capability.
			echo '<option value="publish" ' . selected( $status, 'publish', false ) . '>' . esc_html__( 'Published', 'boris-stock-inventory-management' ) . '</option>';
		}
		echo '</select></label><button class="boris-btn boris-btn--small" type="submit">' . esc_html__( 'Save', 'boris-stock-inventory-management' ) . '</button></form>';
	}

	private function header( string $title, string $subtitle, string $css_class = '' ): void {
		echo '<div class="wrap boris-admin ' . esc_attr( $css_class ) . '"><header class="boris-header"><div class="boris-brand"><img class="boris-brand__logo" src="' . esc_url( BORIS_STOCK_URL . 'assets/images/boris-logo-transparant-vierkant.png' ) . '" alt="' . esc_attr__( 'BORIS Systems', 'boris-stock-inventory-management' ) . '"><span>BORIS <b>STOCK</b></span></div><div><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $subtitle ) . '</p></div></header>';
	}

	/** Render the safe two-step WooCommerce catalogue-photo workflow. */
	private function catalogue_media_organization(): void {
		if ( ! MediaFolderCompatibility::folders_available() ) {
			echo '<section class="boris-card"><h2>' . esc_html__( 'Organize existing product photos', 'boris-stock-inventory-management' ) . '</h2><p>' . esc_html__( 'This feature is unavailable because a confirmed media-folder compatibility conflict was detected.', 'boris-stock-inventory-management' ) . '</p></section>';
			return;
		}

		$analysis = get_transient( $this->catalog_media_analysis_key() );
		$job      = get_transient( $this->catalog_media_job_key() );
		$result   = get_transient( $this->catalog_media_result_key() );

		echo '<section class="boris-card"><h2>' . esc_html__( 'Organize existing product photos', 'boris-stock-inventory-management' ) . '</h2><p>' . esc_html__( 'Analyze WooCommerce first. BORIS will show the proposed logical folders before it changes any folder assignments. Physical files, attachment IDs, URLs and WooCommerce image settings are never changed.', 'boris-stock-inventory-management' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="boris_stock_analyze_product_photos">';
		wp_nonce_field( 'boris_stock_analyze_product_photos' );
		echo '<button class="boris-btn" type="submit">' . esc_html__( 'Analyze product photos', 'boris-stock-inventory-management' ) . '</button></form></section>';

		if ( is_array( $result ) ) {
			echo '<section class="boris-card"><h2>' . esc_html__( 'Latest photo-organization result', 'boris-stock-inventory-management' ) . '</h2><div class="boris-summary">';
			$this->summary_value( __( 'Products processed', 'boris-stock-inventory-management' ), absint( $result['processed'] ?? 0 ) );
			$this->summary_value( __( 'Folders created', 'boris-stock-inventory-management' ), absint( $result['folders_created'] ?? 0 ) );
			$this->summary_value( __( 'Folders reused', 'boris-stock-inventory-management' ), absint( $result['folders_reused'] ?? 0 ) );
			$this->summary_value( __( 'Images organized', 'boris-stock-inventory-management' ), absint( $result['attachments_assigned'] ?? 0 ) );
			$this->summary_value( __( 'Manual placements preserved', 'boris-stock-inventory-management' ), absint( $result['manual_preserved'] ?? 0 ) );
			$this->summary_value( __( 'Errors', 'boris-stock-inventory-management' ), count( $result['errors'] ?? array() ) );
			echo '</div>';
			if ( ! empty( $result['errors'] ) ) {
				echo '<details><summary>' . esc_html__( 'Show organization errors', 'boris-stock-inventory-management' ) . '</summary><ul>';
				foreach ( $result['errors'] as $error ) {
					echo '<li>' . esc_html( sprintf( /* translators: 1: WooCommerce product ID, 2: error message. */ __( 'Product #%1$d: %2$s', 'boris-stock-inventory-management' ), absint( $error['product_id'] ?? 0 ), (string) ( $error['message'] ?? '' ) ) ) . '</li>';
				}
				echo '</ul></details>';
			}
			echo '</section>';
		}

		if ( is_array( $job ) ) {
			$processed = absint( $job['processed'] ?? 0 );
			$total     = absint( $job['total'] ?? 0 );
			echo '<section id="boris-catalog-media-progress" class="boris-card" data-active="1"><h2>' . esc_html__( 'Organizing product photos', 'boris-stock-inventory-management' ) . '</h2><p class="boris-catalog-media-progress__message" role="status" aria-live="polite">' . esc_html__( 'The next safe batch will start automatically.', 'boris-stock-inventory-management' ) . '</p><progress value="' . esc_attr( (string) $processed ) . '" max="' . esc_attr( (string) max( 1, $total ) ) . '"></progress><p><span class="boris-catalog-media-progress__processed">' . esc_html( (string) $processed ) . '</span> / <span class="boris-catalog-media-progress__total">' . esc_html( (string) $total ) . '</span></p></section>';
		}

		if ( ! is_array( $analysis ) ) {
			return;
		}

		$summary = is_array( $analysis['summary'] ?? null ) ? $analysis['summary'] : array();
		echo '<section class="boris-card"><h2>' . esc_html__( 'Photo-analysis preview', 'boris-stock-inventory-management' ) . '</h2><p>' . esc_html__( 'Review these results and category choices before starting. Existing manual BORIS placements will be preserved.', 'boris-stock-inventory-management' ) . '</p><div class="boris-summary">';
		$this->summary_value( __( 'Products examined', 'boris-stock-inventory-management' ), absint( $summary['products_examined'] ?? 0 ) );
		$this->summary_value( __( 'Images examined', 'boris-stock-inventory-management' ), absint( $summary['images_examined'] ?? 0 ) );
		$this->summary_value( __( 'Products to organize', 'boris-stock-inventory-management' ), absint( $summary['products_to_organize'] ?? 0 ) );
		$this->summary_value( __( 'Shared images', 'boris-stock-inventory-management' ), absint( $summary['shared_images'] ?? 0 ) );
		$this->summary_value( __( 'Products with multiple category branches', 'boris-stock-inventory-management' ), absint( $summary['multiple_categories'] ?? 0 ) );
		$this->summary_value( __( 'Manual placements preserved', 'boris-stock-inventory-management' ), absint( $summary['manual_placements'] ?? 0 ) );
		$this->summary_value( __( 'Name conflicts', 'boris-stock-inventory-management' ), absint( $summary['name_conflicts'] ?? 0 ) );
		$this->summary_value( __( 'Products without images', 'boris-stock-inventory-management' ), absint( $summary['products_without_images'] ?? 0 ) );
		echo '</div><p class="boris-muted">' . esc_html__( 'Shared images will be placed once under Winkel / Shared product media. This logical folder is marked so it can be subdivided safely in a future version.', 'boris-stock-inventory-management' ) . '</p>';

		echo '<form class="boris-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="boris_stock_start_product_photo_organization">';
		wp_nonce_field( 'boris_stock_start_product_photo_organization' );
		echo '<details open><summary>' . esc_html__( 'Review proposed product folders', 'boris-stock-inventory-management' ) . '</summary><div class="boris-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Images', 'boris-stock-inventory-management' ) . '</th><th>' . esc_html__( 'Proposed folder', 'boris-stock-inventory-management' ) . '</th></tr></thead><tbody>';
		foreach ( $analysis['products'] ?? array() as $product_id => $row ) {
			if ( empty( $row['image_count'] ) ) {
				continue;
			}
			$options = is_array( $row['category_options'] ?? null ) ? $row['category_options'] : array();
			$choice  = absint( $row['proposed_category_id'] ?? 0 );
			echo '<tr><td>' . esc_html( (string) $row['name'] ) . '<br><span class="boris-muted">#' . esc_html( (string) absint( $product_id ) ) . '</span></td><td>' . esc_html( (string) absint( $row['image_count'] ) ) . '</td><td><span class="boris-muted">' . esc_html( 'Winkel / ' ) . '</span>';
			if ( 1 < count( $options ) ) {
				echo '<select name="category_choice[' . esc_attr( (string) absint( $product_id ) ) . ']" aria-label="' . esc_attr( sprintf( /* translators: %s is a product name. */ __( 'Category path for %s', 'boris-stock-inventory-management' ), (string) $row['name'] ) ) . '">';
				foreach ( $options as $option ) {
					echo '<option value="' . esc_attr( (string) absint( $option['id'] ) ) . '" ' . selected( $choice, absint( $option['id'] ), false ) . '>' . esc_html( (string) $option['label'] ) . '</option>';
				}
				echo '</select>';
			} else {
				$label = isset( $options[0]['label'] ) ? (string) $options[0]['label'] : __( 'Uncategorized', 'boris-stock-inventory-management' );
				echo esc_html( $label ) . '<input type="hidden" name="category_choice[' . esc_attr( (string) absint( $product_id ) ) . ']" value="' . esc_attr( (string) $choice ) . '">';
			}
			echo '<span class="boris-muted"> / ' . esc_html( (string) $row['name'] ) . '</span></td></tr>';
		}
		echo '</tbody></table></div></details>';
		if ( ! is_array( $job ) ) {
			echo '<p><button class="boris-btn boris-btn--primary" type="submit">' . esc_html__( 'Organize product photos', 'boris-stock-inventory-management' ) . '</button></p>';
		}
		echo '</form></section>';
	}

	/** User-specific transient key for a read-only media analysis. */
	private function catalog_media_analysis_key(): string {
		return 'boris_stock_catalog_media_analysis_' . get_current_user_id();
	}

	/** User-specific transient key for the active bounded job. */
	private function catalog_media_job_key(): string {
		return 'boris_stock_catalog_media_job_' . get_current_user_id();
	}

	/** User-specific transient key for the latest job result. */
	private function catalog_media_result_key(): string {
		return 'boris_stock_catalog_media_result_' . get_current_user_id();
	}

	/** User-specific lock key preventing overlapping batch requests. */
	private function catalog_media_lock_key(): string {
		return 'boris_stock_catalog_media_lock_' . get_current_user_id();
	}

	/** Render the shop identity on a printable pick list. */
	private function picklist_header( string $title, string $subtitle ): void {
		$shop_name = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		if ( '' === $shop_name ) {
			$shop_name = __( 'Online store', 'boris-stock-inventory-management' );
		}

		$logo_url = '';
		$logo_id  = absint( get_theme_mod( 'custom_logo' ) );
		if ( 0 < $logo_id ) {
			$logo_url = (string) wp_get_attachment_image_url( $logo_id, 'medium' );
		}

		echo '<div class="wrap boris-admin boris-pick-print"><header class="boris-header boris-pick-header"><div class="boris-shop-brand">';
		if ( '' !== $logo_url ) {
			echo '<img class="boris-shop-brand__logo" src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $shop_name ) . '">';
		}
		echo '<span class="boris-shop-brand__name">' . esc_html( $shop_name ) . '</span></div><div><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $subtitle ) . '</p></div></header>';
	}

	/**
	 * Render the product result count and compact first/previous/number/next/end navigation.
	 *
	 * @param array{total:int,page:int,pages:int,per_page:int} $catalogue Catalogue page data.
	 * @param array<string,string>                             $filters   Active URL filters.
	 */
	private function product_catalogue_pagination( array $catalogue, array $filters ): void {
		$total = (int) $catalogue['total'];
		$page  = (int) $catalogue['page'];
		$pages = (int) $catalogue['pages'];
		$start = 0 < $total ? ( ( $page - 1 ) * (int) $catalogue['per_page'] ) + 1 : 0;
		$end   = min( $total, $page * (int) $catalogue['per_page'] );

		echo '<div class="boris-catalogue-footer"><p class="boris-result-count">' . esc_html(
			sprintf(
				/* translators: 1: first result, 2: last result, 3: total products, 4: current page, 5: total pages. */
				__( 'Showing %1$s–%2$s of %3$s products · Page %4$s of %5$s', 'boris-stock-inventory-management' ),
				number_format_i18n( $start ),
				number_format_i18n( $end ),
				number_format_i18n( $total ),
				number_format_i18n( $page ),
				number_format_i18n( $pages )
			)
		) . '</p>';

		if ( 1 < $pages ) {
			$url_args = array_merge( array( 'page' => 'boris-stock-inventory' ), array_filter( $filters, static fn( $value ) => '' !== $value ) );
			$page_url = static fn( int $number ): string => add_query_arg( array_merge( $url_args, array( 'paged' => $number ) ), admin_url( 'admin.php' ) );
			$base     = add_query_arg( array_merge( $url_args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );
			$numbers  = paginate_links(
				array(
					'base'      => $base,
					'format'    => '',
					'current'   => $page,
					'total'     => $pages,
					'mid_size'  => 2,
					'end_size'  => 1,
					'prev_next' => false,
					'type'      => 'array',
				)
			);

			echo '<nav class="boris-pagination" aria-label="' . esc_attr__( 'Product pages', 'boris-stock-inventory-management' ) . '">';
			echo 1 < $page ? '<a class="page-numbers boris-page-edge" href="' . esc_url( $page_url( 1 ) ) . '">' . esc_html__( 'First', 'boris-stock-inventory-management' ) . '</a>' : '<span class="page-numbers boris-page-edge is-disabled">' . esc_html__( 'First', 'boris-stock-inventory-management' ) . '</span>';
			echo 1 < $page ? '<a class="page-numbers" href="' . esc_url( $page_url( $page - 1 ) ) . '">' . esc_html__( 'Previous', 'boris-stock-inventory-management' ) . '</a>' : '<span class="page-numbers is-disabled">' . esc_html__( 'Previous', 'boris-stock-inventory-management' ) . '</span>';
			if ( is_array( $numbers ) ) {
				echo wp_kses_post( implode( '', $numbers ) );
			}
			echo $page < $pages ? '<a class="page-numbers" href="' . esc_url( $page_url( $page + 1 ) ) . '">' . esc_html__( 'Next', 'boris-stock-inventory-management' ) . '</a>' : '<span class="page-numbers is-disabled">' . esc_html__( 'Next', 'boris-stock-inventory-management' ) . '</span>';
			echo $page < $pages ? '<a class="page-numbers boris-page-edge" href="' . esc_url( $page_url( $pages ) ) . '">' . esc_html__( 'End', 'boris-stock-inventory-management' ) . '</a>' : '<span class="page-numbers boris-page-edge is-disabled">' . esc_html__( 'End', 'boris-stock-inventory-management' ) . '</span>';
			echo '</nav>';
		}
		echo '</div>';
	}

	private function footer(): void {
		echo '<footer class="boris-footer"><a href="' . esc_url( BORIS_STOCK_PRODUCT_URL ) . '" target="_blank" rel="noopener noreferrer">BORIS Systems</a> · ' . esc_html__( 'local warehouse control for WooCommerce', 'boris-stock-inventory-management' ) . '</footer></div>';
	}

	private function stat( string $label, int $value, string $tone ): void {
		echo '<div class="boris-card boris-stat boris-stat--' . esc_attr( $tone ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( number_format_i18n( $value ) ) . '</strong></div>';
	}

	private function badge( string $status ): string {
		$tone   = in_array( $status, array( 'READY', 'COMPLETED' ), true ) ? 'success' : ( in_array( $status, array( 'SHORTAGE', 'CANCELLED' ), true ) ? 'danger' : 'warning' );
		$labels = array(
			'READY'     => __( 'Complete', 'boris-stock-inventory-management' ),
			'PICKING'   => __( 'Picking', 'boris-stock-inventory-management' ),
			'COMPLETED' => __( 'Completed', 'boris-stock-inventory-management' ),
			'SHORTAGE'  => __( 'Shortage', 'boris-stock-inventory-management' ),
			'CANCELLED' => __( 'Cancelled', 'boris-stock-inventory-management' ),
		);
		$label  = $labels[ $status ] ?? $status;
		return '<span class="boris-badge boris-badge--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
	}

	private function messages(): void {
		if ( isset( $_GET['boris_product_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status message.
			$status = sanitize_key( wp_unslash( $_GET['boris_product_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status message.
			if ( in_array( $status, array( 'draft', 'publish' ), true ) ) {
				echo '<div class="boris-notice boris-notice--success">' . esc_html( 'publish' === $status ? __( 'The product was published successfully.', 'boris-stock-inventory-management' ) : __( 'The product was changed to Draft successfully.', 'boris-stock-inventory-management' ) ) . '</div>';
			}
		}
		if ( isset( $_GET['boris_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="boris-notice boris-notice--success">' . esc_html__( 'The operation was processed.', 'boris-stock-inventory-management' ) . '</div>';
		}
		if ( isset( $_GET['boris_published_after_receive'] ) && 1 === absint( $_GET['boris_published_after_receive'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status message.
			echo '<div class="boris-notice boris-notice--success">' . esc_html__( 'Stock was received and the product was published successfully.', 'boris-stock-inventory-management' ) . '</div>';
		}
		if ( isset( $_GET['boris_receive_warning'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status message.
			/* translators: %s is the WooCommerce publication error after a successful stock receipt. */
			echo '<div class="boris-notice boris-notice--danger">' . esc_html( sprintf( __( 'Stock was received successfully, but the product remained Draft: %s', 'boris-stock-inventory-management' ), sanitize_text_field( wp_unslash( $_GET['boris_receive_warning'] ) ) ) ) . '</div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status message.
		}
		if ( isset( $_GET['boris_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="boris-notice boris-notice--danger">' . esc_html( sanitize_text_field( wp_unslash( $_GET['boris_error'] ) ) ) . '</div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status message.
		}
		if ( isset( $_GET['boris_mail_sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="boris-notice boris-notice--success">' . esc_html__( 'The pick list email was processed; a previously confirmed delivery was not sent twice.', 'boris-stock-inventory-management' ) . '</div>';
		}
		if ( isset( $_GET['boris_test_email_sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="boris-notice boris-notice--success">' . esc_html__( 'The test email was sent.', 'boris-stock-inventory-management' ) . '</div>';
		}
	}

	private function verify( string $capability, string $nonce_action ): void {
		$this->guard( $capability );
		check_admin_referer( $nonce_action );
	}

	private function guard( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die(
				esc_html__( 'You do not have permission for this BORIS Stock action.', 'boris-stock-inventory-management' ),
				esc_html__( 'Access denied', 'boris-stock-inventory-management' ),
				array( 'response' => 403 )
			);
		}
	}

	private function handle( callable $callback, string $page, array $extra = array() ): void {
		try {
			$callback();
			$extra['boris_saved'] = 1;
		} catch ( \Throwable $throwable ) {
			$extra['boris_error'] = $throwable->getMessage();
		}
		$this->redirect( $page, $extra );
	}

	private function redirect( string $page, array $args = array() ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
