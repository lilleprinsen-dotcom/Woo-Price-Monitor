<?php
/**
 * Local tests for manual live discovery helpers.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';

use Lilleprinsen\PriceMonitor\Service\ManualDiscoveryService;

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post_id ) {
		return $GLOBALS['lpm_test_titles'][ (int) $post_id ] ?? 'Product ' . (int) $post_id;
	}
}

lpm_run_tests(
	'ManualDiscoveryService',
	array(
		'Manual discovery job creation builds selected product and active competitor pairs' => static function (): void {
			$GLOBALS['lpm_test_titles'][101] = 'Selected Stroller';
			$pairs = ManualDiscoveryService::build_run_pairs(
				array(
					(object) array(
						'id' => 7,
						'product_id' => 101,
						'variation_id' => 0,
						'sku' => 'SKU-101',
						'gtin' => '7040000000101',
						'brand' => 'Brand A',
					),
				),
				array(
					array( 'id' => 3, 'name' => 'Competitor A', 'enabled' => 1 ),
				)
			);
			$run = ManualDiscoveryService::build_run_state( 'manual-1', $pairs );

			lpm_assert_same( 1, $run['total'], 'One selected product against one competitor should create one job pair.' );
			lpm_assert_same( 'running', $run['status'], 'Non-empty manual runs should start as running.' );
			lpm_assert_same( 'Selected Stroller', $run['pairs'][0]['product_title'], 'Job rows should include product title for live feedback.' );
			lpm_assert_same( 'SKU-101', $run['pairs'][0]['sku'], 'Job rows should include our SKU.' );
		},
		'Manual run confirmation can happen before creating a run' => static function (): void {
			lpm_assert_true( ManualDiscoveryService::needs_preflight_confirmation( 0, 0, 10, 3 ), 'All products/all competitors should warn before creating a run.' );
			lpm_assert_true( ManualDiscoveryService::needs_preflight_confirmation( 7, 0, 10, 3 ), 'One product/all competitors should warn when multiple competitors are selected.' );
			lpm_assert_true( ManualDiscoveryService::needs_preflight_confirmation( 0, 4, 10, 3 ), 'All products/one competitor should warn when multiple products are selected.' );
			lpm_assert_true( ! ManualDiscoveryService::needs_preflight_confirmation( 7, 4, 10, 3 ), 'One product/one competitor should not need a large-run preflight.' );
		},
		'Manual discovery only uses explicitly selected products passed to the run builder' => static function (): void {
			$pairs = ManualDiscoveryService::build_run_pairs(
				array(
					(object) array( 'id' => 1, 'product_id' => 11, 'variation_id' => 0, 'sku' => 'SELECTED-1' ),
					(object) array( 'id' => 2, 'product_id' => 12, 'variation_id' => 0, 'sku' => 'SELECTED-2' ),
				),
				array(
					array( 'id' => 1, 'name' => 'Competitor A', 'enabled' => 1 ),
					array( 'id' => 2, 'name' => 'Competitor B', 'enabled' => 1 ),
				)
			);

			lpm_assert_same( 4, count( $pairs ), 'Only the two provided selected products should be paired with competitors.' );
			lpm_assert_same( array( 'SELECTED-1', 'SELECTED-1', 'SELECTED-2', 'SELECTED-2' ), array_column( $pairs, 'sku' ), 'No product outside the selected input should appear.' );
		},
		'Manual discovery supports one product versus one competitor' => static function (): void {
			$pairs = ManualDiscoveryService::build_run_pairs(
				array( (object) array( 'id' => 9, 'product_id' => 99, 'variation_id' => 0, 'sku' => 'ONE' ) ),
				array( array( 'id' => 8, 'name' => 'One Competitor', 'enabled' => 1 ) )
			);

			lpm_assert_same( 1, count( $pairs ), 'One product versus one competitor should produce exactly one pair.' );
			lpm_assert_same( 9, $pairs[0]['discovery_product_id'], 'Pair should preserve selected discovery product ID.' );
			lpm_assert_same( 8, $pairs[0]['competitor_id'], 'Pair should preserve competitor ID.' );
		},
		'Manual discovery run state is hard capped for option storage' => static function (): void {
			$pairs = array();
			for ( $i = 1; $i <= 550; $i++ ) {
				$pairs[] = array(
					'discovery_product_id' => $i,
					'competitor_id' => 1,
				);
			}

			$run = ManualDiscoveryService::build_run_state( 'large-option-test', $pairs );

			lpm_assert_same( 500, $run['total'], 'Manual run option payloads should be capped to avoid unbounded wp_options growth.' );
			lpm_assert_same( 500, count( $run['pairs'] ), 'Stored run pairs should match the hard cap.' );
		},
		'Targeted retest uses one product plus one competitor IDs' => static function (): void {
			$pairs = ManualDiscoveryService::build_run_pairs(
				array( (object) array( 'id' => 123, 'product_id' => 99, 'variation_id' => 0, 'sku' => 'RETEST' ) ),
				array( array( 'id' => 456, 'name' => 'Retest Competitor', 'enabled' => 1 ) )
			);

			lpm_assert_same( 1, count( $pairs ), 'Targeted retest should build one pair.' );
			lpm_assert_same( 123, $pairs[0]['discovery_product_id'], 'Targeted retest should keep discovery product ID.' );
			lpm_assert_same( 456, $pairs[0]['competitor_id'], 'Targeted retest should keep competitor ID.' );
		},
		'Manual discovery no-match reasons are explicit' => static function (): void {
			lpm_assert_same( 'competitor search URL not configured', ManualDiscoveryService::no_match_reason( array( 'technical_details' => 'no search page: add a search URL template' ) ), 'Missing search templates should be explicit.' );
			lpm_assert_same( 'search page returned no product URLs', ManualDiscoveryService::no_match_reason( array( 'technical_details' => 'No product URLs found for this selected SKU.' ) ), 'Empty search results should be explicit.' );
			lpm_assert_same( 'JavaScript required', ManualDiscoveryService::no_match_reason( array(), array( 'requires_javascript' => true ) ), 'JavaScript-only pages should be warnings only.' );
			lpm_assert_same( 'no price found', ManualDiscoveryService::no_match_reason( array(), array( 'monitored_price' => null ) ), 'Pages without detected price should be explicit.' );
			lpm_assert_same( 'HTTP blocked/error', ManualDiscoveryService::no_match_reason( array( 'technical_details' => 'HTTP status 403' ) ), 'Blocked HTTP responses should be explicit.' );
		},
		'Manual discovery checks later search result candidates while staying bounded' => static function (): void {
			$candidates = ManualDiscoveryService::candidate_urls_for_processing(
				array(
					'https://competitor.test/product/accessory-1',
					'https://competitor.test/product/accessory-2',
					'https://competitor.test/product/other-color',
					'https://competitor.test/product/thule-chariot-sport-2-double-black',
					'https://competitor.test/product/extra-5',
					'https://competitor.test/product/extra-6',
					'https://competitor.test/product/extra-7',
					'https://competitor.test/product/extra-8',
					'https://competitor.test/product/extra-9',
				)
			);

			lpm_assert_same( 8, count( $candidates ), 'Manual discovery should remain bounded per product/competitor pair.' );
			lpm_assert_same( 'https://competitor.test/product/thule-chariot-sport-2-double-black', $candidates[3], 'The fourth visible product candidate should be tested.' );
			lpm_assert_true( ! in_array( 'https://competitor.test/product/extra-9', $candidates, true ), 'Candidates beyond the bounded cap should not be tested.' );
		},
		'Approving from live results builds an active competitor link' => static function (): void {
			$data = ManualDiscoveryService::competitor_link_data_for_approval(
				(object) array(
					'competitor_id' => 4,
					'competitor_url' => 'https://competitor.no/product/sku-101',
					'confidence_label' => 'High confidence',
				),
				array( 'name' => 'Competitor A' ),
				22
			);

			lpm_assert_same( 22, $data['monitored_product_id'], 'Approved live result should target the monitored product.' );
			lpm_assert_same( 4, $data['competitor_id'], 'Approved live result should preserve competitor profile.' );
			lpm_assert_same( 'https://competitor.no/product/sku-101', $data['competitor_url'], 'Approved live result should preserve competitor URL.' );
			lpm_assert_same( 'exact', $data['match_type'], 'High confidence live result should create an exact competitor link.' );
			lpm_assert_same( 1, $data['enabled'], 'Approved live result should create an active competitor link.' );
		},
		'No processed row ends with searching status' => static function (): void {
			$service = new ReflectionClass( ManualDiscoveryService::class );
			$instance = $service->newInstanceWithoutConstructor();
			$method = $service->getMethod( 'finalize_row' );
			$row = $method->invoke( $instance, array( 'status' => 'searching', 'error' => '' ) );

			lpm_assert_same( 'no_match', $row['status'], 'Finalized row should not stay searching.' );
			lpm_assert_same( 'no SKU/EAN/title match', $row['error'], 'Finalized no-match row should get a clear reason.' );
		},
		'Cleanup removes stale manual discovery runs only' => static function (): void {
			$now = time();
			$options = array(
				'lpm_manual_discovery_run_old_completed' => array( 'status' => 'completed', 'updated_at' => gmdate( 'Y-m-d H:i:s', $now - 90000 ) ),
				'lpm_manual_discovery_run_old_running' => array( 'status' => 'running', 'updated_at' => gmdate( 'Y-m-d H:i:s', $now - 90000 ) ),
				'lpm_manual_discovery_run_recent_completed' => array( 'status' => 'completed', 'updated_at' => gmdate( 'Y-m-d H:i:s', $now - 60 ) ),
				'unrelated_option' => array( 'status' => 'completed', 'updated_at' => gmdate( 'Y-m-d H:i:s', $now - 90000 ) ),
			);

			$deleted = ManualDiscoveryService::cleanup_stale_run_options( $options, $now );
			lpm_assert_same( 2, $deleted, 'Old completed and stale running run options should be deleted.' );
			lpm_assert_true( isset( $options['lpm_manual_discovery_run_recent_completed'] ), 'Recent completed runs should remain.' );
			lpm_assert_true( isset( $options['unrelated_option'] ), 'Cleanup must not touch unrelated options.' );
		},
		'Cancelling a run stops future processing state' => static function (): void {
			$reflection = new ReflectionClass( ManualDiscoveryService::class );
			$service = $reflection->newInstanceWithoutConstructor();
			$run = ManualDiscoveryService::build_run_state(
				'cancel-test',
				array(
					array(
						'discovery_product_id' => 1,
						'competitor_id' => 2,
					),
				)
			);
			update_option( 'lpm_manual_discovery_run_cancel-test', $run );
			$cancelled = $service->cancel_run( 'cancel-test' );
			$processed = $service->process_batch( 'cancel-test', 1 );

			lpm_assert_same( 'cancelled', $cancelled['status'], 'Cancelled run should carry cancelled status.' );
			lpm_assert_same( 'cancelled', $processed['status'], 'Processing a cancelled run should return cancelled status.' );
			lpm_assert_same( 0, $processed['processed'], 'Cancelled run should not process remaining work.' );
		},
		'Manual discovery creates suggestions without auto-approving them during processing' => static function (): void {
			$method = new ReflectionMethod( ManualDiscoveryService::class, 'process_pair' );
			$source = file_get_contents( LPM_TEST_ROOT . '/src/Service/ManualDiscoveryService.php' );
			$lines  = file( LPM_TEST_ROOT . '/src/Service/ManualDiscoveryService.php' );
			$body   = implode( '', array_slice( $lines ?: array(), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );

			lpm_assert_true( str_contains( (string) $source, 'create_suggestions' ), 'Processing should create suggestions when matches are found.' );
			lpm_assert_true( ! str_contains( $body, 'approve_suggestion' ), 'Processing must not auto-approve suggestions.' );
			lpm_assert_true( str_contains( $source, 'add_competitor_link' ), 'Dedicated approval flow should create active competitor links.' );
		},
	)
);
