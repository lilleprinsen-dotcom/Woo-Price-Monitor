<?php
/**
 * Local tests for scheduled monitoring/discovery settings.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';
require_once LPM_TEST_ROOT . '/src/Settings/Settings.php';
require_once LPM_TEST_ROOT . '/src/Settings/DiscoverySettings.php';

use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;
use Lilleprinsen\PriceMonitor\Settings\Settings;

lpm_run_tests(
	'Scheduler settings',
	array(
		'approved competitor check intervals are limited to production choices' => static function (): void {
			$options = Settings::check_interval_options();

			lpm_assert_same( array( 1, 2, 3, 4, 6, 8, 12, 24 ), array_keys( $options ), 'Approved match check intervals should stay within 1-24 hours.' );
			lpm_assert_same( 6, Settings::sanitize_check_interval_hours( 6 ), 'Valid six-hour interval should be accepted.' );
			lpm_assert_same( 24, Settings::sanitize_check_interval_hours( 720 ), 'Unsafe long intervals should fall back to 24 hours.' );
			lpm_assert_same( 24, Settings::sanitize_check_interval_hours( 0 ), 'Invalid empty interval should fall back to 24 hours.' );
		},
		'discovery intervals are limited to one through seven days' => static function (): void {
			$options = DiscoverySettings::schedule_interval_options();

			lpm_assert_same( array( 1, 2, 3, 4, 5, 6, 7 ), array_keys( $options ), 'Discovery intervals should stay within 1-7 days.' );
			lpm_assert_same( 3, DiscoverySettings::sanitize_schedule_interval_days( 3 ), 'Valid three-day interval should be accepted.' );
			lpm_assert_same( 7, DiscoverySettings::sanitize_schedule_interval_days( 14 ), 'Unsafe long discovery intervals should fall back to 7 days.' );
			lpm_assert_same( 7, DiscoverySettings::sanitize_schedule_interval_days( 0 ), 'Invalid empty discovery interval should fall back to 7 days.' );
		},
		'settings expose queued batch spacing controls' => static function (): void {
			$settings = ( new Settings() )->sanitize(
				array(
					'scheduled_batch_spacing_minutes' => 999,
				)
			);
			$discovery = ( new DiscoverySettings( new Settings() ) )->sanitize(
				array(
					'discovery_batch_spacing_minutes' => 999,
				)
			);

			lpm_assert_same( 60, (int) $settings['scheduled_batch_spacing_minutes'], 'Check batch spacing should be capped at one hour.' );
			lpm_assert_same( 180, (int) $discovery['discovery_batch_spacing_minutes'], 'Discovery batch spacing should be capped at three hours.' );
		},
	)
);
