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
use Lilleprinsen\PriceMonitor\Service\DiscoverySourceService;
use Lilleprinsen\PriceMonitor\Service\DiscoveryUrlService;
use Lilleprinsen\PriceMonitor\Service\MatchSuggestionService;
use Lilleprinsen\PriceMonitor\Service\ProductIdentifierService;
use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component );
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		private int $id;
		private string $type;
		private int $parent_id;
		private string $sku;
		private string $global_id;
		private array $meta;
		private array $attrs;
		public function __construct( int $id, string $type = 'simple', int $parent_id = 0, string $sku = '', string $global_id = '', array $meta = array(), array $attrs = array() ) {
			$this->id = $id;
			$this->type = $type;
			$this->parent_id = $parent_id;
			$this->sku = $sku;
			$this->global_id = $global_id;
			$this->meta = $meta;
			$this->attrs = $attrs;
		}
		public function get_id() { return $this->id; }
		public function is_type( $type ) { return $this->type === $type; }
		public function get_parent_id() { return $this->parent_id; }
		public function get_sku() { return $this->sku; }
		public function get_global_unique_id() { return $this->global_id; }
		public function get_meta( $key, $single = true ) { unset( $single ); return $this->meta[ $key ] ?? ''; }
		public function get_attribute( $key ) { return $this->attrs[ $key ] ?? ''; }
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $id ) {
		return $GLOBALS['lpm_test_products'][ (int) $id ] ?? null;
	}
}

$url_service   = new DiscoveryUrlService();
$settings      = new DiscoverySettings( new Settings() );
$extractor     = new CompetitorProductExtractor( $url_service, $settings );
$source_service = new DiscoverySourceService( $url_service, $settings );
$matcher       = new MatchSuggestionService( new DiscoveryRepository() );
$identifiers   = new ProductIdentifierService( $settings );

lpm_run_tests(
	'Competitor discovery services',
	array(
		'URL normalization removes tracking parameters and blocks unsafe URLs' => static function () use ( $url_service ): void {
			$normalized = $url_service->normalize( 'HTTPS://Example.no/product/123?utm_source=mail&variant=blue&fbclid=abc#reviews' );
			lpm_assert_same( 'https://example.no/product/123?variant=blue', $normalized, 'Tracking parameters and fragments should be removed.' );
			lpm_assert_true( $url_service->matches_domain( $normalized, 'example.no' ), 'Same-domain check should pass.' );
			lpm_assert_true( ! $url_service->is_safe_url( 'http://127.0.0.1/admin' ), 'Localhost IPs must be blocked.' );
			lpm_assert_true( ! $url_service->is_safe_url( 'https://example.no:22/product' ), 'Unexpected ports must be blocked.' );
			lpm_assert_true( ! $url_service->looks_like_product_url( 'https://example.no/category/barnevogn', array(), array(), array( 'p' ) ), 'Single-letter product patterns must not match every https URL.' );
			lpm_assert_true( $url_service->looks_like_product_url( 'https://example.no/p/10201031', array(), array(), array( 'p' ) ), 'Single-letter product patterns should still match path segments.' );
		},
		'EAN source resolves SKU, custom meta and variation before parent fallback' => static function () use ( $identifiers ): void {
			$GLOBALS['lpm_test_products'] = array(
				10 => new WC_Product( 10, 'simple', 0, 'PARENT-SKU', '7040000000001', array( '_ean' => '1111111111111' ) ),
				11 => new WC_Product( 11, 'variation', 10, 'VAR-SKU', '', array( '_ean' => '2222222222222' ) ),
			);
			update_option( Settings::OPTION_NAME, array( 'discovery_gtin_source' => 'custom_meta', 'discovery_gtin_meta_key' => '_ean' ) );
			$result = $identifiers->get_for_product_id( 11 );
			lpm_assert_same( '2222222222222', $result['gtin'], 'Variation custom EAN should win before parent.' );
			update_option( Settings::OPTION_NAME, array( 'discovery_gtin_source' => 'sku' ) );
			$result = $identifiers->get_for_product_id( 11 );
			lpm_assert_same( 'VAR-SKU', $result['gtin'], 'SKU source should use variation SKU.' );
			update_option( Settings::OPTION_NAME, array( 'discovery_gtin_source' => 'global_unique_id' ) );
			$result = $identifiers->get_for_product_id( 11 );
			lpm_assert_same( '7040000000001', $result['gtin'], 'Built-in source should fallback to parent when variation is empty.' );
		},
		'JSON-LD Product extraction handles identifiers, price and availability' => static function () use ( $extractor ): void {
			$html = '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Stroller Model X","sku":"10201031","gtin13":"7040351234567","mpn":"MPN-88","brand":{"name":"Nordic Baby"},"image":"https://competitor.no/images/10201031.jpg","offers":{"@type":"Offer","price":"1 299,00","priceCurrency":"NOK","availability":"https://schema.org/InStock"}}</script></head><body></body></html>';
			$result = $extractor->extract_html( $html, 'https://competitor.no/p/model-x' );
			lpm_assert_true( $result['success'], 'Extraction should succeed.' );
			lpm_assert_same( 'Stroller Model X', $result['title'], 'Title should come from JSON-LD.' );
			lpm_assert_same( '10201031', $result['sku'], 'SKU should come from JSON-LD.' );
			lpm_assert_same( '7040351234567', $result['gtin'], 'GTIN should come from JSON-LD.' );
			lpm_assert_float_equals( 1299.0, (float) $result['regular_price'], 'NOK decimal comma price should normalize.' );
			lpm_assert_same( 'in_stock', $result['stock_status'], 'Schema availability should normalize.' );
		},
		'Product meta tags extract retailer SKU, sale price and currency' => static function () use ( $extractor ): void {
			$html = '<html><head><meta property="product:retailer_item_id" content="SKU-ABC"><meta property="product:price:amount" content="1499.00"><meta property="product:sale_price:amount" content="1199.00"><meta property="product:price:currency" content="NOK"><meta property="product:brand" content="Easy Brand"><meta property="product:availability" content="out of stock"><meta property="og:title" content="Easy Brand Seat"><meta property="og:image" content="/img/sku-abc.jpg"><link rel="canonical" href="https://competitor.no/products/seat"></head></html>';
			$result = $extractor->extract_html( $html, 'https://competitor.no/products/seat?utm_campaign=x' );
			lpm_assert_same( 'SKU-ABC', $result['sku'], 'Meta retailer item ID should map to SKU.' );
			lpm_assert_float_equals( 1199.0, (float) $result['sale_price'], 'Sale price should be captured separately.' );
			lpm_assert_same( 'out_of_stock', $result['stock_status'], 'Meta availability should normalize.' );
			lpm_assert_same( 'sale_price', $result['monitored_price_field'], 'Sale price should be the default monitored field when present.' );
			lpm_assert_float_equals( 1199.0, (float) $result['monitored_price'], 'Chosen monitored price should follow the detected sale price.' );
			lpm_assert_true( count( $result['price_candidates'] ) >= 2, 'Meta regular and sale prices should both appear as selectable candidates.' );
		},
		'Choosing detected candidate can be saved as a competitor extraction rule' => static function () use ( $extractor ): void {
			$rule = $extractor->profile_rule_from_price_candidate(
				array(
					'field'  => 'regular_price',
					'price'  => 1299,
					'source' => 'selector',
					'rule'   => '.product-price',
				)
			);

			lpm_assert_same( 'regular_price', $rule['monitored_price_field'], 'Chosen regular price should become the monitored field.' );
			lpm_assert_same( '.product-price', $rule['regular_price_selector'], 'Selector candidate should persist as regular price selector.' );
			lpm_assert_same( '.product-price', $rule['price_selector'], 'Selector candidate should also populate the generic price selector.' );
		},
		'Competitor selectors and regex rules override extraction' => static function () use ( $extractor ): void {
			$html = '<html><body><h1 id="name">Rule Product</h1><span class="sku">SKU-777</span><span class="now">899 kr</span><span class="brand">Rule Brand</span><div>EAN: 7030000000007</div></body></html>';
			$result = $extractor->extract_html(
				$html,
				'https://competitor.no/produkt/rule-product',
				array(
					'sku_selector' => '.sku',
					'price_selector' => '.now',
					'notes' => '{"title_selector":"#name","brand_selector":".brand","gtin_regex":"/EAN:\\s*([0-9]{13})/"}',
				)
			);
			lpm_assert_same( 'Rule Product', $result['title'], 'Title selector should be used.' );
			lpm_assert_same( 'SKU-777', $result['sku'], 'SKU selector should be used.' );
			lpm_assert_same( 'Rule Brand', $result['brand'], 'Brand selector should be used.' );
			lpm_assert_same( '7030000000007', $result['gtin'], 'GTIN regex should be used.' );
			lpm_assert_float_equals( 899.0, (float) $result['regular_price'], 'Price selector should be normalized.' );
			lpm_assert_same( 'Custom competitor rule', $result['sources']['sku'], 'Rule source should be human-friendly.' );
			lpm_assert_same( 'selector', $result['price_candidates'][0]['source'], 'Selector price should appear as a selector candidate.' );
		},
		'JavaScript-heavy pages are flagged instead of pretending rendering works' => static function () use ( $extractor ): void {
			$html = '<html><head><script>window.__APP__={}</script><script></script><script></script><script></script><script></script></head><body><div id="app"></div></body></html>';
			$result = $extractor->extract_html( $html, 'https://competitor.no/product/js-only' );

			lpm_assert_true( $result['requires_javascript'], 'JS-heavy pages with no server-rendered price should be flagged.' );
			lpm_assert_same( null, $result['monitored_price'], 'Internal checker should not invent a price for JS-only pages.' );
		},
		'Image filename fallback can supply SKU when metadata is missing' => static function () use ( $extractor ): void {
			$html = '<html><head><meta property="product:price:amount" content="999"><meta property="og:image" content="https://competitor.no/media/10201031.jpg"></head><body>På lager</body></html>';
			$result = $extractor->extract_html( $html, 'https://competitor.no/p/no-meta' );
			lpm_assert_same( '10201031', $result['sku'], 'Numeric image filename should be used as fallback SKU.' );
			lpm_assert_same( 'in_stock', $result['stock_status'], 'Norwegian stock text should normalize.' );
		},
		'Listing and sitemap source extraction filters candidates conservatively' => static function () use ( $source_service ): void {
			$seed = (object) array( 'include_patterns' => '', 'exclude_patterns' => 'cart', 'product_url_patterns' => 'produkt,product' );
			$html = '<a href="/produkt/stroller-123">A</a><a href="/cart">Cart</a><a href="https://other.test/product/x">Other</a>';
			$urls = $source_service->extract_listing_urls( $html, 'https://competitor.no/category' );
			$filtered = $source_service->filter_candidate_urls( $urls, $seed, array( 'domain' => 'competitor.no' ) );
			lpm_assert_same( array( 'https://competitor.no/produkt/stroller-123' ), $filtered, 'Only same-domain product-looking URLs should remain.' );
			$sitemap = '<urlset><url><loc>https://competitor.no/product/a</loc></url><url><loc>https://competitor.no/search?q=x</loc></url></urlset>';
			$filtered = $source_service->filter_candidate_urls( $source_service->extract_sitemap_urls( $sitemap ), $seed, array( 'domain' => 'competitor.no' ) );
			lpm_assert_same( array( 'https://competitor.no/product/a' ), $filtered, 'Sitemap extraction should filter non-product URLs.' );
		},
		'Match scoring uses exact identifiers for high confidence' => static function () use ( $matcher ): void {
			$product = (object) array( 'id' => 1, 'product_id' => 10, 'variation_id' => 0, 'sku' => 'ABC-123', 'gtin' => '7040351234567', 'mpn' => 'MPN-1', 'brand' => 'Easy Brand', 'normalized_sku' => 'ABC123', 'normalized_gtin' => '7040351234567', 'normalized_mpn' => 'MPN1' );
			$discovered = (object) array( 'title' => 'Easy Brand Product', 'brand' => 'Easy Brand', 'normalized_sku' => '', 'normalized_gtin' => '7040351234567', 'normalized_mpn' => '' );
			$result = $matcher->score_match( $product, $discovered );
			lpm_assert_same( 'exact_gtin', $result['match_type'], 'GTIN should win first.' );
			lpm_assert_same( 'High confidence', $result['confidence_label'], 'Exact GTIN should be high confidence.' );
		},
		'Match scoring allows medium confidence for same brand and similar title' => static function () use ( $matcher ): void {
			$product = (object) array( 'id' => 2, 'product_id' => 20, 'variation_id' => 0, 'sku' => '', 'gtin' => '', 'mpn' => '', 'brand' => 'Nordic Baby', 'normalized_sku' => '', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$discovered = (object) array( 'title' => 'Nordic Baby Stroller Model X', 'brand' => 'Nordic Baby', 'normalized_sku' => '', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$GLOBALS['lpm_test_titles'][20] = 'Nordic Baby Stroller Model X';
			if ( ! function_exists( 'get_the_title' ) ) {
				function get_the_title( $post_id ) { return $GLOBALS['lpm_test_titles'][ (int) $post_id ] ?? ''; }
			}
			$result = $matcher->score_match( $product, $discovered );
			lpm_assert_same( 'brand_title_evidence', $result['match_type'], 'Similar same-brand title should be considered.' );
			lpm_assert_same( 'Medium confidence', $result['confidence_label'], 'Same brand and strong title should be medium confidence.' );
		},
		'Match scoring uses bassinet bag liggedel vocabulary without high confidence' => static function () use ( $matcher ): void {
			$product = (object) array( 'id' => 4, 'product_id' => 40, 'variation_id' => 0, 'sku' => '20110754', 'gtin' => '', 'mpn' => '', 'brand' => 'Thule', 'normalized_sku' => '20110754', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$discovered = (object) array( 'title' => 'Bag, Thule, Black', 'brand' => 'Thule', 'normalized_sku' => '', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$GLOBALS['lpm_test_titles'][40] = 'Thule Urban Glide 3 bassinet - black on black';
			$result = $matcher->score_match( $product, $discovered );

			lpm_assert_same( 'Medium confidence', $result['confidence_label'], 'Accessory vocabulary plus brand and color should be medium but not high.' );
			lpm_assert_contains( 'Vocabulary match', $result['explanation'], 'Explanation should show synonym evidence.' );
			lpm_assert_contains( 'No exact SKU/EAN/MPN+brand', $result['explanation'], 'Synonyms alone must not create high confidence.' );
		},
		'Match scoring warns and caps bundle package candidates' => static function () use ( $matcher ): void {
			$product = (object) array( 'id' => 5, 'product_id' => 50, 'variation_id' => 0, 'sku' => '', 'gtin' => '', 'mpn' => '', 'brand' => 'Thule', 'normalized_sku' => '', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$discovered = (object) array( 'title' => 'Vognpakke, Thule, Urban Glide 3, Black, ink. Bag Black', 'brand' => 'Thule', 'normalized_sku' => '', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$GLOBALS['lpm_test_titles'][50] = 'Thule Urban Glide 3 bassinet - black on black';
			$result = $matcher->score_match( $product, $discovered );

			lpm_assert_same( 'Low confidence', $result['confidence_label'], 'Bundle/package risk should prevent medium confidence.' );
			lpm_assert_contains( 'bundle/package', $result['explanation'], 'Explanation should warn about bundle/package candidates.' );
		},
		'Match scoring warns on color mismatch' => static function () use ( $matcher ): void {
			$product = (object) array( 'id' => 6, 'product_id' => 60, 'variation_id' => 0, 'sku' => '', 'gtin' => '', 'mpn' => '', 'brand' => 'Thule', 'normalized_sku' => '', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$discovered = (object) array( 'title' => 'Thule Urban Glide 3 bassinet mid blue', 'brand' => 'Thule', 'normalized_sku' => '', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$GLOBALS['lpm_test_titles'][60] = 'Thule Urban Glide 3 bassinet black on black';
			$result = $matcher->score_match( $product, $discovered );

			lpm_assert_same( 'Low confidence', $result['confidence_label'], 'Color mismatch should cap the suggestion at low confidence.' );
			lpm_assert_contains( 'Color/variant mismatch', $result['explanation'], 'Explanation should warn about color mismatch.' );
		},
		'Match scoring warns on single double mismatch' => static function () use ( $matcher ): void {
			$product = (object) array( 'id' => 7, 'product_id' => 70, 'variation_id' => 0, 'sku' => '', 'gtin' => '', 'mpn' => '', 'brand' => 'Thule', 'normalized_sku' => '', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$discovered = (object) array( 'title' => 'Thule Chariot Sport 2 single black', 'brand' => 'Thule', 'normalized_sku' => '', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$GLOBALS['lpm_test_titles'][70] = 'Thule Chariot Sport 2 double black';
			$result = $matcher->score_match( $product, $discovered );

			lpm_assert_same( 'Low confidence', $result['confidence_label'], 'Single/double mismatch should cap confidence.' );
			lpm_assert_contains( 'Single/double variant mismatch', $result['explanation'], 'Explanation should warn about single/double mismatch.' );
		},
		'Match scoring keeps high confidence limited to exact identifiers' => static function () use ( $matcher ): void {
			$product = (object) array( 'id' => 8, 'product_id' => 80, 'variation_id' => 0, 'sku' => '', 'gtin' => '', 'mpn' => '', 'brand' => 'Thule', 'normalized_sku' => '', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$discovered = (object) array( 'title' => 'Thule Chariot Sport 2 double black', 'brand' => 'Thule', 'normalized_sku' => '', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$GLOBALS['lpm_test_titles'][80] = 'Thule Chariot Sport 2 double black';
			$result = $matcher->score_match( $product, $discovered );

			lpm_assert_true( 'High confidence' !== $result['confidence_label'], 'Perfect title and brand without exact identifier must not become high confidence.' );
		},
		'Match scoring keeps title-only suggestions low confidence' => static function () use ( $matcher ): void {
			$product = (object) array( 'id' => 3, 'product_id' => 30, 'variation_id' => 0, 'sku' => '', 'gtin' => '', 'mpn' => '', 'brand' => '', 'normalized_sku' => '', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$discovered = (object) array( 'title' => 'Thule Chariot Sport 2', 'brand' => '', 'normalized_sku' => '', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$GLOBALS['lpm_test_titles'][30] = 'Thule Chariot Sport 2';
			$result = $matcher->score_match( $product, $discovered );
			lpm_assert_same( 'title_only', $result['match_type'], 'Title-only match should be allowed only as a weak suggestion.' );
			lpm_assert_same( 'Low confidence', $result['confidence_label'], 'Title-only match must stay low confidence.' );
			lpm_assert_true( $result['confidence_score'] <= 59, 'Title-only score must not reach medium confidence.' );
		},
		'Rejected suggestion fingerprints remain stable until competitor content changes' => static function () use ( $matcher ): void {
			$reflection = new ReflectionClass( $matcher );
			$method = $reflection->getMethod( 'fingerprint' );
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}
			$product = (object) array( 'product_id' => 90, 'variation_id' => 0, 'normalized_sku' => 'SKU90', 'normalized_gtin' => '', 'normalized_mpn' => '' );
			$discovered = (object) array( 'competitor_id' => 4, 'url_hash' => 'url-hash', 'content_hash' => 'same-content' );

			$first = $method->invoke( $matcher, $product, $discovered, 'title_only' );
			$second = $method->invoke( $matcher, $product, $discovered, 'title_only' );
			$discovered->content_hash = 'changed-content';
			$changed = $method->invoke( $matcher, $product, $discovered, 'title_only' );

			lpm_assert_same( $first, $second, 'Same candidate content should keep the same fingerprint, so rejected suggestions do not immediately reappear.' );
			lpm_assert_true( $first !== $changed, 'Changed candidate content should get a new fingerprint for review.' );
		},
		'Scheduled competitor checks are not wired to price updates' => static function (): void {
			$reflection = new ReflectionClass( Lilleprinsen\PriceMonitor\Jobs\CheckCompetitorLinkJob::class );
			$constructor = $reflection->getConstructor();
			$parameters = $constructor ? $constructor->getParameters() : array();
			$types = array_map(
				static function ( ReflectionParameter $parameter ): string {
					$type = $parameter->getType();
					return $type ? (string) $type : '';
				},
				$parameters
			);

			lpm_assert_true( ! in_array( Lilleprinsen\PriceMonitor\Service\PriceUpdateService::class, $types, true ), 'Scheduled checks should not receive the price update service.' );
		}
	)
);
