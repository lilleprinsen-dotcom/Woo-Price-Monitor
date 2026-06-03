<?php
/**
 * Safe custom table repository helpers.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Repository {
	private \wpdb $wpdb;

	/**
	 * @var array<string, string>
	 */
	private array $tables;

	public function __construct( ?\wpdb $database = null ) {
		global $wpdb;

		$this->wpdb   = $database ?? $wpdb;
		$this->tables = Schema::table_names();
	}

	/**
	 * @param array<string, mixed> $context Optional structured context.
	 */
	public function insert_log( string $level, string $event, ?string $message = null, array $context = array(), ?int $product_id = null ): bool {
		return $this->write_log( $level, $event, $message, $context, $product_id );
	}

	/**
	 * @param array<string, mixed> $context Optional structured context.
	 */
	public function write_log( string $level, string $event, ?string $message = null, array $context = array(), ?int $product_id = null ): bool {
		$table = $this->tables['logs'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$context_json = ! empty( $context ) ? wp_json_encode( $context ) : null;

		$inserted = $this->wpdb->insert(
			$table,
			array(
				'level'      => $this->sanitize_limited_text( $level, 20, 'info' ),
				'event'      => $this->sanitize_limited_text( $event, 100, 'event' ),
				'message'    => null === $message ? null : sanitize_textarea_field( $message ),
				'context'    => false === $context_json ? null : $context_json,
				'product_id' => $product_id ? absint( $product_id ) : null,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * @return array<string, int>
	 */
	public function get_dashboard_counts(): array {
		$recent_cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - WEEK_IN_SECONDS );

		return array(
			'monitored_products'          => $this->count_where( 'monitored_products', 'enabled = %d', array( 1 ) ),
			'active_competitor_links'     => $this->count_where( 'competitor_links', 'enabled = %d', array( 1 ) ),
			'pending_suggestions'         => $this->count_where( 'price_suggestions', 'status = %s', array( 'pending' ) ),
			'blocked_suggestions'         => $this->count_where( 'price_suggestions', 'status = %s', array( 'blocked' ) ),
			'recovery_suggestions'        => $this->count_price_suggestions_by_view( 'recovery' ),
			'recent_failed_checks'        => $this->count_where( 'logs', 'level = %s AND event = %s AND created_at >= %s', array( 'error', 'competitor_check_failed', $recent_cutoff ) ),
			'failed_logs'                 => $this->count_where( 'logs', 'level = %s', array( 'error' ) ),
			'active_price_match_sessions' => $this->count_active_price_match_sessions(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_table_status(): array {
		$tables = array();

		foreach ( $this->tables as $key => $name ) {
			$exists   = $this->table_exists( $name );
			$tables[] = array(
				'key'    => $key,
				'name'   => $name,
				'exists' => $exists,
				'count'  => $exists ? $this->get_table_count( $key ) : 0,
			);
		}

		return array(
			'schema_version'          => Schema::get_schema_version(),
			'expected_schema_version' => Schema::VERSION,
			'tables'                  => $tables,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function add_monitored_product( int $product_id, ?string $sku = null ): array {
		$table = $this->tables['monitored_products'];

		if ( ! $this->table_exists( $table ) ) {
			return array(
				'success' => false,
				'code'    => 'missing_table',
			);
		}

		$product_id = absint( $product_id );

		if ( 0 >= $product_id ) {
			return array(
				'success' => false,
				'code'    => 'invalid_product',
			);
		}

		$existing = $this->get_monitored_product_by_product_id( $product_id );
		$now      = current_time( 'mysql' );
		$sku      = $this->sanitize_sku( $sku );

		if ( $existing ) {
			$updated = $this->wpdb->update(
				$table,
				array(
					'sku'        => $sku,
					'enabled'    => 1,
					'updated_at' => $now,
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);

			return array(
				'success' => false !== $updated,
				'code'    => ! empty( $existing['enabled'] ) ? 'already_monitored' : 'monitoring_reenabled',
				'id'      => (int) $existing['id'],
			);
		}

		$inserted = $this->wpdb->insert(
			$table,
			array(
				'product_id'             => $product_id,
				'sku'                    => $sku,
				'enabled'                => 1,
				'priority'               => 'normal',
				'strategy'               => 'notify_only',
				'check_frequency_hours'  => 24,
				'created_at'             => $now,
				'updated_at'             => $now,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return array(
			'success' => false !== $inserted,
			'code'    => false !== $inserted ? 'monitoring_added' : 'monitoring_add_failed',
			'id'      => false !== $inserted ? (int) $this->wpdb->insert_id : 0,
		);
	}

	public function set_monitored_product_enabled( int $monitored_product_id, bool $enabled ): bool {
		$table = $this->tables['monitored_products'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array(
				'enabled'    => $enabled ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $monitored_product_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_monitored_products( int $page, int $per_page ): array {
		$table = $this->tables['monitored_products'];

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$limit  = $this->sanitize_per_page( $per_page );
		$offset = $this->get_offset( $page, $limit );
		$sql    = $this->wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
			$limit,
			$offset
		);

		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	public function count_monitored_products(): int {
		return $this->get_table_count( 'monitored_products' );
	}

	/**
	 * @return array<int, int>
	 */
	public function count_competitor_links_for_monitored_products( array $monitored_product_ids ): array {
		$table = $this->tables['competitor_links'];
		$ids   = array_values( array_filter( array_map( 'absint', $monitored_product_ids ) ) );

		if ( empty( $ids ) || ! $this->table_exists( $table ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = $this->wpdb->prepare(
			"SELECT monitored_product_id, COUNT(*) AS total FROM {$table} WHERE monitored_product_id IN ({$placeholders}) GROUP BY monitored_product_id",
			$ids
		);
		$rows         = $this->wpdb->get_results( $sql, ARRAY_A );
		$counts       = array();

		if ( ! is_array( $rows ) ) {
			return $counts;
		}

		foreach ( $rows as $row ) {
			$counts[ (int) $row['monitored_product_id'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_monitored_product( int $monitored_product_id ): ?array {
		$table = $this->tables['monitored_products'];

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $monitored_product_id ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_monitored_product_by_product_id( int $product_id ): ?array {
		$table = $this->tables['monitored_products'];

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d LIMIT 1", absint( $product_id ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $data Competitor link fields.
	 */
	public function add_competitor_link( array $data ): int {
		$table = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$now      = current_time( 'mysql' );
		$inserted = $this->wpdb->insert(
			$table,
			array(
				'monitored_product_id' => absint( $data['monitored_product_id'] ?? 0 ),
				'competitor_name'      => sanitize_text_field( (string) ( $data['competitor_name'] ?? '' ) ),
				'competitor_url'       => esc_url_raw( (string) ( $data['competitor_url'] ?? '' ) ),
				'match_type'           => $this->sanitize_match_type( (string) ( $data['match_type'] ?? 'unknown' ) ),
				'enabled'              => ! empty( $data['enabled'] ) ? 1 : 0,
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return false !== $inserted ? (int) $this->wpdb->insert_id : 0;
	}

	/**
	 * @param array<string, mixed> $data Competitor link fields.
	 */
	public function update_competitor_link( int $competitor_link_id, array $data ): bool {
		$table = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array(
				'competitor_name' => sanitize_text_field( (string) ( $data['competitor_name'] ?? '' ) ),
				'competitor_url'  => esc_url_raw( (string) ( $data['competitor_url'] ?? '' ) ),
				'match_type'      => $this->sanitize_match_type( (string) ( $data['match_type'] ?? 'unknown' ) ),
				'enabled'         => ! empty( $data['enabled'] ) ? 1 : 0,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => absint( $competitor_link_id ) ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function set_competitor_link_enabled( int $competitor_link_id, bool $enabled ): bool {
		$table = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array(
				'enabled'    => $enabled ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $competitor_link_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function delete_competitor_link( int $competitor_link_id ): bool {
		$table = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$deleted = $this->wpdb->delete(
			$table,
			array( 'id' => absint( $competitor_link_id ) ),
			array( '%d' )
		);

		return false !== $deleted;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_competitor_links_for_monitored_product( int $monitored_product_id ): array {
		$table = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE monitored_product_id = %d ORDER BY enabled DESC, competitor_name ASC, id DESC",
				absint( $monitored_product_id )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_competitor_link( int $competitor_link_id ): ?array {
		$table = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $competitor_link_id ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_due_competitor_links( int $limit ): array {
		$links_table     = $this->tables['competitor_links'];
		$monitored_table = $this->tables['monitored_products'];

		if ( ! $this->table_exists( $links_table ) || ! $this->table_exists( $monitored_table ) ) {
			return array();
		}

		$limit = $this->sanitize_per_page( $limit );
		$now   = current_time( 'mysql' );
		$sql   = $this->wpdb->prepare(
			"SELECT cl.*, mp.product_id, mp.sku, mp.check_frequency_hours
			FROM {$links_table} cl
			INNER JOIN {$monitored_table} mp ON cl.monitored_product_id = mp.id
			WHERE cl.enabled = %d
				AND mp.enabled = %d
				AND (
					cl.last_checked_at IS NULL
					OR cl.last_checked_at <= DATE_SUB(%s, INTERVAL mp.check_frequency_hours HOUR)
				)
			ORDER BY cl.last_checked_at IS NULL DESC, cl.last_checked_at ASC, cl.id ASC
			LIMIT %d",
			1,
			1,
			$now,
			$limit
		);
		$rows  = $this->wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	public function update_competitor_check_result( int $competitor_link_id, ?float $price, string $currency, ?string $error ): bool {
		$table = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$data = array(
			'last_checked_at' => current_time( 'mysql' ),
			'last_error'      => null === $error || '' === $error ? null : sanitize_textarea_field( $error ),
			'updated_at'      => current_time( 'mysql' ),
		);

		$formats = array( '%s', '%s', '%s' );

		if ( null !== $price ) {
			$data['last_price']    = $this->nullable_decimal( $price );
			$data['last_currency'] = $this->sanitize_currency( $currency );
			$formats[]             = '%s';
			$formats[]             = '%s';
		}

		$updated = $this->wpdb->update(
			$table,
			$data,
			array( 'id' => absint( $competitor_link_id ) ),
			$formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * @param array<string, mixed> $data Suggestion fields.
	 */
	public function create_price_suggestion( array $data ): int {
		$table = $this->tables['price_suggestions'];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$now      = current_time( 'mysql' );
		$inserted = $this->wpdb->insert(
			$table,
			array(
				'monitored_product_id' => absint( $data['monitored_product_id'] ?? 0 ),
				'competitor_link_id'   => isset( $data['competitor_link_id'] ) ? absint( $data['competitor_link_id'] ) : null,
				'product_id'           => absint( $data['product_id'] ?? 0 ),
				'current_price'        => $this->decimal( $data['current_price'] ?? 0 ),
				'competitor_price'     => $this->decimal( $data['competitor_price'] ?? 0 ),
				'suggested_price'      => $this->decimal( $data['suggested_price'] ?? 0 ),
				'difference'           => $this->decimal( $data['difference'] ?? 0 ),
				'suggestion_type'      => $this->sanitize_suggestion_type( (string) ( $data['suggestion_type'] ?? 'price_match_down' ) ),
				'status'               => $this->sanitize_suggestion_status( (string) ( $data['status'] ?? 'pending' ) ),
				'reason'               => isset( $data['reason'] ) ? sanitize_textarea_field( (string) $data['reason'] ) : null,
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $inserted ? (int) $this->wpdb->insert_id : 0;
	}

	public function has_duplicate_pending_suggestion( int $monitored_product_id, int $competitor_link_id, float $competitor_price ): bool {
		$table = $this->tables['price_suggestions'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$sql = $this->wpdb->prepare(
			"SELECT id FROM {$table} WHERE monitored_product_id = %d AND competitor_link_id = %d AND status = %s AND ABS(competitor_price - %f) < 0.0001 LIMIT 1",
			absint( $monitored_product_id ),
			absint( $competitor_link_id ),
			'pending',
			$competitor_price
		);

		return (bool) $this->wpdb->get_var( $sql );
	}

	/**
	 * @param array<string, mixed> $filters Suggestion filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_price_suggestions( array $filters, int $page, int $per_page ): array {
		$table = $this->tables['price_suggestions'];
		$links = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$where  = $this->build_suggestion_where( $filters );
		$limit  = $this->sanitize_per_page( $per_page );
		$offset = $this->get_offset( $page, $limit );
		$sql    = "SELECT ps.*, cl.competitor_name, cl.competitor_url FROM {$table} ps LEFT JOIN {$links} cl ON ps.competitor_link_id = cl.id {$where['sql']} ORDER BY ps.created_at DESC, ps.id DESC LIMIT %d OFFSET %d";
		$args   = array_merge( $where['args'], array( $limit, $offset ) );
		$rows   = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string, mixed> $filters Suggestion filters.
	 */
	public function count_price_suggestions( array $filters ): int {
		$table = $this->tables['price_suggestions'];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$where = $this->build_suggestion_where( $filters );
		$sql   = "SELECT COUNT(*) FROM {$table} ps {$where['sql']}";

		if ( ! empty( $where['args'] ) ) {
			$sql = $this->wpdb->prepare( $sql, $where['args'] );
		}

		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_price_suggestion( int $suggestion_id ): ?array {
		$table = $this->tables['price_suggestions'];
		$links = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT ps.*, cl.competitor_name, cl.competitor_url FROM {$table} ps LEFT JOIN {$links} cl ON ps.competitor_link_id = cl.id WHERE ps.id = %d LIMIT 1",
				absint( $suggestion_id )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function update_suggested_price( int $suggestion_id, float $suggested_price ): bool {
		$table      = $this->tables['price_suggestions'];
		$suggestion = $this->get_price_suggestion( $suggestion_id );

		if ( ! $suggestion || ! $this->table_exists( $table ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array(
				'suggested_price' => $this->decimal( $suggested_price ),
				'difference'      => $this->decimal( $suggested_price - (float) $suggestion['current_price'] ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => absint( $suggestion_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function approve_suggestion_dry_run( int $suggestion_id, int $user_id ): bool {
		return $this->set_suggestion_review_status( $suggestion_id, 'approved_dry_run', 'approved_at', 'approved_by', $user_id );
	}

	public function approve_suggestion_real_update( int $suggestion_id, int $user_id ): bool {
		return $this->set_suggestion_review_status( $suggestion_id, 'approved_real_update', 'approved_at', 'approved_by', $user_id );
	}

	public function reject_suggestion( int $suggestion_id, int $user_id ): bool {
		return $this->set_suggestion_review_status( $suggestion_id, 'rejected', 'rejected_at', 'rejected_by', $user_id );
	}

	public function mark_suggestion_failed( int $suggestion_id, string $reason ): bool {
		$table = $this->tables['price_suggestions'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array(
				'status'     => 'failed',
				'reason'     => sanitize_textarea_field( $reason ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $suggestion_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * @return array<string, int>
	 */
	public function get_suggestion_counts(): array {
		return array(
			'pending'          => $this->count_where( 'price_suggestions', 'status = %s', array( 'pending' ) ),
			'blocked'          => $this->count_where( 'price_suggestions', 'status = %s', array( 'blocked' ) ),
			'approved_dry_run' => $this->count_where( 'price_suggestions', 'status = %s', array( 'approved_dry_run' ) ),
			'approved_real_update' => $this->count_where( 'price_suggestions', 'status = %s', array( 'approved_real_update' ) ),
			'rejected'         => $this->count_where( 'price_suggestions', 'status = %s', array( 'rejected' ) ),
			'recovery'         => $this->count_price_suggestions_by_view( 'recovery' ),
		);
	}

	/**
	 * @param array<string, mixed> $filters Log filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_logs( array $filters, int $page, int $per_page ): array {
		$table = $this->tables['logs'];

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$where = $this->build_log_where( $filters );
		$limit = $this->sanitize_per_page( $per_page );
		$offset = $this->get_offset( $page, $limit );
		$sql   = "SELECT * FROM {$table} {$where['sql']} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
		$args  = array_merge( $where['args'], array( $limit, $offset ) );
		$rows  = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string, mixed> $filters Log filters.
	 */
	public function count_logs( array $filters ): int {
		$table = $this->tables['logs'];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$where = $this->build_log_where( $filters );
		$sql   = "SELECT COUNT(*) FROM {$table} {$where['sql']}";

		if ( ! empty( $where['args'] ) ) {
			$sql = $this->wpdb->prepare( $sql, $where['args'] );
		}

		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * @param array<string, mixed> $data Session fields.
	 */
	public function create_price_match_session( array $data ): int {
		$table = $this->tables['price_match_sessions'];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$now      = current_time( 'mysql' );
		$inserted = $this->wpdb->insert(
			$table,
			array(
				'product_id'                       => absint( $data['product_id'] ?? 0 ),
				'monitored_product_id'             => absint( $data['monitored_product_id'] ?? 0 ),
				'suggestion_id'                    => isset( $data['suggestion_id'] ) ? absint( $data['suggestion_id'] ) : null,
				'status'                           => $this->sanitize_limited_text( (string) ( $data['status'] ?? 'active' ), 30, 'active' ),
				'original_regular_price'           => $this->nullable_decimal( $data['original_regular_price'] ?? null ),
				'original_sale_price'              => $this->nullable_decimal( $data['original_sale_price'] ?? null ),
				'original_active_price'            => $this->nullable_decimal( $data['original_active_price'] ?? null ),
				'original_sale_start'              => $this->nullable_datetime( $data['original_sale_start'] ?? null ),
				'original_sale_end'                => $this->nullable_datetime( $data['original_sale_end'] ?? null ),
				'matched_price'                    => $this->nullable_decimal( $data['matched_price'] ?? null ),
				'matched_regular_price'            => $this->nullable_decimal( $data['matched_regular_price'] ?? null ),
				'matched_sale_price'               => $this->nullable_decimal( $data['matched_sale_price'] ?? null ),
				'matched_at'                       => $this->nullable_datetime( $data['matched_at'] ?? null ),
				'matched_by'                       => isset( $data['matched_by'] ) ? absint( $data['matched_by'] ) : null,
				'restore_strategy'                 => $this->sanitize_limited_text( (string) ( $data['restore_strategy'] ?? 'previous_active_price' ), 50, 'previous_active_price' ),
				'recovery_strategy'                => $this->sanitize_limited_text( (string) ( $data['recovery_strategy'] ?? 'suggest_only' ), 50, 'suggest_only' ),
				'last_competitor_price'            => $this->nullable_decimal( $data['last_competitor_price'] ?? null ),
				'last_lowest_competitor_price'     => $this->nullable_decimal( $data['last_lowest_competitor_price'] ?? null ),
				'last_checked_at'                  => $this->nullable_datetime( $data['last_checked_at'] ?? null ),
				'created_at'                       => $now,
				'updated_at'                       => $now,
			),
			array(
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		return false !== $inserted ? (int) $this->wpdb->insert_id : 0;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_active_price_match_session_for_product( int $product_id ): ?array {
		$table = $this->tables['price_match_sessions'];

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d AND status IN (%s, %s) ORDER BY matched_at DESC, id DESC LIMIT 1",
				absint( $product_id ),
				'active',
				'active_dry_run'
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function end_price_match_session( int $session_id, string $status = 'ended' ): bool {
		$table = $this->tables['price_match_sessions'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array(
				'status'     => $this->sanitize_limited_text( $status, 30, 'ended' ),
				'updated_at' => current_time( 'mysql' ),
				'ended_at'   => current_time( 'mysql' ),
			),
			array( 'id' => absint( $session_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function count_active_price_match_sessions(): int {
		return $this->count_where( 'price_match_sessions', 'status IN (%s, %s)', array( 'active', 'active_dry_run' ) );
	}

	private function count_price_suggestions_by_view( string $view ): int {
		return $this->count_price_suggestions( array( 'view' => $view ) );
	}

	private function set_suggestion_review_status( int $suggestion_id, string $status, string $date_column, string $user_column, int $user_id ): bool {
		$table = $this->tables['price_suggestions'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$allowed_date_columns = array( 'approved_at', 'rejected_at' );
		$allowed_user_columns = array( 'approved_by', 'rejected_by' );

		if ( ! in_array( $date_column, $allowed_date_columns, true ) || ! in_array( $user_column, $allowed_user_columns, true ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array(
				'status'      => $this->sanitize_suggestion_status( $status ),
				$date_column  => current_time( 'mysql' ),
				$user_column  => absint( $user_id ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => absint( $suggestion_id ) ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function get_table_count( string $table_key ): int {
		if ( ! isset( $this->tables[ $table_key ] ) ) {
			return 0;
		}

		$table = $this->tables[ $table_key ];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * @param array<int, mixed> $params Prepare parameters.
	 */
	private function count_where( string $table_key, string $where_sql, array $params ): int {
		if ( ! isset( $this->tables[ $table_key ] ) ) {
			return 0;
		}

		$table = $this->tables[ $table_key ];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			$sql = $this->wpdb->prepare( $sql, $params );
		}

		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * @param array<string, mixed> $filters Log filters.
	 * @return array{sql: string, args: array<int, mixed>}
	 */
	private function build_log_where( array $filters ): array {
		$where = array();
		$args  = array();

		if ( ! empty( $filters['level'] ) ) {
			$where[] = 'level = %s';
			$args[]  = $this->sanitize_limited_text( (string) $filters['level'], 20, 'info' );
		}

		if ( ! empty( $filters['event'] ) ) {
			$where[] = 'event = %s';
			$args[]  = $this->sanitize_limited_text( (string) $filters['event'], 100, 'event' );
		}

		if ( ! empty( $filters['product_id'] ) ) {
			$where[] = 'product_id = %d';
			$args[]  = absint( $filters['product_id'] );
		}

		return array(
			'sql'  => ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '',
			'args' => $args,
		);
	}

	/**
	 * @param array<string, mixed> $filters Suggestion filters.
	 * @return array{sql: string, args: array<int, mixed>}
	 */
	private function build_suggestion_where( array $filters ): array {
		$view  = isset( $filters['view'] ) ? sanitize_key( (string) $filters['view'] ) : 'pending';
		$where = array();
		$args  = array();

		switch ( $view ) {
			case 'pending':
			case 'blocked':
			case 'approved_dry_run':
			case 'rejected':
			case 'failed':
				$where[] = 'ps.status = %s';
				$args[]  = $view;
				break;
			case 'approved_real_update':
				$where[] = 'ps.status = %s';
				$args[]  = 'approved_real_update';
				break;
			case 'price_match_down':
			case 'price_match_up':
				$where[] = 'ps.suggestion_type = %s';
				$args[]  = $view;
				break;
			case 'restore_previous_price':
				$where[] = 'ps.suggestion_type IN (%s, %s, %s)';
				$args[]  = 'restore_previous_active_price';
				$args[]  = 'restore_previous_regular_price';
				$args[]  = 'restore_previous_sale_price';
				break;
			case 'recovery':
				$where[] = 'ps.suggestion_type IN (%s, %s, %s, %s)';
				$args[]  = 'price_match_up';
				$args[]  = 'restore_previous_active_price';
				$args[]  = 'restore_previous_regular_price';
				$args[]  = 'restore_previous_sale_price';
				break;
			case 'all':
			default:
				break;
		}

		return array(
			'sql'  => ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '',
			'args' => $args,
		);
	}

	private function table_exists( string $table ): bool {
		return $table === $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private function sanitize_limited_text( string $value, int $length, string $fallback ): string {
		$sanitized = sanitize_key( $value );

		if ( '' === $sanitized ) {
			$sanitized = $fallback;
		}

		return substr( $sanitized, 0, $length );
	}

	private function sanitize_sku( ?string $sku ): ?string {
		if ( null === $sku ) {
			return null;
		}

		$sku = sanitize_text_field( $sku );

		return '' === $sku ? null : substr( $sku, 0, 191 );
	}

	private function sanitize_match_type( string $match_type ): string {
		$allowed = array( 'unknown', 'exact', 'similar', 'different_variant', 'bundle', 'not_comparable' );
		$match_type = sanitize_key( $match_type );

		return in_array( $match_type, $allowed, true ) ? $match_type : 'unknown';
	}

	private function sanitize_suggestion_type( string $suggestion_type ): string {
		$allowed = array(
			'price_match_down',
			'price_match_up',
			'restore_previous_active_price',
			'restore_previous_regular_price',
			'restore_previous_sale_price',
			'manual_review',
			'blocked',
		);
		$suggestion_type = sanitize_key( $suggestion_type );

		return in_array( $suggestion_type, $allowed, true ) ? $suggestion_type : 'manual_review';
	}

	private function sanitize_suggestion_status( string $status ): string {
		$allowed = array( 'pending', 'blocked', 'approved_dry_run', 'approved_real_update', 'rejected', 'failed' );
		$status  = sanitize_key( $status );

		return in_array( $status, $allowed, true ) ? $status : 'pending';
	}

	private function sanitize_currency( string $currency ): string {
		$currency = strtoupper( sanitize_text_field( $currency ) );
		$currency = preg_replace( '/[^A-Z]/', '', $currency );

		return is_string( $currency ) && '' !== $currency ? substr( $currency, 0, 10 ) : 'NOK';
	}

	private function sanitize_per_page( int $per_page ): int {
		return min( 200, max( 1, absint( $per_page ) ) );
	}

	private function get_offset( int $page, int $per_page ): int {
		return max( 0, ( max( 1, absint( $page ) ) - 1 ) * $per_page );
	}

	/**
	 * @param mixed $value Raw decimal.
	 */
	private function nullable_decimal( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$decimal = str_replace( ',', '.', sanitize_text_field( (string) $value ) );

		if ( ! is_numeric( $decimal ) ) {
			return null;
		}

		return number_format( (float) $decimal, 4, '.', '' );
	}

	/**
	 * @param mixed $value Raw decimal.
	 */
	private function decimal( $value ): string {
		$decimal = $this->nullable_decimal( $value );

		return null === $decimal ? '0.0000' : $decimal;
	}

	/**
	 * @param mixed $value Raw datetime.
	 */
	private function nullable_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value     = sanitize_text_field( (string) $value );
		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
