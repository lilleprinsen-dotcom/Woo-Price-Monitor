<?php
/**
 * Deterministic competitor platform detection helpers.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects common ecommerce/search platforms from safe admin-provided signals.
 */
final class CompetitorPlatformDetector {
	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function platform_options(): array {
		return array(
			'auto'    => array(
				'label'       => __( 'Auto-detect', 'lilleprinsen-price-monitor' ),
				'templates'   => array(),
				'description' => __( 'Use the website URL and optional search page URL to suggest the best setup.', 'lilleprinsen-price-monitor' ),
			),
			'woo'     => array(
				'label'       => __( 'WooCommerce', 'lilleprinsen-price-monitor' ),
				'templates'   => array( '?post_type=product&s={query}', '?s={query}' ),
				'description' => __( 'Typical WordPress/Woo search pages.', 'lilleprinsen-price-monitor' ),
			),
			'magento' => array(
				'label'       => __( 'Magento', 'lilleprinsen-price-monitor' ),
				'templates'   => array( 'catalogsearch/result/?q={query}' ),
				'description' => __( 'Typical Magento catalog search pages.', 'lilleprinsen-price-monitor' ),
			),
			'shopify' => array(
				'label'       => __( 'Shopify', 'lilleprinsen-price-monitor' ),
				'templates'   => array( 'search?q={query}', 'search?type=product&q={query}' ),
				'description' => __( 'Typical Shopify product search pages.', 'lilleprinsen-price-monitor' ),
			),
			'algolia' => array(
				'label'       => __( 'Algolia', 'lilleprinsen-price-monitor' ),
				'templates'   => array( '?s={query}', 'search?q={query}' ),
				'description' => __( 'HTML search plus public Algolia fallback when exposed by the page.', 'lilleprinsen-price-monitor' ),
			),
			'voyado'  => array(
				'label'       => __( 'Voyado Elevate', 'lilleprinsen-price-monitor' ),
				'templates'   => array( 'catalogsearch/result/?q={query}&origin=ORGANIC', 'catalogsearch/result/?q={query}' ),
				'description' => __( 'Magento/Voyado search pages that may render results with JavaScript.', 'lilleprinsen-price-monitor' ),
			),
			'custom'  => array(
				'label'       => __( 'Custom / unknown', 'lilleprinsen-price-monitor' ),
				'templates'   => array( 'search?q={query}', '?s={query}' ),
				'description' => __( 'Start with generic search templates and adjust after testing.', 'lilleprinsen-price-monitor' ),
			),
		);
	}

	/**
	 * @return array{platform:string,label:string,confidence:string,templates:array<int,string>,requires_javascript:int,signals:array<int,string>,description:string}
	 */
	public static function detect( string $domain_or_url, string $search_url = '', string $html = '', string $manual_platform = 'auto' ): array {
		$options = self::platform_options();
		$manual_platform = sanitize_key( $manual_platform );

		if ( isset( $options[ $manual_platform ] ) && 'auto' !== $manual_platform ) {
			return self::result( $manual_platform, 'manual', $options[ $manual_platform ]['templates'], array( 'Chosen by admin.' ) );
		}

		$haystack = strtolower( html_entity_decode( implode( ' ', array( $domain_or_url, $search_url, $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$signals  = array();
		$scores   = array_fill_keys( array( 'voyado', 'algolia', 'shopify', 'magento', 'woo' ), 0 );

		self::add_signal_if( $haystack, array( 'bmvoyadosearchresults', 'voyadoelevate', 'elevate-api.cloud', '/queries/search-page', 'bm-voyado-results' ), $scores, $signals, 'voyado', 35, __( 'Voyado Elevate search markers found.', 'lilleprinsen-price-monitor' ) );
		self::add_signal_if( $haystack, array( 'var algolia', 'algolia.net', 'wp-search-with-algolia', 'instantsearch', 'x-algolia-application-id', 'algolia_search' ), $scores, $signals, 'algolia', 35, __( 'Algolia search markers found.', 'lilleprinsen-price-monitor' ) );
		self::add_signal_if( $haystack, array( 'cdn.shopify.com', 'shopify.theme', 'shopifyanalytics', 'myshopify.com', '/products/' ), $scores, $signals, 'shopify', 30, __( 'Shopify storefront markers found.', 'lilleprinsen-price-monitor' ) );
		self::add_signal_if( $haystack, array( 'catalogsearch/result', 'magento_ui', 'x-magento-init', 'mage-cache', 'product-items' ), $scores, $signals, 'magento', 25, __( 'Magento catalog/search markers found.', 'lilleprinsen-price-monitor' ) );
		self::add_signal_if( $haystack, array( 'woocommerce', 'wp-content/plugins/woocommerce', 'post_type=product', 'woocommerce-loopproduct-link' ), $scores, $signals, 'woo', 25, __( 'WooCommerce product/search markers found.', 'lilleprinsen-price-monitor' ) );

		arsort( $scores );
		$platform = (string) key( $scores );
		$score    = (int) reset( $scores );

		if ( $score <= 0 ) {
			return self::result( 'custom', 'low', $options['custom']['templates'], array( __( 'No known platform markers found.', 'lilleprinsen-price-monitor' ) ) );
		}

		$confidence = $score >= 35 ? 'high' : ( $score >= 25 ? 'medium' : 'low' );
		$templates  = self::templates_for_detected_platform( $platform, $search_url );

		return self::result( $platform, $confidence, $templates, $signals );
	}

	/**
	 * @param array<string,int> $scores Scores by platform.
	 * @param array<int,string> $signals Signals.
	 * @param array<int,string> $needles Search needles.
	 */
	private static function add_signal_if( string $haystack, array $needles, array &$scores, array &$signals, string $platform, int $points, string $signal ): void {
		foreach ( $needles as $needle ) {
			if ( '' !== $needle && str_contains( $haystack, $needle ) ) {
				$scores[ $platform ] += $points;
				$signals[] = $signal;
				return;
			}
		}
	}

	/**
	 * @return array<int,string>
	 */
	private static function templates_for_detected_platform( string $platform, string $search_url ): array {
		$options   = self::platform_options();
		$templates = $options[ $platform ]['templates'] ?? $options['custom']['templates'];

		$derived = self::template_from_search_url( $search_url );
		if ( '' !== $derived ) {
			array_unshift( $templates, $derived );
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $templates ) ) ) );
	}

	private static function template_from_search_url( string $search_url ): string {
		$search_url = trim( $search_url );
		if ( '' === $search_url ) {
			return '';
		}

		if ( str_contains( $search_url, '{query}' ) || str_contains( $search_url, '{sku}' ) || str_contains( $search_url, '{ean}' ) || str_contains( $search_url, '{gtin}' ) ) {
			return sanitize_text_field( $search_url );
		}

		$parts = wp_parse_url( $search_url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
		}

		foreach ( array( 'q', 'query', 's', 'search', 'text', 'term' ) as $key ) {
			if ( ! array_key_exists( $key, $query ) ) {
				continue;
			}
			$query[ $key ] = '{query}';
			$scheme = ! empty( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';
			$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
			$params = array();
			foreach ( $query as $param_key => $value ) {
				$params[] = rawurlencode( (string) $param_key ) . '=' . ( '{query}' === $value ? '{query}' : rawurlencode( (string) $value ) );
			}
			return sanitize_text_field( $scheme . '://' . $parts['host'] . $path . ( empty( $params ) ? '' : '?' . implode( '&', $params ) ) );
		}

		return '';
	}

	/**
	 * @param array<int,string> $templates Templates.
	 * @param array<int,string> $signals Signals.
	 * @return array{platform:string,label:string,confidence:string,templates:array<int,string>,requires_javascript:int,signals:array<int,string>,description:string}
	 */
	private static function result( string $platform, string $confidence, array $templates, array $signals ): array {
		$options = self::platform_options();
		$option  = $options[ $platform ] ?? $options['custom'];

		return array(
			'platform'            => $platform,
			'label'               => (string) $option['label'],
			'confidence'          => $confidence,
			'templates'           => $templates,
			'requires_javascript' => in_array( $platform, array( 'voyado', 'algolia' ), true ) ? 1 : 0,
			'signals'             => array_values( array_unique( array_filter( $signals ) ) ),
			'description'         => (string) $option['description'],
		);
	}
}
