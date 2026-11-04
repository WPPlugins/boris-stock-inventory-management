<?php
/**
 * Create a normal WooCommerce product and connect its opening warehouse stock.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Products;

use BorisSystems\Stock\Application\Inventory\InitialStockService;
use BorisSystems\Stock\Application\Media\MediaFolderService;
use BorisSystems\Stock\Compatibility\MediaFolderCompatibility;
use BorisSystems\Stock\Domain\Inventory\Identifier;
use BorisSystems\Stock\Domain\Inventory\Quantity;
use BorisSystems\Stock\Infrastructure\Database\Repository;

defined( 'ABSPATH' ) || exit;

// Translated exception messages are data; the receiving UI escapes them for its concrete output context.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/** Orchestrates WooCommerce CRUD and the existing BORIS opening-stock flow. */
final class ProductCreationService {
	private const REQUEST_META = '_boris_stock_creation_request';
	private const HASH_META    = '_boris_stock_creation_payload_hash';
	private const STATE_META   = '_boris_stock_creation_state';

	/**
	 * Persistence gateway for BORIS warehouse data.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Existing WooCommerce-to-BORIS mapping service.
	 *
	 * @var ProductService
	 */
	private ProductService $products;

	/**
	 * Existing idempotent physical opening-stock placement service.
	 *
	 * @var InitialStockService
	 */
	private InitialStockService $initial_stock;

	/** Virtual folder organizer for ordinary WordPress attachments. */
	private MediaFolderService $media_folders;

	/**
	 * Create the product-creation orchestrator.
	 *
	 * @param Repository|null          $repository    Optional persistence gateway.
	 * @param ProductService|null      $products      Optional mapping service.
	 * @param InitialStockService|null $initial_stock Optional opening-stock service.
	 * @param MediaFolderService|null   $media_folders Optional virtual media-folder service.
	 */
	public function __construct(
		?Repository $repository = null,
		?ProductService $products = null,
		?InitialStockService $initial_stock = null,
		?MediaFolderService $media_folders = null
	) {
		$this->repository    = $repository ?? new Repository();
		$this->products      = $products ?? new ProductService( $this->repository );
		$this->initial_stock = $initial_stock ?? new InitialStockService( $this->repository );
		$this->media_folders = $media_folders ?? new MediaFolderService();
	}

	/** Return the BORIS product mapping for a recoverable creation request, when present. */
	public function boris_product_id_for_request( string $request_key ): int {
		if ( ! wp_is_uuid( $request_key, 4 ) ) {
			return 0;
		}
		$product = $this->product_for_request( $request_key );
		$mapping = $product ? $this->repository->product_by_wc_id( $product->get_id() ) : null;
		return $mapping ? (int) $mapping->id : 0;
	}

	/**
	 * Create or safely resume one product-creation request.
	 *
	 * @param string                         $request_key Browser-generated UUID.
	 * @param array<string,mixed>            $data        Sanitized product fields.
	 * @param array<int,array<string,mixed>> $allocations Location quantities.
	 * @param int                            $user_id     Acting WordPress user.
	 * @return array{wc_product_id:int,boris_product_id:int,status:string}
	 * @throws \RuntimeException When validation, product creation or allocation fails.
	 * @throws \Throwable When an underlying WooCommerce or persistence operation fails.
	 */
	public function create( string $request_key, array $data, array $allocations, int $user_id ): array {
		$request_key = sanitize_text_field( $request_key );
		if ( ! wp_is_uuid( $request_key, 4 ) ) {
			throw new \RuntimeException( __( 'The product request has expired. Reload the page and try again.', 'boris-stock-inventory-management' ) );
		}

		$normalized       = $this->validate_product_data( $data );
		$product          = $this->product_for_request( $request_key );
		$existing_mapping = $product ? $this->repository->product_by_wc_id( $product->get_id() ) : null;
		$allocations      = $this->validate_allocations( $allocations, $normalized['stock_quantity'], $existing_mapping ? (int) $existing_mapping->id : 0 );
		$payload_hash     = hash( 'sha256', wp_json_encode( array( $normalized, $allocations ) ) );

		if ( $product ) {
			if ( 'complete' === $product->get_meta( self::STATE_META, true ) ) {
				$mapping = $this->repository->product_by_wc_id( $product->get_id() );
				return array(
					'wc_product_id'    => $product->get_id(),
					'boris_product_id' => $mapping ? (int) $mapping->id : 0,
					'status'           => (string) $product->get_status(),
				);
			}
			if ( ! hash_equals( (string) $product->get_meta( self::HASH_META, true ), $payload_hash ) ) {
				$state        = (string) $product->get_meta( self::STATE_META, true );
				$placed_stock = $existing_mapping ? $this->repository->import_balance( (int) $existing_mapping->id ) : null;
				$has_placed   = $existing_mapping && (
					0 < $this->repository->physical_total( (int) $existing_mapping->id ) ||
					( $placed_stock && 0 < (int) $placed_stock->initial_stock_placed )
				);
				if ( ! in_array( $state, array( 'pending', 'failed' ), true ) || $has_placed ) {
					throw new \RuntimeException( __( 'This product request was already started with different information. Start a new request or reconcile its warehouse stock first.', 'boris-stock-inventory-management' ) );
				}
				$product->update_meta_data( self::HASH_META, $payload_hash );
			}
		} else {
			$product = new \WC_Product_Simple();
			$product->update_meta_data( self::REQUEST_META, $request_key );
			$product->update_meta_data( self::HASH_META, $payload_hash );
		}

		$product_id = (int) $product->get_id();
		$this->validate_unique_identifiers( $product_id, $normalized['sku'], $normalized['global_unique_id'] );
		$this->apply_product_data( $product, $normalized );
		$product->set_status( 'draft' );
		$product->update_meta_data( self::STATE_META, 'pending' );
		$product_id = (int) $product->save();

		if ( 1 > $product_id ) {
			throw new \RuntimeException( __( 'WooCommerce could not save the product.', 'boris-stock-inventory-management' ) );
		}
		$problem_key = hash( 'sha256', 'product-creation-incomplete:' . $request_key );
		try {
			$this->media_folders->assign(
				$normalized['media_folder_id'],
				array_values( array_filter( array_merge( array( $normalized['image_id'] ), $normalized['gallery_image_ids'] ) ) )
			);
			$boris_product_id = $this->products->synchronize( $product_id );
			if ( 1 > $boris_product_id ) {
				throw new \RuntimeException( __( 'BORIS Stock could not create the WooCommerce product mapping.', 'boris-stock-inventory-management' ) );
			}

			$this->repository->initialize_import_balance( $boris_product_id, $normalized['stock_quantity'] );
			foreach ( $allocations as $allocation ) {
				$this->initial_stock->place(
					$request_key . '-' . $allocation['location_id'],
					$boris_product_id,
					$allocation['location_id'],
					$allocation['quantity'],
					$user_id
				);
			}

			$fresh_product = wc_get_product( $product_id );
			if ( ! $fresh_product || (int) $fresh_product->get_stock_quantity() !== $normalized['stock_quantity'] ) {
				throw new \RuntimeException( __( 'WooCommerce stock changed while the warehouse allocation was being created.', 'boris-stock-inventory-management' ) );
			}
			if ( $this->repository->physical_total( $boris_product_id ) !== $normalized['stock_quantity'] ) {
				throw new \RuntimeException( __( 'The warehouse allocation does not equal the WooCommerce opening stock.', 'boris-stock-inventory-management' ) );
			}

			$fresh_product->set_status( $normalized['status'] );
			$fresh_product->update_meta_data( self::STATE_META, 'complete' );
			$fresh_product->save();
			$this->repository->resolve_problem( $problem_key );

			return array(
				'wc_product_id'    => $product_id,
				'boris_product_id' => $boris_product_id,
				'status'           => $normalized['status'],
			);
		} catch ( \Throwable $throwable ) {
			$recoverable = wc_get_product( $product_id );
			if ( $recoverable ) {
				$recoverable->set_status( 'draft' );
				$recoverable->update_meta_data( self::STATE_META, 'failed' );
				$recoverable->save();
			}
			$this->repository->problem(
				$problem_key,
				'PRODUCT_CREATION_INCOMPLETE',
				__( 'A WooCommerce product draft needs its BORIS Stock opening allocation to be completed.', 'boris-stock-inventory-management' ),
				array(
					'wc_product_id' => $product_id,
					'request_key'   => $request_key,
				)
			);
			throw $throwable;
		}
	}

	/**
	 * Validate and normalize submitted WooCommerce product fields.
	 *
	 * @param array<string,mixed> $data Sanitized product fields.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When a field is invalid.
	 */
	private function validate_product_data( array $data ): array {
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			throw new \RuntimeException( __( 'Product name is required.', 'boris-stock-inventory-management' ) );
		}

		$sku = trim( (string) ( $data['sku'] ?? '' ) );
		Identifier::normalize( $sku );
		$stock_quantity = Quantity::non_negative( (string) ( $data['stock_quantity'] ?? '' ) );
		$status         = (string) ( $data['status'] ?? 'draft' );
		if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
			throw new \RuntimeException( __( 'Choose a valid publication status.', 'boris-stock-inventory-management' ) );
		}

		$regular_price = $this->decimal( (string) ( $data['regular_price'] ?? '' ), __( 'Regular price', 'boris-stock-inventory-management' ) );
		$sale_price    = $this->decimal( (string) ( $data['sale_price'] ?? '' ), __( 'Sale price', 'boris-stock-inventory-management' ) );
		if ( '' !== $regular_price && '' !== $sale_price && (float) $sale_price > (float) $regular_price ) {
			throw new \RuntimeException( __( 'Sale price cannot be higher than the regular price.', 'boris-stock-inventory-management' ) );
		}

		$categories = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $data['category_ids'] ?? array() ) ) ) ) );
		foreach ( $categories as $category_id ) {
			if ( ! term_exists( $category_id, 'product_cat' ) ) {
				throw new \RuntimeException( __( 'One of the selected product categories no longer exists.', 'boris-stock-inventory-management' ) );
			}
		}
		sort( $categories, SORT_NUMERIC );

		$image_id    = absint( $data['image_id'] ?? 0 );
		$gallery_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $data['gallery_image_ids'] ?? array() ) ) ) ) );
		if ( $image_id ) {
			$this->validate_image( $image_id );
		}
		foreach ( $gallery_ids as $gallery_id ) {
			$this->validate_image( $gallery_id );
		}
		$gallery_ids     = array_values( array_diff( $gallery_ids, array( $image_id ) ) );
		$media_folder_id = absint( $data['media_folder_id'] ?? 0 );
		if ( $media_folder_id && ( ! MediaFolderCompatibility::folders_available() || ! term_exists( $media_folder_id, MediaFolderService::TAXONOMY ) ) ) {
			throw new \RuntimeException( __( 'Choose a valid available BORIS photo folder.', 'boris-stock-inventory-management' ) );
		}

		$global_unique_id = trim( (string) ( $data['global_unique_id'] ?? '' ) );
		if ( '' !== $global_unique_id && ! preg_match( '/^[0-9-]*[0-9Xx]$/D', $global_unique_id ) ) {
			throw new \RuntimeException( __( 'Enter a valid GTIN, UPC, EAN or ISBN.', 'boris-stock-inventory-management' ) );
		}

		return array(
			'name'              => $name,
			'sku'               => $sku,
			'global_unique_id'  => $global_unique_id,
			'regular_price'     => $regular_price,
			'sale_price'        => $sale_price,
			'short_description' => (string) ( $data['short_description'] ?? '' ),
			'description'       => (string) ( $data['description'] ?? '' ),
			'category_ids'      => $categories,
			'image_id'          => $image_id,
			'gallery_image_ids' => $gallery_ids,
			'media_folder_id'   => $media_folder_id,
			'weight'            => $this->decimal( (string) ( $data['weight'] ?? '' ), __( 'Weight', 'boris-stock-inventory-management' ) ),
			'length'            => $this->decimal( (string) ( $data['length'] ?? '' ), __( 'Length', 'boris-stock-inventory-management' ) ),
			'width'             => $this->decimal( (string) ( $data['width'] ?? '' ), __( 'Width', 'boris-stock-inventory-management' ) ),
			'height'            => $this->decimal( (string) ( $data['height'] ?? '' ), __( 'Height', 'boris-stock-inventory-management' ) ),
			'stock_quantity'    => $stock_quantity,
			'status'            => $status,
		);
	}

	/**
	 * Validate that physical allocations exactly equal the WooCommerce total.
	 *
	 * @param array<int,array<string,mixed>> $allocations               Submitted location quantities.
	 * @param int                            $stock_quantity            WooCommerce opening quantity.
	 * @param int                            $existing_boris_product_id Existing mapping during a recovery attempt.
	 * @return array<int,array{location_id:int,quantity:int}>
	 * @throws \RuntimeException When a location or quantity is invalid.
	 */
	private function validate_allocations( array $allocations, int $stock_quantity, int $existing_boris_product_id ): array {
		$valid = array();
		$total = 0;
		foreach ( $allocations as $allocation ) {
			$location_id  = absint( $allocation['location_id'] ?? 0 );
			$location_code = trim( (string) ( $allocation['location_code'] ?? '' ) );
			$raw_quantity = (string) ( $allocation['quantity'] ?? '' );
			if ( 0 === $location_id && '' === $location_code && '' === $raw_quantity ) {
				continue;
			}
			if ( 0 === $location_id && '' !== $location_code ) {
				$candidate = $this->repository->location_by_normalized_code( Identifier::normalize( $location_code ) );
				if ( ! $candidate || 1 !== (int) $candidate->active ) {
					throw new \RuntimeException( __( 'The entered warehouse location code does not exist or is inactive.', 'boris-stock-inventory-management' ) );
				}
				$location_id = (int) $candidate->id;
			}
			if ( 1 > $location_id ) {
				throw new \RuntimeException( __( 'Choose a warehouse location for every allocated quantity.', 'boris-stock-inventory-management' ) );
			}
			$quantity = Quantity::positive( $raw_quantity );
			if ( isset( $valid[ $location_id ] ) ) {
				throw new \RuntimeException( __( 'Each warehouse location can be selected only once.', 'boris-stock-inventory-management' ) );
			}
			$location = $this->repository->location_for_product( $location_id, $existing_boris_product_id );
			if ( ! $location ) {
				throw new \RuntimeException( __( 'A selected warehouse location is inactive or already contains another product.', 'boris-stock-inventory-management' ) );
			}
			$valid[ $location_id ] = array(
				'location_id' => $location_id,
				'quantity'    => $quantity,
			);
			$total                += $quantity;
		}

		if ( $total > $stock_quantity ) {
			throw new \RuntimeException( __( 'The allocated location quantities exceed the WooCommerce opening stock. Reduce the location quantities before saving.', 'boris-stock-inventory-management' ) );
		}
		if ( $total < $stock_quantity ) {
			throw new \RuntimeException( __( 'The allocated location quantities are lower than the WooCommerce opening stock. Allocate the complete opening stock before saving.', 'boris-stock-inventory-management' ) );
		}
		ksort( $valid, SORT_NUMERIC );
		return array_values( $valid );
	}

	/**
	 * Ensure the two catalogue identifiers are unique in WooCommerce.
	 *
	 * @param int    $product_id      Existing product ID during recovery, or zero.
	 * @param string $sku             Product SKU.
	 * @param string $global_unique_id Optional GTIN, UPC, EAN or ISBN.
	 * @throws \RuntimeException When an identifier belongs to another product.
	 */
	private function validate_unique_identifiers( int $product_id, string $sku, string $global_unique_id ): void {
		if ( ! wc_product_has_unique_sku( $product_id, $sku ) ) {
			throw new \RuntimeException( __( 'This SKU is already used by another WooCommerce product.', 'boris-stock-inventory-management' ) );
		}
		if ( '' !== $global_unique_id && ! wc_product_has_global_unique_id( $product_id, $global_unique_id ) ) {
			throw new \RuntimeException( __( 'This GTIN, UPC, EAN or ISBN is already used by another WooCommerce product.', 'boris-stock-inventory-management' ) );
		}
	}

	/**
	 * Apply validated fields through WooCommerce CRUD setters.
	 *
	 * @param \WC_Product_Simple  $product WooCommerce product.
	 * @param array<string,mixed> $data    Validated product fields.
	 */
	private function apply_product_data( $product, array $data ): void {
		$product->set_name( $data['name'] );
		$product->set_sku( $data['sku'] );
		$product->set_global_unique_id( $data['global_unique_id'] );
		$product->set_regular_price( $data['regular_price'] );
		$product->set_sale_price( $data['sale_price'] );
		$product->set_description( $data['description'] );
		$product->set_short_description( $data['short_description'] );
		$product->set_category_ids( $data['category_ids'] );
		$product->set_image_id( $data['image_id'] );
		$product->set_gallery_image_ids( $data['gallery_image_ids'] );
		$product->set_weight( $data['weight'] );
		$product->set_length( $data['length'] );
		$product->set_width( $data['width'] );
		$product->set_height( $data['height'] );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $data['stock_quantity'] );
		$product->set_stock_status( 0 < $data['stock_quantity'] ? 'instock' : 'outofstock' );
		$product->set_backorders( 'no' );
		$product->set_catalog_visibility( 'visible' );
	}

	/**
	 * Normalize one optional non-negative WooCommerce decimal.
	 *
	 * @param string $value Submitted value.
	 * @param string $label Translated field label for an error.
	 * @return string Normalized decimal or an empty string.
	 * @throws \RuntimeException When the value is invalid.
	 */
	private function decimal( string $value, string $label ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$decimal = wc_format_decimal( $value );
		if ( '' === $decimal || ! is_numeric( $decimal ) || 0 > (float) $decimal ) {
			/* translators: %s is a product field label. */
			throw new \RuntimeException( sprintf( __( '%s must be a non-negative number.', 'boris-stock-inventory-management' ), $label ) );
		}
		return $decimal;
	}

	/**
	 * Validate one Media Library image attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @throws \RuntimeException When the attachment is not an image.
	 */
	private function validate_image( int $attachment_id ): void {
		if ( 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
			throw new \RuntimeException( __( 'One of the selected media items is not a valid image attachment.', 'boris-stock-inventory-management' ) );
		}
	}

	/**
	 * Find a WooCommerce product belonging to a previous identical request.
	 *
	 * @param string $request_key Product-creation UUID.
	 * @return \WC_Product_Simple|null
	 */
	private function product_for_request( string $request_key ) {
		$query = new \WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => array( 'draft', 'publish', 'private', 'pending' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_key'               => self::REQUEST_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One exact recovery key for a user-initiated operation.
				'meta_value'             => $request_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One exact recovery key for a user-initiated operation.
			)
		);
		if ( empty( $query->posts ) ) {
			return null;
		}
		$product = wc_get_product( (int) $query->posts[0] );
		return $product instanceof \WC_Product_Simple ? $product : null;
	}
}
