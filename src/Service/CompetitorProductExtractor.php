<?php
/**
 * Competitor product page extraction.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;

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
		$url      = $this->url_service->normalize( $url );
		$settings = $this->settings->get_all();
		$ports    = array_map( 'absint', $this->settings->get_list( 'discovery_allow_ports' ) );

		if ( '' === $url || ! $this->url_service->is_safe_url( $url, $ports ) ) {
			return $this->failure( 'We could not read this product page.', 'The URL is not allowed for safety reasons.' );
		}

		$domain = (string) ( $competitor['domain'] ?? $competitor['website_url'] ?? '' );
		if ( ! empty( $settings['discovery_same_domain_only'] ) && '' !== $domain && ! $this->url_service->matches_domain( $url, $domain ) ) {
			return $this->failure( 'We could not read this product page.', 'The URL does not match the competitor website.' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => absint( $settings['discovery_request_timeout'] ?? 12 ),
				'redirection' => 0,
				'user-agent'  => $this->user_agent(),
				'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->failure( 'We could not read this product page.', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 && $code < 400 ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			$next     = is_array( $location ) ? reset( $location ) : $location;
			$next_url = $this->url_service->resolve( (string) $next, $url );
			if ( '' === $next_url || ! $this->url_service->is_safe_url( $next_url, $ports ) || ( ! empty( $settings['discovery_same_domain_only'] ) && '' !== $domain && ! $this->url_service->matches_domain( $next_url, $domain ) ) ) {
				return $this->failure( 'We could not read this product page.', 'The page redirected somewhere unsafe.' );
			}
			$response = wp_remote_get(
				$next_url,
				array(
					'timeout'     => absint( $settings['discovery_request_timeout'] ?? 12 ),
					'redirection' => 0,
					'user-agent'  => $this->user_agent(),
				)
			);
			$url = $next_url;
		}

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

		return $this->extract_html( $html, $url, $competitor );
	}

	/**
	 * Extract product fields from HTML.
	 *
	 * @param array<string,mixed> $competitor Competitor profile/rules.
	 * @return array<string,mixed>
	 */
	public function extract_html( string $html, string $url, array $competitor = array() ): array {
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
			'price_candidates'     => array(),
			'monitored_price'      => null,
			'monitored_price_field'=> '',
			'requires_javascript'  => false,
			'warnings'             => array(),
			'sources'             => array(),
			'raw_metadata'        => array(),
			'content_hash'        => hash( 'sha256', wp_strip_all_tags( $html ) ),
		);

		$meta  = $this->extract_meta_tags( $html );
		$json  = $this->extract_json_ld( $html );
		$canon = $this->extract_canonical( $html, $url );

		$this->merge_json_ld( $data, $json );
		$this->merge_meta( $data, $meta, $url );
		$this->apply_competitor_rules( $data, $html, $meta, $competitor );
		$this->merge_visible_price_candidates( $data, $html );

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
			$sku = $this->sku_from_image_url( $data['image_url'], $this->advanced_rules( $competitor ) );
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

		$data['regular_price']   = $this->normalize_price( $data['regular_price'] );
		$data['sale_price']      = $this->normalize_price( $data['sale_price'] );
		$data['price_candidates'] = $this->normalized_price_candidates( $data['price_candidates'] );
		$data['monitored_price_field'] = $this->chosen_monitored_price_field( $data, $competitor );
		$data['monitored_price'] = $this->price_for_field( $data, $data['monitored_price_field'] );
		$data['normalized_sku']  = $this->normalize_identifier( (string) $data['sku'] );
		$data['normalized_gtin'] = $this->normalize_gtin( (string) $data['gtin'] );
		$data['normalized_mpn']  = $this->normalize_identifier( (string) $data['mpn'] );
		if ( $this->looks_javascript_required( $html, $data ) ) {
			$data['requires_javascript'] = true;
			$data['warnings'][] = 'This page appears to require JavaScript. The internal checker reads HTML only, so it may not see the live price.';
		}

		$data['extraction_status'] = ( null !== $data['sale_price'] || null !== $data['regular_price'] ) ? 'success' : 'partial';
		$data['extraction_source'] = $this->primary_source( $data['sources'] );
		$data['confidence_status'] = $this->confidence_status( $data );
		$data['message']           = $this->plain_message( $data );
		$data['raw_metadata']      = array(
			'sources' => $data['sources'],
			'meta'    => $meta,
			'json_ld' => $json,
			'rules'   => $this->advanced_rules( $competitor ),
			'price_candidates' => $data['price_candidates'],
		);

		return $data;
	}

	/**
	 * Build profile updates from a tested price candidate.
	 *
	 * @param array<string,mixed> $candidate Candidate from price_candidates.
	 * @return array<string,mixed>
	 */
	public function profile_rule_from_price_candidate( array $candidate ): array {
		$field  = sanitize_key( (string) ( $candidate['field'] ?? '' ) );
		$source = sanitize_text_field( (string) ( $candidate['source'] ?? '' ) );
		$rule   = sanitize_text_field( (string) ( $candidate['rule'] ?? '' ) );

		$data = array( 'monitored_price_field' => in_array( $field, array( 'regular_price', 'sale_price' ), true ) ? $field : 'sale_price_first' );
		if ( in_array( $source, array( 'selector', 'custom_rule' ), true ) && '' !== $rule ) {
			if ( 'sale_price' === $field ) {
				$data['sale_price_selector'] = $rule;
			} else {
				$data['regular_price_selector'] = $rule;
				$data['price_selector'] = $rule;
			}
		}

		return $data;
	}

	/**
	 * Apply existing competitor profile selectors and advanced notes JSON.
	 *
	 * @param array<string,mixed>  $data Data by reference.
	 * @param array<string,string> $meta Meta tags.
	 * @param array<string,mixed>  $competitor Competitor profile.
	 */
	private function apply_competitor_rules( array &$data, string $html, array $meta, array $competitor ): void {
		$selector_map = array(
			'sku'           => $competitor['sku_selector'] ?? '',
			'gtin'          => $competitor['gtin_selector'] ?? '',
			'regular_price' => $competitor['regular_price_selector'] ?? $competitor['price_selector'] ?? '',
			'sale_price'    => $competitor['sale_price_selector'] ?? '',
			'stock_status'  => $competitor['stock_selector'] ?? '',
		);

		$advanced = $this->advanced_rules( $competitor );
		foreach ( array( 'title', 'brand', 'mpn' ) as $field ) {
			if ( ! empty( $advanced[ $field . '_selector' ] ) ) {
				$selector_map[ $field ] = (string) $advanced[ $field . '_selector' ];
			}
		}

		foreach ( $selector_map as $field => $selector ) {
			$selector = trim( (string) $selector );
			if ( '' === $selector ) {
				continue;
			}
			$value = $this->selector_text( $html, $selector );
			if ( '' === $value ) {
				continue;
			}
			if ( 'stock_status' === $field ) {
				$data['stock_status'] = $this->normalize_availability( $value );
			} else {
				$data[ $field ] = $value;
			}
			$data['sources'][ $field ] = 'Custom competitor rule';
			if ( in_array( $field, array( 'regular_price', 'sale_price' ), true ) ) {
				$this->add_price_candidate( $data, $field, $value, 'selector', 'Selector', $selector );
			}
		}

		if ( ! empty( $advanced['meta_map'] ) && is_array( $advanced['meta_map'] ) ) {
			foreach ( $advanced['meta_map'] as $field => $meta_key ) {
				$field    = sanitize_key( (string) $field );
				$meta_key = strtolower( trim( (string) $meta_key ) );
				if ( isset( $data[ $field ], $meta[ $meta_key ] ) && '' !== $meta[ $meta_key ] ) {
					$data[ $field ] = $meta[ $meta_key ];
					$data['sources'][ $field ] = 'Custom competitor rule';
				}
			}
		}

		foreach ( array( 'sku', 'gtin', 'mpn', 'brand' ) as $field ) {
			$pattern = $advanced[ $field . '_regex' ] ?? '';
			if ( '' === (string) $pattern || ! empty( $data[ $field ] ) ) {
				continue;
			}
			$value = $this->regex_value( $html, (string) $pattern );
			if ( '' !== $value ) {
				$data[ $field ] = $value;
				$data['sources'][ $field ] = 'Custom competitor rule';
			}
		}
	}

	/**
	 * Extract text for a small safe subset of selectors.
	 */
	private function selector_text( string $html, string $selector ): string {
		$selector = trim( $selector );
		$pattern  = '';
		if ( str_starts_with( $selector, '#' ) ) {
			$id      = preg_quote( substr( $selector, 1 ), '#' );
			$pattern = '#<([a-z0-9]+)[^>]*\bid=["\']' . $id . '["\'][^>]*>(.*?)</\1>#is';
		} elseif ( str_starts_with( $selector, '.' ) ) {
			$class   = preg_quote( substr( $selector, 1 ), '#' );
			$pattern = '#<([a-z0-9]+)[^>]*\bclass=["\'][^"\']*\b' . $class . '\b[^"\']*["\'][^>]*>(.*?)</\1>#is';
		} elseif ( preg_match( '/^\[([a-z0-9_:-]+)(?:=["\']?([^"\']+)["\']?)?\]$/i', $selector, $match ) ) {
			$attr = preg_quote( $match[1], '#' );
			if ( ! empty( $match[2] ) ) {
				$value   = preg_quote( $match[2], '#' );
				$pattern = '#<([a-z0-9]+)[^>]*\b' . $attr . '=["\']' . $value . '["\'][^>]*>(.*?)</\1>#is';
			} else {
				$pattern = '#<([a-z0-9]+)[^>]*\b' . $attr . '\b[^>]*>(.*?)</\1>#is';
			}
		} elseif ( preg_match( '/^[a-z0-9]+$/i', $selector ) ) {
			$tag     = preg_quote( $selector, '#' );
			$pattern = '#<' . $tag . '[^>]*>(.*?)</' . $tag . '>#is';
		}

		if ( '' === $pattern || ! preg_match( $pattern, $html, $match ) ) {
			return '';
		}

		return trim( wp_strip_all_tags( html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Regex value with safe delimiter handling.
	 */
	private function regex_value( string $html, string $pattern ): string {
		if ( '' === $pattern || @preg_match( $pattern, '' ) === false ) {
			return '';
		}
		if ( preg_match( $pattern, $html, $match ) ) {
			return trim( wp_strip_all_tags( (string) ( $match[1] ?? $match[0] ) ) );
		}

		return '';
	}

	/**
	 * Advanced rules are JSON stored in competitor notes.
	 *
	 * @param array<string,mixed> $competitor Competitor profile.
	 * @return array<string,mixed>
	 */
	private function advanced_rules( array $competitor ): array {
		$notes = trim( (string) ( $competitor['notes'] ?? '' ) );
		if ( '' === $notes || '{' !== substr( $notes, 0, 1 ) ) {
			return array();
		}
		$decoded = json_decode( $notes, true );
		if ( ! is_array( $decoded ) && str_contains( $notes, '\\' ) ) {
			$decoded = json_decode( (string) preg_replace( '/\\\\(?!["\\\\\/bfnrtu])/', '\\\\\\\\', $notes ), true );
		}

		return is_array( $decoded ) ? $decoded : array();
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
			$decoded = json_decode( html_entity_decode( trim( $json ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$this->collect_product_nodes( $decoded, $products );
			}
		}

		return $products;
	}

	/**
	 * Recursively collect Product nodes.
	 *
	 * @param mixed                   $node Node.
	 * @param array<int,array<mixed>> $products Products.
	 */
	private function collect_product_nodes( $node, array &$products ): void {
		if ( ! is_array( $node ) ) {
			return;
		}
		$type = $node['@type'] ?? '';
		if ( is_array( $type ) ) {
			$type = implode( ',', $type );
		}
		if ( is_string( $type ) && false !== stripos( $type, 'Product' ) ) {
			$products[] = $node;
		}
		foreach ( array( '@graph', 'mainEntity', 'itemListElement' ) as $key ) {
			if ( ! empty( $node[ $key ] ) && is_array( $node[ $key ] ) ) {
				$this->collect_product_nodes( $node[ $key ], $products );
			}
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
	 * @param array<string,mixed>              $data Data by reference.
	 * @param array<int,array<string,mixed>>   $products Product nodes.
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
				if ( ! empty( $offers['price'] ) ) {
					$this->add_price_candidate( $data, 'regular_price', $offers['price'], 'json_ld', 'JSON-LD', 'offers.price' );
				}
				$this->set_if_empty( $data, 'currency', $offers['priceCurrency'] ?? '', 'Structured product data' );
				if ( 'unknown' === $data['stock_status'] && ! empty( $offers['availability'] ) ) {
					$data['stock_status'] = $this->normalize_availability( (string) $offers['availability'] );
					$data['sources']['stock_status'] = 'Structured product data';
				}
			}
		}
	}

	/**
	 * Merge OpenGraph/product meta tags.
	 *
	 * @param array<string,mixed>  $data Data by reference.
	 * @param array<string,string> $meta Meta tags.
	 */
	private function merge_meta( array &$data, array $meta, string $base_url ): void {
		$this->set_if_empty( $data, 'sku', $meta['product:retailer_item_id'] ?? '', 'Product meta tag' );
		$this->set_if_empty( $data, 'brand', $meta['product:brand'] ?? '', 'Product meta tag' );
		$this->set_if_empty( $data, 'title', $meta['og:title'] ?? '', 'Product meta tag' );
		$this->set_if_empty( $data, 'regular_price', $meta['product:price:amount'] ?? '', 'Product meta tag' );
		$this->set_if_empty( $data, 'sale_price', $meta['product:sale_price:amount'] ?? '', 'Product meta tag' );
		if ( ! empty( $meta['product:price:amount'] ) ) {
			$this->add_price_candidate( $data, 'regular_price', $meta['product:price:amount'], 'meta_tag', 'Meta tag', 'product:price:amount' );
		}
		if ( ! empty( $meta['product:sale_price:amount'] ) ) {
			$this->add_price_candidate( $data, 'sale_price', $meta['product:sale_price:amount'], 'meta_tag', 'Meta tag', 'product:sale_price:amount' );
		}
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
	 * Add visible-text price candidates without trusting them over structured data.
	 *
	 * @param array<string,mixed> $data Data by reference.
	 */
	private function merge_visible_price_candidates( array &$data, string $html ): void {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( ! preg_match_all( '/(?:NOK|kr)\s*([0-9][0-9\s\.,]{1,12})|([0-9][0-9\s\.,]{1,12})\s*(?:NOK|kr)/iu', $text, $matches, PREG_SET_ORDER ) ) {
			return;
		}

		foreach ( array_slice( $matches, 0, 8 ) as $index => $match ) {
			$value = trim( (string) ( $match[1] ?: $match[2] ) );
			$this->add_price_candidate( $data, 0 === $index ? 'regular_price' : 'detected_price', $value, 'visible_text', 'Visible page text', 'price text near NOK/kr' );
		}
	}

	/**
	 * @param array<string,mixed> $data Data by reference.
	 * @param mixed               $value Raw price value.
	 */
	private function add_price_candidate( array &$data, string $field, $value, string $source, string $label, string $rule = '' ): void {
		if ( ! isset( $data['price_candidates'] ) || ! is_array( $data['price_candidates'] ) ) {
			$data['price_candidates'] = array();
		}

		$data['price_candidates'][] = array(
			'field'  => sanitize_key( $field ),
			'value'  => is_scalar( $value ) ? (string) $value : '',
			'price'  => $this->normalize_price( $value ),
			'source' => sanitize_key( $source ),
			'label'  => sanitize_text_field( $label ),
			'rule'   => sanitize_text_field( $rule ),
		);
	}

	/**
	 * Normalize and deduplicate detected price candidates.
	 *
	 * @param array<int,array<string,mixed>> $candidates Raw candidates.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalized_price_candidates( array $candidates ): array {
		$out = array();
		$seen = array();
		foreach ( $candidates as $candidate ) {
			$price = $this->normalize_price( $candidate['price'] ?? $candidate['value'] ?? null );
			if ( null === $price ) {
				continue;
			}
			$field = sanitize_key( (string) ( $candidate['field'] ?? 'detected_price' ) );
			$key = $field . '|' . (string) $price . '|' . sanitize_key( (string) ( $candidate['source'] ?? '' ) ) . '|' . sanitize_text_field( (string) ( $candidate['rule'] ?? '' ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[] = array(
				'field'  => $field,
				'value'  => sanitize_text_field( (string) ( $candidate['value'] ?? $price ) ),
				'price'  => $price,
				'source' => sanitize_key( (string) ( $candidate['source'] ?? 'unknown' ) ),
				'label'  => sanitize_text_field( (string) ( $candidate['label'] ?? 'Detected value' ) ),
				'rule'   => sanitize_text_field( (string) ( $candidate['rule'] ?? '' ) ),
			);
		}

		return array_slice( $out, 0, 12 );
	}

	/**
	 * Pick the monitored price field from competitor profile and detected values.
	 *
	 * @param array<string,mixed> $data Extracted data.
	 * @param array<string,mixed> $competitor Competitor profile.
	 */
	private function chosen_monitored_price_field( array $data, array $competitor ): string {
		$field = sanitize_key( (string) ( $competitor['monitored_price_field'] ?? 'sale_price_first' ) );
		if ( in_array( $field, array( 'regular_price', 'sale_price' ), true ) && null !== $this->price_for_field( $data, $field ) ) {
			return $field;
		}
		if ( 'regular_price' === $field && null !== $this->price_for_field( $data, 'regular_price' ) ) {
			return 'regular_price';
		}
		if ( null !== $this->price_for_field( $data, 'sale_price' ) ) {
			return 'sale_price';
		}
		if ( null !== $this->price_for_field( $data, 'regular_price' ) ) {
			return 'regular_price';
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $data Extracted data.
	 */
	private function price_for_field( array $data, string $field ): ?float {
		if ( in_array( $field, array( 'regular_price', 'sale_price' ), true ) && array_key_exists( $field, $data ) ) {
			return $this->normalize_price( $data[ $field ] );
		}

		return null;
	}

	/**
	 * Detect pages that likely need a browser renderer.
	 *
	 * @param array<string,mixed> $data Extracted data.
	 */
	private function looks_javascript_required( string $html, array $data ): bool {
		if ( null !== $data['monitored_price'] ) {
			return false;
		}

		$lower = strtolower( $html );
		$script_count = substr_count( $lower, '<script' );
		$body_text = trim( wp_strip_all_tags( preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html ) ?: $html ) );

		return $script_count >= 5 && strlen( $body_text ) < 400;
	}

	/** Extract canonical URL. */
	private function extract_canonical( string $html, string $base_url ): string {
		if ( preg_match( '#<link\s+[^>]*rel=["\']canonical["\'][^>]*>#i', $html, $match ) ) {
			return $this->url_service->resolve( $this->attr( $match[0], 'href' ), $base_url );
		}
		return '';
	}

	/** Extract title tag. */
	private function extract_title( string $html ): string {
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $match ) ) {
			return trim( wp_strip_all_tags( html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		}
		return '';
	}

	/** Get an HTML attribute from a raw tag/attribute string. */
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
	 * @param mixed               $value Value.
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

	/** @param mixed $brand Brand. */
	private function brand_value( $brand ): string {
		if ( is_array( $brand ) ) {
			return (string) ( $brand['name'] ?? '' );
		}
		return is_scalar( $brand ) ? (string) $brand : '';
	}

	/** @param mixed $image Image. */
	private function image_value( $image ): string {
		if ( is_array( $image ) ) {
			$first = reset( $image );
			return is_scalar( $first ) ? (string) $first : '';
		}
		return is_scalar( $image ) ? (string) $image : '';
	}

	/** @param mixed $value Raw price. */
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

	/** Normalize SKU/MPN-like identifiers. */
	public function normalize_identifier( string $value ): string {
		$value = strtoupper( html_entity_decode( trim( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$value = preg_replace( '/[^A-Z0-9]/', '', $value );
		return is_string( $value ) ? $value : '';
	}

	/** Normalize GTIN values. */
	public function normalize_gtin( string $value ): string {
		$value = preg_replace( '/\D+/', '', trim( $value ) );
		return is_string( $value ) ? $value : '';
	}

	/** Normalize availability. */
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

	/** Scan visible-ish HTML text for stock phrases. */
	private function scan_stock_text( string $html ): string {
		return $this->normalize_availability( strtolower( wp_strip_all_tags( $html ) ) );
	}

	/** Try a SKU from the image filename or configured image regex. */
	private function sku_from_image_url( string $url, array $advanced ): string {
		if ( ! empty( $advanced['image_url_regex'] ) ) {
			$value = $this->regex_value( $url, (string) $advanced['image_url_regex'] );
			if ( '' !== $value ) {
				return $value;
			}
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '/(?:^|\/)([0-9]{5,18})(?:[-_][^\/]+)?\.(?:jpg|jpeg|png|webp)$/i', $path, $match ) ) {
			return $match[1];
		}
		return '';
	}

	/** @param array<string,string> $sources Sources. */
	private function primary_source( array $sources ): string {
		foreach ( array( 'regular_price', 'sale_price', 'sku', 'gtin', 'mpn', 'title' ) as $key ) {
			if ( ! empty( $sources[ $key ] ) ) {
				return $sources[ $key ];
			}
		}
		return empty( $sources ) ? 'Fallback scan' : (string) reset( $sources );
	}

	/** @param array<string,mixed> $data Extracted data. */
	private function confidence_status( array $data ): string {
		$has_price = null !== $data['sale_price'] || null !== $data['regular_price'];
		$has_id    = '' !== $data['normalized_sku'] || '' !== $data['normalized_gtin'] || '' !== $data['normalized_mpn'];
		if ( $has_price && $has_id && 'unknown' !== $data['stock_status'] ) {
			return 'Good';
		}
		return $has_price ? 'Needs review' : 'Could not read enough';
	}

	/** @param array<string,mixed> $data Extracted data. */
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

	/** @return array<string,mixed> */
	private function failure( string $message, string $technical ): array {
		return array(
			'success'           => false,
			'message'           => $message,
			'technical_details' => $technical,
			'extraction_status' => 'failed',
			'price_candidates'  => array(),
			'monitored_price'   => null,
			'monitored_price_field' => '',
			'requires_javascript' => false,
			'warnings'          => array(),
			'sources'           => array(),
		);
	}

	/** Request User-Agent. */
	private function user_agent(): string {
		$version = defined( 'LPM_VERSION' ) ? LPM_VERSION : 'unknown';
		$site    = wp_parse_url( home_url(), PHP_URL_HOST );
		return 'Lilleprinsen Price Monitor/' . $version . ' Competitor Price Assistant; ' . $site;
	}
}
