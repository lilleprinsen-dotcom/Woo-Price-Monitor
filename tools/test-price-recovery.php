<?php
/**
 * Local tests for PriceRecoveryService.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';

use Lilleprinsen\PriceMonitor\Service\PriceRecoveryService;

$service = new PriceRecoveryService();

$settings = array(
	'recovery_when_competitor_increases' => 'suggest_only',
	'recovery_if_competitor_still_below_previous_sale_price' => 'suggest_match_competitor',
	'recovery_if_competitor_above_previous_regular_price' => 'suggest_restore_previous_regular_price',
	'multiple_competitor_recovery_basis' => 'lowest_valid_competitor',
);

$session = array(
	'original_regular_price' => 1499,
	'original_sale_price'    => 1299,
	'matched_price'          => 1199,
);

lpm_run_tests(
	'PriceRecoveryService',
	array(
		'competitor increases below previous sale price' => static function () use ( $service, $settings, $session ): void {
			$result = $service->determine_recovery_suggestion( 1199, 1249, $session, $settings );

			lpm_assert_same( 'price_match_up', $result['suggestion_type'], 'Below-sale recovery should suggest price match up.' );
			lpm_assert_same( 'pending', $result['status'], 'Below-sale recovery should be pending.' );
			lpm_assert_float_equals( 1249, $result['suggested_price'], 'Below-sale recovery should match competitor.' );
		},
		'competitor increases between sale and regular price' => static function () use ( $service, $settings, $session ): void {
			$result = $service->determine_recovery_suggestion(
				1199,
				1349,
				$session,
				array_merge( $settings, array( 'recovery_when_competitor_increases' => 'suggest_restore_previous_sale_price' ) )
			);

			lpm_assert_same( 'restore_previous_sale_price', $result['suggestion_type'], 'Between-sale-and-regular recovery should restore sale price when configured.' );
			lpm_assert_same( 'pending', $result['status'], 'Restore sale recovery should be pending.' );
			lpm_assert_float_equals( 1299, $result['suggested_price'], 'Restore sale recovery should use original sale price.' );
		},
		'competitor increases above previous regular price' => static function () use ( $service, $settings, $session ): void {
			$result = $service->determine_recovery_suggestion( 1199, 1599, $session, $settings );

			lpm_assert_same( 'restore_previous_regular_price', $result['suggestion_type'], 'Above-regular recovery should restore regular price.' );
			lpm_assert_same( 'pending', $result['status'], 'Restore regular recovery should be pending.' );
			lpm_assert_float_equals( 1499, $result['suggested_price'], 'Restore regular recovery should use original regular price.' );
		},
		'no active price match session state' => static function () use ( $service, $settings ): void {
			$result = $service->determine_recovery_suggestion( 1199, 1249, array(), $settings );

			lpm_assert_same( 'manual_review', $result['suggestion_type'], 'Missing session state should require manual review.' );
			lpm_assert_same( 'pending', $result['status'], 'Missing session manual review should be pending.' );
			lpm_assert_float_equals( 1199, $result['suggested_price'], 'Missing session should keep current price.' );
		},
		'another competitor still lower than proposed recovery price' => static function () use ( $service, $settings, $session ): void {
			$result = $service->determine_recovery_suggestion(
				1199,
				1599,
				$session,
				$settings,
				array(
					array(
						'enabled'    => 1,
						'last_price' => 1290,
					),
				)
			);

			lpm_assert_same( 'manual_review', $result['suggestion_type'], 'Lower active competitor should stop automatic recovery plan.' );
			lpm_assert_same( 'skipped', $result['status'], 'Lower active competitor should skip suggestion.' );
			lpm_assert_float_equals( 1199, $result['suggested_price'], 'Skipped recovery should keep current price.' );
		},
		'missing original sale price' => static function () use ( $service, $settings ): void {
			$result = $service->determine_recovery_suggestion(
				1199,
				1349,
				array(
					'original_regular_price' => 1499,
					'matched_price'          => 1199,
				),
				$settings
			);

			lpm_assert_same( 'manual_review', $result['suggestion_type'], 'Missing sale price should require manual review for between-range recovery.' );
			lpm_assert_same( 'pending', $result['status'], 'Missing sale price manual review should be pending.' );
		},
		'missing original regular price' => static function () use ( $service, $settings ): void {
			$result = $service->determine_recovery_suggestion(
				1199,
				1599,
				array(
					'original_sale_price' => 1299,
					'matched_price'       => 1199,
				),
				$settings
			);

			lpm_assert_same( 'manual_review', $result['suggestion_type'], 'Missing regular price should require manual review for above-regular recovery.' );
			lpm_assert_same( 'pending', $result['status'], 'Missing regular price manual review should be pending.' );
		},
	)
);
