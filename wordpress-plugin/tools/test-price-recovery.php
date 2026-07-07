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
	'recovery_max_competitor_price_age_hours' => 48,
	'max_allowed_price_increase_percent' => 50,
);

$session = array(
	'original_regular_price' => 1499,
	'original_sale_price'    => 1299,
	'original_active_price'  => 1299,
	'matched_price'          => 1199,
	'status'                 => 'active',
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
		'primary competitor recovery' => static function () use ( $service, $settings, $session ): void {
			$result = $service->determine_recovery_suggestion(
				1199,
				1599,
				$session,
				array_merge( $settings, array( 'multiple_competitor_recovery_basis' => 'primary_competitor' ) ),
				array(
					array(
						'enabled'         => 1,
						'is_primary'      => 1,
						'match_type'      => 'exact',
						'last_price'      => 1599,
						'last_checked_at' => gmdate( 'Y-m-d H:i:s' ),
					),
				)
			);

			lpm_assert_same( 'restore_previous_regular_price', $result['suggestion_type'], 'Fresh primary competitor should allow configured recovery.' );
			lpm_assert_same( 'pending', $result['status'], 'Primary competitor recovery should be pending.' );
		},
		'primary competitor missing triggers manual review' => static function () use ( $service, $settings, $session ): void {
			$result = $service->determine_recovery_suggestion(
				1199,
				1599,
				$session,
				array_merge( $settings, array( 'multiple_competitor_recovery_basis' => 'primary_competitor' ) ),
				array()
			);

			lpm_assert_same( 'manual_review', $result['suggestion_type'], 'Missing primary competitor should require manual review.' );
			lpm_assert_same( 'pending', $result['status'], 'Missing primary manual review should be pending.' );
		},
		'all competitors must increase' => static function () use ( $service, $settings, $session ): void {
			$result = $service->determine_recovery_suggestion(
				1199,
				1599,
				$session,
				array_merge( $settings, array( 'multiple_competitor_recovery_basis' => 'all_competitors_must_increase' ) ),
				array(
					array(
						'enabled'         => 1,
						'match_type'      => 'exact',
						'last_price'      => 1600,
						'last_checked_at' => gmdate( 'Y-m-d H:i:s' ),
					),
					array(
						'enabled'         => 1,
						'match_type'      => 'similar',
						'last_price'      => 1510,
						'last_checked_at' => gmdate( 'Y-m-d H:i:s' ),
					),
					array(
						'enabled'    => 1,
						'match_type' => 'not_comparable',
						'last_price' => 900,
					),
				)
			);

			lpm_assert_same( 'restore_previous_regular_price', $result['suggestion_type'], 'All fresh exact/similar competitors above proposed price should allow recovery.' );
			lpm_assert_same( 'pending', $result['status'], 'All-competitor recovery should be pending.' );
		},
		'all competitors lower price blocks recovery' => static function () use ( $service, $settings, $session ): void {
			$result = $service->determine_recovery_suggestion(
				1199,
				1599,
				$session,
				array_merge( $settings, array( 'multiple_competitor_recovery_basis' => 'all_competitors_must_increase' ) ),
				array(
					array(
						'enabled'         => 1,
						'match_type'      => 'similar',
						'last_price'      => 1290,
						'last_checked_at' => gmdate( 'Y-m-d H:i:s' ),
					),
				)
			);

			lpm_assert_same( 'manual_review', $result['suggestion_type'], 'Lower exact/similar competitor should stop recovery.' );
			lpm_assert_same( 'skipped', $result['status'], 'Lower exact/similar competitor should skip recovery suggestion.' );
		},
		'stale primary competitor data triggers manual review' => static function () use ( $service, $settings, $session ): void {
			$result = $service->determine_recovery_suggestion(
				1199,
				1599,
				$session,
				array_merge( $settings, array( 'multiple_competitor_recovery_basis' => 'primary_competitor' ) ),
				array(
					array(
						'enabled'         => 1,
						'is_primary'      => 1,
						'match_type'      => 'exact',
						'last_price'      => 1599,
						'last_checked_at' => gmdate( 'Y-m-d H:i:s', time() - ( 72 * HOUR_IN_SECONDS ) ),
					),
				)
			);

			lpm_assert_same( 'manual_review', $result['suggestion_type'], 'Stale primary data should require manual review.' );
			lpm_assert_same( 'pending', $result['status'], 'Stale primary manual review should be pending.' );
		},
		'manual product price change triggers manual review' => static function () use ( $service, $settings, $session ): void {
			$result = $service->determine_recovery_suggestion( 1250, 1599, $session, $settings );

			lpm_assert_same( 'manual_review', $result['suggestion_type'], 'Manual product price drift should require manual review.' );
			lpm_assert_same( 'pending', $result['status'], 'Manual product price drift should be pending manual review.' );
			lpm_assert_contains( 'changed since the price match session', $result['reason'], 'Manual price drift should be explained.' );
		},
	)
);
