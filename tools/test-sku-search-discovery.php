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
					'notes' => '{"search_url_templates":["finn?q={sku}","varer/sok/{sku}","ignored-without-placeholder"]}',
				)
			);

			lpm_assert_true( in_array( 'finn?q={sku}', $templates, true ), 'Custom search template should be included.' );
			lpm_assert_true( in_array( 'varer/sok/{sku}', $templates, true ), 'Path-style search template should be included.' );
			lpm_assert_true( ! in_array( 'ignored-without-placeholder', $templates, true ), 'Templates without placeholders should be ignored.' );
		},
		'Search template test reports why no match was found' => static function () use ( $sku_search ): void {
			update_option(
				Settings::OPTION_NAME,
				array(
					'discovery_name_search_enabled'      => 0,
					'discovery_search_urls_per_sku'      => 1,
					'discovery_sku_search_url_templates' => '?s={sku}',
					'discovery_product_url_patterns'     => 'produkt,product,p',
				)
			);
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://competitor.no/?s=NO-HIT' => array(
					'body' => '<html><body>No products here</body></html>',
				),
			);

			$result = $sku_search->discover_for_product(
				array( 'domain' => 'competitor.no', 'enabled' => 1 ),
				(object) array(
					'id'             => 8,
					'product_id'     => 102,
					'sku'            => 'NO-HIT',
					'normalized_sku' => 'NOHIT',
				)
			);
			unset( $GLOBALS['lpm_test_http_responses'] );

			lpm_assert_true( ! $result['success'], 'No URLs should be reported as a failed discovery test.' );
			lpm_assert_true( str_contains( $result['technical_details'], 'No SKU/EAN on page' ), 'No-match result should explain missing SKU/EAN and product URLs.' );
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
					'discovery_name_search_enabled'      => '1',
					'discovery_max_crawl_pages_per_run' => '999',
					'discovery_max_crawl_candidate_urls' => '999',
				)
			);

			lpm_assert_same( 1, $sanitized['discovery_sku_crawl_enabled'], 'SKU crawling should be enabled when checked.' );
			lpm_assert_same( 1, $sanitized['discovery_name_search_enabled'], 'Name search fallback should be enabled when checked.' );
			lpm_assert_same( 50, $sanitized['discovery_max_crawl_pages_per_run'], 'Crawl pages must be capped.' );
			lpm_assert_same( 200, $sanitized['discovery_max_crawl_candidate_urls'], 'Candidate URLs must be capped.' );
		},
		'Name search extraction queues title-relevant product candidates only' => static function () use ( $sku_search ): void {
			$html = '<a href="/produkt/thule-chariot-sport-2-midnight-black">Thule Chariot Sport 2 Midnight Black</a><a href="/produkt/cybex-priam">Cybex Priam</a><a href="/kategori/thule">Thule</a>';
			$urls = $sku_search->name_matched_urls_from_html( $html, 'https://competitor.no/search?q=thule', 'Thule Chariot Sport 2 midnight black', 'competitor.no' );

			lpm_assert_same( array( 'https://competitor.no/produkt/thule-chariot-sport-2-midnight-black' ), $urls, 'Name search should queue matching product links and skip unrelated/category links.' );
		},
		'Product-name search fallback finds candidates when SKU search finds nothing' => static function () use ( $sku_search ): void {
			update_option(
				Settings::OPTION_NAME,
				array(
					'discovery_name_search_enabled'      => 1,
					'discovery_search_urls_per_sku'      => 1,
					'discovery_sku_search_url_templates' => '?s={query}',
					'discovery_product_url_patterns'     => 'produkt,product,p',
					'discovery_exclude_url_patterns'     => 'cart,checkout,account,login,filter,wp-admin,add-to-cart',
				)
			);
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://competitor.no/?s=10201031' => array(
					'response' => array( 'code' => 404 ),
				),
				'https://competitor.no/?s=Thule%20Chariot%20Sport%202%20double%20midnight%20black' => array(
					'body' => '<a href="/produkt/thule-chariot-sport-2-double-midnight-black">Thule Chariot Sport 2 double midnight black</a><a href="/produkt/other-product">Other product</a>',
				),
			);

			$result = $sku_search->discover_for_product(
				array( 'domain' => 'competitor.no', 'enabled' => 1 ),
				(object) array(
					'id'             => 7,
					'product_id'     => 101,
					'sku'            => '10201031',
					'normalized_sku' => '10201031',
					'product_name'   => 'Thule Chariot Sport 2 double midnight black',
				)
			);
			unset( $GLOBALS['lpm_test_http_responses'] );

			lpm_assert_true( $result['success'], 'Name search fallback should succeed after SKU search produces no candidate URLs.' );
			lpm_assert_same( 2, $result['request_count'], 'Name fallback should keep requests bounded.' );
			lpm_assert_same( array( 'https://competitor.no/produkt/thule-chariot-sport-2-double-midnight-black' ), $result['urls'], 'Name fallback should queue only relevant product candidates.' );
			lpm_assert_same( 'Thule Chariot Sport 2 double midnight black', $result['searched_name'], 'The searched name should be stored for logs/metadata.' );
		},
		'Identifier search queues exact-match redirects to product pages' => static function () use ( $sku_search ): void {
			update_option(
				Settings::OPTION_NAME,
				array(
					'discovery_name_search_enabled'      => 0,
					'discovery_search_urls_per_sku'      => 1,
					'discovery_sku_search_url_templates' => 'catalogsearch/result/?q={query}&origin=ORGANIC',
					'discovery_product_url_patterns'     => 'produkt,product,p,varer,vare',
					'discovery_exclude_url_patterns'     => 'cart,checkout,account,login,filter,wp-admin,add-to-cart',
				)
			);
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://competitor.no/catalogsearch/result/?q=10101001&origin=ORGANIC' => array(
					'response' => array( 'code' => 302 ),
					'headers'  => array( 'location' => '/thule-chariot-sport-2-double-black.html' ),
				),
			);

			$result = $sku_search->discover_for_product(
				array( 'domain' => 'competitor.no', 'enabled' => 1 ),
				(object) array(
					'id'             => 7,
					'product_id'     => 101,
					'sku'            => '10101001',
					'normalized_sku' => '10101001',
				)
			);
			unset( $GLOBALS['lpm_test_http_responses'] );

			lpm_assert_true( $result['success'], 'Exact SKU search redirects should be queued for extraction even when the product slug does not contain the SKU.' );
			lpm_assert_same( array( 'https://competitor.no/thule-chariot-sport-2-double-black.html' ), $result['urls'], 'Safe same-domain product redirect should be returned as a candidate URL.' );
			lpm_assert_true( str_contains( $result['technical_details'], 'Search redirected to a possible product page' ), 'Redirect diagnostics should explain why the product URL was queued.' );
		},
		'Product-name search fallback tries shorter title variants' => static function () use ( $sku_search ): void {
			update_option(
				Settings::OPTION_NAME,
				array(
					'discovery_name_search_enabled'      => 1,
					'discovery_search_urls_per_sku'      => 4,
					'discovery_sku_search_url_templates' => '?s={query}',
					'discovery_product_url_patterns'     => 'produkt,product,p',
					'discovery_exclude_url_patterns'     => 'cart,checkout,account,login,filter,wp-admin,add-to-cart',
				)
			);
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://competitor.no/?s=10201031' => array(
					'body' => '<a href="/produkt/cybex-priam">Cybex Priam</a>',
				),
				'https://competitor.no/?s=197074564740' => array(
					'body' => '<a href="/produkt/cybex-priam">Cybex Priam</a>',
				),
				'https://competitor.no/?s=Thule%20Chariot%20Sport%202%20double%20Gen%203%202024%20midnight%20black' => array(
					'body' => '<a href="/produkt/cybex-priam">Cybex Priam</a>',
				),
				'https://competitor.no/?s=Thule%20Chariot%20Sport%202%20double%20midnight%20black' => array(
					'body' => '<a href="/produkt/cybex-priam">Cybex Priam</a>',
				),
				'https://competitor.no/?s=Thule%20Chariot%20Sport%202%20double%20midnight' => array(
					'body' => '<a href="/produkt/cybex-priam">Cybex Priam</a>',
				),
				'https://competitor.no/?s=Thule%20Chariot%20Sport%202%20double' => array(
					'body' => '<a href="/produkt/thule-chariot-sport-2-double-black">Thule Chariot Sport 2 double black</a><a href="/produkt/cybex-priam">Cybex Priam</a>',
				),
			);

			$result = $sku_search->discover_for_product(
				array( 'domain' => 'competitor.no', 'enabled' => 1 ),
				(object) array(
					'id'              => 7,
					'product_id'      => 101,
					'sku'             => '10201031',
					'normalized_sku'  => '10201031',
					'gtin'            => '197074564740',
					'normalized_gtin' => '197074564740',
					'product_name'    => 'Thule Chariot Sport 2 double (Gen 3 2024) - midnight black',
				)
			);
			unset( $GLOBALS['lpm_test_http_responses'] );

			lpm_assert_true( $result['success'], 'Name search should try shorter variants when the full product title is too exact.' );
			lpm_assert_same( array( 'https://competitor.no/produkt/thule-chariot-sport-2-double-black' ), $result['urls'], 'Shorter name search should queue the competitor product with a different color/title wording.' );
			lpm_assert_true( in_array( 'https://competitor.no/?s=Thule%20Chariot%20Sport%202%20double', $result['searched_urls'], true ), 'Search logs should show the shorter name URL that found the candidate.' );
		},
		'Search results pages that mention identifiers do not block name fallback' => static function () use ( $sku_search ): void {
			update_option(
				Settings::OPTION_NAME,
				array(
					'discovery_name_search_enabled'      => 1,
					'discovery_search_urls_per_sku'      => 4,
					'discovery_sku_search_url_templates' => '?s={query}&post_type=product, ?s={query}',
					'discovery_product_url_patterns'     => 'produkt,product,p',
					'discovery_exclude_url_patterns'     => 'cart,checkout,account,login,filter,wp-admin,add-to-cart',
				)
			);
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://competitor.no/?s=10201031&post_type=product' => array(
					'body' => '<html><head><title>10201031 - competitor.no</title></head><body>10201031 197074564740</body></html>',
				),
				'https://competitor.no/?s=10201031' => array(
					'body' => '<html><head><title>10201031 - competitor.no</title></head><body>10201031 197074564740</body></html>',
				),
				'https://competitor.no/?s=197074564740&post_type=product' => array(
					'body' => '<html><head><title>197074564740 - competitor.no</title></head><body>197074564740</body></html>',
				),
				'https://competitor.no/?s=197074564740' => array(
					'body' => '<html><head><title>197074564740 - competitor.no</title></head><body>197074564740</body></html>',
				),
				'https://competitor.no/?s=Thule%20Chariot%20Sport%202%20double%20Gen%203%202024%20midnight%20black&post_type=product' => array(
					'body' => '<a href="/produkt/cybex-priam">Cybex Priam</a>',
				),
				'https://competitor.no/?s=Thule%20Chariot%20Sport%202%20double%20Gen%203%202024%20midnight%20black' => array(
					'body' => '<a href="/produkt/cybex-priam">Cybex Priam</a>',
				),
				'https://competitor.no/?s=Thule%20Chariot%20Sport%202%20double%20midnight%20black&post_type=product' => array(
					'body' => '<a href="/produkt/cybex-priam">Cybex Priam</a>',
				),
				'https://competitor.no/?s=Thule%20Chariot%20Sport%202%20double%20midnight%20black' => array(
					'body' => '<a href="/produkt/thule-chariot-sport-2-double-black">Thule Chariot Sport 2 double black</a>',
				),
			);

			$result = $sku_search->discover_for_product(
				array( 'domain' => 'competitor.no', 'enabled' => 1 ),
				(object) array(
					'id'              => 7,
					'product_id'      => 101,
					'sku'             => '10201031',
					'normalized_sku'  => '10201031',
					'gtin'            => '197074564740',
					'normalized_gtin' => '197074564740',
					'product_name'    => 'Thule Chariot Sport 2 double (Gen 3 2024) - midnight black',
				)
			);
			unset( $GLOBALS['lpm_test_http_responses'] );

			lpm_assert_true( $result['success'], 'Identifier search result pages must not prevent the name fallback from trying title queries.' );
			lpm_assert_same( array( 'https://competitor.no/produkt/thule-chariot-sport-2-double-black' ), $result['urls'], 'The name fallback should find the product URL after identifier search pages fail to expose one.' );
			lpm_assert_true( in_array( 'https://competitor.no/?s=Thule%20Chariot%20Sport%202%20double%20midnight%20black', $result['searched_urls'], true ), 'Search logs should include the name URL that found the product.' );
			lpm_assert_true( str_contains( $result['technical_details'], 'Search results page mentioned SKU/EAN' ), 'Diagnostics should explain why identifier search pages were not accepted as product pages.' );
		},
		'Public Algolia product index can supply product URLs when HTML search is thin' => static function () use ( $sku_search ): void {
			update_option(
				Settings::OPTION_NAME,
				array(
					'discovery_name_search_enabled'      => 1,
					'discovery_search_urls_per_sku'      => 1,
					'discovery_sku_search_url_templates' => '?s={query}',
					'discovery_product_url_patterns'     => 'produkt,product,p',
					'discovery_exclude_url_patterns'     => 'cart,checkout,account,login,filter,wp-admin,add-to-cart',
				)
			);
			$algolia_config = '<script type="text/javascript">var algolia = {"application_id":"BTHP9JUMB1","search_api_key":"545bf09bb920b3fb99b0708f8055cc10","indices":{"posts_product":{"name":"wp_posts_product","id":"posts_product","enabled":true}},"autocomplete":{"sources":[{"index_id":"posts_product","index_name":"wp_posts_product","enabled":true}]}};</script>';
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://denlillebarnebutikken.no/?s=10201031' => array(
					'body' => '<html><head><title>10201031 - denlillebarnebutikken.no</title></head><body>' . $algolia_config . '10201031</body></html>',
				),
				'https://BTHP9JUMB1-dsn.algolia.net/1/indexes/wp_posts_product/query' => array(
					'body' => wp_json_encode(
						array(
							'hits' => array(
								array(
									'permalink'  => 'https://denlillebarnebutikken.no/product/thule-chariot-sport2-double-black/',
									'post_title' => 'Thule, Multisportsvogn, Chariot Sport 2, Double - Black',
									'sku'        => '10201031',
								),
							),
						)
					),
				),
			);

			$result = $sku_search->discover_for_product(
				array( 'domain' => 'denlillebarnebutikken.no', 'enabled' => 1 ),
				(object) array(
					'id'             => 7,
					'product_id'     => 101,
					'sku'            => '10201031',
					'normalized_sku' => '10201031',
					'product_name'   => 'Thule Chariot Sport 2 double midnight black',
				)
			);
			unset( $GLOBALS['lpm_test_http_responses'] );

			lpm_assert_true( $result['success'], 'Public Algolia product search should provide product URLs when the HTML search page has no links.' );
			lpm_assert_same( array( 'https://denlillebarnebutikken.no/product/thule-chariot-sport2-double-black/' ), $result['urls'], 'Algolia result permalink should be queued as the candidate product URL.' );
			lpm_assert_true( in_array( 'https://BTHP9JUMB1-dsn.algolia.net/1/indexes/wp_posts_product/query', $result['searched_urls'], true ), 'Search details should show that the public Algolia product index was queried without exposing the API key.' );
			lpm_assert_true( str_contains( $result['technical_details'], 'Algolia product search found possible product URLs' ), 'Diagnostics should explain the Algolia fallback.' );
		},
		'Name search queues redirects to product pages' => static function () use ( $sku_search ): void {
			update_option(
				Settings::OPTION_NAME,
				array(
					'discovery_name_search_enabled'      => 1,
					'discovery_search_urls_per_sku'      => 2,
					'discovery_sku_search_url_templates' => '?s={query}',
					'discovery_product_url_patterns'     => 'produkt,product,p,varer,vare',
					'discovery_exclude_url_patterns'     => 'cart,checkout,account,login,filter,wp-admin,add-to-cart',
				)
			);
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://competitor.no/?s=NO-HIT' => array(
					'body' => '<a href="/produkt/cybex-priam">Cybex Priam</a>',
				),
				'https://competitor.no/?s=Thule%20Chariot%20Sport%202%20double%20black' => array(
					'response' => array( 'code' => 302 ),
					'headers'  => array( 'location' => '/thule-chariot-sport-2-double-black.html' ),
				),
			);

			$result = $sku_search->discover_for_product(
				array( 'domain' => 'competitor.no', 'enabled' => 1 ),
				(object) array(
					'id'             => 7,
					'product_id'     => 101,
					'sku'            => 'NO-HIT',
					'normalized_sku' => 'NOHIT',
					'product_name'   => 'Thule Chariot Sport 2 double black',
				)
			);
			unset( $GLOBALS['lpm_test_http_responses'] );

			lpm_assert_true( $result['success'], 'Name-search redirects should be queued when the redirect target looks like a product page.' );
			lpm_assert_same( array( 'https://competitor.no/thule-chariot-sport-2-double-black.html' ), $result['urls'], 'Name redirect product page should be returned as a candidate URL.' );
			lpm_assert_true( in_array( 'https://competitor.no/?s=Thule%20Chariot%20Sport%202%20double%20black', $result['searched_urls'], true ), 'Search logs should include the name search redirect URL.' );
		},
		'EAN search can find candidates when SKU search has no hit' => static function () use ( $sku_search ): void {
			update_option(
				Settings::OPTION_NAME,
				array(
					'discovery_name_search_enabled'      => 0,
					'discovery_search_urls_per_sku'      => 1,
					'discovery_sku_search_url_templates' => '?s={query}',
					'discovery_product_url_patterns'     => 'produkt,product,p',
					'discovery_exclude_url_patterns'     => 'cart,checkout,account,login,filter,wp-admin,add-to-cart',
				)
			);
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://competitor.no/?s=10201031' => array(
					'body' => '<a href="/produkt/cybex-priam">Cybex Priam</a>',
				),
				'https://competitor.no/?s=197074564740' => array(
					'body' => '<a href="/produkt/thule-chariot-sport-2-double-midnight-black?ean=197074564740">Thule Chariot Sport 2</a>',
				),
			);

			$result = $sku_search->discover_for_product(
				array( 'domain' => 'competitor.no', 'enabled' => 1 ),
				(object) array(
					'id'              => 7,
					'product_id'      => 101,
					'sku'             => '10201031',
					'normalized_sku'  => '10201031',
					'gtin'            => '197074564740',
					'normalized_gtin' => '197074564740',
				)
			);
			unset( $GLOBALS['lpm_test_http_responses'] );

			lpm_assert_true( $result['success'], 'EAN/GTIN search should run after SKU search and find identifier-matching links.' );
			lpm_assert_same( array( 'https://competitor.no/produkt/thule-chariot-sport-2-double-midnight-black?ean=197074564740' ), $result['urls'], 'Only the EAN-matching product URL should be queued.' );
			lpm_assert_same( 2, $result['request_count'], 'SKU plus EAN search should stay bounded.' );
		},
		'Identifier search does not queue unrelated broad product links' => static function () use ( $sku_search ): void {
			update_option(
				Settings::OPTION_NAME,
				array(
					'discovery_name_search_enabled'      => 0,
					'discovery_search_urls_per_sku'      => 1,
					'discovery_sku_search_url_templates' => '?s={sku}',
					'discovery_product_url_patterns'     => 'produkt,product,p',
					'discovery_exclude_url_patterns'     => 'cart,checkout,account,login,filter,wp-admin,add-to-cart',
				)
			);
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://competitor.no/?s=10201031' => array(
					'body' => '<a href="/produkt/cybex-priam">Cybex Priam</a><a href="/produkt/talos-s-lux">Talos S Lux</a>',
				),
			);

			$result = $sku_search->discover_for_product(
				array( 'domain' => 'competitor.no', 'enabled' => 1 ),
				(object) array(
					'id'             => 7,
					'product_id'     => 101,
					'sku'            => '10201031',
					'normalized_sku' => '10201031',
				)
			);
			unset( $GLOBALS['lpm_test_http_responses'] );

			lpm_assert_true( ! $result['success'], 'Identifier search should not queue unrelated product-looking links.' );
			lpm_assert_same( array(), $result['urls'], 'Unrelated product links should be ignored until title fallback or a matching identifier is found.' );
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
		'Crawler stays bounded when many same-domain links are present' => static function () use ( $sku_search ): void {
			update_option(
				Settings::OPTION_NAME,
				array(
					'discovery_max_crawl_pages_per_run'  => 2,
					'discovery_max_crawl_candidate_urls' => 3,
					'discovery_product_url_patterns'     => 'produkt,product,p',
					'discovery_exclude_url_patterns'     => 'cart,checkout,account,login,filter,wp-admin,add-to-cart',
				)
			);
			$links = '';
			for ( $i = 1; $i <= 40; $i++ ) {
				$links .= '<a href="/kategori/side-' . $i . '">Category ' . $i . '</a><a href="/produkt/item-' . $i . '">Product ' . $i . '</a>';
			}
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://competitor.no/' => array(
					'body' => $links,
				),
				'https://competitor.no/kategori/side-1' => array(
					'body' => '<a href="/produkt/deeper-1">Deeper product</a>',
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
				2
			);
			unset( $GLOBALS['lpm_test_http_responses'] );

			lpm_assert_true( $result['request_count'] <= 2, 'Crawler should never exceed the explicit request budget.' );
			lpm_assert_true( count( $result['urls'] ) <= 3, 'Crawler should never exceed the configured candidate URL limit.' );
		},
	)
);
