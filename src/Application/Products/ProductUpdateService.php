<?php
/**
 * Safe non-stock WooCommerce product updates.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Products;

defined( 'ABSPATH' ) || exit;

final class ProductUpdateService {
	/**
	 * Update editable catalogue fields without touching stock or warehouse allocations.
	 *
	 * @param int                 $product_id WooCommerce product ID.
	 * @param array<string,mixed> $data       Validated request data.
	 * @return int BORIS product mapping ID.
	 */
	public function update( int $product_id, array $data ): int {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'simple' ) ) {
			throw new \RuntimeException( __( 'Only an existing simple WooCommerce product can be edited here.', 'boris-stock-inventory-management' ) );
		}
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$sku  = wc_clean( (string) ( $data['sku'] ?? '' ) );
		if ( '' === $name || '' === $sku ) {
			throw new \RuntimeException( __( 'Product name and SKU are required.', 'boris-stock-inventory-management' ) );
		}
		$regular = $this->decimal( (string) ( $data['regular_price'] ?? '' ), __( 'Regular price', 'boris-stock-inventory-management' ) );
		$sale    = $this->decimal( (string) ( $data['sale_price'] ?? '' ), __( 'Sale price', 'boris-stock-inventory-management' ) );
		$cost    = $this->decimal( (string) ( $data['cost_price'] ?? '' ), __( 'Cost price', 'boris-stock-inventory-management' ) );
		if ( '' !== $regular && '' !== $sale && (float) $sale > (float) $regular ) {
			throw new \RuntimeException( __( 'Sale price cannot be higher than the regular price.', 'boris-stock-inventory-management' ) );
		}
		$gtin = trim( (string) ( $data['global_unique_id'] ?? '' ) );
		if ( '' !== $gtin && ! preg_match( '/^[0-9-]*[0-9Xx]$/D', $gtin ) ) {
			throw new \RuntimeException( __( 'Enter a valid GTIN, EAN, UPC or ISBN.', 'boris-stock-inventory-management' ) );
		}
		$sku_owner = (int) wc_get_product_id_by_sku( $sku );
		if ( 0 < $sku_owner && $sku_owner !== $product_id ) {
			throw new \RuntimeException( __( 'This SKU is already used by another product.', 'boris-stock-inventory-management' ) );
		}
		if ( '' !== $gtin && function_exists( 'wc_product_has_global_unique_id' ) && ! wc_product_has_global_unique_id( $product_id, $gtin ) ) {
			throw new \RuntimeException( __( 'This GTIN, EAN, UPC or ISBN is already used by another product.', 'boris-stock-inventory-management' ) );
		}
		$categories = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $data['category_ids'] ?? array() ) ) ) ) );
		foreach ( $categories as $category_id ) {
			if ( ! term_exists( $category_id, 'product_cat' ) ) {
				throw new \RuntimeException( __( 'One of the selected product categories no longer exists.', 'boris-stock-inventory-management' ) );
			}
		}
		$image_id   = absint( $data['image_id'] ?? 0 );
		$gallery_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $data['gallery_image_ids'] ?? array() ) ) ) ) );
		foreach ( array_merge( $image_id ? array( $image_id ) : array(), $gallery_ids ) as $attachment_id ) {
			if ( 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
				throw new \RuntimeException( __( 'One of the selected media items is not a valid image attachment.', 'boris-stock-inventory-management' ) );
			}
		}
		$gallery_ids = array_values( array_diff( $gallery_ids, array( $image_id ) ) );

		$stock_before = $product->get_stock_quantity();
		$product->set_name( $name );
		$product->set_sku( $sku );
		if ( method_exists( $product, 'set_global_unique_id' ) ) {
			$product->set_global_unique_id( $gtin );
		}
		$product->set_regular_price( $regular );
		$product->set_sale_price( $sale );
		if ( method_exists( $product, 'set_cogs_value' ) && null !== $product->get_cogs_value() ) {
			$product->set_cogs_value( '' === $cost ? null : (float) $cost );
		}
		$product->set_short_description( wp_kses_post( (string) ( $data['short_description'] ?? '' ) ) );
		$product->set_description( wp_kses_post( (string) ( $data['description'] ?? '' ) ) );
		$product->set_category_ids( $categories );
		$product->set_image_id( $image_id );
		$product->set_gallery_image_ids( $gallery_ids );
		$product->save();

		$fresh = wc_get_product( $product_id );
		if ( ! $fresh || $fresh->get_stock_quantity() !== $stock_before ) {
			throw new \RuntimeException( __( 'The product update was stopped because its stock quantity changed unexpectedly.', 'boris-stock-inventory-management' ) );
		}
		$mapping_id = ( new ProductService() )->synchronize( $product_id );
		if ( 0 === $mapping_id ) {
			throw new \RuntimeException( __( 'The updated product no longer meets the BORIS Stock product rules.', 'boris-stock-inventory-management' ) );
		}
		return $mapping_id;
	}

	private function decimal( string $value, string $label ): string {
		$value = trim( str_replace( ',', '.', $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( ! is_numeric( $value ) || (float) $value < 0 ) {
			throw new \RuntimeException( sprintf( /* translators: %s is a field label. */ __( '%s must be a valid non-negative amount.', 'boris-stock-inventory-management' ), $label ) );
		}
		return wc_format_decimal( $value );
	}
}
