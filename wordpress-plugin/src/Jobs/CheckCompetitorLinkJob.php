<?php
/**
 * Bounded competitor link check batch job.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Jobs;

use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Notifications\NotificationService;
use Lilleprinsen\PriceMonitor\Plugin;
use Lilleprinsen\PriceMonitor\Service\PriceCheckService;
use Lilleprinsen\PriceMonitor\Service\SuggestionService;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CheckCompetitorLinkJob {
	public const LOCK_KEY = 'lpm_check_batch_lock';

	private Repository $repository;

	private Settings $settings;

	private PriceCheckService $price_check_service;

	private SuggestionService $suggestion_service;

	private NotificationService $notification_service;

	public function __construct( Repository $repository, Settings $settings, PriceCheckService $price_check_service, SuggestionService $suggestion_service, NotificationService $notification_service ) {
		$this->repository           = $repository;
		$this->settings             = $settings;
		$this->price_check_service  = $price_check_service;
		$this->suggestion_service   = $suggestion_service;
		$this->notification_service = $notification_service;
	}

	/**
	 * @return array<string, int>
	 */
	public function run_manual_batch(): array {
		if ( ! is_admin() || ! Plugin::can_manage() ) {
			return $this->empty_result( 'unsafe_manual_context' );
		}

		return $this->run_batch( 'manual_admin' );
	}

	/**
	 * @return array<string, int>
	 */
	public function run_scheduled_batch(): array {
		$settings = $this->settings->get_all();

		if ( empty( $settings['scheduled_checks_enabled'] ) || ( ! wp_doing_cron() && ! is_admin() ) ) {
			return $this->empty_result( 'unsafe_scheduled_context' );
		}

		return $this->run_batch( 'scheduled' );
	}

	/**
	 * @return array<string, int>
	 */
	public function run_cli_batch( int $limit = 10 ): array {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return $this->empty_result( 'unsafe_cli_context' );
		}

		return $this->run_batch( 'wp_cli', $limit );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_lock_status(): array {
		$lock = get_transient( self::LOCK_KEY );

		if ( false === $lock ) {
			return array(
				'locked'     => false,
				'source'     => '',
				'started_at' => '',
			);
		}

		if ( ! is_array( $lock ) ) {
			return array(
				'locked'     => true,
				'source'     => '',
				'started_at' => '',
			);
		}

		return array(
			'locked'     => true,
			'source'     => sanitize_key( (string) ( $lock['source'] ?? '' ) ),
			'started_at' => sanitize_text_field( (string) ( $lock['started_at'] ?? '' ) ),
		);
	}

	/**
	 * @return array<string, int>
	 */
	private function run_batch( string $source, ?int $limit_override = null ): array {
		$settings = $this->settings->get_all();
		$result   = array(
			'processed' => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'suggested' => 0,
			'locked'    => 0,
		);
		$limit    = max( 1, min( 100, null === $limit_override ? (int) ( $settings['max_urls_per_batch'] ?? 10 ) : absint( $limit_override ) ) );

		if ( ! $this->acquire_lock( $source, $settings ) ) {
			$result['locked'] = 1;
			return $result;
		}

		try {
			$links                 = $this->repository->get_due_competitor_links( $limit );
			$processed_competitors = array();

			$this->repository->write_log( 'info', 'check_batch_started', __( 'Competitor check batch started.', 'lilleprinsen-price-monitor' ), array( 'source' => $source, 'limit' => $limit ) );

			foreach ( $links as $link ) {
				$competitor_id = absint( $link['competitor_id'] ?? 0 );
				$delay_seconds = absint( $link['competitor_request_delay_seconds'] ?? 0 );

				if ( $competitor_id > 0 && $delay_seconds > 0 && isset( $processed_competitors[ $competitor_id ] ) ) {
					$result['skipped']++;
					$this->repository->write_log( 'info', 'check_batch_link_skipped_for_delay', __( 'Competitor link skipped to respect profile request delay within this batch.', 'lilleprinsen-price-monitor' ), array( 'competitor_id' => $competitor_id, 'competitor_link_id' => (int) $link['id'], 'delay_seconds' => $delay_seconds ), (int) $link['product_id'] );
					continue;
				}

				if ( $competitor_id > 0 && $delay_seconds > 0 ) {
					$processed_competitors[ $competitor_id ] = true;
				}

				$result['processed']++;
				$check = $this->price_check_service->test_check( $link, $settings );
				$this->repository->update_competitor_check_result(
					(int) $link['id'],
					$check['success'] ? (float) $check['price'] : null,
					(string) $check['currency'],
					$check['success'] ? null : (string) $check['error'],
					$check['success'] ? (string) $check['stock_status'] : null
				);

				if ( empty( $check['success'] ) ) {
					$result['failed']++;
					$this->repository->write_log( 'error', 'competitor_check_failed', __( 'Scheduled competitor check failed.', 'lilleprinsen-price-monitor' ), array( 'competitor_link_id' => (int) $link['id'], 'source' => $source, 'error' => (string) $check['error'], 'http_status' => (int) $check['http_status'], 'response_time_ms' => (int) $check['response_time_ms'] ), (int) $link['product_id'] );
					$this->maybe_notify_failed_check( $settings, $link, (string) $check['error'] );
					continue;
				}

				$link['last_price']        = $check['price'];
				$link['last_currency']     = $check['currency'];
				$link['last_stock_status'] = $check['stock_status'];
				$link['last_regular_price'] = $check['regular_price'] ?? null;
				$link['last_sale_price']   = $check['sale_price'] ?? null;
				$link['last_extraction_method'] = $check['extraction_method'] ?? '';

				if ( empty( $settings['create_suggestions_from_scheduled_checks'] ) ) {
					$result['skipped']++;
					$this->repository->write_log( 'info', 'scheduled_suggestion_skipped', __( 'Scheduled check detected a price, but scheduled suggestion creation is disabled.', 'lilleprinsen-price-monitor' ), array( 'competitor_link_id' => (int) $link['id'] ), (int) $link['product_id'] );
					continue;
				}

				$this->maybe_create_suggestion( $settings, $link, $result );
			}

			$this->repository->write_log( 'info', 'check_batch_completed', __( 'Competitor check batch completed.', 'lilleprinsen-price-monitor' ), array_merge( array( 'source' => $source ), $result ) );

			return $result;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	private function acquire_lock( string $source, array $settings ): bool {
		if ( false !== get_transient( self::LOCK_KEY ) ) {
			$this->repository->write_log( 'warning', 'check_batch_skipped', __( 'Batch skipped because another batch is running.', 'lilleprinsen-price-monitor' ), array( 'reason' => 'batch_locked', 'source' => $source ) );
			return false;
		}

		$minutes = max( 1, min( 60, absint( $settings['check_batch_lock_minutes'] ?? 10 ) ) );
		set_transient(
			self::LOCK_KEY,
			array(
				'source'     => sanitize_key( $source ),
				'started_at' => current_time( 'mysql' ),
			),
			$minutes * MINUTE_IN_SECONDS
		);

		return true;
	}

	private function release_lock(): void {
		delete_transient( self::LOCK_KEY );
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @param array<string, mixed> $link Competitor link row.
	 * @param array<string, int> $result Batch result counters.
	 */
	private function maybe_create_suggestion( array $settings, array $link, array &$result ): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			$result['skipped']++;
			return;
		}

		$product = wc_get_product( (int) $link['product_id'] );

		if ( ! is_object( $product ) ) {
			$result['skipped']++;
			$this->repository->write_log( 'warning', 'scheduled_suggestion_skipped', __( 'Scheduled suggestion skipped because the WooCommerce product was unavailable.', 'lilleprinsen-price-monitor' ), array( 'competitor_link_id' => (int) $link['id'] ), (int) $link['product_id'] );
			return;
		}

		$monitored = $this->repository->get_monitored_product( (int) $link['monitored_product_id'] );

		if ( ! $monitored ) {
			$result['skipped']++;
			return;
		}

		$suggestion = $this->suggestion_service->create_from_competitor_link( $monitored, $link, $product, $settings );

		if ( in_array( (string) ( $suggestion['status'] ?? '' ), array( 'pending', 'blocked' ), true ) ) {
			$result['suggested']++;
			$this->maybe_notify_suggestion( $settings, $suggestion, (int) $link['product_id'] );
			return;
		}

		$result['skipped']++;
		$this->repository->write_log( 'info', 'scheduled_suggestion_skipped', (string) ( $suggestion['message'] ?? __( 'Scheduled suggestion was skipped.', 'lilleprinsen-price-monitor' ) ), array( 'competitor_link_id' => (int) $link['id'] ), (int) $link['product_id'] );
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @param array<string, mixed> $suggestion Suggestion result.
	 */
	private function maybe_notify_suggestion( array $settings, array $suggestion, int $product_id ): void {
		$status = (string) ( $suggestion['status'] ?? '' );

		if ( ! in_array( $status, array( 'pending', 'blocked' ), true ) ) {
			return;
		}

		$this->notification_service->send(
			'price_suggestion_' . $status,
			__( 'Price Monitor would send a suggestion notification.', 'lilleprinsen-price-monitor' ),
			$settings,
			$suggestion,
			$product_id
		);
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @param array<string, mixed> $link Competitor link row.
	 */
	private function maybe_notify_failed_check( array $settings, array $link, string $error ): void {
		$this->notification_service->send(
			'failed_check',
			__( 'Price Monitor would send a failed check notification.', 'lilleprinsen-price-monitor' ),
			$settings,
			array(
				'competitor_link_id' => (int) $link['id'],
				'competitor_url'     => (string) ( $link['competitor_url'] ?? '' ),
				'error'              => $error,
			),
			(int) $link['product_id']
		);
	}

	/**
	 * @return array<string, int>
	 */
	private function empty_result( string $reason ): array {
		$this->repository->write_log( 'warning', 'check_batch_skipped', __( 'Competitor check batch skipped.', 'lilleprinsen-price-monitor' ), array( 'reason' => $reason ) );

		return array(
			'processed' => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'suggested' => 0,
		);
	}
}
