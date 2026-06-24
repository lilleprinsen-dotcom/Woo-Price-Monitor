<?php
/**
 * Bounded competitor discovery refresh job.
 *
 * @package LillePrinsen\PriceMonitor\Jobs
 */

namespace LillePrinsen\PriceMonitor\Jobs;

use LillePrinsen\PriceMonitor\Database\DiscoveryRepository;
use LillePrinsen\PriceMonitor\Database\DiscoverySchema;
use LillePrinsen\PriceMonitor\Database\Repository;
use LillePrinsen\PriceMonitor\Service\CompetitorProductExtractor;
use LillePrinsen\PriceMonitor\Service\MatchSuggestionService;
use LillePrinsen\PriceMonitor\Settings\DiscoverySettings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Runs safe, resumable discovery batches.
 */
class CompetitorDiscoveryJob {
    public const ACTION = 'lpm_run_competitor_discovery_batch';

    private const LOCK_KEY = 'lpm_competitor_discovery_lock';

    private Repository $repository;
    private DiscoveryRepository $discovery_repository;
    private DiscoverySettings $settings;
    private CompetitorProductExtractor $extractor;
    private MatchSuggestionService $matcher;

    /**
     * Constructor.
     */
    public function __construct(
        Repository $repository,
        DiscoveryRepository $discovery_repository,
        DiscoverySettings $settings,
        CompetitorProductExtractor $extractor,
        MatchSuggestionService $matcher
    ) {
        $this->repository           = $repository;
        $this->discovery_repository = $discovery_repository;
        $this->settings             = $settings;
        $this->extractor            = $extractor;
        $this->matcher              = $matcher;
    }

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action( self::ACTION, array( $this, 'run' ), 10, 2 );
        add_action( 'init', array( $this, 'maybe_schedule' ) );
    }

    /**
     * Schedule weekly discovery if enabled.
     */
    public function maybe_schedule(): void {
        $settings = $this->settings->get_all();
        if ( empty( $settings['discovery_enabled'] ) || ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
            return;
        }

        if ( as_next_scheduled_action( self::ACTION ) ) {
            return;
        }

        $hour = absint( $settings['discovery_low_traffic_hour'] ?? 2 );
        $first = strtotime( 'next Sunday ' . $hour . ':00:00', current_time( 'timestamp' ) );
        as_schedule_recurring_action( $first ?: time() + DAY_IN_SECONDS, WEEK_IN_SECONDS, self::ACTION, array( 0, 0 ), 'lilleprinsen-price-monitor' );
    }

    /**
     * Queue a manual batch when Action Scheduler is available.
     */
    public function enqueue_manual_batch( int $competitor_id = 0 ): bool {
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( self::ACTION, array( $competitor_id, 0 ), 'lilleprinsen-price-monitor' );
            return true;
        }

        return false;
    }

    /**
     * Run one bounded refresh batch.
     */
    public function run( int $competitor_id = 0, int $offset = 0 ): void {
        if ( get_transient( self::LOCK_KEY ) ) {
            return;
        }

        set_transient( self::LOCK_KEY, 1, 10 * MINUTE_IN_SECONDS );
        DiscoverySchema::maybe_upgrade();

        $settings = $this->settings->get_all();
        $limit    = max( 1, min( 500, absint( $settings['discovery_max_product_pages_per_run'] ?? 50 ) ) );
        $run_id   = $this->discovery_repository->create_run( $competitor_id > 0 ? $competitor_id : null, 'scheduled_refresh', $limit );
        $processed = 0;
        $suggestions = 0;
        $failures = 0;
        $last_error = '';

        try {
            $rows = $this->get_rows_for_refresh( $competitor_id, $limit, $offset );
            $selected_products = $this->discovery_repository->get_enabled_products_for_matching( 500 );

            foreach ( $rows as $row ) {
                $competitor = $this->repository->get_competitor( (int) $row->competitor_id );
                if ( ! $competitor || empty( $competitor['enabled'] ) ) {
                    continue;
                }

                $result = $this->extractor->test_url( (string) $row->url, $competitor );
                if ( empty( $result['success'] ) ) {
                    ++$failures;
                    $last_error = (string) ( $result['technical_details'] ?? $result['message'] ?? '' );
                    continue;
                }

                $stored_id = $this->discovery_repository->store_discovered_product( (int) $row->competitor_id, (string) $row->url, $result );
                $stored = $this->discovery_repository->get_discovered_product( $stored_id );
                if ( $stored ) {
                    $suggestions += count( $this->matcher->create_suggestions( $stored_id, $stored, $selected_products ) );
                }

                ++$processed;
                $delay = absint( $settings['discovery_request_delay_seconds'] ?? 3 );
                if ( $delay > 0 ) {
                    sleep( min( 5, $delay ) );
                }
            }

            $this->discovery_repository->finish_run( $run_id, 'completed', $processed, $suggestions, $failures, $last_error );
        } catch ( \Throwable $error ) {
            $this->discovery_repository->finish_run( $run_id, 'failed', $processed, $suggestions, $failures + 1, $error->getMessage() );
        }

        delete_transient( self::LOCK_KEY );
    }

    /**
     * Read discovered product rows due for refresh.
     *
     * @return array<int,object>
     */
    private function get_rows_for_refresh( int $competitor_id, int $limit, int $offset ): array {
        global $wpdb;

        $tables = DiscoverySchema::table_names();
        $table  = $tables['discovered_competitor_products'];

        if ( $competitor_id > 0 ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE competitor_id = %d ORDER BY last_checked_at ASC, id ASC LIMIT %d OFFSET %d",
                    $competitor_id,
                    $limit,
                    max( 0, $offset )
                )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY last_checked_at ASC, id ASC LIMIT %d OFFSET %d",
                $limit,
                max( 0, $offset )
            )
        );
    }
}
