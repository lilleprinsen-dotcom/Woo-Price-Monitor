<?php
/**
 * Local tests for production schema safety declarations.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';

use Lilleprinsen\PriceMonitor\Database\DiscoverySchema;
use Lilleprinsen\PriceMonitor\Database\Schema;
use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;

lpm_run_tests(
	'Schema safety',
	array(
		'Main schema declares scale indexes for common filters' => static function (): void {
			$source = (string) file_get_contents( LPM_TEST_ROOT . '/src/Database/Schema.php' );

			lpm_assert_same( '2.2.1', Schema::VERSION, 'Main schema version should bump when indexes change.' );
			lpm_assert_contains( 'KEY enabled_next_check_after (enabled, next_check_after)', $source, 'Competitor link scheduler should have an enabled/next-check index.' );
			lpm_assert_contains( 'KEY monitored_enabled (monitored_product_id, enabled)', $source, 'Competitor links should support product-scoped enabled filters.' );
			lpm_assert_contains( 'KEY status_created_at (status, created_at)', $source, 'Suggestion inbox should support status/date pagination.' );
			lpm_assert_contains( 'KEY event_created_at (event, created_at)', $source, 'Logs should support event/date filters.' );
		},
		'Discovery schema declares selected-product and suggestion indexes' => static function (): void {
			$source = (string) file_get_contents( LPM_TEST_ROOT . '/src/Database/DiscoverySchema.php' );

			lpm_assert_same( '1.1.1', DiscoverySchema::VERSION, 'Discovery schema version should bump when indexes change.' );
			lpm_assert_contains( 'KEY enabled_priority_updated_at (enabled, priority, updated_at)', $source, 'Selected discovery products should support bounded priority ordering.' );
			lpm_assert_contains( 'KEY competitor_status_checked (competitor_id, status, last_checked_at)', $source, 'Discovery seed URLs should support competitor/status/check filters.' );
			lpm_assert_contains( 'KEY status_created_at (status, created_at)', $source, 'Discovery suggestions should support status/date pagination.' );
			lpm_assert_contains( 'KEY status_updated_at (status, updated_at)', $source, 'Discovery runs should support status cleanup/reporting.' );
		},
		'Scheduled discovery targets selected products without competitor links by default' => static function (): void {
			$settings = ( new DiscoverySettings( new Lilleprinsen\PriceMonitor\Settings\Settings() ) )->defaults();
			$job_source = (string) file_get_contents( LPM_TEST_ROOT . '/src/Jobs/CompetitorDiscoveryJob.php' );
			$repository_source = (string) file_get_contents( LPM_TEST_ROOT . '/src/Database/DiscoveryRepository.php' );

			lpm_assert_same( 1, (int) $settings['discovery_rediscover_missing_links_only'], 'Missing-link rediscovery should be enabled by default.' );
			lpm_assert_contains( "array( 0, 0, 'missing_links' )", $job_source, 'Recurring scheduled discovery should use the missing-links scope.' );
			lpm_assert_contains( "array( \$competitor_id, 0, 'all_selected' )", $job_source, 'Manual queued discovery should still scan all selected products when requested.' );
			lpm_assert_contains( 'get_enabled_products_without_competitor_links_for_matching', $job_source, 'Scheduled missing-link scope should use the no-link product selector.' );
			lpm_assert_contains( 'LEFT JOIN {$competitor_links} cl', $repository_source, 'No-link selector should join competitor links.' );
			lpm_assert_contains( 'cl.id IS NULL', $repository_source, 'No-link selector should exclude products that already have an active competitor link.' );
		},
	)
);
