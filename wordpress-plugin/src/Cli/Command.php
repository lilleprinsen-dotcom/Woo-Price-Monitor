<?php
/**
 * Safe WP-CLI commands.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Cli;

use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Jobs\CheckCompetitorLinkJob;
use Lilleprinsen\PriceMonitor\Plugin;
use Lilleprinsen\PriceMonitor\Service\RetentionService;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Command {
	private Settings $settings;

	private Repository $repository;

	private CheckCompetitorLinkJob $check_job;

	private RetentionService $retention_service;

	public function __construct( Settings $settings, Repository $repository, CheckCompetitorLinkJob $check_job, RetentionService $retention_service ) {
		$this->settings          = $settings;
		$this->repository        = $repository;
		$this->check_job         = $check_job;
		$this->retention_service = $retention_service;
	}

	/**
	 * Run one bounded competitor check batch.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Maximum links to process. Capped at 100.
	 *
	 * @param array<int, string> $args Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 */
	public function check_batch( array $args, array $assoc_args ): void {
		$limit  = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 10;
		$limit  = max( 1, min( 100, $limit ) );
		$result = $this->check_job->run_cli_batch( $limit );

		if ( ! empty( $result['locked'] ) ) {
			\WP_CLI::warning( 'Batch skipped because another batch is running.' );
			return;
		}

		\WP_CLI::success(
			sprintf(
				'Batch finished: %d processed, %d failed, %d skipped, %d suggestions.',
				(int) $result['processed'],
				(int) $result['failed'],
				(int) $result['skipped'],
				(int) $result['suggested']
			)
		);
	}

	public function cleanup(): void {
		$summary = $this->retention_service->run_cleanup();

		\WP_CLI::success(
			sprintf(
				'Cleanup finished: %d logs deleted, %d observations deleted.',
				(int) $summary['logs_deleted'],
				(int) $summary['observations_deleted']
			)
		);
	}

	public function status(): void {
		$settings    = $this->settings->get_all();
		$counts      = $this->repository->get_dashboard_counts();
		$lock_status = CheckCompetitorLinkJob::get_lock_status();

		\WP_CLI::line( 'Lilleprinsen Price Monitor status' );
		\WP_CLI::line( 'Plugin enabled: ' . $this->yes_no( ! empty( $settings['plugin_enabled'] ) ) );
		\WP_CLI::line( 'Dry-run mode: ' . $this->yes_no( ! empty( $settings['dry_run_mode'] ) ) );
		\WP_CLI::line( 'Scheduled checks enabled: ' . $this->yes_no( ! empty( $settings['scheduled_checks_enabled'] ) ) );
		\WP_CLI::line( 'Batch lock active: ' . $this->yes_no( ! empty( $lock_status['locked'] ) ) );
		\WP_CLI::line( 'Pending suggestions: ' . (int) $counts['pending_suggestions'] );
		\WP_CLI::line( 'Blocked suggestions: ' . (int) $counts['blocked_suggestions'] );
		\WP_CLI::line( 'Failed checks last 24h: ' . (int) $counts['failed_checks_last_24h'] );
		\WP_CLI::line( 'Active price match sessions: ' . (int) $counts['active_price_match_sessions'] );
		\WP_CLI::line( 'Emergency price updates disabled: ' . $this->yes_no( ! empty( $settings['disable_all_price_updates'] ) ) );
		\WP_CLI::line( 'Real price updates possible: ' . $this->yes_no( $this->real_updates_possible( $settings ) ) );
		\WP_CLI::line( 'WooCommerce active: ' . $this->yes_no( Plugin::is_woocommerce_active() ) );
	}

	private function yes_no( bool $value ): string {
		return $value ? 'yes' : 'no';
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	private function real_updates_possible( array $settings ): bool {
		return empty( $settings['dry_run_mode'] )
			&& empty( $settings['disable_all_price_updates'] )
			&& ! empty( $settings['allow_real_price_updates'] )
			&& ! empty( $settings['require_manual_approval'] )
			&& ! empty( $settings['require_confirmation_for_real_updates'] );
	}
}
