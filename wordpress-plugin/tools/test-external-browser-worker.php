<?php
/**
 * Local tests for optional external browser worker integration.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require __DIR__ . '/test-bootstrap.php';

use Lilleprinsen\PriceMonitor\Service\DiscoverySourceService;
use Lilleprinsen\PriceMonitor\Service\DiscoveryUrlService;
use Lilleprinsen\PriceMonitor\Service\ExternalBrowserWorkerClient;
use Lilleprinsen\PriceMonitor\Service\SkuSearchDiscoveryService;
use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;
use Lilleprinsen\PriceMonitor\Settings\Settings;

function lpm_worker_settings(): array {
	return array(
		'external_browser_worker_enabled'         => 1,
		'external_browser_worker_endpoint'        => 'https://worker.test',
		'external_browser_worker_secret'          => 'secret',
		'external_browser_worker_timeout_seconds' => 10,
		'external_browser_worker_max_candidates'  => 5,
		'discovery_same_domain_only'              => 1,
		'discovery_request_timeout'               => 2,
		'discovery_search_urls_per_sku'           => 2,
		'discovery_name_search_enabled'           => 1,
		'discovery_sku_search_url_templates'      => array( 'https://{domain}/search?q={query}' ),
		'discovery_allow_ports'                   => array( 80, 443 ),
		'discovery_exclude_url_patterns'          => array(),
		'discovery_product_url_patterns'          => array(),
	);
}

function lpm_worker_competitor(): array {
	return array(
		'id'                  => 10,
		'name'                => 'JS Shop',
		'domain'              => 'competitor.test',
		'requires_javascript' => 1,
		'notes'               => wp_json_encode(
			array(
				'external_browser_worker_mode'            => 'js',
				'external_browser_worker_search_enabled'  => true,
				'external_browser_worker_product_enabled' => true,
				'search_url_templates'                    => array( 'https://{domain}/search?q={query}' ),
			)
		),
	);
}

lpm_run_tests(
	'External browser worker',
	array(
		'is_enabled reflects stored global worker settings' => function (): void {
			$GLOBALS['lpm_test_options'] = array(
				Settings::OPTION_NAME => lpm_worker_settings(),
			);
			$client = new ExternalBrowserWorkerClient();

			lpm_assert_true( $client->is_enabled(), 'Worker interface enabled check should use stored global settings.' );
			unset( $GLOBALS['lpm_test_options'] );
		},
		'signs worker search request and returns same-domain candidates' => function (): void {
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://worker.test/v1/search' => static function ( string $url, array $args ): array {
					unset( $url );
					$headers = $args['headers'] ?? array();
					$body    = (string) ( $args['body'] ?? '' );
					lpm_assert_true( ! empty( $headers['X-LPM-Signature'] ), 'Worker request should include HMAC signature.' );
					lpm_assert_same( hash( 'sha256', $body ), $headers['X-LPM-Body-SHA256'] ?? '', 'Worker request body hash should match.' );

					return array(
						'response' => array( 'code' => 200 ),
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'success'     => true,
								'candidates'  => array(
									array( 'url' => 'https://competitor.test/product/thule-bag-black' ),
									array( 'url' => 'https://other.test/product/wrong' ),
								),
								'diagnostics' => array( 'Rendered search page.' ),
							)
						),
					);
				},
			);
			$client = new ExternalBrowserWorkerClient();
			$result = $client->search(
				'https://competitor.test/search?q=20110754',
				lpm_worker_settings(),
				lpm_worker_competitor(),
				(object) array(
					'id'           => 1,
					'sku'          => '20110754',
					'gtin'         => '872299049660',
					'product_name' => 'Thule Urban Glide 3 bassinet black',
				)
			);

			lpm_assert_true( $result['success'], 'Worker search should succeed.' );
			lpm_assert_same( array( 'https://competitor.test/product/thule-bag-black' ), $result['urls'], 'Worker client should keep only same-domain candidate URLs.' );
		},
		'worker product request sends expected product identifiers and sanitizes price candidates' => function (): void {
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://worker.test/v1/product' => static function ( string $url, array $args ): array {
					unset( $url );
					$payload = json_decode( (string) ( $args['body'] ?? '{}' ), true );
					lpm_assert_same( '20110754', (string) ( $payload['expected']['sku'] ?? '' ), 'Expected SKU should be sent to worker product endpoint.' );
					lpm_assert_same( '872299049660', (string) ( $payload['expected']['ean'] ?? '' ), 'Expected EAN should be sent to worker product endpoint.' );

					return array(
						'response' => array( 'code' => 200 ),
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'success'         => true,
								'url'             => 'https://competitor.test/product/one',
								'title'           => 'Rendered Product',
								'monitored_price' => '3 499,00',
								'currency'        => 'NOK',
								'price_candidates'=> array(
									array( 'value' => '3 499,00', 'source' => 'Rendered visible text<script>', 'field' => 'sale_price' ),
									array( 'value' => 'not a price', 'source' => 'bad' ),
								),
							)
						),
					);
				},
			);
			$client = new ExternalBrowserWorkerClient();
			$result = $client->product(
				'https://competitor.test/product/one',
				lpm_worker_settings(),
				lpm_worker_competitor(),
				array(
					'sku'   => '20110754',
					'ean'   => '872299049660',
					'title' => 'Thule Bag',
					'brand' => 'Thule',
				)
			);

			lpm_assert_true( $result['success'], 'Worker product extraction should succeed.' );
			lpm_assert_same( 1, count( $result['price_candidates'] ), 'Invalid worker price candidates should be discarded.' );
			lpm_assert_same( 'renderedvisibletextscript', $result['price_candidates'][0]['source'], 'Worker candidate source should be sanitized.' );
		},
		'malformed worker response fails safely' => function (): void {
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://worker.test/v1/product' => array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => 'not json',
				),
			);
			$client = new ExternalBrowserWorkerClient();
			$result = $client->product( 'https://competitor.test/product/one', lpm_worker_settings(), lpm_worker_competitor() );

			lpm_assert_true( empty( $result['success'] ), 'Malformed worker JSON should not succeed.' );
			lpm_assert_contains( 'malformed JSON', (string) $result['technical_details'], 'Malformed response should be visible in diagnostics.' );
		},
		'SKU discovery can use worker candidates without auto approval' => function (): void {
			$GLOBALS['lpm_test_options'] = array(
				Settings::OPTION_NAME => lpm_worker_settings(),
			);
			$GLOBALS['lpm_test_http_responses'] = array(
				'https://worker.test/v1/search' => array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'success'     => true,
							'candidates'  => array(
								array(
									'url'   => 'https://competitor.test/product/thule-urban-glide-3-bag-black',
									'title' => 'Bag, Thule, Black',
									'price' => 'kr 3 499,00',
								),
							),
							'diagnostics' => array( 'External rendered cards.' ),
						)
					),
				),
			);
			$url_service = new DiscoveryUrlService();
			$settings = new DiscoverySettings( new Settings() );
			$sku_search = new SkuSearchDiscoveryService( $url_service, new DiscoverySourceService( $url_service, $settings ), $settings, new ExternalBrowserWorkerClient( $url_service ) );

			$result = $sku_search->discover_for_product(
				lpm_worker_competitor(),
				(object) array(
					'id'           => 7,
					'sku'          => '20110754',
					'gtin'         => '872299049660',
					'product_name' => 'Thule Urban Glide 3 bassinet black on black',
				)
			);

			lpm_assert_true( $result['success'], 'Worker discovery should return candidate URLs.' );
			lpm_assert_contains( 'External browser worker rendered search page', $result['technical_details'], 'Worker diagnostics should be retained.' );
			lpm_assert_same( 'worker', $result['winning_search_type'], 'Worker-sourced candidate should be labeled for admin diagnostics.' );
			$stats = get_option( 'lpm_discovery_search_strategy_stats', array() );
			lpm_assert_same( 1, (int) ( $stats['id:10']['worker']['successes'] ?? 0 ), 'Worker-sourced discovery should update adaptive worker stats.' );
		},
	)
);
