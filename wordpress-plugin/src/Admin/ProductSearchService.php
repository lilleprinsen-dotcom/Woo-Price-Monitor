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
	private const TITLE_CANDIDATE_LIMIT = 80;

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
		if ( $this->looks_like_identifier_query( $query ) ) {
			$this->search_by_identifier_meta( $query, $products, $limit );
		}
		$this->search_by_title( $query, $products, $limit );

		return array_slice( array_map( array( $this, 'product_to_display_array' ), array_values( $products ) ), 0, $limit );
	}

	private function looks_like_identifier_query( string $query ): bool {
		$query = trim( $query );
		if ( '' === $query || preg_match( '/\s/', $query ) ) {
			return false;
		}

		if ( preg_match( '/^\d{6,14}$/', $query ) ) {
			return true;
		}

		return (bool) preg_match( '/^(?=.*\d)[A-Za-z0-9][A-Za-z0-9_.-]{2,}$/', $query );
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
				if ( $this->product_has_identifier( $product, $query, $meta_keys ) ) {
					$this->add_product_object_to_results( $product, $products );
				}
			}
		}
	}

	/**
	 * @param array<int,string> $meta_keys Identifier meta keys to verify.
	 */
	private function product_has_identifier( object $product, string $query, array $meta_keys ): bool {
		$query = trim( $query );
		if ( '' === $query ) {
			return false;
		}

		if ( method_exists( $product, 'get_sku' ) && 0 === strcasecmp( trim( (string) $product->get_sku() ), $query ) ) {
			return true;
		}

		if ( method_exists( $product, 'get_meta' ) ) {
			foreach ( $meta_keys as $key ) {
				$value = $product->get_meta( $key, true );
				if ( is_scalar( $value ) && 0 === strcasecmp( trim( (string) $value ), $query ) ) {
					return true;
				}
			}
		}

		return false;
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
					'limit'   => max( $limit - count( $products ), min( self::TITLE_CANDIDATE_LIMIT, $limit * 4 ) ),
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

		$ranked = array();
		foreach ( $matches as $product ) {
			if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
				$score = $this->score_title_match( $query, $product );
				if ( $score > 0 ) {
					$ranked[] = array(
						'product' => $product,
						'score'   => $score,
					);
				}
			}
		}

		usort(
			$ranked,
			static function ( array $a, array $b ): int {
				return (int) $b['score'] <=> (int) $a['score'];
			}
		);

		foreach ( $ranked as $row ) {
			if ( count( $products ) >= $limit ) {
				return;
			}
			$this->add_product_object_to_results( $row['product'], $products );
		}
	}

	private function score_title_match( string $query, object $product ): int {
		$title = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';
		if ( '' === trim( $title ) ) {
			return 0;
		}

		$query_tokens = $this->search_tokens( $query );
		$title_tokens = $this->search_tokens( $title );
		if ( empty( $query_tokens ) || empty( $title_tokens ) ) {
			return 0;
		}

		$matches      = 0;
		$prefix_match = false;
		$score        = 0;
		foreach ( $query_tokens as $index => $query_token ) {
			if ( $this->token_matches_any( $query_token, $title_tokens ) ) {
				++$matches;
				$score += 10;
				if ( 0 === $index ) {
					$prefix_match = true;
					$score       += 25;
				}
			}
		}

		$query_phrase = implode( ' ', $query_tokens );
		$title_phrase = implode( ' ', $title_tokens );
		if ( str_contains( $title_phrase, $query_phrase ) ) {
			$score += 100;
		}

		$token_count      = count( $query_tokens );
		$minimum_matches  = $token_count >= 4 ? 3 : min( 2, $token_count );
		$brand_like_match = $prefix_match && $matches >= 2;
		if ( $matches < $minimum_matches && ! $brand_like_match ) {
			return 0;
		}

		return $score + $matches;
	}

	/**
	 * @return array<int, string>
	 */
	private function search_tokens( string $value ): array {
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = preg_replace( '/([a-z])([A-Z])/', '$1 $2', $value );
		$value = is_string( $value ) ? strtolower( $value ) : '';
		$value = strtr(
			$value,
			array(
				'æ' => 'ae',
				'ø' => 'o',
				'å' => 'a',
				'é' => 'e',
				'è' => 'e',
				'ö' => 'o',
				'ü' => 'u',
			)
		);
		$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
		$raw   = preg_split( '/\s+/', trim( is_string( $value ) ? $value : '' ) );
		$tokens = array();
		foreach ( is_array( $raw ) ? $raw : array() as $token ) {
			$token = trim( (string) $token );
			if ( strlen( $token ) < 2 && ! ctype_digit( $token ) ) {
				continue;
			}
			$tokens[ $token ] = $token;
		}

		return array_values( $tokens );
	}

	/**
	 * @param array<int, string> $candidates Candidate title tokens.
	 */
	private function token_matches_any( string $needle, array $candidates ): bool {
		foreach ( $candidates as $candidate ) {
			if ( $needle === $candidate ) {
				return true;
			}
			if ( strlen( $needle ) >= 5 && ( str_starts_with( $candidate, $needle ) || str_starts_with( $needle, $candidate ) ) ) {
				return true;
			}
		}

		return false;
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
