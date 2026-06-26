<?php
/**
 * Local tests for SKU search discovery.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';

use Lilleprinsen\PriceMonitor\Service\DiscoverySourceService;
use Lilleprinsen\PriceMonitor\Service\DiscoveryUrlService;
use Lilleprinsen\PriceMonitor\Service\SkuSearchDiscoveryService;
use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component );
	}
}

$url_service    = new DiscoveryUrlService();
$settings       = new DiscoverySettings( new Settings() );
$source_service = new DiscoverySourceService( $url_service, $settings );
$sku_search     = new SkuSearchDiscoveryService( $url_service, $source_service, $settings );

lpm_run_tests(
	'SKU search discovery',
	array(
		'Builds safe competitor search URLs from monitored SKU' => static function () use ( $sku_search ): void {
			lpm_assert_same( 'https://competitor.no/?s=10201031', $sku_search->build_search_url( 'competitor.no', '?s={sku}', '10201031' ), 'WooCommerce-style search URL should be absolute.' );
			lpm_assert_same( 'https://competitor.no/search?q=ABC-123', $sku_search->build_search_url( 'https://competitor.no/', 'search?q={query}', 'ABC-123' ), 'Search template should support {query}.' );
			lpm_assert_same( 'https://competitor.no/catalogsearch/result/?q=ABC%20123', $sku_search->build_search_url( 'competitor.no', 'catalogsearch/result/?q=%s', 'ABC 123' ), 'Magento-style template should encode the SKU.' );
		},
		'Competitor notes can provide simple advanced search templates' => static function () use ( $sku_search ): void {
			$templates = $sku_search->search_templates(
				array(
					'notes' => '{"search_url_templates":["finn?q={sku}","varer/sok/{sku}"]}',
				)
			);

			lpm_assert_true( in_array( 'finn?q={sku}', $templates, true ), 'Custom search template should be included.' );
			lpm_assert_true( in_array( 'varer/sok/{sku}', $templates, true ), 'Path-style search template should be included.' );
		},
		'Crawl extraction queues same-domain links that mention monitored SKUs' => static function () use ( $sku_search ): void {
			$html = '<a href="/thule/chariot-sport-2">Thule Chariot Sport 2 - SKU 10201031</a><a href="/checkout?sku=10201031">Checkout</a><a href="https://other.no/product/10201031">Other</a><a href="/thule/other">No SKU</a>';
			$urls = $sku_search->sku_matched_urls_from_html(
				$html,
				'https://competitor.no/category',
				array(
					array(
						'id'         => 1,
						'raw'        => '10201031',
						'normalized' => '10201031',
					),
				),
				'competitor.no'
			);

			lpm_assert_same( array( 'https://competitor.no/thule/chariot-sport-2' ), $urls, 'Only safe same-domain SKU links should be queued.' );
		},
		'Discovery settings include bounded SKU crawling defaults' => static function () use ( $settings ): void {
			$sanitized = $settings->sanitize(
				array(
					'discovery_sku_crawl_enabled'       => '1',
					'discovery_max_crawl_pages_per_run' => '999',
					'discovery_max_crawl_candidate_urls' => '999',
				)
			);

			lpm_assert_same( 1, $sanitized['discovery_sku_crawl_enabled'], 'SKU crawling should be enabled when checked.' );
			lpm_assert_same( 50, $sanitized['discovery_max_crawl_pages_per_run'], 'Crawl pages must be capped.' );
			lpm_assert_same( 200, $sanitized['discovery_max_crawl_candidate_urls'], 'Candidate URLs must be capped.' );
		},
		'Crawler follows bounded same-domain pages and finds monitored SKU links' => static function () use ( $sku_search ): void {
			update_option(
				Settings::OPTION_NAME,
				array(
					'discovery_max_crawl_pages_per_run' => 5,
					'discovery_exclude_url_patterns'    => 'cart,checkout,account,login,filter,wp-admin,add-to-cart',
				)
			);
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://competitor.no/' => array(
					'body' => '<a href="/barnevogn">Barnevogn</a>',
				),
				'https://competitor.no/barnevogn' => array(
					'body' => '<a href="/thule/chariot-sport-2">Thule Chariot Sport 2 SKU 10201031</a><a href="https://other.no/product/10201031">Other</a>',
				),
			);

			$result = $sku_search->crawl_for_selected_skus(
				array( 'domain' => 'competitor.no', 'enabled' => 1 ),
				array(
					(object) array(
						'id'             => 7,
						'sku'            => '10201031',
						'normalized_sku' => '10201031',
					),
				),
				array(),
				5
			);
			unset( $GLOBALS['lpm_test_http_responses'] );

			lpm_assert_true( $result['success'], 'Crawler should succeed with safe same-domain pages.' );
			lpm_assert_same( 2, $result['request_count'], 'Crawler should stop after the bounded pages it needed.' );
			lpm_assert_same( array( 'https://competitor.no/thule/chariot-sport-2' ), $result['urls'], 'Crawler should queue the same-domain product link that mentions the monitored SKU.' );
			lpm_assert_same( array( 7 ), $result['matched_products'], 'Crawler should report the selected discovery product it matched.' );
		},
		'Crawler queues product candidates when SKU is hidden on product page' => static function () use ( $sku_search ): void {
			update_option(
				Settings::OPTION_NAME,
				array(
					'discovery_max_crawl_pages_per_run'  => 5,
					'discovery_max_crawl_candidate_urls' => 10,
					'discovery_product_url_patterns'     => 'produkt,product,p',
					'discovery_exclude_url_patterns'     => 'cart,checkout,account,login,filter,wp-admin,add-to-cart',
				)
			);
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://competitor.no/' => array(
					'body' => '<a href="/barnevogn">Barnevogn</a>',
				),
				'https://competitor.no/barnevogn' => array(
					'body' => '<a href="/produkt/thule-chariot-sport-2">Thule Chariot Sport 2</a><a href="/kategori/thule">Thule category</a>',
				),
			);

			$result = $sku_search->crawl_for_selected_skus(
				array( 'domain' => 'competitor.no', 'enabled' => 1 ),
				array(
					(object) array(
						'id'             => 7,
						'sku'            => '10201031',
						'normalized_sku' => '10201031',
					),
				),
				array(),
				5
			);
			unset( $GLOBALS['lpm_test_http_responses'] );

			lpm_assert_true( $result['success'], 'Crawler should still succeed when SKUs are hidden until product-page extraction.' );
			lpm_assert_same( array( 'https://competitor.no/produkt/thule-chariot-sport-2' ), $result['urls'], 'Crawler should queue product-looking links for extraction even without SKU in listing text.' );
			lpm_assert_same( array(), $result['matched_products'], 'Candidate-only crawl should not claim a selected product match until extraction verifies identifiers.' );
		},
	)
);
