<?php
/**
 * Safe bounded WooCommerce product search for admin screens.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProductSearchService {
	private const DEFAULT_LIMIT = 20;

	private ?Repository $repository;

	public function __construct( ?Repository $repository = null ) {
		$this->repository = $repository;
	}

	/**
	 * Search by ID, SKU, and bounded WooCommerce title query.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $query, int $limit = self::DEFAULT_LIMIT ): array {
		if ( ! Plugin::is_woocommerce_active() || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$query    = trim( sanitize_text_field( $query ) );
		$limit    = max( 1, min( self::DEFAULT_LIMIT, absint( $limit ) ) );
		$products = array();

		if ( '' === $query ) {
			return array();
		}

		if ( is_numeric( $query ) ) {
			$this->add_product_to_results( absint( $query ), $products );
		}

		$this->search_by_sku( $query, $products );
		$this->search_by_identifier_meta( $query, $products, $limit );
		$this->search_by_title( $query, $products, $limit );

		return array_slice( array_map( array( $this, 'product_to_display_array' ), array_values( $products ) ), 0, $limit );
	}

	/**
	 * @param array<int, object> $products Products keyed by product ID.
	 */
	private function search_by_identifier_meta( string $query, array &$products, int $limit ): void {
		if ( ! function_exists( 'wc_get_products' ) || count( $products ) >= $limit ) {
			return;
		}

		$meta_keys = array( '_global_unique_id', '_alg_ean', '_wpm_gtin_code', 'ean', 'gtin', 'barcode' );
		$queries   = array( 'relation' => 'OR' );
		foreach ( $meta_keys as $key ) {
			$queries[] = array(
				'key'     => $key,
				'value'   => $query,
				'compare' => '=',
			);
		}

		try {
			$matches = wc_get_products(
				array(
					'limit'      => $limit - count( $products ),
					'status'     => array( 'publish', 'private', 'draft' ),
					'meta_query' => $queries,
				)
			);
		} catch ( \Throwable $throwable ) {
			if ( $this->repository ) {
				$this->repository->write_log(
					'error',
					'product_identifier_search_failed',
					__( 'WooCommerce product identifier search failed.', 'lilleprinsen-price-monitor' ),
					array( 'error' => $throwable->getMessage() )
				);
			}
			return;
		}

		if ( ! is_array( $matches ) ) {
			return;
		}

		foreach ( $matches as $product ) {
			if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
				$this->add_product_object_to_results( $product, $products );
			}
		}
	}

	/**
	 * @param array<int, object> $products Products keyed by product ID.
	 */
	private function search_by_sku( string $query, array &$products ): void {
		if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return;
		}

		$product_id = wc_get_product_id_by_sku( $query );

		if ( $product_id ) {
			$this->add_product_to_results( (int) $product_id, $products );
		}
	}

	/**
	 * @param array<int, object> $products Products keyed by product ID.
	 */
	private function search_by_title( string $query, array &$products, int $limit ): void {
		if ( ! function_exists( 'wc_get_products' ) || count( $products ) >= $limit ) {
			return;
		}

		try {
			$matches = wc_get_products(
				array(
					'limit'   => $limit - count( $products ),
					'status'  => array( 'publish', 'private', 'draft' ),
					'orderby' => 'title',
					'order'   => 'ASC',
					's'       => $query,
				)
			);
		} catch ( \Throwable $throwable ) {
			if ( $this->repository ) {
				$this->repository->write_log(
					'error',
					'product_search_failed',
					__( 'WooCommerce product search failed.', 'lilleprinsen-price-monitor' ),
					array( 'error' => $throwable->getMessage() )
				);
			}
			return;
		}

		if ( ! is_array( $matches ) ) {
			return;
		}

		foreach ( $matches as $product ) {
			if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
				$this->add_product_object_to_results( $product, $products );
			}
		}
	}

	/**
	 * @param array<int, object> $products Products keyed by product ID.
	 */
	private function add_product_to_results( int $product_id, array &$products ): void {
		$product = wc_get_product( $product_id );

		if ( is_object( $product ) ) {
			$this->add_product_object_to_results( $product, $products );
		}
	}

	/**
	 * @param array<int, object> $products Products keyed by product ID.
	 */
	private function add_product_object_to_results( object $product, array &$products ): void {
		$product_id = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;

		if ( $product_id > 0 && ! isset( $products[ $product_id ] ) ) {
			$products[ $product_id ] = $product;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function product_to_display_array( object $product ): array {
		$product_id = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		$sku        = method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';

		return array(
			'id'           => $product_id,
			'name'         => method_exists( $product, 'get_name' ) ? (string) $product->get_name() : __( 'Untitled product', 'lilleprinsen-price-monitor' ),
			'sku'          => '' === $sku ? '—' : $sku,
			'price_html'   => $this->get_price_html( $product ),
			'stock_status' => $this->get_stock_status( $product ),
			'thumbnail'    => $this->get_thumbnail_html( $product ),
		);
	}

	private function get_price_html( object $product ): string {
		if ( method_exists( $product, 'get_price_html' ) ) {
			$price_html = (string) $product->get_price_html();

			if ( '' !== $price_html ) {
				return $price_html;
			}
		}

		if ( method_exists( $product, 'get_price' ) && function_exists( 'wc_price' ) ) {
			$price = $product->get_price();

			return '' !== (string) $price ? wc_price( $price ) : '—';
		}

		return '—';
	}

	private function get_stock_status( object $product ): string {
		$status = method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : '';

		return '' === $status ? __( 'Unknown', 'lilleprinsen-price-monitor' ) : $status;
	}

	private function get_thumbnail_html( object $product ): string {
		if ( ! method_exists( $product, 'get_image_id' ) ) {
			return '<span class="lpm-thumb-placeholder"></span>';
		}

		$image_id = (int) $product->get_image_id();

		if ( $image_id <= 0 ) {
			return '<span class="lpm-thumb-placeholder"></span>';
		}

		$image = wp_get_attachment_image( $image_id, array( 48, 48 ), false, array( 'class' => 'lpm-product-thumb' ) );

		return is_string( $image ) && '' !== $image ? $image : '<span class="lpm-thumb-placeholder"></span>';
	}
}
