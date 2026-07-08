<?php
/**
 * Bounded competitor discovery job.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Jobs;

use Lilleprinsen\PriceMonitor\Database\DiscoveryRepository;
use Lilleprinsen\PriceMonitor\Database\DiscoverySchema;
use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Service\CompetitorProductExtractor;
use Lilleprinsen\PriceMonitor\Service\DiscoverySourceService;
use Lilleprinsen\PriceMonitor\Service\DiscoveryUrlService;
use Lilleprinsen\PriceMonitor\Service\MatchSuggestionService;
use Lilleprinsen\PriceMonitor\Service\SkuSearchDiscoveryService;
use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs safe, resumable discovery batches.
 */
class CompetitorDiscoveryJob {
	public const ACTION = 'lpm_run_competitor_discovery_batch';

	private const LOCK_KEY = 'lpm_competitor_discovery_lock';
	private const RECURRING_ARGS = array( 0, 0, 'missing_links' );
	private const INTERVAL_OPTION = 'lpm_discovery_schedule_interval_days';

	private Repository $repository;
	private DiscoveryRepository $discovery_repository;
	private DiscoverySettings $settings;
	private CompetitorProductExtractor $extractor;
	private MatchSuggestionService $matcher;
	private DiscoverySourceService $source_service;
	private SkuSearchDiscoveryService $sku_search;
	private DiscoveryUrlService $url_service;

	/** Constructor. */
	public function __construct(
		Repository $repository,
		DiscoveryRepository $discovery_repository,
		DiscoverySettings $settings,
		CompetitorProductExtractor $extractor,
		MatchSuggestionService $matcher,
		DiscoverySourceService $source_service,
		SkuSearchDiscoveryService $sku_search,
		DiscoveryUrlService $url_service
	) {
		$this->repository           = $repository;
		$this->discovery_repository = $discovery_repository;
		$this->settings             = $settings;
		$this->extractor            = $extractor;
		$this->matcher              = $matcher;
		$this->source_service       = $source_service;
		$this->sku_search           = $sku_search;
		$this->url_service          = $url_service;
	}

	/** Register hooks. */
	public function register(): void {
		add_action( self::ACTION, array( $this, 'run' ), 10, 3 );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	/** Schedule or unschedule weekly discovery according to settings. */
	public function maybe_schedule(): void {
		$settings = $this->settings->get_all();
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( empty( $settings['discovery_enabled'] ) ) {
			$this->unschedule();
			return;
		}

		$interval_days = DiscoverySettings::sanitize_schedule_interval_days( $settings['discovery_schedule_interval_days'] ?? 7 );
		$scheduled_for = absint( get_option( self::INTERVAL_OPTION, 0 ) );

		if ( $scheduled_for !== $interval_days ) {
			$this->unschedule();
		}

		if ( as_next_scheduled_action( self::ACTION, self::RECURRING_ARGS, 'lilleprinsen-price-monitor' ) ) {
			return;
		}

		$hour  = absint( $settings['discovery_low_traffic_hour'] ?? 2 );
		$first = strtotime( 'tomorrow ' . $hour . ':00:00', current_time( 'timestamp' ) );
		as_schedule_recurring_action( $first ?: time() + DAY_IN_SECONDS, $interval_days * DAY_IN_SECONDS, self::ACTION, self::RECURRING_ARGS, 'lilleprinsen-price-monitor' );
		update_option( self::INTERVAL_OPTION, $interval_days, false );
	}

	/** Unschedule recurring discovery actions. */
	public function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION, null, 'lilleprinsen-price-monitor' );
			delete_option( self::INTERVAL_OPTION );
		}
	}

	/** Queue a manual batch when Action Scheduler is available. */
	public function enqueue_manual_batch( int $competitor_id = 0 ): bool {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION, array( $competitor_id, 0, 'all_selected' ), 'lilleprinsen-price-monitor' );
			return true;
		}

		return false;
	}

	/** Run one bounded discovery batch. */
	public function run( int $competitor_id = 0, int $offset = 0, string $scope = 'missing_links' ): void {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, 10 * MINUTE_IN_SECONDS );
		DiscoverySchema::maybe_upgrade();

		$settings       = $this->settings->get_all();
		$request_limit  = max( 1, min( 100, absint( $settings['discovery_max_requests_per_batch'] ?? 25 ) ) );
		$product_limit  = max( 1, min( 500, absint( $settings['discovery_max_product_pages_per_run'] ?? 50 ) ) );
		$listing_limit  = max( 0, min( 50, absint( $settings['discovery_max_listing_pages_per_run'] ?? 5 ) ) );
		$sku_scan_limit = max( 1, min( 200, absint( $settings['discovery_max_sku_searches_per_run'] ?? 25 ) ) );
		$scope          = $this->sanitize_run_scope( $scope );
		$run_source     = 'missing_links' === $scope ? 'missing_link_rediscovery' : 'batch_discovery';
		$run_id         = $this->discovery_repository->create_run( $competitor_id > 0 ? $competitor_id : null, $run_source, $request_limit );
		$processed      = 0;
		$suggestions    = 0;
		$failures       = 0;
		$requests       = 0;
		$discovered     = 0;
		$last_error     = '';
		$last_hash      = '';

		try {
			$selected_products = $this->products_for_run_scope( $scope, 500 );

			if ( 'missing_links' === $scope && empty( $selected_products ) ) {
				$this->discovery_repository->finish_run( $run_id, 'completed', 0, 0, 0, '', 0, 0 );
				return;
			}

			$seed_rows = $this->discovery_repository->get_due_seed_urls( $competitor_id, $listing_limit, $offset );
			foreach ( $seed_rows as $seed ) {
				if ( $requests >= $request_limit ) {
					break;
				}
				$competitor = $this->repository->get_competitor( (int) $seed->competitor_id );
				if ( ! $competitor || empty( $competitor['enabled'] ) ) {
					continue;
				}

				$result = $this->source_service->discover_from_seed( $seed, $competitor );
				$requests += (int) ( $result['request_count'] ?? 1 );
				if ( empty( $result['success'] ) ) {
					++$failures;
					$last_error = (string) ( $result['technical_details'] ?? $result['message'] ?? '' );
					$this->discovery_repository->mark_seed_url_checked( (int) $seed->id, $last_error );
					$this->record_health( (int) $seed->competitor_id, false, $last_error, '' );
					continue;
				}

				foreach ( $result['urls'] as $url ) {
					$this->discovery_repository->queue_discovered_product_url( (int) $seed->competitor_id, $url, $this->url_service->hash_url( $url ), (int) $seed->id, $this->url_service->get_domain( $url ) );
					++$discovered;
				}
				$this->discovery_repository->mark_seed_url_checked( (int) $seed->id );
				$this->delay( $settings );
			}

			if ( ! empty( $settings['discovery_sku_crawl_enabled'] ) && $requests < $request_limit && ! empty( $selected_products ) ) {
				foreach ( $this->competitors_for_run( $competitor_id ) as $competitor ) {
					if ( empty( $competitor['enabled'] ) || $requests >= $request_limit ) {
						continue;
					}

					$result = $this->sku_search->crawl_for_selected_skus(
						$competitor,
						$selected_products,
						$this->discovery_repository->get_seed_urls_for_competitor( (int) $competitor['id'] ),
						max( 1, $request_limit - $requests )
					);
					$requests += (int) ( $result['request_count'] ?? 0 );

					foreach ( (array) ( $result['matched_products'] ?? array() ) as $discovery_product_id ) {
						$this->discovery_repository->mark_discovery_product_run( (int) $discovery_product_id );
					}

					if ( empty( $result['success'] ) ) {
						++$failures;
						$last_error = (string) ( $result['technical_details'] ?? $result['message'] ?? '' );
						$this->record_health( (int) $competitor['id'], false, $last_error, '' );
						continue;
					}

					foreach ( $result['urls'] as $url ) {
						$this->discovery_repository->store_discovered_product(
							(int) $competitor['id'],
							$url,
							array(
								'url_hash'          => $this->url_service->hash_url( $url ),
								'discovery_source'  => 'sku_crawl',
								'domain'            => $this->url_service->get_domain( $url ),
								'extraction_status' => 'queued',
								'raw_metadata'      => array(
									'matched_discovery_product_ids' => array_map( 'absint', (array) ( $result['matched_products'] ?? array() ) ),
								),
							)
						);
						++$discovered;
					}

					$this->record_health( (int) $competitor['id'], true, '', '' );
					$this->delay( $settings );
				}
			}

			if ( ! empty( $settings['discovery_sku_scan_enabled'] ) && $requests < $request_limit && ! empty( $selected_products ) ) {
				$competitors = $this->competitors_for_run( $competitor_id );
				$sku_searches = 0;

				foreach ( $competitors as $competitor ) {
					if ( empty( $competitor['enabled'] ) ) {
						continue;
					}

					foreach ( $selected_products as $product ) {
						if ( $requests >= $request_limit || $sku_searches >= $sku_scan_limit ) {
							break 2;
						}
						if ( '' === trim( (string) ( $product->sku ?? '' ) ) ) {
							continue;
						}

						$result = $this->sku_search->discover_for_product( $competitor, $product );
						$requests += (int) ( $result['request_count'] ?? 0 );
						++$sku_searches;
						$this->discovery_repository->mark_discovery_product_run( (int) $product->id );

						if ( empty( $result['success'] ) ) {
							++$failures;
							$last_error = (string) ( $result['technical_details'] ?? $result['message'] ?? '' );
							$this->record_health( (int) $competitor['id'], false, $last_error, '' );
							continue;
						}

						foreach ( $result['urls'] as $url ) {
							$this->discovery_repository->store_discovered_product(
								(int) $competitor['id'],
								$url,
								array(
									'url_hash'          => $this->url_service->hash_url( $url ),
									'discovery_source'  => 'sku_search',
									'domain'            => $this->url_service->get_domain( $url ),
									'extraction_status' => 'queued',
									'raw_metadata'      => array(
										'searched_sku'          => (string) ( $result['sku'] ?? $product->sku ),
										'searched_name'         => (string) ( $result['searched_name'] ?? '' ),
										'discovery_product_id'  => (int) $product->id,
									),
								)
							);
							++$discovered;
						}

						$this->record_health( (int) $competitor['id'], true, '', '' );
						$this->delay( $settings );
					}
				}
			}

			$product_rows = $this->discovery_repository->get_due_discovered_product_pages( $competitor_id, min( $product_limit, max( 0, $request_limit - $requests ) ), 0 );

			foreach ( $product_rows as $row ) {
				if ( $requests >= $request_limit ) {
					break;
				}
				$competitor = $this->repository->get_competitor( (int) $row->competitor_id );
				if ( ! $competitor || empty( $competitor['enabled'] ) ) {
					continue;
				}

				$result = $this->extractor->test_url( (string) $row->url, $competitor );
				++$requests;
				if ( empty( $result['success'] ) ) {
					++$failures;
					$last_error = (string) ( $result['technical_details'] ?? $result['message'] ?? '' );
					$this->discovery_repository->store_discovered_product( (int) $row->competitor_id, (string) $row->url, array_merge( (array) $result, array( 'url_hash' => (string) $row->url_hash, 'failure_count' => (int) $row->failure_count + 1 ) ) );
					$this->record_health( (int) $row->competitor_id, false, $last_error, '' );
					continue;
				}

				$stored_id = $this->discovery_repository->store_discovered_product( (int) $row->competitor_id, (string) $row->url, $result );
				$stored    = $this->discovery_repository->get_discovered_product( $stored_id );
				if ( $stored ) {
					$suggestions += count( $this->matcher->create_suggestions( $stored_id, $stored, $selected_products ) );
					$last_hash = (string) $stored->content_hash;
				}
				++$processed;
				$this->record_health( (int) $row->competitor_id, true, '', $last_hash );
				$this->delay( $settings );
			}

			$this->discovery_repository->finish_run( $run_id, 'completed', $processed, $suggestions, $failures, $last_error, $requests, $discovered );
			if ( $requests >= $request_limit ) {
				$this->queue_continuation( $competitor_id, $offset, $scope, $settings );
			}
		} catch ( \Throwable $error ) {
			$this->discovery_repository->finish_run( $run_id, 'failed', $processed, $suggestions, $failures + 1, $error->getMessage(), $requests, $discovered );
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Competitors to scan in this run.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function competitors_for_run( int $competitor_id ): array {
		if ( $competitor_id > 0 ) {
			$competitor = $this->repository->get_competitor( $competitor_id );
			return $competitor ? array( $competitor ) : array();
		}

		return $this->repository->get_competitors( 1, 200 );
	}

	/**
	 * @return array<int,object>
	 */
	private function products_for_run_scope( string $scope, int $limit ): array {
		$settings = $this->settings->get_all();
		if ( 'missing_links' === $scope && ! empty( $settings['discovery_rediscover_missing_links_only'] ) ) {
			return $this->discovery_repository->get_enabled_products_without_competitor_links_for_matching( $limit );
		}

		return $this->discovery_repository->get_enabled_products_for_matching( $limit );
	}

	private function sanitize_run_scope( string $scope ): string {
		$scope = sanitize_key( $scope );

		return in_array( $scope, array( 'missing_links', 'all_selected' ), true ) ? $scope : 'missing_links';
	}

	/**
	 * Queue another small discovery batch instead of trying to drain all selected products at once.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function queue_continuation( int $competitor_id, int $offset, string $scope, array $settings ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$args = array( absint( $competitor_id ), absint( $offset ), $this->sanitize_run_scope( $scope ) );
		if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( self::ACTION, $args, 'lilleprinsen-price-monitor' ) ) {
			return;
		}

		$spacing_minutes = max( 1, min( 180, absint( $settings['discovery_batch_spacing_minutes'] ?? 15 ) ) );
		as_schedule_single_action( time() + ( $spacing_minutes * MINUTE_IN_SECONDS ), self::ACTION, $args, 'lilleprinsen-price-monitor' );
	}

	/** Record competitor health. */
	private function record_health( int $competitor_id, bool $success, string $error, string $content_hash ): void {
		$settings = $this->settings->get_all();
		$this->discovery_repository->record_competitor_health( $competitor_id, $success, $error, $content_hash, $this->discovery_repository->count_suggestions( 'pending' ), 0, absint( $settings['discovery_auto_pause_failures'] ?? 5 ) );
		if ( ! $success ) {
			$health = $this->discovery_repository->get_competitor_health_rows();
			foreach ( $health as $row ) {
				if ( (int) $row->competitor_id === $competitor_id && 'paused' === (string) $row->status ) {
					$this->repository->set_competitor_enabled( $competitor_id, false );
					break;
				}
			}
		}
	}

	/** Delay between requests. */
	private function delay( array $settings ): void {
		$delay = absint( $settings['discovery_request_delay_seconds'] ?? 3 );
		if ( $delay > 0 ) {
			sleep( min( 5, $delay ) );
		}
	}
}
