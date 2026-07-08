<?php
/**
 * Safe background job scheduler skeleton.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Jobs;

use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobScheduler {
	public const ACTION = 'lpm_run_competitor_check_batch';
	private const RECURRING_ARGS = array( 'recurring' );
	private const CONTINUATION_ARGS = array( 'continuation' );
	private const INTERVAL_OPTION = 'lpm_scheduled_check_interval_hours';

	private Settings $settings;

	private CheckCompetitorLinkJob $job;

	private Repository $repository;

	public function __construct( Settings $settings, CheckCompetitorLinkJob $job, Repository $repository ) {
		$this->settings   = $settings;
		$this->job        = $job;
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( self::ACTION, array( $this->job, 'run_scheduled_batch' ), 10, 1 );
		add_action( 'admin_init', array( $this, 'maybe_schedule' ) );
	}

	public function maybe_schedule(): void {
		$settings = $this->settings->get_all();

		if ( empty( $settings['scheduled_checks_enabled'] ) ) {
			$this->clear_action_scheduler_jobs();
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			$interval_hours = Settings::sanitize_check_interval_hours( $settings['scheduled_check_interval_hours'] ?? 24 );
			$scheduled_for  = absint( get_option( self::INTERVAL_OPTION, 0 ) );

			if ( $scheduled_for !== $interval_hours ) {
				$this->clear_action_scheduler_jobs();
			}

			if ( ! as_has_scheduled_action( self::ACTION, self::RECURRING_ARGS, 'lilleprinsen-price-monitor' ) ) {
				as_schedule_recurring_action( time() + ( $interval_hours * HOUR_IN_SECONDS ), $interval_hours * HOUR_IN_SECONDS, self::ACTION, self::RECURRING_ARGS, 'lilleprinsen-price-monitor' );
				update_option( self::INTERVAL_OPTION, $interval_hours, false );
			}
			return;
		}

		$this->repository->write_log(
			'warning',
			'scheduled_checks_not_registered',
			__( 'Scheduled checks are enabled, but Action Scheduler is unavailable. No fallback job was registered.', 'lilleprinsen-price-monitor' ),
			array( 'fallback' => 'noop' )
		);
	}

	/**
	 * @return array<string, int>
	 */
	public function run_one_small_batch_now(): array {
		return $this->job->run_manual_batch();
	}

	/**
	 * Queue a small follow-up batch so large due sets drain gradually.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 */
	public static function queue_continuation_batch( array $settings ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( self::ACTION, self::CONTINUATION_ARGS, 'lilleprinsen-price-monitor' ) ) {
			return;
		}

		$spacing_minutes = max( 1, min( 60, absint( $settings['scheduled_batch_spacing_minutes'] ?? 5 ) ) );
		as_schedule_single_action( time() + ( $spacing_minutes * MINUTE_IN_SECONDS ), self::ACTION, self::CONTINUATION_ARGS, 'lilleprinsen-price-monitor' );
	}

	private function clear_action_scheduler_jobs(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION, null, 'lilleprinsen-price-monitor' );
			delete_option( self::INTERVAL_OPTION );
		}
	}
}
