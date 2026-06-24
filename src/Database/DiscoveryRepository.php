<?php
/**
 * Repository for competitor discovery data.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for selected products, seed URLs, extracted competitor pages, suggestions and health.
 */
class DiscoveryRepository {
	/**
	 * Store or update a selected discovery product.
	 *
	 * @param int                  $product_id Product ID.
	 * @param int                  $variation_id Variation ID, or 0.
	 * @param array<string,string> $identifiers Cached identifiers.
	 */
	public function upsert_discovery_product( int $product_id, int $variation_id, array $identifiers ): int {
		global $wpdb;

		$table       = $this->table( 'discovery_products' );
		$now         = current_time( 'mysql' );
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE product_id = %d AND variation_id = %d LIMIT 1", $product_id, $variation_id )
		);

		$data = array(
			'product_id'       => $product_id,
			'variation_id'     => $variation_id,
			'enabled'          => 1,
			'priority'         => 'normal',
			'sku'              => $identifiers['sku'] ?? '',
			'gtin'             => $identifiers['gtin'] ?? '',
			'gtin_source'      => $identifiers['gtin_source'] ?? '',
			'mpn'              => $identifiers['mpn'] ?? '',
			'brand'            => $identifiers['brand'] ?? '',
			'normalized_sku'   => $identifiers['normalized_sku'] ?? '',
			'normalized_gtin'  => $identifiers['normalized_gtin'] ?? '',
			'normalized_mpn'   => $identifiers['normalized_mpn'] ?? '',
			'status'           => 'selected',
			'updated_at'       => $now,
		);

		if ( $existing_id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $existing_id ) );
			return $existing_id;
		}

		$data['created_at'] = $now;
		$wpdb->insert( $table, $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Enable/disable a selected product.
	 */
	public function set_discovery_product_enabled( int $id, bool $enabled ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			$this->table( 'discovery_products' ),
			array(
				'enabled'    => $enabled ? 1 : 0,
				'status'     => $enabled ? 'selected' : 'removed',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Mark a selected product as touched by discovery.
	 */
	public function mark_discovery_product_run( int $id ): void {
		global $wpdb;

		$wpdb->update(
			$this->table( 'discovery_products' ),
			array(
				'last_discovery_at' => current_time( 'mysql' ),
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Get a selected product by ID.
	 */
	public function get_discovery_product( int $id ): ?object {
		global $wpdb;

		$table = $this->table( 'discovery_products' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return $row ?: null;
	}

	/**
	 * Get a selected product by WooCommerce product ID and variation ID.
	 */
	public function get_discovery_product_by_product_id( int $product_id, int $variation_id = 0 ): ?object {
		global $wpdb;

		$table = $this->table( 'discovery_products' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d AND variation_id = %d LIMIT 1", $product_id, $variation_id )
		);

		return $row ?: null;
	}

	/**
	 * List selected products with pagination.
	 *
	 * @return array<int,object>
	 */
	public function get_selected_products( int $page = 1, int $per_page = 50, string $search = '' ): array {
		global $wpdb;

		$table  = $this->table( 'discovery_products' );
		$offset = max( 0, ( $page - 1 ) * $per_page );

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE enabled = 1 AND (sku LIKE %s OR gtin LIKE %s OR mpn LIKE %s OR brand LIKE %s) ORDER BY updated_at DESC LIMIT %d OFFSET %d",
					$like,
					$like,
					$like,
					$like,
					$per_page,
					$offset
				)
			);
		}

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY updated_at DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
	}

	/**
	 * Count selected products.
	 */
	public function count_selected_products( string $search = '' ): int {
		global $wpdb;

		$table = $this->table( 'discovery_products' );

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1 AND (sku LIKE %s OR gtin LIKE %s OR mpn LIKE %s OR brand LIKE %s)", $like, $like, $like, $like )
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1" );
	}

	/**
	 * Get enabled selected products for matching.
	 *
	 * @return array<int,object>
	 */
	public function get_enabled_products_for_matching( int $limit = 500 ): array {
		global $wpdb;

		$table = $this->table( 'discovery_products' );

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY priority DESC, updated_at DESC LIMIT %d", $limit ) );
	}

	/**
	 * Identifier quality counters for selected products.
	 *
	 * @return array<string,int>
	 */
	public function get_identifier_quality_counts(): array {
		global $wpdb;

		$table = $this->table( 'discovery_products' );

		return array(
			'selected'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1" ),
			'with_sku'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1 AND normalized_sku <> ''" ),
			'with_gtin'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1 AND normalized_gtin <> ''" ),
			'with_mpn'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1 AND normalized_mpn <> ''" ),
			'missing_id' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1 AND normalized_sku = '' AND normalized_gtin = '' AND normalized_mpn = ''" ),
			'duplicates' => $this->count_duplicate_identifiers(),
		);
	}

	/**
	 * Store a competitor discovery seed URL.
	 *
	 * @param array<string,mixed> $data Seed data.
	 */
	public function upsert_seed_url( array $data ): int {
		global $wpdb;

		$table       = $this->table( 'discovery_seed_urls' );
		$now         = current_time( 'mysql' );
		$url_hash    = (string) ( $data['url_hash'] ?? hash( 'sha256', (string) ( $data['url'] ?? '' ) ) );
		$competitor  = absint( $data['competitor_id'] ?? 0 );
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE competitor_id = %d AND url_hash = %s LIMIT 1", $competitor, $url_hash ) );
		$row         = array(
			'competitor_id'        => $competitor,
			'source_type'          => $this->sanitize_source_type( (string) ( $data['source_type'] ?? 'product' ) ),
			'url_hash'             => $url_hash,
			'url'                  => esc_url_raw( (string) ( $data['url'] ?? '' ) ),
			'domain'               => sanitize_text_field( (string) ( $data['domain'] ?? '' ) ),
			'include_patterns'     => sanitize_textarea_field( (string) ( $data['include_patterns'] ?? '' ) ),
			'exclude_patterns'     => sanitize_textarea_field( (string) ( $data['exclude_patterns'] ?? '' ) ),
			'product_url_patterns' => sanitize_textarea_field( (string) ( $data['product_url_patterns'] ?? '' ) ),
			'status'               => sanitize_key( (string) ( $data['status'] ?? 'active' ) ),
			'updated_at'           => $now,
		);

		if ( $existing_id > 0 ) {
			$wpdb->update( $table, $row, array( 'id' => $existing_id ) );
			return $existing_id;
		}

		$row['created_at'] = $now;
		$wpdb->insert( $table, $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get seed URL.
	 */
	public function get_seed_url( int $id ): ?object {
		global $wpdb;

		$table = $this->table( 'discovery_seed_urls' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return $row ?: null;
	}

	/**
	 * List seed URLs for competitor.
	 *
	 * @return array<int,object>
	 */
	public function get_seed_urls_for_competitor( int $competitor_id ): array {
		global $wpdb;

		$table = $this->table( 'discovery_seed_urls' );

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE competitor_id = %d ORDER BY updated_at DESC, id DESC", $competitor_id ) );
	}

	/**
	 * Get due seed URLs.
	 *
	 * @return array<int,object>
	 */
	public function get_due_seed_urls( int $competitor_id, int $limit, int $offset = 0 ): array {
		global $wpdb;

		$table = $this->table( 'discovery_seed_urls' );
		if ( $competitor_id > 0 ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'active' AND competitor_id = %d ORDER BY last_checked_at IS NULL DESC, last_checked_at ASC, id ASC LIMIT %d OFFSET %d", $competitor_id, $limit, max( 0, $offset ) ) );
		}

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'active' ORDER BY last_checked_at IS NULL DESC, last_checked_at ASC, id ASC LIMIT %d OFFSET %d", $limit, max( 0, $offset ) ) );
	}

	/**
	 * Mark seed URL after processing.
	 */
	public function mark_seed_url_checked( int $id, string $error = '' ): void {
		global $wpdb;

		$wpdb->update(
			$this->table( 'discovery_seed_urls' ),
			array(
				'last_checked_at' => current_time( 'mysql' ),
				'last_error'      => $error,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Store extracted competitor product.
	 *
	 * @param int                 $competitor_id Competitor ID.
	 * @param string              $url URL.
	 * @param array<string,mixed> $extracted Extracted data.
	 */
	public function store_discovered_product( int $competitor_id, string $url, array $extracted ): int {
		global $wpdb;

		$table          = $this->table( 'discovered_competitor_products' );
		$now            = current_time( 'mysql' );
		$url_hash       = (string) ( $extracted['url_hash'] ?? hash( 'sha256', $url ) );
		$canonical_hash = ! empty( $extracted['canonical_url_hash'] ) ? (string) $extracted['canonical_url_hash'] : null;
		$existing_id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE competitor_id = %d AND url_hash = %s LIMIT 1", $competitor_id, $url_hash ) );
		$row            = array(
			'competitor_id'       => $competitor_id,
			'seed_url_id'         => ! empty( $extracted['seed_url_id'] ) ? absint( $extracted['seed_url_id'] ) : null,
			'discovery_source'    => sanitize_key( (string) ( $extracted['discovery_source'] ?? 'manual' ) ),
			'url_hash'            => $url_hash,
			'canonical_url_hash'  => $canonical_hash,
			'url'                 => esc_url_raw( $url ),
			'canonical_url'       => esc_url_raw( (string) ( $extracted['canonical_url'] ?? '' ) ),
			'domain'              => sanitize_text_field( (string) ( $extracted['domain'] ?? '' ) ),
			'title'               => sanitize_text_field( (string) ( $extracted['title'] ?? '' ) ),
			'brand'               => sanitize_text_field( (string) ( $extracted['brand'] ?? '' ) ),
			'sku'                 => sanitize_text_field( (string) ( $extracted['sku'] ?? '' ) ),
			'gtin'                => sanitize_text_field( (string) ( $extracted['gtin'] ?? '' ) ),
			'mpn'                 => sanitize_text_field( (string) ( $extracted['mpn'] ?? '' ) ),
			'normalized_sku'      => sanitize_text_field( (string) ( $extracted['normalized_sku'] ?? '' ) ),
			'normalized_gtin'     => sanitize_text_field( (string) ( $extracted['normalized_gtin'] ?? '' ) ),
			'normalized_mpn'      => sanitize_text_field( (string) ( $extracted['normalized_mpn'] ?? '' ) ),
			'regular_price'       => isset( $extracted['regular_price'] ) && '' !== $extracted['regular_price'] ? (float) $extracted['regular_price'] : null,
			'sale_price'          => isset( $extracted['sale_price'] ) && '' !== $extracted['sale_price'] ? (float) $extracted['sale_price'] : null,
			'currency'            => sanitize_text_field( (string) ( $extracted['currency'] ?? '' ) ),
			'stock_status'        => sanitize_key( (string) ( $extracted['stock_status'] ?? 'unknown' ) ),
			'image_url'           => esc_url_raw( (string) ( $extracted['image_url'] ?? '' ) ),
			'raw_metadata'        => wp_json_encode( $extracted['raw_metadata'] ?? array() ),
			'extraction_status'   => sanitize_key( (string) ( $extracted['extraction_status'] ?? 'unknown' ) ),
			'extraction_source'   => sanitize_text_field( (string) ( $extracted['extraction_source'] ?? '' ) ),
			'content_hash'        => sanitize_text_field( (string) ( $extracted['content_hash'] ?? '' ) ),
			'failure_count'       => empty( $extracted['success'] ) ? 1 : 0,
			'last_checked_at'     => $now,
			'updated_at'          => $now,
		);

		if ( $existing_id > 0 ) {
			$old_hash = (string) $wpdb->get_var( $wpdb->prepare( "SELECT content_hash FROM {$table} WHERE id = %d", $existing_id ) );
			if ( '' !== $old_hash && '' !== (string) $row['content_hash'] && $old_hash !== (string) $row['content_hash'] ) {
				$row['extraction_status'] = 'changed';
			}
			$wpdb->update( $table, $row, array( 'id' => $existing_id ) );
			return $existing_id;
		}

		$row['created_at'] = $now;
		$wpdb->insert( $table, $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Queue a product URL discovered from a seed page.
	 */
	public function queue_discovered_product_url( int $competitor_id, string $url, string $url_hash, int $seed_url_id, string $domain ): int {
		return $this->store_discovered_product(
			$competitor_id,
			$url,
			array(
				'url_hash'          => $url_hash,
				'seed_url_id'       => $seed_url_id,
				'discovery_source'  => 'seed',
				'domain'            => $domain,
				'extraction_status' => 'queued',
			)
		);
	}

	/**
	 * Get discovered competitor product.
	 */
	public function get_discovered_product( int $id ): ?object {
		global $wpdb;

		$table = $this->table( 'discovered_competitor_products' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return $row ?: null;
	}

	/**
	 * Product pages due for extraction.
	 *
	 * @return array<int,object>
	 */
	public function get_due_discovered_product_pages( int $competitor_id, int $limit, int $offset = 0 ): array {
		global $wpdb;

		$table = $this->table( 'discovered_competitor_products' );
		if ( $competitor_id > 0 ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE competitor_id = %d AND extraction_status IN ('queued','partial','failed','changed','success') ORDER BY extraction_status = 'queued' DESC, last_checked_at ASC, id ASC LIMIT %d OFFSET %d", $competitor_id, $limit, max( 0, $offset ) ) );
		}

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE extraction_status IN ('queued','partial','failed','changed','success') ORDER BY extraction_status = 'queued' DESC, last_checked_at ASC, id ASC LIMIT %d OFFSET %d", $limit, max( 0, $offset ) ) );
	}

	/**
	 * Create a suggestion if its fingerprint has not already been seen.
	 *
	 * @param array<string,mixed> $data Suggestion data.
	 */
	public function create_suggestion_if_new( array $data ): int {
		global $wpdb;

		$table       = $this->table( 'discovery_match_suggestions' );
		$fingerprint = (string) ( $data['fingerprint'] ?? '' );
		if ( '' === $fingerprint ) {
			return 0;
		}

		$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE fingerprint = %s LIMIT 1", $fingerprint ) );
		if ( $existing_id > 0 ) {
			return $existing_id;
		}

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'discovery_product_id' => absint( $data['discovery_product_id'] ?? 0 ),
				'discovered_product_id'=> absint( $data['discovered_product_id'] ?? 0 ),
				'product_id'           => absint( $data['product_id'] ?? 0 ),
				'variation_id'         => absint( $data['variation_id'] ?? 0 ),
				'competitor_id'        => absint( $data['competitor_id'] ?? 0 ),
				'competitor_url'       => esc_url_raw( (string) ( $data['competitor_url'] ?? '' ) ),
				'match_type'           => sanitize_key( (string) ( $data['match_type'] ?? 'unknown' ) ),
				'confidence_score'     => (float) ( $data['confidence_score'] ?? 0 ),
				'confidence_label'     => sanitize_text_field( (string) ( $data['confidence_label'] ?? 'Low confidence' ) ),
				'explanation'          => wp_kses_post( (string) ( $data['explanation'] ?? '' ) ),
				'status'               => 'pending',
				'fingerprint'          => $fingerprint,
				'created_at'           => $now,
				'updated_at'           => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * List match suggestions.
	 *
	 * @return array<int,object>
	 */
	public function get_suggestions( string $status = 'pending', int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		$table  = $this->table( 'discovery_match_suggestions' );
		$offset = max( 0, ( $page - 1 ) * $per_page );

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY confidence_score DESC, created_at DESC LIMIT %d OFFSET %d", $status, $per_page, $offset ) );
	}

	/**
	 * Count suggestions by status.
	 */
	public function count_suggestions( string $status = 'pending' ): int {
		global $wpdb;

		$table = $this->table( 'discovery_match_suggestions' );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
	}

	/**
	 * Get one suggestion.
	 */
	public function get_suggestion( int $id ): ?object {
		global $wpdb;

		$table = $this->table( 'discovery_match_suggestions' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return $row ?: null;
	}

	/**
	 * Mark a pending suggestion approved.
	 */
	public function approve_suggestion( int $id, int $user_id, int $competitor_link_id ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			$this->table( 'discovery_match_suggestions' ),
			array(
				'status'             => 'approved',
				'approved_by'        => $user_id,
				'approved_at'        => current_time( 'mysql' ),
				'competitor_link_id' => $competitor_link_id,
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => $id, 'status' => 'pending' )
		);
	}

	/**
	 * Mark a pending suggestion rejected.
	 */
	public function reject_suggestion( int $id, int $user_id ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			$this->table( 'discovery_match_suggestions' ),
			array(
				'status'      => 'rejected',
				'rejected_by' => $user_id,
				'rejected_at' => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $id, 'status' => 'pending' )
		);
	}

	/**
	 * Suggestion counts keyed by selected discovery product ID.
	 *
	 * @param array<int,int> $ids Discovery product IDs.
	 * @return array<int,int>
	 */
	public function get_pending_suggestion_counts_by_discovery_product_ids( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = $this->table( 'discovery_match_suggestions' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT discovery_product_id, COUNT(*) AS total FROM {$table} WHERE status = 'pending' AND discovery_product_id IN ({$placeholders}) GROUP BY discovery_product_id", ...$ids ) );
		$counts       = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row->discovery_product_id ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Create a discovery run row.
	 */
	public function create_run( ?int $competitor_id, string $source, int $limit ): int {
		global $wpdb;

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$this->table( 'discovery_runs' ),
			array(
				'competitor_id' => $competitor_id,
				'source'        => sanitize_key( $source ),
				'status'        => 'running',
				'limit_count'   => $limit,
				'started_at'    => $now,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Finish a discovery run.
	 */
	public function finish_run( int $run_id, string $status, int $processed, int $suggestions, int $failures, string $error = '', int $requests = 0, int $discovered_urls = 0 ): void {
		global $wpdb;

		$wpdb->update(
			$this->table( 'discovery_runs' ),
			array(
				'status'               => sanitize_key( $status ),
				'processed_count'      => $processed,
				'discovered_url_count' => $discovered_urls,
				'suggestion_count'     => $suggestions,
				'failure_count'        => $failures,
				'request_count'        => $requests,
				'last_error'           => $error,
				'completed_at'         => current_time( 'mysql' ),
				'updated_at'           => current_time( 'mysql' ),
			),
			array( 'id' => $run_id )
		);
	}

	/**
	 * Record competitor health after a run.
	 */
	public function record_competitor_health( int $competitor_id, bool $success, string $last_error = '', string $content_hash = '', int $pending = 0, int $approved = 0, int $auto_pause_failures = 5 ): string {
		global $wpdb;

		$table = $this->table( 'discovery_competitor_health' );
		$now   = current_time( 'mysql' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE competitor_id = %d LIMIT 1", $competitor_id ) );
		$status = 'working';
		$consecutive = $row ? (int) $row->consecutive_failures : 0;
		$changed_at = $row ? $row->extraction_changed_at : null;

		if ( $success ) {
			$consecutive = 0;
			if ( $row && '' !== $content_hash && '' !== (string) $row->last_content_hash && $content_hash !== (string) $row->last_content_hash ) {
				$status = 'extraction_changed';
				$changed_at = $now;
			}
		} else {
			++$consecutive;
			$status = $consecutive >= $auto_pause_failures ? 'paused' : 'needs_attention';
		}

		$data = array(
			'competitor_id'         => $competitor_id,
			'status'                => $status,
			'last_run_at'           => $now,
			'last_success_at'       => $success ? $now : ( $row ? $row->last_success_at : null ),
			'success_count'         => ( $row ? (int) $row->success_count : 0 ) + ( $success ? 1 : 0 ),
			'failure_count'         => ( $row ? (int) $row->failure_count : 0 ) + ( $success ? 0 : 1 ),
			'consecutive_failures'  => $consecutive,
			'pending_suggestions'   => $pending,
			'approved_links'        => $approved,
			'last_error'            => $last_error,
			'last_content_hash'     => $content_hash,
			'extraction_changed_at' => $changed_at,
			'paused_at'             => 'paused' === $status ? $now : ( $row ? $row->paused_at : null ),
			'updated_at'            => $now,
		);

		if ( $row ) {
			$wpdb->update( $table, $data, array( 'competitor_id' => $competitor_id ) );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data );
		}

		return $status;
	}

	/**
	 * Get competitor health rows.
	 *
	 * @return array<int,object>
	 */
	public function get_competitor_health_rows(): array {
		global $wpdb;

		$table = $this->table( 'discovery_competitor_health' );

		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC" );
	}

	/**
	 * Count duplicate normalized identifiers among selected products.
	 */
	private function count_duplicate_identifiers(): int {
		global $wpdb;

		$table = $this->table( 'discovery_products' );
		$sql   = "SELECT SUM(duplicates) FROM (
			SELECT COUNT(*) - 1 AS duplicates FROM {$table} WHERE enabled = 1 AND normalized_gtin <> '' GROUP BY normalized_gtin HAVING COUNT(*) > 1
			UNION ALL
			SELECT COUNT(*) - 1 AS duplicates FROM {$table} WHERE enabled = 1 AND normalized_sku <> '' GROUP BY normalized_sku HAVING COUNT(*) > 1
			UNION ALL
			SELECT COUNT(*) - 1 AS duplicates FROM {$table} WHERE enabled = 1 AND normalized_mpn <> '' GROUP BY normalized_mpn HAVING COUNT(*) > 1
		) duplicate_counts";

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Source type sanitizer.
	 */
	private function sanitize_source_type( string $type ): string {
		$type = sanitize_key( $type );

		return in_array( $type, array( 'product', 'listing', 'sitemap' ), true ) ? $type : 'product';
	}

	/**
	 * Get a discovery table name.
	 */
	private function table( string $key ): string {
		$tables = DiscoverySchema::table_names();

		return $tables[ $key ];
	}
}
