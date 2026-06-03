<?php
/**
 * Custom database schema.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {
	public const VERSION = '1.0.0';

	public const OPTION_NAME = 'lpm_schema_version';

	/**
	 * @return array<string, string>
	 */
	public static function table_names(): array {
		global $wpdb;

		return array(
			'monitored_products' => $wpdb->prefix . 'lpm_monitored_products',
			'competitor_links'   => $wpdb->prefix . 'lpm_competitor_links',
			'price_suggestions'  => $wpdb->prefix . 'lpm_price_suggestions',
			'logs'               => $wpdb->prefix . 'lpm_logs',
		);
	}

	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tables          = self::table_names();

		$sql = array();

		$sql[] = "CREATE TABLE {$tables['monitored_products']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			sku varchar(191) DEFAULT NULL,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			priority varchar(20) NOT NULL DEFAULT 'normal',
			strategy varchar(50) NOT NULL DEFAULT 'notify_only',
			min_margin_percent decimal(10,2) DEFAULT NULL,
			min_price decimal(20,4) DEFAULT NULL,
			check_frequency_hours int(10) unsigned NOT NULL DEFAULT 24,
			last_checked_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_id (product_id),
			KEY sku (sku),
			KEY enabled (enabled),
			KEY priority (priority),
			KEY last_checked_at (last_checked_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['competitor_links']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			monitored_product_id bigint(20) unsigned NOT NULL,
			competitor_name varchar(191) NOT NULL,
			competitor_url text NOT NULL,
			match_type varchar(50) NOT NULL DEFAULT 'unknown',
			enabled tinyint(1) NOT NULL DEFAULT 1,
			last_price decimal(20,4) DEFAULT NULL,
			last_currency varchar(10) DEFAULT NULL,
			last_stock_status varchar(50) DEFAULT NULL,
			last_checked_at datetime DEFAULT NULL,
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY monitored_product_id (monitored_product_id),
			KEY competitor_name (competitor_name),
			KEY enabled (enabled),
			KEY last_checked_at (last_checked_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['price_suggestions']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			monitored_product_id bigint(20) unsigned NOT NULL,
			competitor_link_id bigint(20) unsigned DEFAULT NULL,
			product_id bigint(20) unsigned NOT NULL,
			current_price decimal(20,4) NOT NULL,
			competitor_price decimal(20,4) NOT NULL,
			suggested_price decimal(20,4) NOT NULL,
			difference decimal(20,4) NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'pending',
			reason text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			approved_at datetime DEFAULT NULL,
			rejected_at datetime DEFAULT NULL,
			approved_by bigint(20) unsigned DEFAULT NULL,
			rejected_by bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY monitored_product_id (monitored_product_id),
			KEY product_id (product_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['logs']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(20) NOT NULL DEFAULT 'info',
			event varchar(100) NOT NULL,
			message text NULL,
			context longtext NULL,
			product_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY event (event),
			KEY product_id (product_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::OPTION_NAME, self::VERSION, false );
	}

	public static function get_schema_version(): string {
		return (string) get_option( self::OPTION_NAME, '' );
	}
}
