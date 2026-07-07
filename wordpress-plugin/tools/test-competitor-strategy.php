<?php
/**
 * Local tests for competitor strategy detection.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';
require_once LPM_TEST_ROOT . '/src/Service/CompetitorStrategyService.php';

use Lilleprinsen\PriceMonitor\Service\CompetitorStrategyService;

/**
 * @return array<string,mixed>
 */
function lpm_strategy_observation( int $link_id, int $competitor_id, string $name, int $product_id, float $price, string $checked_at ): array {
	return array(
		'competitor_link_id'  => $link_id,
		'competitor_id'       => $competitor_id,
		'competitor_name'     => $name,
		'monitored_product_id' => $product_id,
		'product_id'          => 1000 + $product_id,
		'observed_price'      => $price,
		'success'             => 1,
		'checked_at'          => $checked_at,
	);
}

/**
 * @param array<string,mixed> $summary Strategy summary.
 * @return array<string,mixed>
 */
function lpm_strategy_row( array $summary, string $name ): array {
	foreach ( $summary['competitors'] as $row ) {
		if ( $name === (string) $row['competitor_name'] ) {
			return $row;
		}
	}

	return array();
}

lpm_run_tests(
	'CompetitorStrategyService',
	array(
		'detects price-drop leaders and market followers' => static function (): void {
			$service = new CompetitorStrategyService();
			$rows    = array(
				lpm_strategy_observation( 10, 1, 'Leader Store', 1, 1000, '2026-01-01 08:00:00' ),
				lpm_strategy_observation( 10, 1, 'Leader Store', 1, 900, '2026-01-01 09:00:00' ),
				lpm_strategy_observation( 20, 2, 'Follower Store', 1, 1000, '2026-01-01 08:00:00' ),
				lpm_strategy_observation( 20, 2, 'Follower Store', 1, 900, '2026-01-02 09:00:00' ),
				lpm_strategy_observation( 11, 1, 'Leader Store', 2, 1200, '2026-01-03 08:00:00' ),
				lpm_strategy_observation( 11, 1, 'Leader Store', 2, 1050, '2026-01-03 09:00:00' ),
				lpm_strategy_observation( 21, 2, 'Follower Store', 2, 1200, '2026-01-03 08:00:00' ),
				lpm_strategy_observation( 21, 2, 'Follower Store', 2, 1050, '2026-01-04 09:00:00' ),
			);

			$summary  = $service->analyze( $rows );
			$leader   = lpm_strategy_row( $summary, 'Leader Store' );
			$follower = lpm_strategy_row( $summary, 'Follower Store' );

			lpm_assert_same( 'price_drop_leader', $leader['strategy'] ?? '', 'Leader store should be classified as a price-drop leader.' );
			lpm_assert_same( 'market_follower', $follower['strategy'] ?? '', 'Follower store should be classified as a market follower.' );
			lpm_assert_same( 1, (int) $summary['leaders'], 'Summary should count one leader.' );
			lpm_assert_same( 1, (int) $summary['followers'], 'Summary should count one follower.' );
		},
		'detects temporary campaign runners' => static function (): void {
			$service = new CompetitorStrategyService();
			$rows    = array(
				lpm_strategy_observation( 30, 3, 'Campaign Store', 3, 1000, '2026-02-01 08:00:00' ),
				lpm_strategy_observation( 30, 3, 'Campaign Store', 3, 850, '2026-02-01 09:00:00' ),
				lpm_strategy_observation( 30, 3, 'Campaign Store', 3, 995, '2026-02-03 09:00:00' ),
				lpm_strategy_observation( 31, 3, 'Campaign Store', 4, 1500, '2026-02-05 08:00:00' ),
				lpm_strategy_observation( 31, 3, 'Campaign Store', 4, 1299, '2026-02-05 09:00:00' ),
				lpm_strategy_observation( 31, 3, 'Campaign Store', 4, 1490, '2026-02-08 09:00:00' ),
			);

			$summary  = $service->analyze( $rows );
			$campaign = lpm_strategy_row( $summary, 'Campaign Store' );

			lpm_assert_same( 'campaign_runner', $campaign['strategy'] ?? '', 'Temporary recoveries should classify the competitor as a campaign runner.' );
			lpm_assert_same( 2, (int) ( $campaign['campaign_events'] ?? 0 ), 'Both drops should be counted as campaign events.' );
			lpm_assert_same( 1, (int) $summary['campaign_runners'], 'Summary should count one campaign runner.' );
		},
		'keeps sparse data unclassified' => static function (): void {
			$service = new CompetitorStrategyService();
			$summary = $service->analyze(
				array(
					lpm_strategy_observation( 40, 4, 'Sparse Store', 5, 999, '2026-03-01 08:00:00' ),
					lpm_strategy_observation( 40, 4, 'Sparse Store', 5, 929, '2026-03-01 09:00:00' ),
				)
			);
			$row     = lpm_strategy_row( $summary, 'Sparse Store' );

			lpm_assert_same( 'not_enough_data', $row['strategy'] ?? '', 'A single drop should not produce a confident strategy label.' );
			lpm_assert_contains( 'At least two', $row['explanation'] ?? '', 'Sparse data should explain why no strategy was assigned.' );
		},
	)
);
