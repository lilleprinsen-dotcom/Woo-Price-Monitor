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

	private Settings $settings;

	private CheckCompetitorLinkJob $job;

	private Repository $repository;

	public function __construct( Settings $settings, CheckCompetitorLinkJob $job, Repository $repository ) {
		$this->settings   = $settings;
		$this->job        = $job;
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( self::ACTION, array( $this->job, 'run_scheduled_batch' ) );
		add_action( 'admin_init', array( $this, 'maybe_schedule' ) );
	}

	public function maybe_schedule(): void {
		$settings = $this->settings->get_all();

		if ( empty( $settings['scheduled_checks_enabled'] ) ) {
			$this->clear_action_scheduler_jobs();
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::ACTION, array(), 'lilleprinsen-price-monitor' ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::ACTION, array(), 'lilleprinsen-price-monitor' );
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

	private function clear_action_scheduler_jobs(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::ACTION, array(), 'lilleprinsen-price-monitor' ) ) {
				return;
			}

			as_unschedule_all_actions( self::ACTION, array(), 'lilleprinsen-price-monitor' );
		}
	}
}
