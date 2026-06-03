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
		return array(
			'monitored_products'      => $this->count_where( 'monitored_products', 'enabled = %d', array( 1 ) ),
			'active_competitor_links' => $this->count_where( 'competitor_links', 'enabled = %d', array( 1 ) ),
			'pending_suggestions'     => $this->count_where( 'price_suggestions', 'status = %s', array( 'pending' ) ),
			'failed_logs'             => $this->count_where( 'logs', 'level = %s', array( 'error' ) ),
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
}
