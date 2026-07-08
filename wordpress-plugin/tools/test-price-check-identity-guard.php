<?php
/**
 * Local tests for competitor link identity drift guard.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';
require_once LPM_TEST_ROOT . '/src/Service/PriceParser.php';
require_once LPM_TEST_ROOT . '/src/Service/PriceCheckService.php';

use Lilleprinsen\PriceMonitor\Service\PriceCheckService;

lpm_run_tests(
	'PriceCheckService identity guard',
	array(
		'stores first observed identity when baseline is missing' => static function (): void {
			$result = PriceCheckService::evaluate_identity_guard(
				array( 'identity_guard_enabled' => 1 ),
				array( 'sku' => 'ABC-123', 'gtin' => '', 'mpn' => '', 'title' => '' )
			);

			lpm_assert_true( ! $result['drift_detected'], 'Missing baseline should not drift.' );
			lpm_assert_true( $result['should_store_baseline'], 'First observed identifier should be stored as baseline.' );
			lpm_assert_same( 'abc123', $result['observed_identity']['sku'], 'SKU baseline should be normalized.' );
		},
		'blocks changed SKU after approval' => static function (): void {
			$result = PriceCheckService::evaluate_identity_guard(
				array(
					'identity_guard_enabled' => 1,
					'approved_sku'           => 'ABC-123',
				),
				array( 'sku' => 'XYZ-999', 'gtin' => '', 'mpn' => '', 'title' => '' )
			);

			lpm_assert_true( $result['drift_detected'], 'Changed SKU should trigger drift.' );
			lpm_assert_contains( 'SKU changed', $result['reason'], 'Reason should identify SKU drift.' );
		},
		'blocks changed EAN GTIN after approval' => static function (): void {
			$result = PriceCheckService::evaluate_identity_guard(
				array(
					'identity_guard_enabled' => 1,
					'approved_gtin'          => '872299049660',
				),
				array( 'sku' => '', 'gtin' => '7040000000001', 'mpn' => '', 'title' => '' )
			);

			lpm_assert_true( $result['drift_detected'], 'Changed GTIN should trigger drift.' );
			lpm_assert_contains( 'GTIN changed', $result['reason'], 'Reason should identify GTIN drift.' );
		},
		'does not block when competitor stops exposing SKU temporarily' => static function (): void {
			$result = PriceCheckService::evaluate_identity_guard(
				array(
					'identity_guard_enabled' => 1,
					'approved_sku'           => 'ABC-123',
				),
				array( 'sku' => '', 'gtin' => '', 'mpn' => '', 'title' => '' )
			);

			lpm_assert_true( ! $result['drift_detected'], 'Missing observed identifiers should not alone block price checks.' );
			lpm_assert_true( ! $result['should_store_baseline'], 'Existing baseline should not be overwritten by missing identifiers.' );
		},
		'title only baseline can detect likely page replacement' => static function (): void {
			$approved_hash = hash( 'sha256', 'thule chariot sport 2 double' );
			$result = PriceCheckService::evaluate_identity_guard(
				array(
					'identity_guard_enabled' => 1,
					'approved_title_hash'    => $approved_hash,
				),
				array( 'sku' => '', 'gtin' => '', 'mpn' => '', 'title' => 'Easygrow Cover Me Stormtrekk' )
			);

			lpm_assert_true( $result['drift_detected'], 'Title-only baseline should detect a changed title when no identifiers exist.' );
			lpm_assert_contains( 'Product title changed', $result['reason'], 'Reason should explain title drift.' );
		},
		'identity guard can be disabled per link' => static function (): void {
			$result = PriceCheckService::evaluate_identity_guard(
				array(
					'identity_guard_enabled' => 0,
					'approved_sku'           => 'ABC-123',
				),
				array( 'sku' => 'XYZ-999', 'gtin' => '', 'mpn' => '', 'title' => '' )
			);

			lpm_assert_true( ! $result['drift_detected'], 'Disabled identity guard should not block.' );
		},
	)
);
