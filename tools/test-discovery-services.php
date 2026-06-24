<?php
/**
 * Local tests for competitor discovery services.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';

use Lilleprinsen\PriceMonitor\Database\DiscoveryRepository;
use Lilleprinsen\PriceMonitor\Service\CompetitorProductExtractor;
use Lilleprinsen\PriceMonitor\Service\DiscoveryUrlService;
use Lilleprinsen\PriceMonitor\Service\MatchSuggestionService;
use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

$url_service = new DiscoveryUrlService();
$settings    = new DiscoverySettings( new Settings() );
$extractor   = new CompetitorProductExtractor( $url_service, $settings );
$matcher     = new MatchSuggestionService( new DiscoveryRepository() );

lpm_run_tests(
	'Competitor discovery services',
	array(
		'URL normalization removes tracking parameters and blocks unsafe URLs' => static function () use ( $url_service ): void {
			$normalized = $url_service->normalize( 'HTTPS://Example.no/product/123?utm_source=mail&variant=blue&fbclid=abc#reviews' );

			lpm_assert_same( 'https://example.no/product/123?variant=blue', $normalized, 'Tracking parameters and fragments should be removed.' );
			lpm_assert_true( $url_service->matches_domain( $normalized, 'example.no' ), 'Same-domain check should pass.' );
			lpm_assert_true( ! $url_service->is_safe_url( 'http://127.0.0.1/admin' ), 'Localhost IPs must be blocked.' );
		},
		'JSON-LD Product extraction handles identifiers, price and availability' => static function () use ( $extractor ): void {
			$html = '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Stroller Model X","sku":"10201031","gtin13":"7040351234567","mpn":"MPN-88","brand":{"name":"Nordic Baby"},"image":"https://competitor.no/images/10201031.jpg","offers":{"@type":"Offer","price":"1 299,00","priceCurrency":"NOK","availability":"https://schema.org/InStock"}}</script></head><body></body></html>';
			$result = $extractor->extract_html( $html, 'https://competitor.no/p/model-x' );

			lpm_assert_true( $result['success'], 'Extraction should succeed.' );
			lpm_assert_same( 'Stroller Model X', $result['title'], 'Title should come from JSON-LD.' );
			lpm_assert_same( '10201031', $result['sku'], 'SKU should come from JSON-LD.' );
			lpm_assert_same( '7040351234567', $result['gtin'], 'GTIN should come from JSON-LD.' );
			lpm_assert_same( 'MPN-88', $result['mpn'], 'MPN should come from JSON-LD.' );
			lpm_assert_float_equals( 1299.0, (float) $result['regular_price'], 'NOK decimal comma price should normalize.' );
			lpm_assert_same( 'in_stock', $result['stock_status'], 'Schema availability should normalize.' );
			lpm_assert_same( 'Structured product data', $result['sources']['sku'], 'Source label should be human-friendly.' );
		},
		'Product meta tags extract retailer SKU, sale price and currency' => static function () use ( $extractor ): void {
			$html = '<html><head><meta property="product:retailer_item_id" content="SKU-ABC"><meta property="product:price:amount" content="1499.00"><meta property="product:sale_price:amount" content="1199.00"><meta property="product:price:currency" content="NOK"><meta property="product:brand" content="Easy Brand"><meta property="product:availability" content="out of stock"><meta property="og:title" content="Easy Brand Seat"><meta property="og:image" content="/img/sku-abc.jpg"><link rel="canonical" href="https://competitor.no/products/seat"></head></html>';
			$result = $extractor->extract_html( $html, 'https://competitor.no/products/seat?utm_campaign=x' );

			lpm_assert_same( 'SKU-ABC', $result['sku'], 'Meta retailer item ID should map to SKU.' );
			lpm_assert_float_equals( 1499.0, (float) $result['regular_price'], 'Regular price should be captured.' );
			lpm_assert_float_equals( 1199.0, (float) $result['sale_price'], 'Sale price should be captured separately.' );
			lpm_assert_same( 'NOK', $result['currency'], 'Currency should be captured.' );
			lpm_assert_same( 'out_of_stock', $result['stock_status'], 'Meta availability should normalize.' );
			lpm_assert_same( 'Product meta tag', $result['sources']['sku'], 'Meta source should be friendly.' );
		},
		'Image filename fallback can supply SKU when metadata is missing' => static function () use ( $extractor ): void {
			$html = '<html><head><meta property="product:price:amount" content="999"><meta property="og:image" content="https://competitor.no/media/10201031.jpg"></head><body>På lager</body></html>';
			$result = $extractor->extract_html( $html, 'https://competitor.no/p/no-meta' );

			lpm_assert_same( '10201031', $result['sku'], 'Numeric image filename should be used as fallback SKU.' );
			lpm_assert_same( 'Image URL', $result['sources']['sku'], 'SKU fallback source should be Image URL.' );
			lpm_assert_same( 'in_stock', $result['stock_status'], 'Norwegian stock text should normalize.' );
		},
		'Match scoring uses exact identifiers for high confidence' => static function () use ( $matcher ): void {
			$product = (object) array(
				'id'              => 1,
				'product_id'      => 10,
				'variation_id'    => 0,
				'sku'             => 'ABC-123',
				'gtin'            => '7040351234567',
				'mpn'             => 'MPN-1',
				'brand'           => 'Easy Brand',
				'normalized_sku'  => 'ABC123',
				'normalized_gtin' => '7040351234567',
				'normalized_mpn'  => 'MPN1',
			);
			$discovered = (object) array(
				'title'           => 'Easy Brand Product',
				'brand'           => 'Easy Brand',
				'normalized_sku'  => '',
				'normalized_gtin' => '7040351234567',
				'normalized_mpn'  => '',
			);

			$result = $matcher->score_match( $product, $discovered );

			lpm_assert_same( 'exact_gtin', $result['match_type'], 'GTIN should win first.' );
			lpm_assert_same( 'High confidence', $result['confidence_label'], 'Exact GTIN should be high confidence.' );
		},
		'Match scoring allows medium confidence for same brand and similar title' => static function () use ( $matcher ): void {
			$product = (object) array(
				'id'              => 2,
				'product_id'      => 20,
				'variation_id'    => 0,
				'sku'             => '',
				'gtin'            => '',
				'mpn'             => '',
				'brand'           => 'Nordic Baby',
				'normalized_sku'  => '',
				'normalized_gtin' => '',
				'normalized_mpn'  => '',
			);
			$discovered = (object) array(
				'title'           => 'Nordic Baby Stroller Model X',
				'brand'           => 'Nordic Baby',
				'normalized_sku'  => '',
				'normalized_gtin' => '',
				'normalized_mpn'  => '',
			);

			$GLOBALS['lpm_test_titles'][20] = 'Nordic Baby Stroller Model X';
			if ( ! function_exists( 'get_the_title' ) ) {
				function get_the_title( $post_id ) {
					return $GLOBALS['lpm_test_titles'][ (int) $post_id ] ?? '';
				}
			}

			$result = $matcher->score_match( $product, $discovered );

			lpm_assert_same( 'brand_title', $result['match_type'], 'Similar same-brand title should be considered.' );
			lpm_assert_same( 'Medium confidence', $result['confidence_label'], 'Same brand and strong title should be medium confidence.' );
		}
	)
);
