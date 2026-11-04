<?php
/**
 * WooCommerce product mapping service.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Products;

use BorisSystems\Stock\Domain\Inventory\ImportProgress;
use BorisSystems\Stock\Infrastructure\Database\Repository;
use BorisSystems\Stock\WooCommerce\ProductRules;

defined( 'ABSPATH' ) || exit;

final class ProductService {
	private Repository $repository;
	private ProductRules $rules;

	public function __construct( ?Repository $repository = null, ?ProductRules $rules = null ) {
		$this->repository = $repository ?? new Repository();
		$this->rules      = $rules ?? new ProductRules();
	}

	/** Validate and store one product mapping. */
	public function synchronize( int $product_id ): int {
		$product = wc_get_product( $product_id );
		$result  = $this->rules->inspect( $product );
		$key     = hash( 'sha256', 'unsupported-product:' . $product_id );
		if ( ! $result['supported'] ) {
			$this->repository->mark_product_unsupported( $product_id );
			$this->repository->problem(
				$key,
				'UNSUPPORTED_PRODUCT_CONFIGURATION',
				implode( ' ', $result['reasons'] ),
				array( 'wc_product_id' => $product_id )
			);
			return 0;
		}
		$sku_mapping = $this->repository->product_by_normalized_sku( $result['sku_normalized'] );
		if ( $sku_mapping && (int) $sku_mapping->wc_product_id !== $product_id ) {
			$this->repository->problem(
				$key,
				'DUPLICATE_NORMALIZED_SKU',
				__( 'After normalization, this SKU conflicts with another WooCommerce product.', 'boris-stock-inventory-management' ),
				array(
					'wc_product_id'             => $product_id,
					'conflicting_wc_product_id' => (int) $sku_mapping->wc_product_id,
				)
			);
			return 0;
		}

		$this->repository->resolve_problem( $key );
		return $this->repository->save_product(
			$product_id,
			(string) $product->get_sku(),
			$result['sku_normalized'],
			(string) $product->get_name(),
			$result['stock_quantity']
		);
	}

	/** Synchronize after a WooCommerce product is saved. */
	public function product_saved( int $product_id ): void {
		$this->synchronize( $product_id );
	}

	/** Discover and validate existing stock-managed WooCommerce products. */
	public function synchronize_catalogue(): int {
		$result = $this->import_catalogue();
		return $result['already_linked'] + $result['imported'];
	}

	/**
	 * Import the WooCommerce catalogue and capture each opening balance once.
	 *
	 * @return array{found:int,already_linked:int,imported:int,conflicts:int}
	 */
	public function import_catalogue(): array {
		$result = array(
			'found'          => 0,
			'already_linked' => 0,
			'imported'       => 0,
			'conflicts'      => 0,
		);
		foreach ( $this->catalogue_product_ids() as $product_id ) {
			++$result['found'];
			$existing         = $this->repository->product_by_wc_id( $product_id );
			$boris_product_id = $this->synchronize( $product_id );
			if ( 0 === $boris_product_id ) {
				++$result['conflicts'];
				continue;
			}
			$product = wc_get_product( $product_id );
			$this->repository->initialize_import_balance( $boris_product_id, (int) $product->get_stock_quantity() );
			if ( $existing ) {
				++$result['already_linked'];
			} else {
				++$result['imported'];
			}
		}
		update_option( 'boris_stock_catalogue_imported_at_gmt', gmdate( 'Y-m-d H:i:s' ), false );
		return $result;
	}

	/**
	 * Return a read-only per-product view for the import screen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function catalogue_snapshot(): array {
		return $this->snapshot_for_ids( $this->catalogue_product_ids() );
	}

	/**
	 * Return one bounded page for the initial-import screen.
	 *
	 * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int}
	 */
	public function catalogue_page( int $page = 1, int $per_page = 50 ): array {
		$page     = max( 1, $page );
		$per_page = max( 10, min( 100, $per_page ) );
		$query    = wc_get_products(
			array(
				'limit'        => $per_page,
				'page'         => $page,
				'paginate'     => true,
				'manage_stock' => true,
				'return'       => 'ids',
			)
		);
		return array(
			'rows'  => $this->snapshot_for_ids( array_map( 'intval', $query->products ) ),
			'total' => (int) $query->total,
			'page'  => $page,
			'pages' => max( 1, (int) $query->max_num_pages ),
		);
	}

	/**
	 * Build validated read models for an explicit bounded product-ID list.
	 *
	 * @param array<int,int> $product_ids WooCommerce product IDs.
	 * @return array<int,array<string,mixed>>
	 */
	private function snapshot_for_ids( array $product_ids ): array {
		$rows = array();
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			$check   = $this->rules->inspect( $product );
			$mapping = $this->repository->product_by_wc_id( $product_id );
			$reasons = $check['reasons'];
			if ( $check['supported'] ) {
				$sku_mapping = $this->repository->product_by_normalized_sku( $check['sku_normalized'] );
				if ( $sku_mapping && (int) $sku_mapping->wc_product_id !== $product_id ) {
					$reasons[] = __( 'After normalization, this SKU conflicts with another WooCommerce product.', 'boris-stock-inventory-management' );
				}
			}

			$opening = $mapping && null !== $mapping->initial_stock_quantity ? (int) $mapping->initial_stock_quantity : null;
			$placed  = $mapping ? (int) $mapping->initial_stock_placed : 0;
			$status  = empty( $reasons ) && null !== $opening ? ImportProgress::status( $opening, $placed ) : ImportProgress::ERROR;
			$rows[]  = array(
				'wc_product_id'    => $product_id,
				'boris_product_id' => $mapping ? (int) $mapping->id : 0,
				'name'             => $product ? (string) $product->get_name() : '',
				'sku'              => $product ? (string) $product->get_sku() : '',
				'wc_quantity'      => $product ? (int) $product->get_stock_quantity() : 0,
				'opening'          => $opening,
				'placed'           => $placed,
				'physical'         => $mapping ? $this->repository->physical_total( (int) $mapping->id ) : 0,
				'remaining'        => null === $opening ? 0 : ImportProgress::remaining( $opening, $placed ),
				'status'           => $status,
				'reasons'          => $reasons,
			);
		}
		return $rows;
	}

	/**
	 * Fetch all stock-managed WooCommerce product IDs.
	 *
	 * @return array<int,int>
	 */
	private function catalogue_product_ids(): array {
		$page = 1;
		$ids  = array();
		do {
			$query = wc_get_products(
				array(
					'limit'        => 100,
					'page'         => $page,
					'paginate'     => true,
					'manage_stock' => true,
					'return'       => 'ids',
				)
			);
			foreach ( $query->products as $product_id ) {
				$ids[] = (int) $product_id;
			}
			++$page;
		} while ( $page <= (int) $query->max_num_pages );
		return $ids;
	}
}
