<?php
/**
 * Safe WooCommerce catalogue-to-media-folder organization.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Media;

defined( 'ABSPATH' ) || exit;

// Translated exception messages are data; the receiving admin UI escapes them for its output context.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Builds a logical BORIS photo tree without changing WooCommerce media data.
 */
final class CatalogMediaOrganizer {
	private const ORIGIN_META   = '_boris_stock_media_origin';
	private const KIND_META     = '_boris_stock_media_kind';
	private const SOURCE_META   = '_boris_stock_media_source_id';
	private const VERSION_META  = '_boris_stock_media_import_version';
	private const CATEGORY_META = '_boris_stock_media_category_id';
	private const ORIGIN        = 'woocommerce_catalogue';
	private const VERSION       = '1';
	private const ROOT_NAME     = 'Winkel';
	private const SHARED_NAME   = 'Shared product media';

	/** @var array<string,int>|null Importer-owned folder IDs indexed by kind and source ID. */
	private ?array $auto_folder_index = null;

	/** Analyze the current WooCommerce catalogue without changing any data. */
	public function analyze(): array {
		$products          = array();
		$attachment_usage  = array();
		$multiple_category = 0;
		$without_images    = 0;

		foreach ( $this->catalogue_product_ids() as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || $product->is_type( 'variation' ) ) {
				continue;
			}

			$image_ids = $this->product_image_ids( $product );
			foreach ( $image_ids as $attachment_id ) {
				$attachment_usage[ $attachment_id ][ $product_id ] = true;
			}

			$options   = $this->category_options( $product );
			$persisted = absint( get_post_meta( $product_id, self::CATEGORY_META, true ) );
			$choice    = $this->choose_category( $options, $persisted );
			$ambiguous = 1 < count( $options );
			if ( $ambiguous ) {
				++$multiple_category;
			}
			if ( empty( $image_ids ) ) {
				++$without_images;
			}

			$products[ $product_id ] = array(
				'id'                   => $product_id,
				'name'                 => (string) $product->get_name(),
				'image_ids'            => $image_ids,
				'image_count'          => count( $image_ids ),
				'category_options'     => $options,
				'proposed_category_id' => $choice,
				'ambiguous'            => $ambiguous,
			);
		}

		$shared_ids        = array();
		$manual_placements = array();
		foreach ( $attachment_usage as $attachment_id => $product_ids ) {
			if ( 1 < count( $product_ids ) ) {
				$shared_ids[] = (int) $attachment_id;
			}
			if ( $this->has_manual_placement( (int) $attachment_id ) ) {
				$manual_placements[] = (int) $attachment_id;
			}
		}

		$name_conflicts = $this->count_planned_name_conflicts( $products );
		$planned        = count( array_filter( $products, static fn( array $row ): bool => 0 < $row['image_count'] ) );

		return array(
			'created_at_gmt'        => gmdate( 'Y-m-d H:i:s' ),
			'catalog_signature'     => $this->analysis_signature( $products ),
			'products'              => $products,
			'shared_attachment_ids' => array_values( array_unique( $shared_ids ) ),
			'summary'               => array(
				'products_examined'       => count( $products ),
				'images_examined'         => count( $attachment_usage ),
				'products_to_organize'    => $planned,
				'shared_images'           => count( $shared_ids ),
				'multiple_categories'     => $multiple_category,
				'manual_placements'       => count( $manual_placements ),
				'name_conflicts'          => $name_conflicts,
				'products_without_images' => $without_images,
			),
		);
	}

	/**
	 * Create a compact, resumable job from an approved analysis.
	 *
	 * @param array<int,int> $choices User-approved category choices keyed by product ID.
	 */
	public function create_job( array $analysis, array $choices ): array {
		$current = $this->analyze();
		if ( empty( $analysis['catalog_signature'] ) || ! hash_equals( (string) $analysis['catalog_signature'], (string) $current['catalog_signature'] ) ) {
			throw new \RuntimeException( __( 'The WooCommerce catalogue changed after the analysis. Run the photo analysis again before organizing.', 'boris-stock-inventory-management' ) );
		}
		$products = isset( $analysis['products'] ) && is_array( $analysis['products'] ) ? $analysis['products'] : array();
		$selected = array();
		$images   = array();
		foreach ( $products as $product_id => $row ) {
			$product_id = absint( $product_id );
			if ( 1 > $product_id || empty( $row['image_ids'] ) ) {
				continue;
			}
			$allowed = array_map( 'absint', array_column( $row['category_options'], 'id' ) );
			$choice  = isset( $choices[ $product_id ] ) ? absint( $choices[ $product_id ] ) : absint( $row['proposed_category_id'] );
			if ( ! in_array( $choice, $allowed, true ) ) {
				throw new \RuntimeException( __( 'One of the selected product categories is no longer valid.', 'boris-stock-inventory-management' ) );
			}
			$selected[ $product_id ] = $choice;
			$images[ $product_id ]   = array_map( 'absint', $row['image_ids'] );
		}

		return array(
			'product_ids'           => array_keys( $selected ),
			'category_choices'      => $selected,
			'product_image_ids'     => $images,
			'shared_attachment_ids' => array_map( 'absint', $analysis['shared_attachment_ids'] ?? array() ),
			'offset'                => 0,
			'total'                 => count( $selected ),
			'processed'             => 0,
			'folders_created'       => 0,
			'folders_reused'        => 0,
			'attachments_assigned'  => 0,
			'manual_preserved'      => 0,
			'errors'                => array(),
			'started_at_gmt'        => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/** Process one bounded batch and return the updated job. */
	public function process_batch( array $job, int $limit = 25 ): array {
		$product_ids = array_map( 'absint', $job['product_ids'] ?? array() );
		$offset      = max( 0, absint( $job['offset'] ?? 0 ) );
		$limit       = max( 1, min( 50, $limit ) );
		$shared      = array_fill_keys( array_map( 'absint', $job['shared_attachment_ids'] ?? array() ), true );
		$choices     = is_array( $job['category_choices'] ?? null ) ? $job['category_choices'] : array();
		$images      = is_array( $job['product_image_ids'] ?? null ) ? $job['product_image_ids'] : array();

		foreach ( array_slice( $product_ids, $offset, $limit ) as $product_id ) {
			try {
				$stats = $this->organize_product( $product_id, absint( $choices[ $product_id ] ?? 0 ), array_map( 'absint', $images[ $product_id ] ?? array() ), $shared );
				foreach ( array( 'folders_created', 'folders_reused', 'attachments_assigned', 'manual_preserved' ) as $key ) {
					$job[ $key ] = absint( $job[ $key ] ?? 0 ) + $stats[ $key ];
				}
			} catch ( \Throwable $throwable ) {
				$job['errors'][] = array(
					'product_id' => $product_id,
					'message'    => sanitize_text_field( $throwable->getMessage() ),
				);
			}
			++$job['processed'];
		}

		$job['offset']   = min( count( $product_ids ), $offset + $limit );
		$job['complete'] = $job['offset'] >= count( $product_ids );
		if ( $job['complete'] ) {
			$job['completed_at_gmt'] = gmdate( 'Y-m-d H:i:s' );
		}
		return $job;
	}

	/** Organize one product without altering any WooCommerce image relationship. */
	private function organize_product( int $product_id, int $category_id, array $expected_images, array $shared ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variation' ) ) {
			throw new \RuntimeException( __( 'A product from the approved analysis no longer exists.', 'boris-stock-inventory-management' ) );
		}

		$options = $this->category_options( $product );
		$allowed = array_map( 'absint', array_column( $options, 'id' ) );
		if ( ! in_array( $category_id, $allowed, true ) ) {
			throw new \RuntimeException( __( 'The approved product category is no longer assigned to this product.', 'boris-stock-inventory-management' ) );
		}
		if ( $expected_images !== $this->product_image_ids( $product ) ) {
			throw new \RuntimeException( __( 'The product images changed after the analysis. Analyze again before organizing this product.', 'boris-stock-inventory-management' ) );
		}

		$stats       = array_fill_keys( array( 'folders_created', 'folders_reused', 'attachments_assigned', 'manual_preserved' ), 0 );
		$root        = $this->ensure_root( $stats );
		$category    = $this->ensure_category_path( $category_id, $root, $stats );
		$product_dir = $this->ensure_auto_folder( (string) $product->get_name(), $category, 'product', $product_id, $stats );
		$shared_dir  = 0;

		update_post_meta( $product_id, self::CATEGORY_META, $category_id );
		foreach ( $this->product_image_ids( $product ) as $attachment_id ) {
			if ( $this->has_manual_placement( $attachment_id ) ) {
				++$stats['manual_preserved'];
				continue;
			}
			$destination = $product_dir;
			if ( isset( $shared[ $attachment_id ] ) ) {
				if ( 0 === $shared_dir ) {
					$shared_dir = $this->ensure_auto_folder( self::SHARED_NAME, $root, 'shared', 0, $stats );
				}
				$destination = $shared_dir;
			}
			$current = $this->folder_ids( $attachment_id );
			if ( array( $destination ) === $current ) {
				continue;
			}
			$result = wp_set_object_terms( $attachment_id, array( $destination ), MediaFolderService::TAXONOMY, false );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}
			++$stats['attachments_assigned'];
		}

		return $stats;
	}

	/** Return normal WooCommerce parent-product IDs in stable order. */
	private function catalogue_product_ids(): array {
		$page = 1;
		$ids  = array();
		do {
			$query = wc_get_products(
				array(
					'limit'    => 100,
					'page'     => $page,
					'paginate' => true,
					'return'   => 'ids',
					'orderby'  => 'ID',
					'order'    => 'ASC',
					'status'   => array( 'publish', 'pending', 'draft', 'private', 'future' ),
				)
			);
			foreach ( $query->products as $product_id ) {
				$ids[] = (int) $product_id;
			}
			++$page;
		} while ( $page <= (int) $query->max_num_pages );
		return array_values( array_unique( $ids ) );
	}

	/** Return valid main, gallery and unique variation image IDs. */
	private function product_image_ids( $product ): array {
		$ids = array_merge( array( absint( $product->get_image_id() ) ), array_map( 'absint', $product->get_gallery_image_ids() ) );
		if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_children' ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation ) {
					$ids[] = absint( $variation->get_image_id() );
				}
			}
		}
		return array_values(
			array_filter(
				array_unique( $ids ),
				static fn( int $attachment_id ): bool => 0 < $attachment_id && 'attachment' === get_post_type( $attachment_id ) && wp_attachment_is_image( $attachment_id )
			)
		);
	}

	/** Return independent assigned category branches, deepest first. */
	private function category_options( $product ): array {
		$assigned = array_values( array_unique( array_map( 'absint', $product->get_category_ids() ) ) );
		$leaves   = array();
		foreach ( $assigned as $category_id ) {
			$is_ancestor = false;
			foreach ( $assigned as $other_id ) {
				if ( $category_id !== $other_id && in_array( $category_id, array_map( 'absint', get_ancestors( $other_id, 'product_cat', 'taxonomy' ) ), true ) ) {
					$is_ancestor = true;
					break;
				}
			}
			if ( ! $is_ancestor && get_term( $category_id, 'product_cat' ) instanceof \WP_Term ) {
				$leaves[] = $category_id;
			}
		}

		if ( empty( $leaves ) ) {
			return array(
				array(
					'id'    => 0,
					'label' => __( 'Uncategorized', 'boris-stock-inventory-management' ),
					'depth' => 0,
				),
			);
		}

		$options = array();
		foreach ( $leaves as $category_id ) {
			$path      = $this->category_path( $category_id );
			$options[] = array(
				'id'    => $category_id,
				'label' => implode( ' / ', array_column( $path, 'name' ) ),
				'depth' => count( $path ),
			);
		}
		usort(
			$options,
			static function ( array $left, array $right ): int {
				if ( $left['depth'] !== $right['depth'] ) {
					return $right['depth'] <=> $left['depth'];
				}
				$name = strnatcasecmp( $left['label'], $right['label'] );
				return 0 !== $name ? $name : $left['id'] <=> $right['id'];
			}
		);
		return $options;
	}

	/** Pick a persisted valid choice or the stable first proposal. */
	private function choose_category( array $options, int $persisted ): int {
		$allowed = array_map( 'absint', array_column( $options, 'id' ) );
		return in_array( $persisted, $allowed, true ) ? $persisted : absint( $options[0]['id'] ?? 0 );
	}

	/** Return one category's complete root-to-leaf WooCommerce path. */
	private function category_path( int $category_id ): array {
		if ( 1 > $category_id ) {
			return array(
				array(
					'id'   => 0,
					'name' => __( 'Uncategorized', 'boris-stock-inventory-management' ),
				),
			);
		}
		$ids  = array_merge( array_reverse( get_ancestors( $category_id, 'product_cat', 'taxonomy' ) ), array( $category_id ) );
		$path = array();
		foreach ( $ids as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				$path[] = array(
					'id'   => (int) $term->term_id,
					'name' => (string) $term->name,
				);
			}
		}
		return $path;
	}

	/** Create/reuse the logical root without taking ownership of a manual root. */
	private function ensure_root( array &$stats ): int {
		$auto = $this->find_auto_folder( 'root', 0 );
		if ( $auto ) {
			++$stats['folders_reused'];
			return $auto;
		}
		$manual = term_exists( self::ROOT_NAME, MediaFolderService::TAXONOMY, 0 );
		if ( $manual ) {
			++$stats['folders_reused'];
			return is_array( $manual ) ? (int) $manual['term_id'] : (int) $manual;
		}
		return $this->create_auto_folder( self::ROOT_NAME, 0, 'root', 0, $stats );
	}

	/** Create/reuse the selected WooCommerce category hierarchy. */
	private function ensure_category_path( int $category_id, int $parent_id, array &$stats ): int {
		foreach ( $this->category_path( $category_id ) as $segment ) {
			$parent_id = $this->ensure_auto_folder( $segment['name'], $parent_id, 'category', (int) $segment['id'], $stats );
		}
		return $parent_id;
	}

	/** Create or safely update one importer-owned folder. */
	private function ensure_auto_folder( string $name, int $parent_id, string $kind, int $source_id, array &$stats ): int {
		$folder_id = $this->find_auto_folder( $kind, $source_id );
		$name      = $this->available_name( sanitize_text_field( $name ), $parent_id, $folder_id, $source_id );
		if ( $folder_id ) {
			$term = get_term( $folder_id, MediaFolderService::TAXONOMY );
			if ( ! $term instanceof \WP_Term ) {
				throw new \RuntimeException( __( 'An automatically managed BORIS photo folder is no longer valid.', 'boris-stock-inventory-management' ) );
			}
			if ( (int) $term->parent !== $parent_id || (string) $term->name !== $name ) {
				( new MediaFolderService() )->assert_safe_parent( $folder_id, $parent_id );
				$updated = wp_update_term(
					$folder_id,
					MediaFolderService::TAXONOMY,
					array(
						'name'   => $name,
						'parent' => $parent_id,
					)
				);
				if ( is_wp_error( $updated ) ) {
					throw new \RuntimeException( $updated->get_error_message() );
				}
			}
			++$stats['folders_reused'];
			return $folder_id;
		}
		return $this->create_auto_folder( $name, $parent_id, $kind, $source_id, $stats );
	}

	/** Insert and mark one importer-owned logical folder. */
	private function create_auto_folder( string $name, int $parent_id, string $kind, int $source_id, array &$stats ): int {
		$name    = $this->available_name( sanitize_text_field( $name ), $parent_id, 0, $source_id );
		$created = wp_insert_term( $name, MediaFolderService::TAXONOMY, array( 'parent' => $parent_id ) );
		if ( is_wp_error( $created ) ) {
			throw new \RuntimeException( $created->get_error_message() );
		}
		$folder_id = (int) $created['term_id'];
		update_term_meta( $folder_id, self::ORIGIN_META, self::ORIGIN );
		update_term_meta( $folder_id, self::KIND_META, $kind );
		update_term_meta( $folder_id, self::SOURCE_META, $source_id );
		update_term_meta( $folder_id, self::VERSION_META, self::VERSION );
		if ( is_array( $this->auto_folder_index ) ) {
			$this->auto_folder_index[ $kind . '|' . $source_id ] = $folder_id;
		}
		++$stats['folders_created'];
		return $folder_id;
	}

	/** Find an importer-owned folder by stable WooCommerce identity. */
	private function find_auto_folder( string $kind, int $source_id ): int {
		if ( null === $this->auto_folder_index ) {
			$this->auto_folder_index = array();
			$terms                   = get_terms(
				array(
					'taxonomy'   => MediaFolderService::TAXONOMY,
					'hide_empty' => false,
				)
			);
			foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
				if ( self::ORIGIN !== get_term_meta( $term->term_id, self::ORIGIN_META, true ) ) {
					continue;
				}
				$term_kind   = (string) get_term_meta( $term->term_id, self::KIND_META, true );
				$term_source = (int) get_term_meta( $term->term_id, self::SOURCE_META, true );
				$this->auto_folder_index[ $term_kind . '|' . $term_source ] = (int) $term->term_id;
			}
		}
		return (int) ( $this->auto_folder_index[ $kind . '|' . $source_id ] ?? 0 );
	}

	/** Hash only the catalogue state that affects the approved organization plan. */
	private function analysis_signature( array $products ): string {
		$rows = array();
		foreach ( $products as $product_id => $row ) {
			$rows[ absint( $product_id ) ] = array(
				'name'       => (string) $row['name'],
				'image_ids'  => array_map( 'absint', $row['image_ids'] ),
				'categories' => array_map( 'absint', array_column( $row['category_options'], 'id' ) ),
			);
		}
		return hash( 'sha256', (string) wp_json_encode( $rows ) );
	}

	/** Avoid taking over a manual or different automatically managed sibling. */
	private function available_name( string $name, int $parent_id, int $own_id, int $source_id ): string {
		$base      = '' !== $name ? $name : __( 'Unnamed product', 'boris-stock-inventory-management' );
		$candidate = $base;
		$suffix    = 0 < $source_id ? sprintf( ' (WooCommerce %d)', $source_id ) : ' (WooCommerce)';
		$attempt   = 1;
		do {
			$existing    = term_exists( $candidate, MediaFolderService::TAXONOMY, $parent_id );
			$existing_id = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
			if ( ! $existing_id || $existing_id === $own_id ) {
				return $candidate;
			}
			$candidate = $base . $suffix . ( 1 < $attempt ? '-' . $attempt : '' );
			++$attempt;
		} while ( 100 > $attempt );
		throw new \RuntimeException( __( 'A unique BORIS photo-folder name could not be created safely.', 'boris-stock-inventory-management' ) );
	}

	/** True when an attachment is organized in a folder the importer does not own. */
	private function has_manual_placement( int $attachment_id ): bool {
		foreach ( $this->folder_ids( $attachment_id ) as $folder_id ) {
			if ( self::ORIGIN !== get_term_meta( $folder_id, self::ORIGIN_META, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Return the attachment's BORIS folder IDs in stable order. */
	private function folder_ids( int $attachment_id ): array {
		$ids = wp_get_object_terms( $attachment_id, MediaFolderService::TAXONOMY, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $ids ) ) {
			return array();
		}
		sort( $ids, SORT_NUMERIC );
		return array_map( 'absint', $ids );
	}

	/** Count obvious same-category product-name collisions before execution. */
	private function count_planned_name_conflicts( array $products ): int {
		$seen      = array();
		$conflicts = 0;
		foreach ( $products as $row ) {
			if ( empty( $row['image_ids'] ) ) {
				continue;
			}
			$key = absint( $row['proposed_category_id'] ) . '|' . sanitize_title( (string) $row['name'] );
			if ( isset( $seen[ $key ] ) ) {
				++$conflicts;
			}
			$seen[ $key ] = true;
		}
		return $conflicts;
	}
}
