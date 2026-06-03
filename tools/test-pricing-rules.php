<?php
/**
 * Local tests for PricingRuleService.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';

use Lilleprinsen\PriceMonitor\Service\PricingRuleService;

$service = new PricingRuleService();

$settings = array(
	'default_currency'                    => 'NOK',
	'default_pricing_strategy'            => 'match_competitor',
	'beat_competitor_amount'              => 1,
	'stay_above_competitor_amount'        => 1,
	'rounding_mode'                       => 'none',
	'cost_source'                         => 'none',
	'block_if_cost_missing'               => 0,
	'minimum_profit_amount'               => '',
	'default_min_margin_percent'          => '',
	'price_comparison_vat_mode'           => 'consumer_prices_include_vat',
	'vat_rate_percent'                    => 25,
	'min_price_difference_to_suggest'     => 0,
	'max_allowed_price_drop_percent'      => 25,
	'max_allowed_price_increase_percent'  => 50,
	'block_suggestions_for_sale_products' => 0,
	'block_suggestions_for_out_of_stock_products' => 0,
);

$base_context = array(
	'product_id'             => 123,
	'current_price'          => 1299,
	'competitor_price'       => 1199,
	'suggestion_type'        => 'price_match_down',
	'monitored_product'      => array(),
	'active_price_match_session' => array(),
	'currency'               => 'NOK',
);

lpm_run_tests(
	'PricingRuleService',
	array(
		'match competitor suggests competitor price' => static function () use ( $service, $settings, $base_context ): void {
			$result = $service->calculate_suggestion( $base_context, $settings );

			lpm_assert_same( 'pending', $result['status'], 'Match strategy should be pending.' );
			lpm_assert_float_equals( 1199, $result['suggested_price'], 'Match strategy should use competitor price.' );
		},
		'beat competitor by amount' => static function () use ( $service, $settings, $base_context ): void {
			$result = $service->calculate_suggestion(
				$base_context,
				array_merge(
					$settings,
					array(
						'default_pricing_strategy' => 'beat_competitor_by_amount',
						'beat_competitor_amount'   => 1,
					)
				)
			);

			lpm_assert_same( 'pending', $result['status'], 'Beat strategy should be pending.' );
			lpm_assert_float_equals( 1198, $result['suggested_price'], 'Beat strategy should subtract configured amount.' );
		},
		'stay above competitor by amount' => static function () use ( $service, $settings, $base_context ): void {
			$result = $service->calculate_suggestion(
				$base_context,
				array_merge(
					$settings,
					array(
						'default_pricing_strategy'     => 'stay_above_competitor_by_amount',
						'stay_above_competitor_amount' => 1,
					)
				)
			);

			lpm_assert_same( 'pending', $result['status'], 'Stay-above strategy should be pending.' );
			lpm_assert_float_equals( 1200, $result['suggested_price'], 'Stay-above strategy should add configured amount.' );
		},
		'end_99 rounding' => static function () use ( $service, $settings, $base_context ): void {
			$result = $service->calculate_suggestion(
				array_merge( $base_context, array( 'competitor_price' => 1183 ) ),
				array_merge( $settings, array( 'rounding_mode' => 'end_99' ) )
			);

			lpm_assert_same( 'pending', $result['status'], 'Rounded suggestion should be pending.' );
			lpm_assert_float_equals( 1199, $result['suggested_price'], 'end_99 should round to nearest 99 ending.' );
			lpm_assert_contains( 'Rounding changed', $result['reason'], 'Rounding should be explained.' );
		},
		'min price blocks current implementation' => static function () use ( $service, $settings, $base_context ): void {
			$result = $service->calculate_suggestion(
				array_merge(
					$base_context,
					array(
						'competitor_price'  => 1090,
						'monitored_product' => array( 'min_price' => 1190 ),
					)
				),
				$settings
			);

			lpm_assert_same( 'blocked', $result['status'], 'Current implementation blocks below product minimum price.' );
			lpm_assert_float_equals( 1090, $result['suggested_price'], 'Blocked result keeps the calculated price for review.' );
			lpm_assert_contains( 'below the product minimum price', $result['reason'], 'Min price block should be explained.' );
		},
		'minimum margin blocks low-margin suggestion' => static function () use ( $service, $settings, $base_context ): void {
			$result = $service->calculate_suggestion(
				array_merge(
					$base_context,
					array(
						'competitor_price' => 1099,
						'product_cost'     => 1000,
					)
				),
				array_merge( $settings, array( 'default_min_margin_percent' => 25 ) )
			);

			lpm_assert_same( 'blocked', $result['status'], 'Low-margin suggestion should be blocked.' );
			lpm_assert_float_equals( 9.01, $result['margin_after_change'], 'Margin after change should be calculated.' );
			lpm_assert_contains( 'below the minimum margin', $result['reason'], 'Margin block should be explained.' );
		},
		'missing cost can block suggestion' => static function () use ( $service, $settings, $base_context ): void {
			$result = $service->calculate_suggestion(
				$base_context,
				array_merge(
					$settings,
					array(
						'cost_source'           => 'custom_meta_key',
						'block_if_cost_missing' => 1,
					)
				)
			);

			lpm_assert_same( 'blocked', $result['status'], 'Missing required cost should block.' );
			lpm_assert_contains( 'no valid cost', $result['reason'], 'Missing cost block should be explained.' );
		},
		'max drop percent blocks suspicious drop' => static function () use ( $service, $settings, $base_context ): void {
			$result = $service->calculate_suggestion(
				array_merge( $base_context, array( 'competitor_price' => 800 ) ),
				$settings
			);

			lpm_assert_same( 'blocked', $result['status'], 'Large price drop should be blocked.' );
			lpm_assert_contains( 'exceeds the configured maximum', $result['reason'], 'Max drop block should be explained.' );
		},
		'max increase percent blocks suspicious increase' => static function () use ( $service, $settings ): void {
			$result = $service->calculate_suggestion(
				array(
					'product_id'        => 123,
					'current_price'     => 100,
					'competitor_price'  => 200,
					'suggestion_type'   => 'manual_review',
					'monitored_product' => array(),
					'currency'          => 'NOK',
				),
				$settings
			);

			lpm_assert_same( 'blocked', $result['status'], 'Large increase should be blocked even from manual review.' );
			lpm_assert_contains( 'Price increase', $result['reason'], 'Max increase block should be explained.' );
		},
	)
);
