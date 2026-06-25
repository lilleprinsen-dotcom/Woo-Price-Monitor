<?php
/**
 * Discovery settings stored with the plugin settings option.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles safe discovery-specific settings.
 */
class DiscoverySettings {
	/**
	 * Constructor keeps parity with other settings services.
	 */
	public function __construct( Settings $settings ) {
		unset( $settings );
	}

	/**
	 * Default values.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return array(
			'discovery_enabled'                   => 0,
			'discovery_sku_scan_enabled'          => 1,
			'discovery_sku_crawl_enabled'         => 1,
			'discovery_gtin_source'               => 'global_unique_id',
			'discovery_gtin_meta_key'             => '',
			'discovery_max_product_pages_per_run' => 50,
			'discovery_max_listing_pages_per_run' => 5,
			'discovery_max_requests_per_batch'    => 25,
			'discovery_max_sku_searches_per_run'  => 5,
			'discovery_search_urls_per_sku'       => 2,
			'discovery_max_crawl_pages_per_run'   => 8,
			'discovery_request_delay_seconds'     => 3,
			'discovery_low_traffic_hour'          => 2,
			'discovery_auto_pause_failures'       => 5,
			'discovery_same_domain_only'          => 1,
			'discovery_identifier_meta_keys'      => '_global_unique_id,_alg_ean,_wpm_gtin_code,ean,gtin,barcode',
			'discovery_mpn_meta_keys'             => 'mpn,_mpn,manufacturer_sku',
			'discovery_brand_meta_keys'           => '_brand,brand,pa_brand',
			'discovery_request_timeout'           => 12,
			'discovery_allow_ports'               => '80,443',
			'discovery_include_url_patterns'      => '',
			'discovery_exclude_url_patterns'      => 'cart,checkout,account,login,search,filter,wp-admin,add-to-cart',
			'discovery_product_url_patterns'      => 'product,produkt,p,varer,vare',
			'discovery_sku_search_url_templates'  => '?s={sku},search?q={sku},search?query={sku},catalogsearch/result/?q={sku}',
		);
	}

	/**
	 * Human labels for EAN/GTIN source options.
	 *
	 * @return array<string,string>
	 */
	public function gtin_source_options(): array {
		return array(
			'sku'              => __( 'Product SKU', 'lilleprinsen-price-monitor' ),
			'global_unique_id' => __( 'Built-in product GTIN/global unique ID field, if available', 'lilleprinsen-price-monitor' ),
			'custom_meta'      => __( 'Custom field / product meta key', 'lilleprinsen-price-monitor' ),
			'none'             => __( 'Do not use EAN/GTIN', 'lilleprinsen-price-monitor' ),
		);
	}

	/**
	 * Get discovery settings from the shared plugin option.
	 *
	 * @return array<string,mixed>
	 */
	public function get_all(): array {
		$stored = get_option( Settings::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$raw = array_merge( $this->defaults(), array_intersect_key( $stored, $this->defaults() ) );

		return $this->sanitize( $raw );
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( string $key ) {
		$settings = $this->get_all();

		return $settings[ $key ] ?? null;
	}

	/**
	 * Update discovery settings in the shared plugin option.
	 *
	 * @param array<string,mixed> $input Raw input.
	 */
	public function update( array $input ): void {
		$existing = get_option( Settings::OPTION_NAME, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$sanitized = $this->sanitize( $input );
		update_option( Settings::OPTION_NAME, array_merge( $existing, $sanitized ), false );
	}

	/**
	 * Sanitize discovery settings.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize( array $input ): array {
		$defaults = $this->defaults();

		return array(
			'discovery_enabled'                   => empty( $input['discovery_enabled'] ) ? 0 : 1,
			'discovery_sku_scan_enabled'          => empty( $input['discovery_sku_scan_enabled'] ) ? 0 : 1,
			'discovery_sku_crawl_enabled'         => empty( $input['discovery_sku_crawl_enabled'] ) ? 0 : 1,
			'discovery_gtin_source'               => $this->sanitize_choice( $input['discovery_gtin_source'] ?? $defaults['discovery_gtin_source'], array_keys( $this->gtin_source_options() ), 'global_unique_id' ),
			'discovery_gtin_meta_key'             => sanitize_key( (string) ( $input['discovery_gtin_meta_key'] ?? '' ) ),
			'discovery_max_product_pages_per_run' => $this->sanitize_int( $input['discovery_max_product_pages_per_run'] ?? $defaults['discovery_max_product_pages_per_run'], 1, 500 ),
			'discovery_max_listing_pages_per_run' => $this->sanitize_int( $input['discovery_max_listing_pages_per_run'] ?? $defaults['discovery_max_listing_pages_per_run'], 0, 50 ),
			'discovery_max_requests_per_batch'    => $this->sanitize_int( $input['discovery_max_requests_per_batch'] ?? $defaults['discovery_max_requests_per_batch'], 1, 100 ),
			'discovery_max_sku_searches_per_run'  => $this->sanitize_int( $input['discovery_max_sku_searches_per_run'] ?? $defaults['discovery_max_sku_searches_per_run'], 1, 200 ),
			'discovery_search_urls_per_sku'       => $this->sanitize_int( $input['discovery_search_urls_per_sku'] ?? $defaults['discovery_search_urls_per_sku'], 1, 10 ),
			'discovery_max_crawl_pages_per_run'   => $this->sanitize_int( $input['discovery_max_crawl_pages_per_run'] ?? $defaults['discovery_max_crawl_pages_per_run'], 1, 50 ),
			'discovery_request_delay_seconds'     => $this->sanitize_int( $input['discovery_request_delay_seconds'] ?? $defaults['discovery_request_delay_seconds'], 0, 30 ),
			'discovery_low_traffic_hour'          => $this->sanitize_int( $input['discovery_low_traffic_hour'] ?? $defaults['discovery_low_traffic_hour'], 0, 23 ),
			'discovery_auto_pause_failures'       => $this->sanitize_int( $input['discovery_auto_pause_failures'] ?? $defaults['discovery_auto_pause_failures'], 1, 50 ),
			'discovery_same_domain_only'          => empty( $input['discovery_same_domain_only'] ) ? 0 : 1,
			'discovery_identifier_meta_keys'      => $this->sanitize_meta_key_list( $input['discovery_identifier_meta_keys'] ?? $defaults['discovery_identifier_meta_keys'] ),
			'discovery_mpn_meta_keys'             => $this->sanitize_meta_key_list( $input['discovery_mpn_meta_keys'] ?? $defaults['discovery_mpn_meta_keys'] ),
			'discovery_brand_meta_keys'           => $this->sanitize_meta_key_list( $input['discovery_brand_meta_keys'] ?? $defaults['discovery_brand_meta_keys'] ),
			'discovery_request_timeout'           => $this->sanitize_int( $input['discovery_request_timeout'] ?? $defaults['discovery_request_timeout'], 4, 30 ),
			'discovery_allow_ports'               => $this->sanitize_port_list( $input['discovery_allow_ports'] ?? $defaults['discovery_allow_ports'] ),
			'discovery_include_url_patterns'      => $this->sanitize_pattern_list( $input['discovery_include_url_patterns'] ?? $defaults['discovery_include_url_patterns'] ),
			'discovery_exclude_url_patterns'      => $this->sanitize_pattern_list( $input['discovery_exclude_url_patterns'] ?? $defaults['discovery_exclude_url_patterns'] ),
			'discovery_product_url_patterns'      => $this->sanitize_pattern_list( $input['discovery_product_url_patterns'] ?? $defaults['discovery_product_url_patterns'] ),
			'discovery_sku_search_url_templates'  => $this->sanitize_search_template_list( $input['discovery_sku_search_url_templates'] ?? $defaults['discovery_sku_search_url_templates'] ),
		);
	}

	/**
	 * Parse a comma-separated meta key setting.
	 *
	 * @param string|array<int,string> $value Raw value.
	 */
	public function sanitize_meta_key_list( $value ): string {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		$keys  = array();

		foreach ( $items as $item ) {
			$key = sanitize_key( trim( (string) $item ) );
			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		return implode( ',', array_values( array_unique( array_slice( $keys, 0, 25 ) ) ) );
	}

	/**
	 * Return a comma-separated setting as an array.
	 *
	 * @param string $key Setting key.
	 * @return array<int,string>
	 */
	public function get_list( string $key ): array {
		$value = (string) $this->get( $key );

		if ( '' === $value ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}

	/**
	 * Return a meta key list as an array.
	 *
	 * @param string $key Setting key.
	 * @return array<int,string>
	 */
	public function get_meta_key_list( string $key ): array {
		return $this->get_list( $key );
	}

	/**
	 * Sanitize integer range.
	 *
	 * @param mixed $value Raw value.
	 */
	private function sanitize_int( $value, int $min, int $max ): int {
		$number = absint( $value );

		return max( $min, min( $max, $number ) );
	}

	/**
	 * Sanitize a controlled choice.
	 *
	 * @param mixed             $value Raw value.
	 * @param array<int,string> $allowed Allowed values.
	 */
	private function sanitize_choice( $value, array $allowed, string $fallback ): string {
		$value = sanitize_key( (string) $value );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Sanitize a URL pattern list without exposing regex by default.
	 *
	 * @param mixed $value Raw value.
	 */
	private function sanitize_pattern_list( $value ): string {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		$out   = array();

		foreach ( $items as $item ) {
			$item = trim( sanitize_text_field( (string) $item ) );
			if ( '' !== $item ) {
				$out[] = substr( $item, 0, 120 );
			}
		}

		return implode( ',', array_values( array_unique( array_slice( $out, 0, 25 ) ) ) );
	}

	/**
	 * Sanitize search URL templates.
	 *
	 * @param mixed $value Raw value.
	 */
	private function sanitize_search_template_list( $value ): string {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		$out   = array();

		foreach ( $items as $item ) {
			$item = trim( sanitize_text_field( (string) $item ) );
			$has_placeholder = false !== strpos( $item, '{sku}' ) || false !== strpos( $item, '{query}' ) || false !== strpos( $item, '%s' );
			if ( '' === $item || ! $has_placeholder ) {
				continue;
			}
			$out[] = substr( $item, 0, 180 );
		}

		return implode( ',', array_values( array_unique( array_slice( $out, 0, 25 ) ) ) );
	}

	/**
	 * Sanitize allowed HTTP port list.
	 *
	 * @param mixed $value Raw value.
	 */
	private function sanitize_port_list( $value ): string {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		$ports = array();

		foreach ( $items as $item ) {
			$port = absint( $item );
			if ( $port > 0 && $port <= 65535 ) {
				$ports[] = (string) $port;
			}
		}

		$ports = array_values( array_unique( $ports ) );

		return implode( ',', $ports ?: array( '80', '443' ) );
	}
}
