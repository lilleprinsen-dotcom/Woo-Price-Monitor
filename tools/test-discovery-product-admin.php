<?php
/**
 * Local tests for product edit discovery admin helpers.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';

use Lilleprinsen\PriceMonitor\Admin\DiscoveryProductAdmin;
use Lilleprinsen\PriceMonitor\Admin\DiscoveryAdminPage;

$method = new ReflectionMethod( DiscoveryProductAdmin::class, 'monitored_product_id_from_result' );

lpm_run_tests(
	'DiscoveryProductAdmin',
	array(
		'Manual URL flow uses existing monitored product ID' => static function () use ( $method ): void {
			$id = $method->invoke( null, array( 'id' => 44 ), array() );

			lpm_assert_same( 44, $id, 'Existing monitored product ID should be used directly.' );
		},
		'Manual URL flow extracts ID from add_monitored_product result array' => static function () use ( $method ): void {
			$id = $method->invoke(
				null,
				null,
				array(
					'success' => true,
					'code'    => 'monitoring_added',
					'id'      => 55,
				)
			);

			lpm_assert_same( 55, $id, 'Created monitored product ID should come from the result array id key.' );
		},
		'Manual URL flow rejects failed add_monitored_product result arrays' => static function () use ( $method ): void {
			$id = $method->invoke(
				null,
				null,
				array(
					'success' => false,
					'code'    => 'monitoring_add_failed',
					'id'      => 77,
				)
			);

			lpm_assert_same( 0, $id, 'Failed add result must not be treated as a raw integer monitored ID.' );
		},
		'Search setup derives template from pasted competitor search URL' => static function (): void {
			$templates = DiscoveryAdminPage::normalize_search_template_inputs(
				'',
				'https://competitor.no/search?q=10201031&sort=popular',
				'10201031'
			);

			lpm_assert_same( array( 'https://competitor.no/search?q={query}&sort=popular' ), $templates, 'Pasted search URL should become a reusable query template.' );
		},
		'Search setup derives Babycare template from the searched value' => static function (): void {
			$templates = DiscoveryAdminPage::normalize_search_template_inputs(
				'',
				'https://www.babycare.no/catalogsearch/result/?q=10101001&origin=ORGANIC',
				'10101001'
			);

			lpm_assert_same( array( 'https://www.babycare.no/catalogsearch/result/?q={query}&origin=ORGANIC' ), $templates, 'Magento-style pasted URLs should save the exact competitor search template with a reusable placeholder.' );
		},
		'Search setup preserves explicit placeholders in absolute URLs' => static function (): void {
			$templates = DiscoveryAdminPage::normalize_search_template_inputs(
				'',
				'https://www.babycare.no/catalogsearch/result/?q={query}&origin=ORGANIC',
				''
			);

			lpm_assert_same( array( 'https://www.babycare.no/catalogsearch/result/?q={query}&origin=ORGANIC' ), $templates, 'Explicit {query} placeholders should not be URL-encoded or removed.' );
		},
		'Search setup keeps advanced templates and removes invalid values' => static function (): void {
			$templates = DiscoveryAdminPage::normalize_search_template_inputs(
				'?s={sku}, ignored-without-placeholder, finn?q={ean}',
				'',
				''
			);

			lpm_assert_same( array( '?s={sku}', 'finn?q={ean}' ), $templates, 'Only templates with supported placeholders should be saved.' );
		},
	)
);
