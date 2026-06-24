<?php
/**
 * Competitor product page extraction.
 *
 * @package LillePrinsen\PriceMonitor\Service
 */

namespace LillePrinsen\PriceMonitor\Service;

use LillePrinsen\PriceMonitor\Settings\DiscoverySettings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Extracts plain product data from one competitor product page.
 */
class CompetitorProductExtractor {
    private DiscoveryUrlService $url_service;
    private DiscoverySettings $settings;

    /**
     * Constructor.
     */
    public function __construct( DiscoveryUrlService $url_service, DiscoverySettings $settings ) {
        $this->url_service = $url_service;
        $this->settings    = $settings;
    }

    /**
     * Fetch and extract a single product URL.
     *
     * @param string              $url Competitor URL.
     * @param array<string,mixed> $competitor Competitor profile.
     * @return array<string,mixed>
     */
    public function test_url( string $url, array $competitor = array() ): array {
        $url = $this->url_service->normalize( $url );

        if ( '' === $url || ! $this->url_service->is_safe_url( $url ) ) {
            return $this->failure( 'We could not read this product page.', 'The URL is not allowed for safety reasons.' );
        }

        $settings = $this->settings->get_all();
        $domain   = (string) ( $competitor['domain'] ?? $competitor['website_url'] ?? '' );
        if ( ! empty( $settings['discovery_same_domain_only'] ) && '' !== $domain && ! $this->url_service->matches_domain( $url, $domain ) ) {
            return $this->failure( 'We could not read this product page.', 'The URL does not match the competitor website.' );
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout'     => absint( $settings['discovery_request_timeout'] ?? 12 ),
                'redirection' => 3,
                'user-agent'  => $this->user_agent(),
                'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $this->failure( 'We could not read this product page.', $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) {
            return $this->failure( 'We could not read this product page.', 'HTTP status ' . $code );
        }

        $html = (string) wp_remote_retrieve_body( $response );
        if ( '' === trim( $html ) ) {
            return $this->failure( 'We could not read this product page.', 'The page was empty.' );
        }

        return $this->extract_html( $html, $url );
    }

    /**
     * Extract product fields from HTML.
     *
     * @return array<string,mixed>
     */
    public function extract_html( string $html, string $url ): array {
        $data = array(
            'success'             => true,
            'message'             => '',
            'confidence_status'   => 'Needs review',
            'url'                 => $url,
            'url_hash'            => $this->url_service->hash_url( $url ),
            'domain'              => $this->url_service->get_domain( $url ),
            'title'               => '',
            'sku'                 => '',
            'gtin'                => '',
            'mpn'                 => '',
            'brand'               => '',
            'regular_price'       => null,
            'sale_price'          => null,
            'currency'            => '',
            'stock_status'        => 'unknown',
            'image_url'           => '',
            'canonical_url'       => '',
            'canonical_url_hash'  => '',
            'extraction_status'   => 'partial',
            'extraction_source'   => '',
            'sources'             => array(),
            'raw_metadata'        => array(),
            'content_hash'        => hash( 'sha256', wp_strip_all_tags( $html ) ),
        );

        $meta = $this->extract_meta_tags( $html );
        $json = $this->extract_json_ld( $html );
        $canon = $this->extract_canonical( $html, $url );

        $this->merge_json_ld( $data, $json );
        $this->merge_meta( $data, $meta, $url );

        if ( '' === $data['canonical_url'] && '' !== $canon ) {
            $data['canonical_url']      = $canon;
            $data['canonical_url_hash'] = $this->url_service->hash_url( $canon );
            $data['sources']['canonical_url'] = 'Product meta tag';
        }

        if ( '' === $data['title'] ) {
            $data['title'] = $this->extract_title( $html );
            if ( '' !== $data['title'] ) {
                $data['sources']['title'] = 'Page content';
            }
        }

        if ( '' === $data['sku'] && '' !== $data['image_url'] ) {
            $sku = $this->sku_from_image_url( $data['image_url'] );
            if ( '' !== $sku ) {
                $data['sku'] = $sku;
                $data['sources']['sku'] = 'Image URL';
            }
        }

        if ( 'unknown' === $data['stock_status'] ) {
            $stock = $this->scan_stock_text( $html );
            if ( 'unknown' !== $stock ) {
                $data['stock_status'] = $stock;
                $data['sources']['stock_status'] = 'Page content';
            }
        }

        $data['regular_price'] = $this->normalize_price( $data['regular_price'] );
        $data['sale_price']    = $this->normalize_price( $data['sale_price'] );
        $data['normalized_sku']  = $this->normalize_identifier( (string) $data['sku'] );
        $data['normalized_gtin'] = $this->normalize_gtin( (string) $data['gtin'] );
        $data['normalized_mpn']  = $this->normalize_identifier( (string) $data['mpn'] );

        $data['extraction_status'] = ( null !== $data['sale_price'] || null !== $data['regular_price'] ) ? 'success' : 'partial';
        $data['extraction_source'] = $this->primary_source( $data['sources'] );
        $data['confidence_status'] = $this->confidence_status( $data );
        $data['message']           = $this->plain_message( $data );
        $data['raw_metadata']      = array(
            'sources' => $data['sources'],
            'meta'    => $meta,
            'json_ld' => $json,
        );

        return $data;
    }

    /**
     * Parse JSON-LD blocks and flatten Product-like nodes.
     *
     * @return array<int,array<string,mixed>>
     */
    private function extract_json_ld( string $html ): array {
        if ( ! preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
            return array();
        }

        $products = array();
        foreach ( $matches[1] as $json ) {
            $json = html_entity_decode( trim( $json ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $decoded = json_decode( $json, true );
            if ( JSON_ERROR_NONE !== json_last_error() ) {
                continue;
            }
            $this->collect_product_nodes( $decoded, $products );
        }

        return $products;
    }

    /**
     * Recursively collect Product nodes.
     *
     * @param mixed                 $node Node.
     * @param array<int,array<mixed>> $products Products.
     */
    private function collect_product_nodes( $node, array &$products ): void {
        if ( ! is_array( $node ) ) {
            return;
        }

        if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
            foreach ( $node['@graph'] as $child ) {
                $this->collect_product_nodes( $child, $products );
            }
        }

        $type = $node['@type'] ?? '';
        if ( is_array( $type ) ) {
            $type = implode( ',', $type );
        }

        if ( is_string( $type ) && false !== stripos( $type, 'Product' ) ) {
            $products[] = $node;
        }

        foreach ( $node as $child ) {
            if ( is_array( $child ) ) {
                $this->collect_product_nodes( $child, $products );
            }
        }
    }

    /**
     * Extract meta tags by name/property.
     *
     * @return array<string,string>
     */
    private function extract_meta_tags( string $html ): array {
        $meta = array();
        if ( preg_match_all( '#<meta\s+([^>]+)>#i', $html, $matches ) ) {
            foreach ( $matches[1] as $attrs ) {
                $name = $this->attr( $attrs, 'property' );
                if ( '' === $name ) {
                    $name = $this->attr( $attrs, 'name' );
                }
                $content = $this->attr( $attrs, 'content' );
                if ( '' !== $name && '' !== $content ) {
                    $meta[ strtolower( $name ) ] = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                }
            }
        }

        return $meta;
    }

    /**
     * Merge JSON-LD product data.
     *
     * @param array<string,mixed> $data Data by reference.
     * @param array<int,array<string,mixed>> $products Product nodes.
     */
    private function merge_json_ld( array &$data, array $products ): void {
        foreach ( $products as $product ) {
            $this->set_if_empty( $data, 'title', $product['name'] ?? '', 'Structured product data' );
            $this->set_if_empty( $data, 'sku', $product['sku'] ?? '', 'Structured product data' );
            $this->set_if_empty( $data, 'mpn', $product['mpn'] ?? '', 'Structured product data' );
            $this->set_if_empty( $data, 'gtin', $this->first_key( $product, array( 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14' ) ), 'Structured product data' );
            $this->set_if_empty( $data, 'brand', $this->brand_value( $product['brand'] ?? '' ), 'Structured product data' );
            $this->set_if_empty( $data, 'image_url', $this->image_value( $product['image'] ?? '' ), 'Structured product data' );

            $offers = $product['offers'] ?? array();
            if ( is_array( $offers ) ) {
                if ( isset( $offers[0] ) && is_array( $offers[0] ) ) {
                    $offers = $offers[0];
                }
                $this->set_if_empty( $data, 'regular_price', $offers['price'] ?? '', 'Structured product data' );
                $this->set_if_empty( $data, 'currency', $offers['priceCurrency'] ?? '', 'Structured product data' );
                $availability = $offers['availability'] ?? '';
                if ( 'unknown' === $data['stock_status'] && '' !== $availability ) {
                    $data['stock_status'] = $this->normalize_availability( (string) $availability );
                    $data['sources']['stock_status'] = 'Structured product data';
                }
            }
        }
    }

    /**
     * Merge OpenGraph/product meta tags.
     *
     * @param array<string,mixed> $data Data by reference.
     * @param array<string,string> $meta Meta tags.
     */
    private function merge_meta( array &$data, array $meta, string $base_url ): void {
        $this->set_if_empty( $data, 'sku', $meta['product:retailer_item_id'] ?? '', 'Product meta tag' );
        $this->set_if_empty( $data, 'brand', $meta['product:brand'] ?? '', 'Product meta tag' );
        $this->set_if_empty( $data, 'title', $meta['og:title'] ?? '', 'Product meta tag' );
        $this->set_if_empty( $data, 'regular_price', $meta['product:price:amount'] ?? '', 'Product meta tag' );
        $this->set_if_empty( $data, 'sale_price', $meta['product:sale_price:amount'] ?? '', 'Product meta tag' );
        $this->set_if_empty( $data, 'currency', $meta['product:price:currency'] ?? $meta['product:sale_price:currency'] ?? '', 'Product meta tag' );

        if ( '' === $data['image_url'] && ! empty( $meta['og:image'] ) ) {
            $data['image_url'] = $this->url_service->resolve( $meta['og:image'], $base_url );
            $data['sources']['image_url'] = 'Product meta tag';
        }

        if ( '' === $data['canonical_url'] && ! empty( $meta['og:url'] ) ) {
            $data['canonical_url'] = $this->url_service->resolve( $meta['og:url'], $base_url );
            $data['canonical_url_hash'] = $this->url_service->hash_url( $data['canonical_url'] );
            $data['sources']['canonical_url'] = 'Product meta tag';
        }

        if ( 'unknown' === $data['stock_status'] && ! empty( $meta['product:availability'] ) ) {
            $data['stock_status'] = $this->normalize_availability( $meta['product:availability'] );
            $data['sources']['stock_status'] = 'Product meta tag';
        }
    }

    /**
     * Extract canonical URL.
     */
    private function extract_canonical( string $html, string $base_url ): string {
        if ( preg_match( '#<link\s+[^>]*rel=["\']canonical["\'][^>]*>#i', $html, $match ) ) {
            $href = $this->attr( $match[0], 'href' );
            return $this->url_service->resolve( $href, $base_url );
        }

        return '';
    }

    /**
     * Extract title tag.
     */
    private function extract_title( string $html ): string {
        if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $match ) ) {
            return trim( wp_strip_all_tags( html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        }

        return '';
    }

    /**
     * Get an HTML attribute from a raw tag/attribute string.
     */
    private function attr( string $attrs, string $name ): string {
        if ( preg_match( '#\b' . preg_quote( $name, '#' ) . '\s*=\s*(["\'])(.*?)\1#i', $attrs, $match ) ) {
            return trim( $match[2] );
        }

        return '';
    }

    /**
     * Set data value if empty.
     *
     * @param array<string,mixed> $data Data.
     * @param mixed              $value Value.
     */
    private function set_if_empty( array &$data, string $key, $value, string $source ): void {
        if ( is_array( $value ) || is_object( $value ) ) {
            return;
        }
        $value = trim( wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        if ( '' !== $value && ( empty( $data[ $key ] ) || 'unknown' === $data[ $key ] ) ) {
            $data[ $key ] = $value;
            $data['sources'][ $key ] = $source;
        }
    }

    /**
     * First value from possible keys.
     *
     * @param array<string,mixed> $data Data.
     * @param array<int,string>   $keys Keys.
     */
    private function first_key( array $data, array $keys ): string {
        foreach ( $keys as $key ) {
            if ( ! empty( $data[ $key ] ) && ! is_array( $data[ $key ] ) ) {
                return (string) $data[ $key ];
            }
        }

        return '';
    }

    /**
     * Get brand string.
     *
     * @param mixed $brand Brand.
     */
    private function brand_value( $brand ): string {
        if ( is_array( $brand ) ) {
            return (string) ( $brand['name'] ?? '' );
        }

        return is_scalar( $brand ) ? (string) $brand : '';
    }

    /**
     * Get image string.
     *
     * @param mixed $image Image.
     */
    private function image_value( $image ): string {
        if ( is_array( $image ) ) {
            $first = reset( $image );
            return is_scalar( $first ) ? (string) $first : '';
        }

        return is_scalar( $image ) ? (string) $image : '';
    }

    /**
     * Normalize price strings.
     *
     * @param mixed $value Raw price.
     * @return float|null
     */
    public function normalize_price( $value ): ?float {
        if ( null === $value || '' === trim( (string) $value ) ) {
            return null;
        }

        $price = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $price = str_replace( array( "\xc2\xa0", 'NOK', 'nok', 'kr', 'Kr' ), ' ', $price );
        $price = preg_replace( '/[^0-9,\.\-]/', '', $price );
        if ( ! is_string( $price ) || '' === $price ) {
            return null;
        }

        $last_comma = strrpos( $price, ',' );
        $last_dot   = strrpos( $price, '.' );

        if ( false !== $last_comma && false !== $last_dot ) {
            if ( $last_comma > $last_dot ) {
                $price = str_replace( '.', '', $price );
                $price = str_replace( ',', '.', $price );
            } else {
                $price = str_replace( ',', '', $price );
            }
        } elseif ( false !== $last_comma ) {
            $price = str_replace( ',', '.', $price );
        }

        return is_numeric( $price ) ? (float) $price : null;
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
     * Normalize GTIN values.
     */
    public function normalize_gtin( string $value ): string {
        $value = preg_replace( '/\D+/', '', trim( $value ) );

        return is_string( $value ) ? $value : '';
    }

    /**
     * Normalize availability.
     */
    private function normalize_availability( string $value ): string {
        $value = strtolower( $value );
        if ( str_contains( $value, 'outofstock' ) || str_contains( $value, 'out of stock' ) || str_contains( $value, 'utsolgt' ) || str_contains( $value, 'ikke på lager' ) || str_contains( $value, 'ikke pa lager' ) ) {
            return 'out_of_stock';
        }
        if ( str_contains( $value, 'backorder' ) || str_contains( $value, 'bestillingsvare' ) ) {
            return 'backorder';
        }
        if ( str_contains( $value, 'instock' ) || str_contains( $value, 'in stock' ) || str_contains( $value, 'på lager' ) || str_contains( $value, 'pa lager' ) ) {
            return 'in_stock';
        }

        return 'unknown';
    }

    /**
     * Scan visible-ish HTML text for stock phrases.
     */
    private function scan_stock_text( string $html ): string {
        $text = strtolower( wp_strip_all_tags( $html ) );
        return $this->normalize_availability( $text );
    }

    /**
     * Try a numeric SKU from the image filename.
     */
    private function sku_from_image_url( string $url ): string {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( preg_match( '/(?:^|\/)([0-9]{5,18})(?:[-_][^\/]+)?\.(?:jpg|jpeg|png|webp)$/i', $path, $match ) ) {
            return $match[1];
        }

        return '';
    }

    /**
     * Pick primary extraction source.
     *
     * @param array<string,string> $sources Sources.
     */
    private function primary_source( array $sources ): string {
        foreach ( array( 'price', 'regular_price', 'sale_price', 'sku', 'gtin', 'mpn', 'title' ) as $key ) {
            if ( ! empty( $sources[ $key ] ) ) {
                return $sources[ $key ];
            }
        }

        return empty( $sources ) ? 'Fallback scan' : reset( $sources );
    }

    /**
     * Plain confidence status.
     *
     * @param array<string,mixed> $data Extracted data.
     */
    private function confidence_status( array $data ): string {
        $has_price = null !== $data['sale_price'] || null !== $data['regular_price'];
        $has_id    = '' !== $data['normalized_sku'] || '' !== $data['normalized_gtin'] || '' !== $data['normalized_mpn'];

        if ( $has_price && $has_id && 'unknown' !== $data['stock_status'] ) {
            return 'Good';
        }
        if ( $has_price ) {
            return 'Needs review';
        }

        return 'Could not read enough';
    }

    /**
     * Plain admin message.
     *
     * @param array<string,mixed> $data Extracted data.
     */
    private function plain_message( array $data ): string {
        $has_price = null !== $data['sale_price'] || null !== $data['regular_price'];
        $has_id    = '' !== $data['normalized_sku'] || '' !== $data['normalized_gtin'] || '' !== $data['normalized_mpn'];

        if ( $has_price && $has_id && 'unknown' !== $data['stock_status'] ) {
            return 'Good: We found price, product identifier and stock on this product page.';
        }
        if ( $has_price && ! $has_id ) {
            return 'We found the price, but not the SKU/EAN. Matches from this competitor may need manual review.';
        }
        if ( $has_price ) {
            return 'We found a price. Please review the other detected values before using matches from this page.';
        }

        return 'We could not read enough from this product page. The competitor may block automated requests or the page may need special settings.';
    }

    /**
     * Failure response.
     *
     * @return array<string,mixed>
     */
    private function failure( string $message, string $technical ): array {
        return array(
            'success'           => false,
            'message'           => $message,
            'technical_details' => $technical,
            'extraction_status' => 'failed',
            'sources'           => array(),
        );
    }

    /**
     * Request User-Agent.
     */
    private function user_agent(): string {
        $version = defined( 'LPM_VERSION' ) ? LPM_VERSION : 'unknown';
        $site    = wp_parse_url( home_url(), PHP_URL_HOST );

        return 'Lilleprinsen Price Monitor/' . $version . ' Competitor Price Assistant; ' . $site;
    }
}
