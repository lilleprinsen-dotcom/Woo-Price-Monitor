<?php
/**
 * Product identifier lookup and normalization.
 *
 * @package LillePrinsen\PriceMonitor\Service
 */

namespace LillePrinsen\PriceMonitor\Service;

use LillePrinsen\PriceMonitor\Settings\DiscoverySettings;
use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolves SKU, GTIN, MPN and brand for products and variations.
 */
class ProductIdentifierService {
    private DiscoverySettings $settings;

    /**
     * Constructor.
     */
    public function __construct( DiscoverySettings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Resolve identifiers for a product ID or variation ID.
     *
     * @param int $product_id Product ID.
     * @return array<string,string>
     */
    public function get_for_product_id( int $product_id ): array {
        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

        if ( ! $product instanceof WC_Product ) {
            return $this->empty_identifiers();
        }

        return $this->get_for_product( $product );
    }

    /**
     * Resolve identifiers for a WooCommerce product.
     *
     * Variation-level values are preferred, then parent values.
     *
     * @param WC_Product $product Product.
     * @return array<string,string>
     */
    public function get_for_product( WC_Product $product ): array {
        $parent = null;
        if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
            $parent = wc_get_product( $product->get_parent_id() );
        }

        $sku   = $this->first_value( array( $product->get_sku(), $parent instanceof WC_Product ? $parent->get_sku() : '' ) );
        $gtin  = $this->resolve_gtin( $product, $parent );
        $mpn   = $this->resolve_meta_list( $product, $parent, $this->settings->get_meta_key_list( 'discovery_mpn_meta_keys' ) );
        $brand = $this->resolve_brand( $product, $parent );

        return array(
            'sku'             => $sku,
            'gtin'            => $gtin,
            'mpn'             => $mpn,
            'brand'           => $brand,
            'normalized_sku'  => $this->normalize_identifier( $sku ),
            'normalized_gtin' => $this->normalize_gtin( $gtin ),
            'normalized_mpn'  => $this->normalize_identifier( $mpn ),
        );
    }

    /**
     * Normalize SKU/MPN-like identifiers.
     */
    public function normalize_identifier( string $value ): string {
        $value = strtoupper( html_entity_decode( trim( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $value = preg_replace( '/[^A-Z0-9]/', '', $value );

        return is_string( $value ) ? $value : '';
    }

    /**
     * Normalize GTIN/EAN-like identifiers.
     */
    public function normalize_gtin( string $value ): string {
        $value = preg_replace( '/\D+/', '', html_entity_decode( trim( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

        return is_string( $value ) ? $value : '';
    }

    /**
     * Empty identifier structure.
     *
     * @return array<string,string>
     */
    private function empty_identifiers(): array {
        return array(
            'sku'             => '',
            'gtin'            => '',
            'mpn'             => '',
            'brand'           => '',
            'normalized_sku'  => '',
            'normalized_gtin' => '',
            'normalized_mpn'  => '',
        );
    }

    /**
     * Resolve GTIN from WooCommerce global unique ID or configured meta keys.
     */
    private function resolve_gtin( WC_Product $product, ?WC_Product $parent ): string {
        $values = array();

        if ( method_exists( $product, 'get_global_unique_id' ) ) {
            $values[] = (string) $product->get_global_unique_id();
        }
        if ( $parent instanceof WC_Product && method_exists( $parent, 'get_global_unique_id' ) ) {
            $values[] = (string) $parent->get_global_unique_id();
        }

        $keys = $this->settings->get_meta_key_list( 'discovery_identifier_meta_keys' );
        foreach ( array( $product, $parent ) as $candidate ) {
            if ( ! $candidate instanceof WC_Product ) {
                continue;
            }
            foreach ( $keys as $key ) {
                $values[] = (string) $candidate->get_meta( $key, true );
            }
        }

        return $this->first_value( $values );
    }

    /**
     * Resolve a value from configured meta keys.
     *
     * @param array<int,string> $keys Meta keys.
     */
    private function resolve_meta_list( WC_Product $product, ?WC_Product $parent, array $keys ): string {
        $values = array();
        foreach ( array( $product, $parent ) as $candidate ) {
            if ( ! $candidate instanceof WC_Product ) {
                continue;
            }
            foreach ( $keys as $key ) {
                $values[] = (string) $candidate->get_meta( $key, true );
            }
        }

        return $this->first_value( $values );
    }

    /**
     * Resolve brand from configured meta keys or attributes.
     */
    private function resolve_brand( WC_Product $product, ?WC_Product $parent ): string {
        $brand = $this->resolve_meta_list( $product, $parent, $this->settings->get_meta_key_list( 'discovery_brand_meta_keys' ) );
        if ( '' !== $brand ) {
            return $brand;
        }

        foreach ( array( 'pa_brand', 'brand' ) as $attribute ) {
            $value = $product->get_attribute( $attribute );
            if ( '' !== trim( $value ) ) {
                return trim( wp_strip_all_tags( $value ) );
            }
            if ( $parent instanceof WC_Product ) {
                $value = $parent->get_attribute( $attribute );
                if ( '' !== trim( $value ) ) {
                    return trim( wp_strip_all_tags( $value ) );
                }
            }
        }

        return '';
    }

    /**
     * Return first non-empty scalar value.
     *
     * @param array<int,mixed> $values Values.
     */
    private function first_value( array $values ): string {
        foreach ( $values as $value ) {
            if ( is_array( $value ) || is_object( $value ) ) {
                continue;
            }
            $value = trim( wp_strip_all_tags( (string) $value ) );
            if ( '' !== $value ) {
                return $value;
            }
        }

        return '';
    }
}
