<?php
/**
 * Admin-invoked retention cleanup.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RetentionService {
	private Repository $repository;

	private Settings $settings;

	public function __construct( Repository $repository, Settings $settings ) {
		$this->repository = $repository;
		$this->settings   = $settings;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function run_cleanup(): array {
		$settings       = $this->settings->get_all();
		$log_days       = $this->bounded_days( $settings['log_retention_days'] ?? 90, 1, 3650, 90 );
		$debug_days     = $this->bounded_days( $settings['debug_log_retention_days'] ?? 14, 1, 3650, 14 );
		$success_days   = $this->bounded_days( $settings['observation_retention_days'] ?? 90, 1, 3650, 90 );
		$failed_days    = $this->bounded_days( $settings['failed_observation_retention_days'] ?? 30, 1, 3650, 30 );
		$log_cutoff     = $this->cutoff_datetime( $log_days );
		$debug_cutoff   = $this->cutoff_datetime( $debug_days );
		$success_cutoff = $this->cutoff_datetime( $success_days );
		$failed_cutoff  = $this->cutoff_datetime( $failed_days );

		$logs_deleted         = $this->repository->delete_old_non_audit_logs( $log_cutoff, $debug_cutoff );
		$observations_deleted = $this->repository->delete_old_price_observations( $success_cutoff, $failed_cutoff );
		$summary              = array(
			'logs_deleted'         => $logs_deleted,
			'observations_deleted' => $observations_deleted,
			'log_cutoff'           => $log_cutoff,
			'debug_log_cutoff'     => $debug_cutoff,
			'observation_cutoff'   => $success_cutoff,
			'failed_observation_cutoff' => $failed_cutoff,
			'audit_logs_preserved' => ! empty( $settings['keep_audit_logs_forever'] ),
		);

		$this->repository->write_log(
			'info',
			'retention_cleanup_completed',
			__( 'Retention cleanup completed.', 'lilleprinsen-price-monitor' ),
			$summary
		);

		return $summary;
	}

	/**
	 * @param mixed $value Raw day count.
	 */
	private function bounded_days( $value, int $min, int $max, int $fallback ): int {
		$days = absint( $value );

		if ( $days < $min ) {
			return $fallback;
		}

		return min( $max, $days );
	}

	private function cutoff_datetime( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );
	}
}
