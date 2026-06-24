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
    private Settings $settings;

    /**
     * Constructor.
     */
    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Default values.
     *
     * @return array<string,mixed>
     */
    public function defaults(): array {
        return array(
            'discovery_enabled'                   => 0,
            'discovery_max_product_pages_per_run' => 50,
            'discovery_max_listing_pages_per_run' => 5,
            'discovery_request_delay_seconds'     => 3,
            'discovery_low_traffic_hour'          => 2,
            'discovery_auto_pause_failures'       => 5,
            'discovery_same_domain_only'          => 1,
            'discovery_identifier_meta_keys'      => '_global_unique_id,_alg_ean,_wpm_gtin_code,ean,gtin,barcode',
            'discovery_mpn_meta_keys'             => 'mpn,_mpn,manufacturer_sku',
            'discovery_brand_meta_keys'           => '_brand,brand,pa_brand',
            'discovery_request_timeout'           => 12,
        );
    }

    /**
     * Get discovery settings from the shared plugin option.
     *
     * The main Settings object intentionally drops unknown keys, so discovery
     * settings read the raw option and sanitize their own keys.
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
            'discovery_max_product_pages_per_run' => $this->sanitize_int( $input['discovery_max_product_pages_per_run'] ?? $defaults['discovery_max_product_pages_per_run'], 1, 500 ),
            'discovery_max_listing_pages_per_run' => $this->sanitize_int( $input['discovery_max_listing_pages_per_run'] ?? $defaults['discovery_max_listing_pages_per_run'], 0, 50 ),
            'discovery_request_delay_seconds'     => $this->sanitize_int( $input['discovery_request_delay_seconds'] ?? $defaults['discovery_request_delay_seconds'], 0, 30 ),
            'discovery_low_traffic_hour'          => $this->sanitize_int( $input['discovery_low_traffic_hour'] ?? $defaults['discovery_low_traffic_hour'], 0, 23 ),
            'discovery_auto_pause_failures'       => $this->sanitize_int( $input['discovery_auto_pause_failures'] ?? $defaults['discovery_auto_pause_failures'], 1, 50 ),
            'discovery_same_domain_only'          => empty( $input['discovery_same_domain_only'] ) ? 0 : 1,
            'discovery_identifier_meta_keys'      => $this->sanitize_meta_key_list( $input['discovery_identifier_meta_keys'] ?? $defaults['discovery_identifier_meta_keys'] ),
            'discovery_mpn_meta_keys'             => $this->sanitize_meta_key_list( $input['discovery_mpn_meta_keys'] ?? $defaults['discovery_mpn_meta_keys'] ),
            'discovery_brand_meta_keys'           => $this->sanitize_meta_key_list( $input['discovery_brand_meta_keys'] ?? $defaults['discovery_brand_meta_keys'] ),
            'discovery_request_timeout'           => $this->sanitize_int( $input['discovery_request_timeout'] ?? $defaults['discovery_request_timeout'], 4, 30 ),
        );
    }

    /**
     * Parse a comma-separated meta key setting.
     *
     * @param string|array<int,string> $value Raw value.
     * @return string
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

        $keys = array_values( array_unique( array_slice( $keys, 0, 25 ) ) );

        return implode( ',', $keys );
    }

    /**
     * Return a meta key list as an array.
     *
     * @param string $key Setting key.
     * @return array<int,string>
     */
    public function get_meta_key_list( string $key ): array {
        $value = (string) $this->get( $key );

        if ( '' === $value ) {
            return array();
        }

        return array_filter( array_map( 'trim', explode( ',', $value ) ) );
    }

    /**
     * Sanitize integer range.
     *
     * @param mixed $value Raw value.
     * @param int   $min Minimum.
     * @param int   $max Maximum.
     */
    private function sanitize_int( $value, int $min, int $max ): int {
        $number = absint( $value );

        return max( $min, min( $max, $number ) );
    }
}
