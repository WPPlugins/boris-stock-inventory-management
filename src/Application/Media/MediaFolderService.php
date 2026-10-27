<?php
/**
 * Virtual BORIS folders for WordPress media attachments.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Media;

defined( 'ABSPATH' ) || exit;

// Translated exception messages are data; the receiving admin UI escapes them for its output context.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Keeps folder organization in WordPress terms without moving upload files.
 */
final class MediaFolderService {
	public const TAXONOMY = 'boris_stock_media_folder';

	/**
	 * Register the taxonomy and Media Library query integration.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_taxonomy' ), 9 );
		if ( is_admin() ) {
			add_filter( 'ajax_query_attachments_args', array( $this, 'filter_attachment_query' ) );
			add_action( 'add_attachment', array( $this, 'assign_uploaded_attachment' ) );
		}
	}

	/**
	 * Assign a freshly uploaded image when the BORIS picker supplied a folder.
	 *
	 * WordPress has already authenticated and authorized the media upload. BORIS
	 * additionally verifies its own nonce and capabilities before adding only the
	 * private logical taxonomy relationship.
	 *
	 * @param int $attachment_id Newly created attachment ID.
	 */
	public function assign_uploaded_attachment( int $attachment_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified explicitly with wp_verify_nonce() below.
		$nonce     = isset( $_POST['boris_stock_media_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['boris_stock_media_nonce'] ) ) : '';
		$folder_id = isset( $_POST['boris_stock_media_folder'] ) ? absint( wp_unslash( $_POST['boris_stock_media_folder'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( 1 > $folder_id || ! wp_verify_nonce( $nonce, 'boris_stock_media_tree' ) ) {
			return;
		}
		if ( ! current_user_can( 'boris_stock_manage_inventory' ) || ! current_user_can( 'upload_files' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Activator.
			return;
		}

		try {
			$this->assign( $folder_id, array( $attachment_id ) );
		} catch ( \Throwable $throwable ) {
			// The WordPress upload must remain successful when optional organization fails.
			do_action( 'boris_stock_media_folder_upload_assignment_failed', $attachment_id, $folder_id, $throwable );
		}
	}

	/**
	 * Register a private hierarchical taxonomy on ordinary attachments.
	 */
	public function register_taxonomy(): void {
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			add_filter( 'boris_stock_media_folder_runtime_conflicts', array( $this, 'report_taxonomy_collision' ) );
			return;
		}

		register_taxonomy(
			self::TAXONOMY,
			'attachment',
			array(
				'labels'             => array(
					'name'          => __( 'BORIS photo folders', 'boris-stock-inventory-management' ),
					'singular_name' => __( 'BORIS photo folder', 'boris-stock-inventory-management' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'hierarchical'       => true,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'query_var'          => false,
				'rewrite'            => false,
				'show_admin_column'  => false,
				'capabilities'       => array(
					'manage_terms' => 'upload_files',
					'edit_terms'   => 'upload_files',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'upload_files',
				),
			)
		);
	}

	/**
	 * Add a confirmed runtime conflict when another component owns our taxonomy name.
	 *
	 * @param array<string,string> $conflicts Existing conflicts.
	 * @return array<string,string>
	 */
	public function report_taxonomy_collision( array $conflicts ): array {
		$conflicts['reserved-taxonomy'] = __( 'another component using the reserved BORIS media-folder name', 'boris-stock-inventory-management' );
		return $conflicts;
	}

	/**
	 * Filter the WordPress media modal by one selected virtual folder.
	 *
	 * @param array<string,mixed> $query WordPress attachment query arguments.
	 * @return array<string,mixed>
	 */
	public function filter_attachment_query( array $query ): array {
		// WordPress removes custom media-model properties before this filter, so read this read-only filter value directly.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core query-attachments AJAX performs its own nonce check.
		$folder_id = isset( $_REQUEST['query']['boris_stock_media_folder'] ) ? absint( wp_unslash( $_REQUEST['query']['boris_stock_media_folder'] ) ) : 0;
		if ( 1 > $folder_id || ! term_exists( $folder_id, self::TAXONOMY ) ) {
			return $query;
		}

		$query['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Explicit user-selected media folder.
			array(
				'taxonomy'         => self::TAXONOMY,
				'field'            => 'term_id',
				'terms'            => array( $folder_id ),
				'include_children' => false,
			),
		);
		return $query;
	}

	/**
	 * Create one virtual folder below an optional parent.
	 *
	 * @param string $name      New folder name.
	 * @param int    $parent_id Parent folder ID, or zero for a root folder.
	 * @return array{id:int,parent:int,name:string,label:string}
	 * @throws \RuntimeException When the folder cannot be created safely.
	 */
	public function create_folder( string $name, int $parent_id = 0 ): array {
		$name = sanitize_text_field( trim( $name ) );
		if ( '' === $name || 100 < strlen( $name ) ) {
			throw new \RuntimeException( __( 'Every photo-folder name must contain between 1 and 100 characters.', 'boris-stock-inventory-management' ) );
		}
		if ( str_contains( $name, '/' ) || str_contains( $name, '\\' ) ) {
			throw new \RuntimeException( __( 'Enter one folder name without slashes.', 'boris-stock-inventory-management' ) );
		}
		if ( 0 < $parent_id && ! term_exists( $parent_id, self::TAXONOMY ) ) {
			throw new \RuntimeException( __( 'The selected parent folder no longer exists.', 'boris-stock-inventory-management' ) );
		}
		if ( term_exists( $name, self::TAXONOMY, $parent_id ) ) {
			throw new \RuntimeException( __( 'A photo folder with this name already exists here.', 'boris-stock-inventory-management' ) );
		}

		$created = wp_insert_term( $name, self::TAXONOMY, array( 'parent' => $parent_id ) );
		if ( is_wp_error( $created ) ) {
			throw new \RuntimeException( $created->get_error_message() );
		}
		$folder_id = (int) $created['term_id'];

		return array(
			'id'     => $folder_id,
			'parent' => $parent_id,
			'name'   => $name,
			'label'  => $this->folder_path( $folder_id ),
		);
	}

	/**
	 * Create or reuse every segment of a slash-separated virtual folder path.
	 *
	 * @param string $path User-entered logical path.
	 * @return array{id:int,label:string}
	 * @throws \RuntimeException When the path cannot be created safely.
	 */
	public function create_path( string $path ): array {
		$path     = str_replace( '\\', '/', $path );
		$segments = array_values( array_filter( array_map( 'trim', explode( '/', $path ) ), static fn( string $segment ): bool => '' !== $segment ) );
		if ( empty( $segments ) ) {
			throw new \RuntimeException( __( 'Enter a folder path first.', 'boris-stock-inventory-management' ) );
		}
		if ( 10 < count( $segments ) ) {
			throw new \RuntimeException( __( 'A BORIS photo-folder path can contain at most ten levels.', 'boris-stock-inventory-management' ) );
		}

		$parent = 0;
		$labels = array();
		foreach ( $segments as $segment ) {
			$segment = sanitize_text_field( $segment );
			if ( '' === $segment || 100 < strlen( $segment ) ) {
				throw new \RuntimeException( __( 'Every photo-folder name must contain between 1 and 100 characters.', 'boris-stock-inventory-management' ) );
			}
			$existing = term_exists( $segment, self::TAXONOMY, $parent );
			if ( is_array( $existing ) ) {
				$parent = (int) $existing['term_id'];
			} elseif ( $existing ) {
				$parent = (int) $existing;
			} else {
				$created = wp_insert_term( $segment, self::TAXONOMY, array( 'parent' => $parent ) );
				if ( is_wp_error( $created ) ) {
					throw new \RuntimeException( $created->get_error_message() );
				}
				$parent = (int) $created['term_id'];
			}
			$labels[] = $segment;
		}

		return array(
			'id'    => $parent,
			'label' => implode( ' / ', $labels ),
		);
	}

	/**
	 * Assign ordinary image attachments to one BORIS virtual folder.
	 *
	 * @param int   $folder_id     BORIS folder term ID, or zero for no assignment.
	 * @param int[] $attachment_ids Image attachment IDs.
	 * @throws \RuntimeException When the folder or assignment is invalid.
	 */
	public function assign( int $folder_id, array $attachment_ids ): void {
		if ( empty( $attachment_ids ) ) {
			return;
		}
		if ( 0 < $folder_id && ! term_exists( $folder_id, self::TAXONOMY ) ) {
			throw new \RuntimeException( __( 'The selected BORIS photo folder no longer exists.', 'boris-stock-inventory-management' ) );
		}

		foreach ( array_values( array_unique( array_map( 'absint', $attachment_ids ) ) ) as $attachment_id ) {
			if ( 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
				throw new \RuntimeException( __( 'One of the selected media items is not a valid image attachment.', 'boris-stock-inventory-management' ) );
			}
			$result = wp_set_object_terms( $attachment_id, 0 < $folder_id ? array( $folder_id ) : array(), self::TAXONOMY, false );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}
		}
	}

	/**
	 * Delete one empty-or-media-only folder without deleting attachments.
	 *
	 * Child folders must be removed first so WordPress never silently reparents a
	 * subtree. Deleting the term removes only its logical relationships; ordinary
	 * attachment posts and upload files remain untouched and become unorganized.
	 *
	 * @param int $folder_id Folder term ID.
	 * @throws \RuntimeException When deletion is unsafe or fails.
	 */
	public function delete_folder( int $folder_id ): void {
		if ( 1 > $folder_id || ! term_exists( $folder_id, self::TAXONOMY ) ) {
			throw new \RuntimeException( __( 'The selected BORIS photo folder no longer exists.', 'boris-stock-inventory-management' ) );
		}
		$children = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'parent'     => $folder_id,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( is_wp_error( $children ) ) {
			throw new \RuntimeException( $children->get_error_message() );
		}
		if ( ! empty( $children ) ) {
			throw new \RuntimeException( __( 'Delete the subfolders first. No folders or media were changed.', 'boris-stock-inventory-management' ) );
		}

		$deleted = wp_delete_term( $folder_id, self::TAXONOMY );
		if ( is_wp_error( $deleted ) ) {
			throw new \RuntimeException( $deleted->get_error_message() );
		}
		if ( false === $deleted ) {
			throw new \RuntimeException( __( 'The photo folder could not be deleted.', 'boris-stock-inventory-management' ) );
		}
	}

	/**
	 * Return flat, parent-linked data for the accessible media-folder tree.
	 *
	 * @return array<int,array{id:int,parent:int,name:string,label:string,count:int}>
	 */
	public function folder_tree(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$tree = array();
		foreach ( $terms as $term ) {
			$tree[] = array(
				'id'     => (int) $term->term_id,
				'parent' => (int) $term->parent,
				'name'   => (string) $term->name,
				'label'  => $this->folder_path( (int) $term->term_id ),
				'count'  => (int) $term->count,
			);
		}

		return $tree;
	}

	/**
	 * Validate a possible future folder move without allowing hierarchy cycles.
	 *
	 * @param int $folder_id Folder being moved.
	 * @param int $parent_id Proposed parent, or zero for the root.
	 * @throws \RuntimeException When the proposed parent creates a cycle.
	 */
	public function assert_safe_parent( int $folder_id, int $parent_id ): void {
		if ( 0 === $parent_id ) {
			return;
		}
		if ( $folder_id === $parent_id ) {
			throw new \RuntimeException( __( 'A folder cannot be placed inside itself.', 'boris-stock-inventory-management' ) );
		}

		$ancestors = get_ancestors( $parent_id, self::TAXONOMY, 'taxonomy' );
		if ( in_array( $folder_id, array_map( 'absint', $ancestors ), true ) ) {
			throw new \RuntimeException( __( 'A folder cannot be placed inside one of its own subfolders.', 'boris-stock-inventory-management' ) );
		}
	}

	/**
	 * Build a readable root-to-leaf label for one folder.
	 *
	 * @param int $folder_id Folder term ID.
	 */
	private function folder_path( int $folder_id ): string {
		$term = get_term( $folder_id, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}
		$names = array();
		foreach ( array_reverse( get_ancestors( $folder_id, self::TAXONOMY, 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, self::TAXONOMY );
			if ( $ancestor && ! is_wp_error( $ancestor ) ) {
				$names[] = (string) $ancestor->name;
			}
		}
		$names[] = (string) $term->name;

		return implode( ' / ', $names );
	}

	/**
	 * Get all folders in a stable parent-before-child order.
	 *
	 * @return array<int,array{id:int,label:string,depth:int}>
	 */
	public function folder_choices(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$children = array();
		foreach ( $terms as $term ) {
			$children[ (int) $term->parent ][] = $term;
		}
		$choices = array();
		$walk    = static function ( int $parent_id, int $depth ) use ( &$walk, &$choices, $children ): void {
			foreach ( $children[ $parent_id ] ?? array() as $term ) {
				$choices[] = array(
					'id'    => (int) $term->term_id,
					'label' => str_repeat( '— ', $depth ) . $term->name,
					'depth' => $depth,
				);
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );

		return $choices;
	}
}
