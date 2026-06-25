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
	)
);
