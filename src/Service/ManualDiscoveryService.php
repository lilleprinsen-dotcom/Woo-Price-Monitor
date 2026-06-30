<?php
/**
 * Manual competitor discovery runs for live admin feedback.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

use Lilleprinsen\PriceMonitor\Database\DiscoveryRepository;
use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and processes bounded manual discovery runs.
 */
class ManualDiscoveryService {
	private const OPTION_PREFIX = 'lpm_manual_discovery_run_';
	private const MAX_RESULTS_PER_RUN = 500;
	private const MAX_PAIRS_PER_RUN = 500;
	private const RETENTION_SECONDS = DAY_IN_SECONDS;

	private Repository $repository;
	private DiscoveryRepository $discovery_repository;
	private DiscoverySettings $settings;
	private SkuSearchDiscoveryService $sku_search;
	private CompetitorProductExtractor $extractor;
	private MatchSuggestionService $matcher;
	private DiscoveryUrlService $url_service;

	/** Constructor. */
	public function __construct( Repository $repository, DiscoveryRepository $discovery_repository, DiscoverySettings $settings, SkuSearchDiscoveryService $sku_search, CompetitorProductExtractor $extractor, MatchSuggestionService $matcher, DiscoveryUrlService $url_service ) {
		$this->repository           = $repository;
		$this->discovery_repository = $discovery_repository;
		$this->settings             = $settings;
		$this->sku_search           = $sku_search;
		$this->extractor            = $extractor;
		$this->matcher              = $matcher;
		$this->url_service          = $url_service;
	}

	/**
	 * Create a manual run from selected filters.
	 *
	 * @return array<string,mixed>
	 */
	public function create_run( int $discovery_product_id = 0, int $competitor_id = 0 ): array {
		$this->cleanup_stale_runs();
		$settings    = $this->settings->get_all();
		$products    = $this->selected_products_for_manual_run( $discovery_product_id, (int) $settings['discovery_manual_max_products_per_run'] );
		$competitors = $this->competitors_for_manual_run( $competitor_id, (int) $settings['discovery_manual_max_competitors_per_run'] );
		$pairs       = self::build_run_pairs( $products, $competitors );
		$run_id      = wp_generate_uuid4();
		$run         = self::build_run_state( $run_id, $pairs );

		$this->save_run( $run );
		$this->log_event( 'info', 'manual_discovery_run_started', __( 'Manual competitor discovery run started.', 'lilleprinsen-price-monitor' ), array( 'run_id' => $run_id, 'total_pairs' => (int) $run['total'], 'discovery_product_id' => $discovery_product_id, 'competitor_id' => $competitor_id ) );

		return $this->public_run_state( $run );
	}

	/**
	 * Create a targeted one product/competitor retest run.
	 *
	 * @return array<string,mixed>
	 */
	public function create_retest_run( int $discovery_product_id, int $competitor_id ): array {
		if ( $discovery_product_id <= 0 || $competitor_id <= 0 ) {
			return array( 'success' => false, 'message' => __( 'Retest needs a product and competitor.', 'lilleprinsen-price-monitor' ) );
		}

		$run = $this->create_run( $discovery_product_id, $competitor_id );
		return array_merge( array( 'success' => true ), $run );
	}

	/**
	 * Process one bounded batch.
	 *
	 * @return array<string,mixed>
	 */
	public function process_batch( string $run_id, int $batch_size = 1 ): array {
		$run = $this->get_run( $run_id );
		if ( empty( $run ) ) {
			return array( 'success' => false, 'message' => __( 'Manual discovery run was not found.', 'lilleprinsen-price-monitor' ) );
		}
		if ( in_array( (string) $run['status'], array( 'completed', 'cancelled' ), true ) ) {
			return array_merge( array( 'success' => true, 'rows' => array(), 'wait_seconds' => 0 ), $this->public_run_state( $run ) );
		}

		$rows       = array();
		$batch_size = max( 1, min( 5, $batch_size ) );

		while ( count( $rows ) < $batch_size && (int) $run['cursor'] < (int) $run['total'] ) {
			$pair = $run['pairs'][ (int) $run['cursor'] ] ?? null;
			if ( ! is_array( $pair ) ) {
				$run['cursor']++;
				continue;
			}

			$wait = $this->wait_seconds_for_competitor( $run, (int) ( $pair['competitor_id'] ?? 0 ) );
			if ( $wait > 0 ) {
				$this->save_run( $run );
				return array_merge( array( 'success' => true, 'rows' => $rows, 'wait_seconds' => $wait ), $this->public_run_state( $run ) );
			}

			$row = $this->finalize_row( $this->process_pair( $pair ) );
			$rows[] = $row;
			$run['results'][] = $row;
			if ( count( $run['results'] ) > self::MAX_RESULTS_PER_RUN ) {
				$run['results'] = array_slice( $run['results'], - self::MAX_RESULTS_PER_RUN );
			}
			$run['cursor']++;
			$run['processed']++;
			if ( 'found' === $row['status'] ) {
				$run['found']++;
			}
			if ( 'error' === $row['status'] ) {
				$run['errors']++;
			}
			$run['competitor_last_seen'][ (string) ( $pair['competitor_id'] ?? 0 ) ] = time();
		}

		if ( (int) $run['cursor'] >= (int) $run['total'] ) {
			$run['status'] = 'completed';
			$this->log_event( 'info', 'manual_discovery_run_completed', __( 'Manual competitor discovery run completed.', 'lilleprinsen-price-monitor' ), array( 'run_id' => (string) $run['id'], 'processed' => (int) $run['processed'], 'found' => (int) $run['found'], 'errors' => (int) $run['errors'] ) );
		}
		$run['updated_at'] = current_time( 'mysql' );
		$this->save_run( $run );

		return array_merge( array( 'success' => true, 'rows' => $rows, 'wait_seconds' => 0 ), $this->public_run_state( $run ) );
	}

	/**
	 * Cancel a run without deleting existing suggestions.
	 *
	 * @return array<string,mixed>
	 */
	public function cancel_run( string $run_id ): array {
		$run = $this->get_run( $run_id );
		if ( empty( $run ) ) {
			return array( 'success' => false, 'message' => __( 'Manual discovery run was not found.', 'lilleprinsen-price-monitor' ) );
		}

		if ( 'completed' !== (string) $run['status'] ) {
			$run['status'] = 'cancelled';
			$run['updated_at'] = current_time( 'mysql' );
			$this->save_run( $run );
			$this->log_event( 'warning', 'manual_discovery_run_cancelled', __( 'Manual competitor discovery run cancelled.', 'lilleprinsen-price-monitor' ), array( 'run_id' => $run_id, 'processed' => (int) ( $run['processed'] ?? 0 ), 'total' => (int) ( $run['total'] ?? 0 ) ) );
		}

		return array_merge( array( 'success' => true, 'message' => __( 'Manual discovery run cancelled.', 'lilleprinsen-price-monitor' ) ), $this->public_run_state( $run ) );
	}

	/**
	 * Approve a discovery match suggestion from live results.
	 *
	 * @return array<string,mixed>
	 */
	public function approve_suggestion( int $suggestion_id, int $user_id ): array {
		$suggestion = $this->discovery_repository->get_suggestion( $suggestion_id );
		if ( ! $suggestion || 'pending' !== (string) $suggestion->status ) {
			return array( 'success' => false, 'message' => __( 'Only pending suggestions can be approved.', 'lilleprinsen-price-monitor' ) );
		}

		$product    = $this->discovery_repository->get_discovery_product( (int) $suggestion->discovery_product_id );
		$competitor = $this->repository->get_competitor( (int) $suggestion->competitor_id );
		$monitored  = $this->repository->get_monitored_product_by_product_id( (int) $suggestion->product_id );
		$created    = $monitored ? array( 'success' => true, 'id' => (int) $monitored['id'] ) : $this->repository->add_monitored_product( (int) $suggestion->product_id, $product ? (string) $product->sku : '' );
		$monitored_id = (int) ( $created['id'] ?? 0 );
		if ( $monitored_id <= 0 ) {
			return array( 'success' => false, 'message' => __( 'The product could not be added to monitoring.', 'lilleprinsen-price-monitor' ) );
		}

		$data = self::competitor_link_data_for_approval( $suggestion, $competitor ?: array(), $monitored_id );
		$existing_link = $this->repository->get_competitor_link_by_url( $monitored_id, (string) $suggestion->competitor_url );
		$link_id       = $existing_link ? (int) $existing_link['id'] : $this->repository->add_competitor_link( $data );
		if ( $existing_link ) {
			$this->repository->update_competitor_link( $link_id, $data );
		}
		if ( $link_id <= 0 ) {
			return array( 'success' => false, 'message' => __( 'The active competitor link could not be saved.', 'lilleprinsen-price-monitor' ) );
		}

		$this->discovery_repository->approve_suggestion( $suggestion_id, $user_id, $link_id );
		$this->log_event( 'info', 'manual_discovery_suggestion_approved', __( 'Manual discovery suggestion approved as an active monitored competitor link.', 'lilleprinsen-price-monitor' ), array( 'suggestion_id' => $suggestion_id, 'competitor_link_id' => $link_id, 'competitor_id' => (int) $suggestion->competitor_id ), (int) $suggestion->product_id );

		return array(
			'success' => true,
			'message' => __( 'Approved. This is now an active monitored competitor link.', 'lilleprinsen-price-monitor' ),
			'competitor_link_id' => $link_id,
		);
	}

	/**
	 * Reject a live discovery suggestion.
	 *
	 * @return array<string,mixed>
	 */
	public function reject_suggestion( int $suggestion_id, int $user_id ): array {
		$suggestion = $this->discovery_repository->get_suggestion( $suggestion_id );
		if ( ! $suggestion || 'pending' !== (string) $suggestion->status ) {
			return array( 'success' => false, 'message' => __( 'Only pending suggestions can be rejected.', 'lilleprinsen-price-monitor' ) );
		}

		$this->discovery_repository->reject_suggestion( $suggestion_id, $user_id );
		$this->log_event( 'info', 'manual_discovery_suggestion_rejected', __( 'Manual discovery suggestion rejected.', 'lilleprinsen-price-monitor' ), array( 'suggestion_id' => $suggestion_id, 'competitor_id' => (int) $suggestion->competitor_id ), (int) $suggestion->product_id );

		return array( 'success' => true, 'message' => __( 'Suggestion rejected. It will not reappear unless the competitor page changes.', 'lilleprinsen-price-monitor' ) );
	}

	/**
	 * Build selected product and competitor pairs.
	 *
	 * @param array<int,object>              $products Selected products.
	 * @param array<int,array<string,mixed>> $competitors Competitors.
	 * @return array<int,array<string,mixed>>
	 */
	public static function build_run_pairs( array $products, array $competitors ): array {
		$pairs = array();
		foreach ( $products as $product ) {
			foreach ( $competitors as $competitor ) {
				$pairs[] = array(
					'discovery_product_id' => (int) ( $product->id ?? 0 ),
					'product_id'           => (int) ( $product->product_id ?? 0 ),
					'variation_id'         => (int) ( $product->variation_id ?? 0 ),
					'product_title'        => self::product_title_for_row( $product ),
					'sku'                  => (string) ( $product->sku ?? '' ),
					'gtin'                 => (string) ( $product->gtin ?? '' ),
					'brand'                => (string) ( $product->brand ?? '' ),
					'competitor_id'        => (int) ( $competitor['id'] ?? 0 ),
					'competitor_name'      => (string) ( $competitor['name'] ?? '' ),
				);
			}
		}

		return $pairs;
	}

	/**
	 * Whether a UI selection should warn before creating the run.
	 */
	public static function needs_preflight_confirmation( int $discovery_product_id, int $competitor_id, int $selected_product_count, int $active_competitor_count ): bool {
		if ( $discovery_product_id <= 0 && $selected_product_count > 1 ) {
			return true;
		}
		if ( $competitor_id <= 0 && $active_competitor_count > 1 ) {
			return true;
		}

		return false;
	}

	/**
	 * Build initial manual run state.
	 *
	 * @param array<int,array<string,mixed>> $pairs Product/competitor pairs.
	 * @return array<string,mixed>
	 */
	public static function build_run_state( string $run_id, array $pairs ): array {
		$pairs = array_slice( $pairs, 0, self::MAX_PAIRS_PER_RUN );

		return array(
			'id'                   => $run_id,
			'status'               => empty( $pairs ) ? 'completed' : 'running',
			'cursor'               => 0,
			'total'                => count( $pairs ),
			'processed'            => 0,
			'found'                => 0,
			'errors'               => 0,
			'pairs'                => $pairs,
			'results'              => array(),
			'competitor_last_seen' => array(),
			'created_at'           => current_time( 'mysql' ),
			'updated_at'           => current_time( 'mysql' ),
		);
	}

	/**
	 * Delete stale manual run option payloads. Suggestions/links are untouched.
	 *
	 * @return int Deleted count.
	 */
	public static function cleanup_stale_run_options( array &$options, int $now ): int {
		$deleted = 0;
		foreach ( $options as $key => $run ) {
			if ( ! str_starts_with( (string) $key, self::OPTION_PREFIX ) || ! is_array( $run ) ) {
				continue;
			}
			$updated = strtotime( (string) ( $run['updated_at'] ?? $run['created_at'] ?? '' ) );
			if ( ! $updated ) {
				$updated = 0;
			}
			$status = (string) ( $run['status'] ?? '' );
			$stale_running = ! in_array( $status, array( 'completed', 'cancelled' ), true ) && $updated < ( $now - self::RETENTION_SECONDS );
			$old_terminal = in_array( $status, array( 'completed', 'cancelled' ), true ) && $updated < ( $now - self::RETENTION_SECONDS );
			if ( $stale_running || $old_terminal ) {
				unset( $options[ $key ] );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Build active competitor link data for an approved live result.
	 *
	 * @param object              $suggestion Discovery suggestion row.
	 * @param array<string,mixed> $competitor Competitor profile.
	 * @return array<string,mixed>
	 */
	public static function competitor_link_data_for_approval( object $suggestion, array $competitor, int $monitored_id ): array {
		return array(
			'monitored_product_id' => $monitored_id,
			'competitor_id'        => (int) $suggestion->competitor_id,
			'competitor_name'      => $competitor['name'] ?? '',
			'competitor_url'       => (string) $suggestion->competitor_url,
			'match_type'           => 'High confidence' === (string) $suggestion->confidence_label ? 'exact' : 'similar',
			'enabled'              => 1,
			'is_primary'           => 0,
		);
	}

	public static function caution_for_confidence( string $confidence_label ): string {
		return 'Low confidence' === $confidence_label ? 'Low confidence: review model, color, bundle and variant carefully before approval.' : '';
	}

	/**
	 * Return a stable no-match reason.
	 *
	 * @param array<string,mixed> $search_result Search result.
	 * @param array<string,mixed> $extract_result Extract result.
	 */
	public static function no_match_reason( array $search_result = array(), array $extract_result = array() ): string {
		$details = strtolower( (string) ( $search_result['technical_details'] ?? $extract_result['technical_details'] ?? '' ) );
		if ( str_contains( $details, 'no search page' ) || str_contains( $details, 'search template' ) ) {
			return 'competitor search URL not configured';
		}
		if ( str_contains( $details, 'timed out' ) || str_contains( $details, 'timeout' ) ) {
			return 'request timed out';
		}
		if ( str_contains( $details, 'http status' ) ) {
			return 'HTTP blocked/error';
		}
		if ( ! empty( $extract_result['requires_javascript'] ) || str_contains( $details, 'javascript' ) ) {
			return 'JavaScript required';
		}
		if ( array_key_exists( 'monitored_price', $extract_result ) && null === $extract_result['monitored_price'] ) {
			return 'no price found';
		}
		if ( str_contains( $details, 'no product urls' ) ) {
			return 'search page returned no product URLs';
		}

		return 'no SKU/EAN/title match';
	}

	/**
	 * @return array<int,object>
	 */
	private function selected_products_for_manual_run( int $discovery_product_id, int $limit ): array {
		$limit = max( 1, min( 200, $limit ) );
		if ( $discovery_product_id > 0 ) {
			$product = $this->discovery_repository->get_discovery_product( $discovery_product_id );
			return $product && ! empty( $product->enabled ) ? array( $product ) : array();
		}

		return $this->discovery_repository->get_enabled_products_for_matching( $limit );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function competitors_for_manual_run( int $competitor_id, int $limit ): array {
		$limit = max( 1, min( 50, $limit ) );
		if ( $competitor_id > 0 ) {
			$competitor = $this->repository->get_competitor( $competitor_id );
			return $competitor && ! empty( $competitor['enabled'] ) ? array( $competitor ) : array();
		}

		return array_values(
			array_filter(
				array_slice( $this->repository->get_competitors( 1, $limit ), 0, $limit ),
				static fn( $competitor ) => ! empty( $competitor['enabled'] )
			)
		);
	}

	/**
	 * @param array<string,mixed> $pair Run pair.
	 * @return array<string,mixed>
	 */
	private function process_pair( array $pair ): array {
		$product = $this->discovery_repository->get_discovery_product( (int) $pair['discovery_product_id'] );
		$competitor = $this->repository->get_competitor( (int) $pair['competitor_id'] );
		$row = $this->base_result_row( $pair );
		$this->log_event( 'info', 'manual_discovery_pair_checked', __( 'Manual discovery checked a selected product against a competitor.', 'lilleprinsen-price-monitor' ), array( 'discovery_product_id' => (int) $pair['discovery_product_id'], 'competitor_id' => (int) $pair['competitor_id'] ), (int) ( $pair['product_id'] ?? 0 ) );
		if ( ! $product || ! $competitor ) {
			$row['status'] = 'error';
			$row['error'] = __( 'Selected product or competitor was not found.', 'lilleprinsen-price-monitor' );
			return $row;
		}

		$search = $this->sku_search->discover_for_product( $competitor, $product );
		$row['search_url'] = $this->first_search_url( $competitor, $product );
		if ( empty( $search['success'] ) || empty( $search['urls'] ) ) {
			$row['status'] = 'no_match';
			$row['error'] = self::no_match_reason( $search );
			$row['details'] = (string) ( $search['technical_details'] ?? '' );
			$this->discovery_repository->mark_discovery_product_run( (int) $product->id );
			$this->log_event( 'warning', 'manual_discovery_no_match', __( 'Manual discovery found no competitor match.', 'lilleprinsen-price-monitor' ), array( 'discovery_product_id' => (int) $product->id, 'competitor_id' => (int) $pair['competitor_id'], 'reason' => (string) $row['error'], 'search_url' => (string) $row['search_url'] ), (int) $product->product_id );
			return $row;
		}

		foreach ( array_slice( (array) $search['urls'], 0, 3 ) as $url ) {
			$result = $this->extractor->test_url( (string) $url, $competitor );
			if ( empty( $result['success'] ) ) {
				$row['status'] = 'error';
				$row['competitor_url'] = (string) $url;
				$row['error'] = self::no_match_reason( $search, $result );
				$row['details'] = (string) ( $result['technical_details'] ?? '' );
				$event = 'JavaScript required' === (string) $row['error'] ? 'manual_discovery_js_required' : 'manual_discovery_price_extraction_failed';
				$this->log_event( 'warning', $event, __( 'Manual discovery could not extract a usable competitor product page.', 'lilleprinsen-price-monitor' ), array( 'discovery_product_id' => (int) $product->id, 'competitor_id' => (int) $pair['competitor_id'], 'reason' => (string) $row['error'], 'url' => (string) $url ), (int) $product->product_id );
				continue;
			}

			$stored_id = $this->discovery_repository->store_discovered_product( (int) $pair['competitor_id'], (string) $url, $result );
			$stored    = $this->discovery_repository->get_discovered_product( $stored_id );
			$suggestion_ids = $stored ? $this->matcher->create_suggestions( $stored_id, $stored, array( $product ) ) : array();
			if ( empty( $suggestion_ids ) ) {
				$row['status'] = 'no_match';
				$row['competitor_title'] = (string) ( $result['title'] ?? '' );
				$row['competitor_url'] = (string) $url;
				$row['detected_sku'] = (string) ( $result['sku'] ?? '' );
				$row['detected_gtin'] = (string) ( $result['gtin'] ?? '' );
				$row['detected_price'] = $this->format_detected_price( $result );
				$row['error'] = self::no_match_reason( $search, $result );
				$this->log_event( 'warning', 'manual_discovery_no_match', __( 'Manual discovery read a competitor page but did not create a match.', 'lilleprinsen-price-monitor' ), array( 'discovery_product_id' => (int) $product->id, 'competitor_id' => (int) $pair['competitor_id'], 'reason' => (string) $row['error'], 'url' => (string) $url ), (int) $product->product_id );
				continue;
			}

			$suggestion = $this->discovery_repository->get_suggestion( (int) reset( $suggestion_ids ) );
			$row['status'] = 'found';
			$row['suggestion_id'] = $suggestion ? (int) $suggestion->id : 0;
			$row['competitor_title'] = (string) ( $result['title'] ?? '' );
			$row['competitor_url'] = (string) $url;
			$row['detected_sku'] = (string) ( $result['sku'] ?? '' );
			$row['detected_gtin'] = (string) ( $result['gtin'] ?? '' );
			$row['detected_price'] = $this->format_detected_price( $result );
			$row['confidence'] = $suggestion ? (string) $suggestion->confidence_label : '';
			$row['match_type'] = $suggestion ? (string) $suggestion->match_type : '';
			$row['caution'] = $suggestion ? self::caution_for_confidence( (string) $suggestion->confidence_label ) : '';
			$row['match_reason'] = $suggestion ? (string) $suggestion->explanation : '';
			$row['error'] = '';
			$this->discovery_repository->mark_discovery_product_run( (int) $product->id );
			$this->log_event( 'info', 'manual_discovery_match_found', __( 'Manual discovery found a suggested competitor match.', 'lilleprinsen-price-monitor' ), array( 'discovery_product_id' => (int) $product->id, 'competitor_id' => (int) $pair['competitor_id'], 'suggestion_id' => (int) $row['suggestion_id'], 'confidence' => (string) $row['confidence'], 'url' => (string) $url ), (int) $product->product_id );
			return $row;
		}

		$this->discovery_repository->mark_discovery_product_run( (int) $product->id );
		return $row;
	}

	/** @param array<string,mixed> $pair Pair. */
	private function base_result_row( array $pair ): array {
		return array(
			'pair_key' => (int) ( $pair['discovery_product_id'] ?? 0 ) . ':' . (int) ( $pair['competitor_id'] ?? 0 ),
			'discovery_product_id' => (int) ( $pair['discovery_product_id'] ?? 0 ),
			'competitor_id' => (int) ( $pair['competitor_id'] ?? 0 ),
			'product_title' => (string) ( $pair['product_title'] ?? '' ),
			'sku' => (string) ( $pair['sku'] ?? '' ),
			'gtin' => (string) ( $pair['gtin'] ?? '' ),
			'brand' => (string) ( $pair['brand'] ?? '' ),
			'competitor_name' => (string) ( $pair['competitor_name'] ?? '' ),
			'search_url' => '',
			'status' => 'searching',
			'competitor_title' => '',
			'competitor_url' => '',
			'detected_sku' => '',
			'detected_gtin' => '',
			'detected_price' => '',
			'confidence' => '',
			'match_type' => '',
			'caution' => '',
			'match_reason' => '',
			'error' => '',
			'details' => '',
			'suggestion_id' => 0,
		);
	}

	/** @param array<string,mixed> $row Row. */
	public function finalize_row( array $row ): array {
		if ( 'searching' === (string) ( $row['status'] ?? '' ) || '' === (string) ( $row['status'] ?? '' ) ) {
			$row['status'] = '' !== (string) ( $row['error'] ?? '' ) ? 'error' : 'no_match';
			if ( '' === (string) ( $row['error'] ?? '' ) ) {
				$row['error'] = self::no_match_reason();
			}
		}

		return $row;
	}

	private function wait_seconds_for_competitor( array $run, int $competitor_id ): int {
		$competitor = $competitor_id > 0 ? $this->repository->get_competitor( $competitor_id ) : null;
		$delay = $competitor ? absint( $competitor['request_delay_seconds'] ?? 0 ) : absint( $this->settings->get( 'discovery_request_delay_seconds' ) );
		$last  = absint( $run['competitor_last_seen'][ (string) $competitor_id ] ?? 0 );
		if ( $delay <= 0 || $last <= 0 ) {
			return 0;
		}

		return max( 0, ( $last + $delay ) - time() );
	}

	private function first_search_url( array $competitor, object $product ): string {
		$templates = $this->sku_search->search_templates( $competitor );
		$domain = (string) ( $competitor['domain'] ?? '' );
		$sku = (string) ( $product->sku ?? '' );
		if ( '' === $domain || '' === $sku || empty( $templates ) ) {
			return '';
		}

		return $this->sku_search->build_search_url( $domain, (string) reset( $templates ), $sku );
	}

	/** @param array<string,mixed> $result Extract result. */
	private function format_detected_price( array $result ): string {
		$price = $result['monitored_price'] ?? $result['sale_price'] ?? $result['regular_price'] ?? null;
		return null === $price ? '' : wc_format_decimal( $price, 2 ) . ( ! empty( $result['currency'] ) ? ' ' . (string) $result['currency'] : '' );
	}

	private static function product_title_for_row( object $product ): string {
		$id = (int) ( $product->variation_id ?: $product->product_id );
		return function_exists( 'get_the_title' ) && $id > 0 ? (string) get_the_title( $id ) : (string) ( $product->sku ?? '' );
	}

	/** @return array<string,mixed> */
	private function get_run( string $run_id ): array {
		$run = get_option( self::OPTION_PREFIX . sanitize_key( $run_id ), array() );
		return is_array( $run ) ? $run : array();
	}

	/** @param array<string,mixed> $run Run state. */
	private function save_run( array $run ): void {
		update_option( self::OPTION_PREFIX . sanitize_key( (string) $run['id'] ), $run, false );
	}

	private function cleanup_stale_runs(): void {
		global $wpdb;

		if ( ! $wpdb || ! method_exists( $wpdb, 'get_results' ) ) {
			return;
		}

		$like = self::OPTION_PREFIX . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d", $like, 100 ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$name = (string) ( $row['option_name'] ?? '' );
			$run  = maybe_unserialize( $row['option_value'] ?? '' );
			if ( ! is_array( $run ) ) {
				continue;
			}
			$options = array( $name => $run );
			if ( self::cleanup_stale_run_options( $options, time() ) > 0 ) {
				delete_option( $name );
			}
		}
	}

	/** @param array<string,mixed> $run Run state. */
	private function public_run_state( array $run ): array {
		return array(
			'run_id' => (string) ( $run['id'] ?? '' ),
			'status' => (string) ( $run['status'] ?? '' ),
			'total' => (int) ( $run['total'] ?? 0 ),
			'processed' => (int) ( $run['processed'] ?? 0 ),
			'found' => (int) ( $run['found'] ?? 0 ),
			'errors' => (int) ( $run['errors'] ?? 0 ),
			'large_run' => (int) ( $run['total'] ?? 0 ) >= 25,
		);
	}

	/**
	 * Write an admin-visible diagnostic log when the repository is available.
	 *
	 * @param array<string,mixed> $context Log context.
	 */
	private function log_event( string $level, string $event, string $message, array $context = array(), ?int $product_id = null ): void {
		if ( ! isset( $this->repository ) || ! method_exists( $this->repository, 'write_log' ) ) {
			return;
		}

		$allowed = array( 'run_id', 'total_pairs', 'processed', 'found', 'errors', 'total', 'discovery_product_id', 'competitor_id', 'suggestion_id', 'competitor_link_id', 'confidence', 'reason', 'search_url', 'url' );
		$safe_context = array_intersect_key( $context, array_flip( $allowed ) );
		$this->repository->write_log( $level, $event, $message, $safe_context, $product_id );
	}
}
