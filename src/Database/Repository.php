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
	 * @return array<string, mixed>
	 */
	public function get_dashboard_counts(): array {
		$recent_cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - WEEK_IN_SECONDS );
		$day_cutoff    = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS );

		return array(
			'monitored_products'          => $this->count_where( 'monitored_products', 'enabled = %d', array( 1 ) ),
			'active_competitor_links'     => $this->count_where( 'competitor_links', 'enabled = %d', array( 1 ) ),
			'pending_suggestions'         => $this->count_where( 'price_suggestions', 'status = %s', array( 'pending' ) ),
			'blocked_suggestions'         => $this->count_where( 'price_suggestions', 'status = %s', array( 'blocked' ) ),
			'recovery_suggestions'        => $this->count_price_suggestions_by_view( 'recovery' ),
			'recent_failed_checks'        => $this->count_where( 'logs', 'level = %s AND event = %s AND created_at >= %s', array( 'error', 'competitor_check_failed', $recent_cutoff ) ),
			'checks_last_24h'             => $this->count_where( 'price_observations', 'checked_at >= %s', array( $day_cutoff ) ),
			'failed_checks_last_24h'      => $this->count_failed_observations_since( $day_cutoff ),
			'last_successful_check_time'  => $this->get_latest_successful_observation_time(),
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
				'strategy'               => 'match_competitor',
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
	 * @param array<string, mixed> $data Rule fields.
	 */
	public function update_monitored_product_rules( int $monitored_product_id, array $data ): bool {
		$table = $this->tables['monitored_products'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array(
				'enabled'               => ! empty( $data['enabled'] ) ? 1 : 0,
				'priority'              => $this->sanitize_monitored_priority( (string) ( $data['priority'] ?? 'normal' ) ),
				'strategy'              => $this->sanitize_monitored_strategy( (string) ( $data['strategy'] ?? 'match_competitor' ) ),
				'min_margin_percent'    => $this->nullable_decimal( $data['min_margin_percent'] ?? null ),
				'min_price'             => $this->nullable_decimal( $data['min_price'] ?? null ),
				'check_frequency_hours' => $this->sanitize_bounded_int( $data['check_frequency_hours'] ?? 24, 1, 720, 24 ),
				'updated_at'            => current_time( 'mysql' ),
			),
			array( 'id' => absint( $monitored_product_id ) ),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' ),
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
	 * @param array<string, mixed> $data Competitor profile fields.
	 */
	public function add_competitor( array $data ): int {
		$table = $this->tables['competitors'];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$profile = $this->sanitize_competitor_profile_data( $data );

		if ( '' === $profile['name'] ) {
			return 0;
		}

		$now      = current_time( 'mysql' );
		$inserted = $this->wpdb->insert(
			$table,
			array_merge(
				$profile,
				array(
					'created_at' => $now,
					'updated_at' => $now,
				)
			),
			array( '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		return false !== $inserted ? (int) $this->wpdb->insert_id : 0;
	}

	/**
	 * @param array<string, mixed> $data Competitor profile fields.
	 */
	public function update_competitor( int $competitor_id, array $data ): bool {
		$table = $this->tables['competitors'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$profile = $this->sanitize_competitor_profile_data( $data );

		if ( '' === $profile['name'] ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array_merge( $profile, array( 'updated_at' => current_time( 'mysql' ) ) ),
			array( 'id' => absint( $competitor_id ) ),
			array( '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function set_competitor_enabled( int $competitor_id, bool $enabled ): bool {
		$table = $this->tables['competitors'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array(
				'enabled'    => $enabled ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $competitor_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_competitor( int $competitor_id ): ?array {
		$table = $this->tables['competitors'];

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $competitor_id ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_competitors( int $page, int $per_page ): array {
		$table = $this->tables['competitors'];
		$links = $this->tables['competitor_links'];
		$obs   = $this->tables['price_observations'];

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$limit  = $this->sanitize_per_page( $per_page );
		$offset = $this->get_offset( $page, $limit );

		if ( ! $this->table_exists( $links ) || ! $this->table_exists( $obs ) ) {
			$sql  = $this->wpdb->prepare( "SELECT *, 0 AS link_count, NULL AS last_check, 0 AS observation_count, 0 AS successful_observation_count FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset );
			$rows = $this->wpdb->get_results( $sql, ARRAY_A );
			return is_array( $rows ) ? $rows : array();
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 30 * DAY_IN_SECONDS ) );
		$sql    = $this->wpdb->prepare(
			"SELECT
				c.*,
				(SELECT COUNT(*) FROM {$links} cl WHERE cl.competitor_id = c.id) AS link_count,
				(SELECT MAX(cl_last.last_checked_at) FROM {$links} cl_last WHERE cl_last.competitor_id = c.id) AS last_check,
				(
					SELECT COUNT(*)
					FROM {$obs} po
					INNER JOIN {$links} cl_obs ON po.competitor_link_id = cl_obs.id
					WHERE cl_obs.competitor_id = c.id AND po.checked_at >= %s
				) AS observation_count,
				(
					SELECT COUNT(*)
					FROM {$obs} po_success
					INNER JOIN {$links} cl_success ON po_success.competitor_link_id = cl_success.id
					WHERE cl_success.competitor_id = c.id AND po_success.checked_at >= %s AND po_success.success = 1
				) AS successful_observation_count
			FROM {$table} c
			ORDER BY c.updated_at DESC, c.id DESC
			LIMIT %d OFFSET %d",
			$cutoff,
			$cutoff,
			$limit,
			$offset
		);
		$rows   = $this->wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	public function count_competitors(): int {
		return $this->get_table_count( 'competitors' );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_competitor_profile_options( int $limit = 500 ): array {
		$table = $this->tables['competitors'];

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$limit = $this->sanitize_export_limit( $limit );
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, name, domain, enabled, requires_javascript FROM {$table} ORDER BY name ASC, id ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_competitor_linked_products( int $competitor_id, int $page, int $per_page ): array {
		$links     = $this->tables['competitor_links'];
		$monitored = $this->tables['monitored_products'];

		if ( ! $this->table_exists( $links ) || ! $this->table_exists( $monitored ) ) {
			return array();
		}

		$limit  = $this->sanitize_per_page( $per_page );
		$offset = $this->get_offset( $page, $limit );
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT cl.*, mp.product_id, mp.sku
				FROM {$links} cl
				INNER JOIN {$monitored} mp ON cl.monitored_product_id = mp.id
				WHERE cl.competitor_id = %d
				ORDER BY cl.updated_at DESC, cl.id DESC
				LIMIT %d OFFSET %d",
				absint( $competitor_id ),
				$limit,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function count_competitor_linked_products( int $competitor_id ): int {
		$table = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE competitor_id = %d",
				absint( $competitor_id )
			)
		);
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
				'competitor_id'        => ! empty( $data['competitor_id'] ) ? absint( $data['competitor_id'] ) : null,
				'competitor_name'      => sanitize_text_field( (string) ( $data['competitor_name'] ?? '' ) ),
				'competitor_url'       => esc_url_raw( (string) ( $data['competitor_url'] ?? '' ) ),
				'match_type'           => $this->sanitize_match_type( (string) ( $data['match_type'] ?? 'unknown' ) ),
				'enabled'              => ! empty( $data['enabled'] ) ? 1 : 0,
				'is_primary'           => ! empty( $data['is_primary'] ) ? 1 : 0,
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		$link_id = false !== $inserted ? (int) $this->wpdb->insert_id : 0;

		if ( $link_id > 0 && ! empty( $data['is_primary'] ) ) {
			$this->unset_primary_competitor_links( absint( $data['monitored_product_id'] ?? 0 ), $link_id );
		}

		return $link_id;
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
				'competitor_id'   => ! empty( $data['competitor_id'] ) ? absint( $data['competitor_id'] ) : null,
				'competitor_name' => sanitize_text_field( (string) ( $data['competitor_name'] ?? '' ) ),
				'competitor_url'  => esc_url_raw( (string) ( $data['competitor_url'] ?? '' ) ),
				'match_type'      => $this->sanitize_match_type( (string) ( $data['match_type'] ?? 'unknown' ) ),
				'enabled'         => ! empty( $data['enabled'] ) ? 1 : 0,
				'is_primary'      => ! empty( $data['is_primary'] ) ? 1 : 0,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => absint( $competitor_link_id ) ),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);

		if ( false !== $updated && ! empty( $data['is_primary'] ) ) {
			$this->unset_primary_competitor_links( absint( $data['monitored_product_id'] ?? 0 ), $competitor_link_id );
		}

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
		$table       = $this->tables['competitor_links'];
		$competitors = $this->tables['competitors'];

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		if ( $this->table_exists( $competitors ) ) {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT cl.*, c.name AS competitor_profile_name, c.domain AS competitor_profile_domain, c.requires_javascript AS competitor_requires_javascript
					FROM {$table} cl
					LEFT JOIN {$competitors} c ON cl.competitor_id = c.id
					WHERE cl.monitored_product_id = %d
					ORDER BY cl.enabled DESC, cl.competitor_name ASC, cl.id DESC",
					absint( $monitored_product_id )
				),
				ARRAY_A
			);

			return is_array( $rows ) ? $rows : array();
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
	 * @return array<string, mixed>|null
	 */
	public function get_competitor_link_by_url( int $monitored_product_id, string $url ): ?array {
		$table = $this->tables['competitor_links'];
		$url   = esc_url_raw( $url );

		if ( '' === $url || ! $this->table_exists( $table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE monitored_product_id = %d AND competitor_url = %s LIMIT 1",
				absint( $monitored_product_id ),
				$url
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function set_competitor_link_match_type( int $competitor_link_id, string $match_type ): bool {
		$table = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array(
				'match_type' => $this->sanitize_match_type( $match_type ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $competitor_link_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Recent active competitor links for admin monitoring status.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_active_competitor_links_status( int $limit = 50 ): array {
		$links_table     = $this->tables['competitor_links'];
		$monitored_table = $this->tables['monitored_products'];
		$competitors     = $this->tables['competitors'];

		if ( ! $this->table_exists( $links_table ) || ! $this->table_exists( $monitored_table ) ) {
			return array();
		}

		$limit = $this->sanitize_per_page( $limit );
		if ( $this->table_exists( $competitors ) ) {
			$sql = $this->wpdb->prepare(
				"SELECT
					cl.*,
					mp.product_id,
					mp.sku,
					mp.check_frequency_hours,
					c.name AS competitor_profile_name,
					c.requires_javascript AS competitor_requires_javascript
				FROM {$links_table} cl
				INNER JOIN {$monitored_table} mp ON cl.monitored_product_id = mp.id
				LEFT JOIN {$competitors} c ON cl.competitor_id = c.id
				WHERE cl.enabled = %d AND mp.enabled = %d
				ORDER BY cl.last_checked_at IS NULL DESC, cl.last_checked_at ASC, cl.updated_at DESC
				LIMIT %d",
				1,
				1,
				$limit
			);
		} else {
			$sql = $this->wpdb->prepare(
				"SELECT cl.*, mp.product_id, mp.sku, mp.check_frequency_hours
				FROM {$links_table} cl
				INNER JOIN {$monitored_table} mp ON cl.monitored_product_id = mp.id
				WHERE cl.enabled = %d AND mp.enabled = %d
				ORDER BY cl.last_checked_at IS NULL DESC, cl.last_checked_at ASC, cl.updated_at DESC
				LIMIT %d",
				1,
				1,
				$limit
			);
		}

		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	private function unset_primary_competitor_links( int $monitored_product_id, int $except_link_id ): void {
		$table = $this->tables['competitor_links'];

		if ( $monitored_product_id <= 0 || ! $this->table_exists( $table ) ) {
			return;
		}

		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table}
				SET is_primary = 0, updated_at = %s
				WHERE monitored_product_id = %d AND id <> %d",
				current_time( 'mysql' ),
				absint( $monitored_product_id ),
				absint( $except_link_id )
			)
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_due_competitor_links( int $limit ): array {
		$links_table     = $this->tables['competitor_links'];
		$monitored_table = $this->tables['monitored_products'];
		$competitors     = $this->tables['competitors'];

		if ( ! $this->table_exists( $links_table ) || ! $this->table_exists( $monitored_table ) ) {
			return array();
		}

		$limit = $this->sanitize_per_page( $limit );
		$now   = current_time( 'mysql' );
		if ( $this->table_exists( $competitors ) ) {
			$sql = $this->wpdb->prepare(
				"SELECT
					cl.*,
					mp.product_id,
					mp.sku,
					mp.check_frequency_hours,
					c.name AS competitor_profile_name,
					c.request_delay_seconds AS competitor_request_delay_seconds,
					c.request_timeout_seconds AS competitor_request_timeout_seconds,
					c.requires_javascript AS competitor_requires_javascript
				FROM {$links_table} cl
				INNER JOIN {$monitored_table} mp ON cl.monitored_product_id = mp.id
				LEFT JOIN {$competitors} c ON cl.competitor_id = c.id
				WHERE cl.enabled = %d
					AND mp.enabled = %d
					AND (cl.competitor_id IS NULL OR c.enabled = %d)
					AND (
						cl.last_checked_at IS NULL
						OR cl.last_checked_at <= DATE_SUB(%s, INTERVAL mp.check_frequency_hours HOUR)
					)
					AND (cl.next_check_after IS NULL OR cl.next_check_after <= %s)
					AND (
						cl.competitor_id IS NULL
						OR c.request_delay_seconds = 0
						OR NOT EXISTS (
							SELECT recent.id FROM {$links_table} recent
							WHERE recent.competitor_id = cl.competitor_id
								AND recent.last_checked_at >= DATE_SUB(%s, INTERVAL c.request_delay_seconds SECOND)
							LIMIT 1
						)
					)
				ORDER BY cl.last_checked_at IS NULL DESC, cl.last_checked_at ASC, cl.id ASC
				LIMIT %d",
				1,
				1,
				1,
				$now,
				$now,
				$now,
				$limit
			);
		} else {
			$sql = $this->wpdb->prepare(
				"SELECT cl.*, mp.product_id, mp.sku, mp.check_frequency_hours
				FROM {$links_table} cl
				INNER JOIN {$monitored_table} mp ON cl.monitored_product_id = mp.id
				WHERE cl.enabled = %d
					AND mp.enabled = %d
					AND (
						cl.last_checked_at IS NULL
						OR cl.last_checked_at <= DATE_SUB(%s, INTERVAL mp.check_frequency_hours HOUR)
					)
					AND (cl.next_check_after IS NULL OR cl.next_check_after <= %s)
				ORDER BY cl.last_checked_at IS NULL DESC, cl.last_checked_at ASC, cl.id ASC
				LIMIT %d",
				1,
				1,
				$now,
				$now,
				$limit
			);
		}
		$rows  = $this->wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	public function update_competitor_check_result( int $competitor_link_id, ?float $price, string $currency, ?string $error, ?string $stock_status = null ): bool {
		$table = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$is_failure = null !== $error && '' !== $error;
		$now        = current_time( 'mysql' );
		$data       = array(
			'last_checked_at' => $now,
			'last_error'      => null === $error || '' === $error ? null : sanitize_textarea_field( $error ),
			'updated_at'      => $now,
		);

		$formats = array( '%s', '%s', '%s' );

		if ( $is_failure ) {
			$consecutive_failures        = $this->get_next_consecutive_failure_count( $competitor_link_id );
			$data['consecutive_failures'] = $consecutive_failures;
			$data['next_check_after']     = $this->get_next_check_after_for_failures( $consecutive_failures );
			$formats[]                    = '%d';
			$formats[]                    = '%s';
		} else {
			$data['consecutive_failures'] = 0;
			$data['next_check_after']     = null;
			$formats[]                    = '%d';
			$formats[]                    = '%s';
		}

		if ( null !== $price ) {
			$data['last_price']    = $this->nullable_decimal( $price );
			$data['last_currency'] = $this->sanitize_currency( $currency );
			$formats[]             = '%s';
			$formats[]             = '%s';
		}

		if ( null !== $stock_status && '' !== $stock_status ) {
			$data['last_stock_status'] = $this->nullable_limited_text( $stock_status, 50 );
			$formats[]                 = '%s';
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

	public function delete_old_non_audit_logs( string $log_cutoff, string $debug_cutoff ): int {
		$table = $this->tables['logs'];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$events       = $this->get_cleanup_log_events();
		$placeholders = implode( ',', array_fill( 0, count( $events ), '%s' ) );
		$sql          = $this->wpdb->prepare(
			"DELETE FROM {$table}
			WHERE (level = %s AND created_at < %s)
				OR (event IN ({$placeholders}) AND created_at < %s)",
			array_merge( array( 'debug', $debug_cutoff ), $events, array( $log_cutoff ) )
		);

		$deleted = $this->wpdb->query( $sql );

		return false === $deleted ? 0 : (int) $deleted;
	}

	public function delete_old_price_observations( string $success_cutoff, string $failed_cutoff ): int {
		$table = $this->tables['price_observations'];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$deleted = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$table}
				WHERE (success = %d AND checked_at < %s)
					OR (success = %d AND checked_at < %s)",
				1,
				$success_cutoff,
				0,
				$failed_cutoff
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * @param array<string, mixed> $data Token row data.
	 */
	public function create_approval_token( array $data ): int {
		$table = $this->tables['approval_tokens'];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$inserted = $this->wpdb->insert(
			$table,
			array(
				'suggestion_id'    => absint( $data['suggestion_id'] ?? 0 ),
				'action'           => $this->sanitize_approval_token_action( (string) ( $data['action'] ?? '' ) ),
				'token_hash'       => sanitize_text_field( (string) ( $data['token_hash'] ?? '' ) ),
				'expires_at'       => sanitize_text_field( (string) ( $data['expires_at'] ?? '' ) ),
				'created_at'       => sanitize_text_field( (string) ( $data['created_at'] ?? current_time( 'mysql' ) ) ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $this->wpdb->insert_id;
	}

	public function delete_existing_approval_tokens_for_suggestion_action( int $suggestion_id, string $action ): int {
		$table = $this->tables['approval_tokens'];
		$action = $this->sanitize_approval_token_action( $action );

		if ( ! $this->table_exists( $table ) || '' === $action ) {
			return 0;
		}

		$deleted = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$table} WHERE suggestion_id = %d AND action = %s AND used_at IS NULL",
				absint( $suggestion_id ),
				$action
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_approval_token_by_hash( string $token_hash ): ?array {
		$table = $this->tables['approval_tokens'];
		$token_hash = sanitize_text_field( $token_hash );

		if ( ! $this->table_exists( $table ) || '' === $token_hash ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1",
				$token_hash
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function mark_approval_token_used( int $token_id, string $ip = '', string $user_agent = '' ): bool {
		$table = $this->tables['approval_tokens'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table}
				SET used_at = %s, used_ip = %s, used_user_agent = %s
				WHERE id = %d AND used_at IS NULL",
				current_time( 'mysql' ),
				substr( sanitize_text_field( $ip ), 0, 100 ),
				substr( sanitize_text_field( $user_agent ), 0, 255 ),
				absint( $token_id )
			)
		);

		return false !== $updated && (int) $updated > 0;
	}

	public function delete_old_approval_tokens( string $cutoff ): int {
		$table = $this->tables['approval_tokens'];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$deleted = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$table} WHERE (used_at IS NOT NULL AND used_at < %s) OR expires_at < %s",
				$cutoff,
				$cutoff
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * @param array<string, mixed> $data Observation fields.
	 */
	public function create_price_observation( array $data ): int {
		$table = $this->tables['price_observations'];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$inserted = $this->wpdb->insert(
			$table,
			array(
				'competitor_link_id'   => absint( $data['competitor_link_id'] ?? 0 ),
				'monitored_product_id' => absint( $data['monitored_product_id'] ?? 0 ),
				'product_id'           => absint( $data['product_id'] ?? 0 ),
				'observed_price'       => $this->nullable_decimal( $data['observed_price'] ?? null ),
				'observed_regular_price' => $this->nullable_decimal( $data['observed_regular_price'] ?? null ),
				'observed_sale_price'  => $this->nullable_decimal( $data['observed_sale_price'] ?? null ),
				'observed_sku'         => $this->nullable_limited_text( $data['observed_sku'] ?? null, 191 ),
				'observed_gtin'        => $this->nullable_limited_text( $data['observed_gtin'] ?? null, 191 ),
				'price_field'          => $this->nullable_limited_text( $data['price_field'] ?? null, 50 ),
				'currency'             => $this->nullable_currency( $data['currency'] ?? null ),
				'stock_status'         => $this->nullable_limited_text( $data['stock_status'] ?? null, 50 ),
				'extraction_method'    => $this->nullable_limited_text( $data['extraction_method'] ?? null, 100 ),
				'http_status'          => $this->nullable_positive_int( $data['http_status'] ?? null ),
				'success'              => ! empty( $data['success'] ) ? 1 : 0,
				'error_message'        => isset( $data['error_message'] ) && '' !== (string) $data['error_message'] ? sanitize_textarea_field( (string) $data['error_message'] ) : null,
				'response_time_ms'     => $this->nullable_positive_int( $data['response_time_ms'] ?? null ),
				'checked_at'           => $this->nullable_datetime( $data['checked_at'] ?? null ) ?? current_time( 'mysql' ),
				'created_at'           => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s' )
		);

		return false !== $inserted ? (int) $this->wpdb->insert_id : 0;
	}

	/**
	 * @param array<string, mixed> $filters Observation filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_price_observations( array $filters, int $page, int $per_page ): array {
		$table = $this->tables['price_observations'];
		$links = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$where  = $this->build_observation_where( $filters );
		$limit  = $this->sanitize_per_page( $per_page );
		$offset = $this->get_offset( $page, $limit );
		$sql    = "SELECT po.*, cl.competitor_name, cl.competitor_url FROM {$table} po LEFT JOIN {$links} cl ON po.competitor_link_id = cl.id {$where['sql']} ORDER BY po.checked_at DESC, po.id DESC LIMIT %d OFFSET %d";
		$args   = array_merge( $where['args'], array( $limit, $offset ) );
		$rows   = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string, mixed> $filters Observation filters.
	 */
	public function count_price_observations( array $filters ): int {
		$table = $this->tables['price_observations'];

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$where = $this->build_observation_where( $filters );
		$sql   = "SELECT COUNT(*) FROM {$table} po {$where['sql']}";

		if ( ! empty( $where['args'] ) ) {
			$sql = $this->wpdb->prepare( $sql, $where['args'] );
		}

		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent_observations_for_competitor_link( int $competitor_link_id, int $limit ): array {
		$table = $this->tables['price_observations'];

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$limit = $this->sanitize_per_page( $limit );
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE competitor_link_id = %d ORDER BY checked_at DESC, id DESC LIMIT %d",
				absint( $competitor_link_id ),
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function count_failed_observations_since( string $datetime ): int {
		return $this->count_where( 'price_observations', 'success = %d AND checked_at >= %s', array( 0, $datetime ) );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_latest_successful_observation_for_link( int $competitor_link_id ): ?array {
		$table = $this->tables['price_observations'];

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE competitor_link_id = %d AND success = %d ORDER BY checked_at DESC, id DESC LIMIT 1",
				absint( $competitor_link_id ),
				1
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
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
				'group_id'             => ! empty( $data['group_id'] ) ? absint( $data['group_id'] ) : null,
				'applies_to_group'     => ! empty( $data['applies_to_group'] ) ? 1 : 0,
				'group_action_status'  => isset( $data['group_action_status'] ) ? $this->sanitize_limited_text( (string) $data['group_action_status'], 30, 'pending' ) : null,
				'reason'               => isset( $data['reason'] ) ? sanitize_textarea_field( (string) $data['reason'] ) : null,
				'margin_after_change'  => $this->nullable_decimal( $data['margin_after_change'] ?? null ),
				'rule_details'         => $this->nullable_json_text( $data['rule_details'] ?? null ),
				'warnings'             => $this->nullable_json_text( $data['warnings'] ?? null ),
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
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
	 * @return array<string, mixed>|null
	 */
	public function get_open_market_suggestion_for_monitored_product( int $monitored_product_id ): ?array {
		$table = $this->tables['price_suggestions'];
		$links = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT ps.*, cl.competitor_name, cl.competitor_url
				FROM {$table} ps
				LEFT JOIN {$links} cl ON ps.competitor_link_id = cl.id
				WHERE ps.monitored_product_id = %d AND ps.status IN (%s, %s)
				ORDER BY ps.updated_at DESC, ps.id DESC
				LIMIT 1",
				absint( $monitored_product_id ),
				'pending',
				'blocked'
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $data Suggestion fields.
	 */
	public function update_market_price_suggestion( int $suggestion_id, array $data ): bool {
		$table = $this->tables['price_suggestions'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array(
				'competitor_link_id'  => isset( $data['competitor_link_id'] ) ? absint( $data['competitor_link_id'] ) : null,
				'current_price'       => $this->decimal( $data['current_price'] ?? 0 ),
				'competitor_price'    => $this->decimal( $data['competitor_price'] ?? 0 ),
				'suggested_price'     => $this->decimal( $data['suggested_price'] ?? 0 ),
				'difference'          => $this->decimal( $data['difference'] ?? 0 ),
				'suggestion_type'     => $this->sanitize_suggestion_type( (string) ( $data['suggestion_type'] ?? 'manual_review' ) ),
				'status'              => $this->sanitize_suggestion_status( (string) ( $data['status'] ?? 'pending' ) ),
				'group_id'            => ! empty( $data['group_id'] ) ? absint( $data['group_id'] ) : null,
				'applies_to_group'    => ! empty( $data['applies_to_group'] ) ? 1 : 0,
				'group_action_status' => isset( $data['group_action_status'] ) ? $this->sanitize_limited_text( (string) $data['group_action_status'], 30, 'pending' ) : null,
				'reason'              => isset( $data['reason'] ) ? sanitize_textarea_field( (string) $data['reason'] ) : null,
				'margin_after_change' => $this->nullable_decimal( $data['margin_after_change'] ?? null ),
				'rule_details'        => $this->nullable_json_text( $data['rule_details'] ?? null ),
				'warnings'            => $this->nullable_json_text( $data['warnings'] ?? null ),
				'updated_at'          => current_time( 'mysql' ),
			),
			array( 'id' => absint( $suggestion_id ) ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * @param array<string, mixed> $filters Suggestion filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_price_suggestions( array $filters, int $page, int $per_page ): array {
		$table  = $this->tables['price_suggestions'];
		$links  = $this->tables['competitor_links'];
		$groups = $this->tables['product_groups'] ?? '';
		$members = $this->tables['product_group_members'] ?? '';

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$where  = $this->build_suggestion_where( $filters );
		$limit  = $this->sanitize_per_page( $per_page );
		$offset = $this->get_offset( $page, $limit );
		$group_select = '';
		$group_join   = '';

		if ( $groups && $members && $this->table_exists( $groups ) && $this->table_exists( $members ) ) {
			$group_select = ", pg.name AS group_name, (SELECT COUNT(*) FROM {$members} pgm WHERE pgm.group_id = ps.group_id AND pgm.enabled = 1) AS group_member_count";
			$group_join   = " LEFT JOIN {$groups} pg ON ps.group_id = pg.id";
		}

		$sql    = "SELECT ps.*, cl.competitor_name, cl.competitor_url{$group_select} FROM {$table} ps LEFT JOIN {$links} cl ON ps.competitor_link_id = cl.id{$group_join} {$where['sql']} ORDER BY ps.created_at DESC, ps.id DESC LIMIT %d OFFSET %d";
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
		$table  = $this->tables['price_suggestions'];
		$links  = $this->tables['competitor_links'];
		$groups = $this->tables['product_groups'] ?? '';
		$members = $this->tables['product_group_members'] ?? '';

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$group_select = '';
		$group_join   = '';

		if ( $groups && $members && $this->table_exists( $groups ) && $this->table_exists( $members ) ) {
			$group_select = ", pg.name AS group_name, (SELECT COUNT(*) FROM {$members} pgm WHERE pgm.group_id = ps.group_id AND pgm.enabled = 1) AS group_member_count";
			$group_join   = " LEFT JOIN {$groups} pg ON ps.group_id = pg.id";
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT ps.*, cl.competitor_name, cl.competitor_url{$group_select} FROM {$table} ps LEFT JOIN {$links} cl ON ps.competitor_link_id = cl.id{$group_join} WHERE ps.id = %d LIMIT 1",
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

	public function update_suggestion_group_action_status( int $suggestion_id, string $status ): bool {
		$table = $this->tables['price_suggestions'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$status  = $this->sanitize_limited_text( $status, 30, 'pending' );
		$updated = $this->wpdb->update(
			$table,
			array(
				'group_action_status' => $status,
				'updated_at'          => current_time( 'mysql' ),
			),
			array( 'id' => absint( $suggestion_id ) ),
			array( '%s', '%s' ),
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

		$session_id = false !== $inserted ? (int) $this->wpdb->insert_id : 0;

		if ( $session_id > 0 && 'active' === (string) ( $data['status'] ?? 'active' ) && function_exists( 'update_post_meta' ) ) {
			update_post_meta( absint( $data['product_id'] ?? 0 ), '_lpm_price_matched_active', 'real' );
		}

		return $session_id;
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

		$session = $this->get_price_match_session( $session_id );
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

		if ( false !== $updated && $session && function_exists( 'delete_post_meta' ) ) {
			delete_post_meta( (int) $session['product_id'], '_lpm_price_matched_active' );
		}

		return false !== $updated;
	}

	public function count_active_price_match_sessions(): int {
		return $this->count_where( 'price_match_sessions', 'status IN (%s, %s)', array( 'active', 'active_dry_run' ) );
	}

	public function product_has_active_price_match_session( int $product_id ): bool {
		$table = $this->tables['price_match_sessions'];

		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		return (bool) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$table} WHERE product_id = %d AND status = %s LIMIT 1",
				absint( $product_id ),
				'active'
			)
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_price_match_session( int $session_id ): ?array {
		$table = $this->tables['price_match_sessions'];

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $session_id ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_active_price_match_sessions( int $limit = 10 ): array {
		$table     = $this->tables['price_match_sessions'];
		$monitored = $this->tables['monitored_products'];

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$limit = $this->sanitize_per_page( $limit );

		if ( $this->table_exists( $monitored ) ) {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT pms.*, mp.sku
					FROM {$table} pms
					LEFT JOIN {$monitored} mp ON pms.monitored_product_id = mp.id
					WHERE pms.status IN (%s, %s)
					ORDER BY pms.matched_at DESC, pms.id DESC
					LIMIT %d",
					'active',
					'active_dry_run',
					$limit
				),
				ARRAY_A
			);

			return is_array( $rows ) ? $rows : array();
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN (%s, %s) ORDER BY matched_at DESC, id DESC LIMIT %d",
				'active',
				'active_dry_run',
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<int, int> $product_ids Product IDs.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_active_price_match_sessions_for_products( array $product_ids ): array {
		$table       = $this->tables['price_match_sessions'];
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );

		if ( empty( $product_ids ) || ! $this->table_exists( $table ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );
		$sql          = "SELECT * FROM {$table} WHERE product_id IN ({$placeholders}) AND status = %s ORDER BY matched_at DESC, id DESC";
		$rows         = $this->wpdb->get_results( $this->wpdb->prepare( $sql, array_merge( $product_ids, array( 'active' ) ) ), ARRAY_A );
		$sessions     = array();

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $row ) {
			$product_id = absint( $row['product_id'] ?? 0 );

			if ( $product_id > 0 && ! isset( $sessions[ $product_id ] ) ) {
				$sessions[ $product_id ] = $row;
			}
		}

		return $sessions;
	}

	private function count_price_suggestions_by_view( string $view ): int {
		return $this->count_price_suggestions( array( 'view' => $view ) );
	}

	/**
	 * @param array<string, mixed> $data Group fields.
	 */
	public function create_product_group( array $data ): int {
		$table = $this->tables['product_groups'] ?? '';

		if ( ! $table || ! $this->table_exists( $table ) ) {
			return 0;
		}

		$now      = current_time( 'mysql' );
		$inserted = $this->wpdb->insert(
			$table,
			array(
				'name'               => substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 191 ),
				'description'        => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : null,
				'enabled'            => ! empty( $data['enabled'] ) ? 1 : 0,
				'pricing_mode'       => $this->sanitize_group_pricing_mode( (string) ( $data['pricing_mode'] ?? 'shared_price' ) ),
				'primary_product_id' => ! empty( $data['primary_product_id'] ) ? absint( $data['primary_product_id'] ) : null,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		return false !== $inserted ? (int) $this->wpdb->insert_id : 0;
	}

	/**
	 * @param array<string, mixed> $data Group fields.
	 */
	public function update_product_group( int $group_id, array $data ): bool {
		$table = $this->tables['product_groups'] ?? '';

		if ( ! $table || ! $this->table_exists( $table ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array(
				'name'               => substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 191 ),
				'description'        => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : null,
				'enabled'            => ! empty( $data['enabled'] ) ? 1 : 0,
				'pricing_mode'       => $this->sanitize_group_pricing_mode( (string) ( $data['pricing_mode'] ?? 'shared_price' ) ),
				'primary_product_id' => ! empty( $data['primary_product_id'] ) ? absint( $data['primary_product_id'] ) : null,
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => absint( $group_id ) ),
			array( '%s', '%s', '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function set_product_group_enabled( int $group_id, bool $enabled ): bool {
		$table = $this->tables['product_groups'] ?? '';

		if ( ! $table || ! $this->table_exists( $table ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array(
				'enabled'    => $enabled ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $group_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function delete_empty_product_group( int $group_id ): bool {
		$groups  = $this->tables['product_groups'] ?? '';
		$members = $this->tables['product_group_members'] ?? '';

		if ( ! $groups || ! $members || ! $this->table_exists( $groups ) || ! $this->table_exists( $members ) ) {
			return false;
		}

		if ( $this->count_product_group_members( $group_id ) > 0 ) {
			return $this->set_product_group_enabled( $group_id, false );
		}

		$deleted = $this->wpdb->delete( $groups, array( 'id' => absint( $group_id ) ), array( '%d' ) );

		return false !== $deleted;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_product_groups( int $page, int $per_page ): array {
		$groups      = $this->tables['product_groups'] ?? '';
		$members     = $this->tables['product_group_members'] ?? '';
		$suggestions = $this->tables['price_suggestions'];

		if ( ! $groups || ! $this->table_exists( $groups ) ) {
			return array();
		}

		$limit  = $this->sanitize_per_page( $per_page );
		$offset = $this->get_offset( $page, $limit );
		$member_select = $this->table_exists( $members ) ? "(SELECT COUNT(*) FROM {$members} pgm WHERE pgm.group_id = pg.id AND pgm.enabled = 1) AS member_count" : '0 AS member_count';
		$suggestion_select = $this->table_exists( $suggestions ) ? "(SELECT MAX(ps.created_at) FROM {$suggestions} ps WHERE ps.group_id = pg.id) AS last_suggestion" : 'NULL AS last_suggestion';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT pg.*, {$member_select}, {$suggestion_select}
				FROM {$groups} pg
				ORDER BY pg.updated_at DESC, pg.id DESC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function count_product_groups(): int {
		return $this->get_table_count( 'product_groups' );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_product_group( int $group_id ): ?array {
		$table = $this->tables['product_groups'] ?? '';

		if ( ! $table || ! $this->table_exists( $table ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $group_id ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array{active_real: int, active_dry_run: int, safety_warnings: int, warnings: array<int, string>}
	 */
	public function get_product_group_health( int $group_id ): array {
		$members  = $this->tables['product_group_members'] ?? '';
		$sessions = $this->tables['price_match_sessions'] ?? '';
		$warnings = array();

		if ( ! $members || ! $this->table_exists( $members ) ) {
			return array(
				'active_real'     => 0,
				'active_dry_run'  => 0,
				'safety_warnings' => 0,
				'warnings'        => array(),
			);
		}

		$active_real = 0;
		$dry_run     = 0;

		if ( $sessions && $this->table_exists( $sessions ) ) {
			$active_real = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(DISTINCT pms.product_id)
					FROM {$sessions} pms
					INNER JOIN {$members} pgm ON pms.product_id = pgm.product_id
					WHERE pgm.group_id = %d AND pgm.enabled = 1 AND pms.status = %s",
					absint( $group_id ),
					'active'
				)
			);
			$dry_run = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(DISTINCT pms.product_id)
					FROM {$sessions} pms
					INNER JOIN {$members} pgm ON pms.product_id = pgm.product_id
					WHERE pgm.group_id = %d AND pgm.enabled = 1 AND pms.status = %s",
					absint( $group_id ),
					'active_dry_run'
				)
			);
		}

		$group = $this->get_product_group( $group_id );

		if ( $group && 'primary_product_controls_group' === (string) ( $group['pricing_mode'] ?? '' ) && empty( $group['primary_product_id'] ) ) {
			$warnings[] = __( 'Primary-controlled group has no primary product.', 'lilleprinsen-price-monitor' );
		}

		$enabled_members = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM {$members} WHERE group_id = %d AND enabled = 1", absint( $group_id ) )
		);

		if ( $enabled_members <= 0 ) {
			$warnings[] = __( 'Group has no enabled members.', 'lilleprinsen-price-monitor' );
		}

		return array(
			'active_real'     => $active_real,
			'active_dry_run'  => $dry_run,
			'safety_warnings' => count( $warnings ),
			'warnings'        => $warnings,
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_active_product_group_for_monitored_product( int $monitored_product_id ): ?array {
		$groups  = $this->tables['product_groups'] ?? '';
		$members = $this->tables['product_group_members'] ?? '';

		if ( ! $groups || ! $members || ! $this->table_exists( $groups ) || ! $this->table_exists( $members ) ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT pg.*, pgm.role AS member_role, pgm.enabled AS member_enabled
				FROM {$members} pgm
				INNER JOIN {$groups} pg ON pgm.group_id = pg.id
				WHERE pgm.monitored_product_id = %d AND pg.enabled = 1 AND pgm.enabled = 1
				ORDER BY pgm.role = 'primary' DESC, pgm.id DESC
				LIMIT 1",
				absint( $monitored_product_id )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<int, string>
	 */
	public function get_group_names_for_monitored_products( array $monitored_product_ids ): array {
		$groups  = $this->tables['product_groups'] ?? '';
		$members = $this->tables['product_group_members'] ?? '';
		$ids     = array_values( array_filter( array_map( 'absint', $monitored_product_ids ) ) );

		if ( empty( $ids ) || ! $groups || ! $members || ! $this->table_exists( $groups ) || ! $this->table_exists( $members ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT pgm.monitored_product_id, pg.name
				FROM {$members} pgm
				INNER JOIN {$groups} pg ON pgm.group_id = pg.id
				WHERE pgm.monitored_product_id IN ({$placeholders}) AND pg.enabled = 1 AND pgm.enabled = 1",
				$ids
			),
			ARRAY_A
		);
		$names = array();

		if ( ! is_array( $rows ) ) {
			return $names;
		}

		foreach ( $rows as $row ) {
			$names[ (int) $row['monitored_product_id'] ] = (string) $row['name'];
		}

		return $names;
	}

	public function add_product_group_member( int $group_id, int $monitored_product_id, string $role = 'member' ): int {
		$table     = $this->tables['product_group_members'] ?? '';
		$monitored = $this->get_monitored_product( $monitored_product_id );

		if ( ! $table || ! $monitored || ! $this->table_exists( $table ) ) {
			return 0;
		}

		$active_group = $this->get_active_product_group_for_monitored_product( $monitored_product_id );

		if ( $active_group && (int) $active_group['id'] !== absint( $group_id ) ) {
			return 0;
		}

		$role = $this->sanitize_group_member_role( $role );
		$now  = current_time( 'mysql' );
		$inserted = $this->wpdb->replace(
			$table,
			array(
				'group_id'             => absint( $group_id ),
				'monitored_product_id' => absint( $monitored_product_id ),
				'product_id'           => absint( $monitored['product_id'] ?? 0 ),
				'role'                 => $role,
				'enabled'              => 1,
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		if ( 'primary' === $role ) {
			$this->set_product_group_primary_member( $group_id, absint( $monitored['product_id'] ?? 0 ) );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_product_group_members( int $group_id, bool $enabled_only = false ): array {
		$table     = $this->tables['product_group_members'] ?? '';
		$monitored = $this->tables['monitored_products'];

		if ( ! $table || ! $this->table_exists( $table ) ) {
			return array();
		}

		$where = $enabled_only ? 'AND pgm.enabled = 1' : '';
		$join  = $this->table_exists( $monitored ) ? " LEFT JOIN {$monitored} mp ON pgm.monitored_product_id = mp.id" : '';
		$select = $this->table_exists( $monitored ) ? ', mp.sku, mp.priority, mp.strategy, mp.min_margin_percent, mp.min_price' : '';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT pgm.*{$select}
				FROM {$table} pgm{$join}
				WHERE pgm.group_id = %d {$where}
				ORDER BY pgm.role = 'primary' DESC, pgm.created_at ASC, pgm.id ASC",
				absint( $group_id )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function count_product_group_members( int $group_id ): int {
		$table = $this->tables['product_group_members'] ?? '';

		if ( ! $table || ! $this->table_exists( $table ) ) {
			return 0;
		}

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE group_id = %d", absint( $group_id ) )
		);
	}

	public function set_product_group_member_enabled( int $member_id, bool $enabled ): bool {
		$table = $this->tables['product_group_members'] ?? '';

		if ( ! $table || ! $this->table_exists( $table ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$table,
			array(
				'enabled'    => $enabled ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $member_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	public function remove_product_group_member( int $member_id ): bool {
		$table = $this->tables['product_group_members'] ?? '';

		if ( ! $table || ! $this->table_exists( $table ) ) {
			return false;
		}

		$deleted = $this->wpdb->delete( $table, array( 'id' => absint( $member_id ) ), array( '%d' ) );

		return false !== $deleted;
	}

	public function set_product_group_primary_member( int $group_id, int $product_id ): bool {
		$groups  = $this->tables['product_groups'] ?? '';
		$members = $this->tables['product_group_members'] ?? '';

		if ( ! $groups || ! $members || ! $this->table_exists( $groups ) || ! $this->table_exists( $members ) ) {
			return false;
		}

		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$members} SET role = %s, updated_at = %s WHERE group_id = %d",
				'member',
				current_time( 'mysql' ),
				absint( $group_id )
			)
		);
		$member_updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$members} SET role = %s, enabled = 1, updated_at = %s WHERE group_id = %d AND product_id = %d",
				'primary',
				current_time( 'mysql' ),
				absint( $group_id ),
				absint( $product_id )
			)
		);
		$group_updated = $this->wpdb->update(
			$groups,
			array(
				'primary_product_id' => absint( $product_id ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => absint( $group_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $member_updated && false !== $group_updated;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function search_monitored_products_for_group( string $query, int $limit = 20 ): array {
		$table = $this->tables['monitored_products'];

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$query = trim( sanitize_text_field( $query ) );
		$limit = min( 20, max( 1, absint( $limit ) ) );

		if ( '' === $query ) {
			return array();
		}

		if ( ctype_digit( $query ) ) {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$table} WHERE product_id = %d OR id = %d ORDER BY enabled DESC, updated_at DESC LIMIT %d",
					absint( $query ),
					absint( $query ),
					$limit
				),
				ARRAY_A
			);
		} else {
			$like = '%' . $this->wpdb->esc_like( $query ) . '%';
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$table} WHERE sku LIKE %s ORDER BY enabled DESC, updated_at DESC LIMIT %d",
					$like,
					$limit
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	private function get_next_consecutive_failure_count( int $competitor_link_id ): int {
		$table = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $table ) ) {
			return 1;
		}

		$current = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT consecutive_failures FROM {$table} WHERE id = %d LIMIT 1",
				absint( $competitor_link_id )
			)
		);

		return max( 1, $current + 1 );
	}

	private function get_next_check_after_for_failures( int $consecutive_failures ): string {
		if ( 1 === $consecutive_failures ) {
			$delay = HOUR_IN_SECONDS;
		} elseif ( 2 === $consecutive_failures ) {
			$delay = 6 * HOUR_IN_SECONDS;
		} else {
			$delay = DAY_IN_SECONDS;
		}

		return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $delay );
	}

	/**
	 * @return array<int, string>
	 */
	private function get_cleanup_log_events(): array {
		return array(
			'check_batch_started',
			'check_batch_completed',
			'check_batch_skipped',
			'check_batch_link_skipped_for_delay',
			'competitor_check_failed',
			'scheduled_suggestion_skipped',
			'scheduled_checks_not_registered',
			'notification_test',
			'notification_sent',
			'notification_skipped',
			'webhook_notification_sent',
			'webhook_notification_failed',
			'webhook_notification_skipped',
			'webhook_test',
			'manual_discovery_run_started',
			'manual_discovery_run_completed',
			'manual_discovery_run_cancelled',
			'manual_discovery_pair_checked',
			'manual_discovery_match_found',
			'manual_discovery_no_match',
			'manual_discovery_price_extraction_failed',
			'manual_discovery_js_required',
			'manual_discovery_suggestion_approved',
			'manual_discovery_suggestion_rejected',
			'retention_cleanup_completed',
		);
	}

	private function sanitize_approval_token_action( string $action ): string {
		$action = sanitize_key( $action );

		return in_array( $action, array( 'approve_dry_run', 'reject', 'match_price', 'match_price_minus_1' ), true ) ? $action : '';
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
	 * @return array<int, array<string, mixed>>
	 */
	public function get_monitored_products_export_rows( int $limit ): array {
		$monitored_table = $this->tables['monitored_products'];
		$links_table     = $this->tables['competitor_links'];

		if ( ! $this->table_exists( $monitored_table ) || ! $this->table_exists( $links_table ) ) {
			return array();
		}

		$limit = $this->sanitize_export_limit( $limit );
		$sql   = $this->wpdb->prepare(
			"SELECT
				mp.product_id,
				mp.sku,
				mp.enabled,
				mp.priority,
				mp.strategy,
				mp.min_margin_percent,
				mp.min_price,
				mp.check_frequency_hours,
				cl.competitor_name,
				cl.competitor_url,
				cl.match_type,
				cl.last_price,
				cl.last_checked_at,
				cl.last_error
			FROM {$monitored_table} mp
			LEFT JOIN {$links_table} cl ON mp.id = cl.monitored_product_id
			ORDER BY mp.updated_at DESC, mp.id DESC, cl.id ASC
			LIMIT %d",
			$limit
		);
		$rows  = $this->wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
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
	 * @param array<string, mixed> $filters Observation filters.
	 * @return array{sql: string, args: array<int, mixed>}
	 */
	private function build_observation_where( array $filters ): array {
		$where = array();
		$args  = array();

		if ( ! empty( $filters['competitor_link_id'] ) ) {
			$where[] = 'po.competitor_link_id = %d';
			$args[]  = absint( $filters['competitor_link_id'] );
		}

		if ( ! empty( $filters['monitored_product_id'] ) ) {
			$where[] = 'po.monitored_product_id = %d';
			$args[]  = absint( $filters['monitored_product_id'] );
		}

		if ( ! empty( $filters['product_id'] ) ) {
			$where[] = 'po.product_id = %d';
			$args[]  = absint( $filters['product_id'] );
		}

		if ( ! empty( $filters['status'] ) ) {
			$status = sanitize_key( (string) $filters['status'] );

			if ( 'success' === $status ) {
				$where[] = 'po.success = %d';
				$args[]  = 1;
			} elseif ( 'failed' === $status ) {
				$where[] = 'po.success = %d';
				$args[]  = 0;
			}
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$date_from = $this->normalize_filter_date( (string) $filters['date_from'], false );

			if ( null !== $date_from ) {
				$where[] = 'po.checked_at >= %s';
				$args[]  = $date_from;
			}
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$date_to = $this->normalize_filter_date( (string) $filters['date_to'], true );

			if ( null !== $date_to ) {
				$where[] = 'po.checked_at <= %s';
				$args[]  = $date_to;
			}
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

		if ( ! empty( $filters['product_id'] ) ) {
			$where[] = 'ps.product_id = %d';
			$args[]  = absint( $filters['product_id'] );
		}

		if ( ! empty( $filters['monitored_product_id'] ) ) {
			$where[] = 'ps.monitored_product_id = %d';
			$args[]  = absint( $filters['monitored_product_id'] );
		}

		if ( ! empty( $filters['competitor_link_id'] ) ) {
			$where[] = 'ps.competitor_link_id = %d';
			$args[]  = absint( $filters['competitor_link_id'] );
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

	/**
	 * @param array<string, mixed> $data Raw competitor profile fields.
	 * @return array<string, mixed>
	 */
	private function sanitize_competitor_profile_data( array $data ): array {
		return array(
			'name'                    => substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 191 ),
			'domain'                  => $this->sanitize_domain( (string) ( $data['domain'] ?? '' ) ),
			'enabled'                 => ! array_key_exists( 'enabled', $data ) || ! empty( $data['enabled'] ) ? 1 : 0,
			'default_currency'        => $this->sanitize_currency( (string) ( $data['default_currency'] ?? 'NOK' ) ),
			'request_delay_seconds'   => $this->sanitize_bounded_int( $data['request_delay_seconds'] ?? 2, 0, 3600, 2 ),
			'request_timeout_seconds' => $this->nullable_bounded_int( $data['request_timeout_seconds'] ?? null, 1, 30 ),
			'price_extraction_mode'   => $this->sanitize_price_extraction_mode( (string) ( $data['price_extraction_mode'] ?? 'auto' ) ),
			'price_selector'          => $this->nullable_limited_text( $data['price_selector'] ?? null, 255 ),
			'regular_price_selector'  => $this->nullable_limited_text( $data['regular_price_selector'] ?? null, 255 ),
			'sale_price_selector'     => $this->nullable_limited_text( $data['sale_price_selector'] ?? null, 255 ),
			'sku_selector'            => $this->nullable_limited_text( $data['sku_selector'] ?? null, 255 ),
			'gtin_selector'           => $this->nullable_limited_text( $data['gtin_selector'] ?? null, 255 ),
			'monitored_price_field'   => $this->sanitize_monitored_price_field( (string) ( $data['monitored_price_field'] ?? 'sale_price_first' ) ),
			'stock_selector'          => $this->nullable_limited_text( $data['stock_selector'] ?? null, 255 ),
			'stock_in_text'           => $this->nullable_limited_text( $data['stock_in_text'] ?? null, 255 ),
			'stock_out_text'          => $this->nullable_limited_text( $data['stock_out_text'] ?? null, 255 ),
			'json_ld_enabled'         => ! array_key_exists( 'json_ld_enabled', $data ) || ! empty( $data['json_ld_enabled'] ) ? 1 : 0,
			'meta_tags_enabled'       => ! array_key_exists( 'meta_tags_enabled', $data ) || ! empty( $data['meta_tags_enabled'] ) ? 1 : 0,
			'visible_regex_enabled'   => ! array_key_exists( 'visible_regex_enabled', $data ) || ! empty( $data['visible_regex_enabled'] ) ? 1 : 0,
			'requires_javascript'     => ! empty( $data['requires_javascript'] ) ? 1 : 0,
			'notes'                   => isset( $data['notes'] ) && '' !== (string) $data['notes'] ? sanitize_textarea_field( (string) $data['notes'] ) : null,
		);
	}

	private function sanitize_price_extraction_mode( string $mode ): string {
		$allowed = array( 'auto', 'json_ld', 'meta_tags', 'selector', 'visible_regex' );
		$mode    = sanitize_key( $mode );

		return in_array( $mode, $allowed, true ) ? $mode : 'auto';
	}

	private function sanitize_monitored_price_field( string $field ): string {
		$allowed = array( 'sale_price_first', 'sale_price', 'regular_price', 'price_selector', 'lowest_price' );
		$field   = sanitize_key( $field );

		return in_array( $field, $allowed, true ) ? $field : 'sale_price_first';
	}

	private function sanitize_domain( string $domain ): ?string {
		$domain = trim( strtolower( sanitize_text_field( $domain ) ) );

		if ( '' === $domain ) {
			return null;
		}

		if ( str_contains( $domain, '://' ) ) {
			$host = wp_parse_url( $domain, PHP_URL_HOST );
			$domain = is_string( $host ) ? $host : '';
		}

		$domain = preg_replace( '/[^a-z0-9.-]/', '', $domain );
		$domain = is_string( $domain ) ? trim( $domain, '.-' ) : '';

		return '' === $domain ? null : substr( $domain, 0, 191 );
	}

	private function sanitize_monitored_priority( string $priority ): string {
		$allowed = array( 'low', 'normal', 'high', 'urgent' );
		$priority = sanitize_key( $priority );

		return in_array( $priority, $allowed, true ) ? $priority : 'normal';
	}

	private function sanitize_monitored_strategy( string $strategy ): string {
		$allowed = array( 'notify_only', 'match_competitor', 'beat_competitor_by_amount', 'stay_above_competitor_by_amount' );
		$strategy = sanitize_key( $strategy );

		return in_array( $strategy, $allowed, true ) ? $strategy : 'match_competitor';
	}

	private function sanitize_group_pricing_mode( string $mode ): string {
		$allowed = array( 'shared_price', 'primary_product_controls_group', 'manual_review_only' );
		$mode    = sanitize_key( $mode );

		return in_array( $mode, $allowed, true ) ? $mode : 'shared_price';
	}

	private function sanitize_group_member_role( string $role ): string {
		$role = sanitize_key( $role );

		return 'primary' === $role ? 'primary' : 'member';
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

	/**
	 * @param mixed $currency Raw currency.
	 */
	private function nullable_currency( $currency ): ?string {
		if ( null === $currency || '' === $currency ) {
			return null;
		}

		$currency = strtoupper( sanitize_text_field( (string) $currency ) );
		$currency = preg_replace( '/[^A-Z]/', '', $currency );

		return is_string( $currency ) && '' !== $currency ? substr( $currency, 0, 10 ) : null;
	}

	/**
	 * @param mixed $value Raw text.
	 */
	private function nullable_limited_text( $value, int $length ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = sanitize_text_field( (string) $value );

		return '' === $value ? null : substr( $value, 0, $length );
	}

	/**
	 * @param mixed $value Raw integer.
	 */
	private function nullable_positive_int( $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$int = absint( $value );

		return $int > 0 ? $int : null;
	}

	private function sanitize_per_page( int $per_page ): int {
		return min( 200, max( 1, absint( $per_page ) ) );
	}

	private function sanitize_export_limit( int $limit ): int {
		return min( 1000, max( 1, absint( $limit ) ) );
	}

	/**
	 * @param mixed $value Raw integer.
	 */
	private function sanitize_bounded_int( $value, int $min, int $max, int $fallback ): int {
		$int = absint( $value );

		if ( $int < $min ) {
			return $fallback;
		}

		return min( $int, $max );
	}

	/**
	 * @param mixed $value Raw integer.
	 */
	private function nullable_bounded_int( $value, int $min, int $max ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$int = absint( $value );

		if ( $int < $min ) {
			return null;
		}

		return min( $int, $max );
	}

	private function get_offset( int $page, int $per_page ): int {
		return max( 0, ( max( 1, absint( $page ) ) - 1 ) * $per_page );
	}

	private function get_latest_successful_observation_time(): string {
		$table = $this->tables['price_observations'];

		if ( ! $this->table_exists( $table ) ) {
			return '';
		}

		return (string) $this->wpdb->get_var( "SELECT checked_at FROM {$table} WHERE success = 1 ORDER BY checked_at DESC, id DESC LIMIT 1" );
	}

	private function normalize_filter_date( string $date, bool $end_of_day ): ?string {
		$date = sanitize_text_field( $date );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}

		return $date . ( $end_of_day ? ' 23:59:59' : ' 00:00:00' );
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
	 * @param mixed $value Raw JSON-compatible data.
	 */
	private function nullable_json_text( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_string( $value ) ) {
			$value = sanitize_textarea_field( $value );
			return '' === $value ? null : $value;
		}

		$json = wp_json_encode( $value );

		return false === $json ? null : $json;
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
