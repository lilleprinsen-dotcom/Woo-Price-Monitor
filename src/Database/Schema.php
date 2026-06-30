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
	public const VERSION = '2.2.1';

	public const OPTION_NAME = 'lpm_schema_version';

	/**
	 * @return array<string, string>
	 */
	public static function table_names(): array {
		global $wpdb;

		return array(
			'monitored_products' => $wpdb->prefix . 'lpm_monitored_products',
			'competitors'        => $wpdb->prefix . 'lpm_competitors',
			'competitor_links'   => $wpdb->prefix . 'lpm_competitor_links',
			'product_groups'     => $wpdb->prefix . 'lpm_product_groups',
			'product_group_members' => $wpdb->prefix . 'lpm_product_group_members',
			'price_observations' => $wpdb->prefix . 'lpm_price_observations',
			'price_suggestions'  => $wpdb->prefix . 'lpm_price_suggestions',
			'price_match_sessions' => $wpdb->prefix . 'lpm_price_match_sessions',
			'approval_tokens'    => $wpdb->prefix . 'lpm_approval_tokens',
			'logs'               => $wpdb->prefix . 'lpm_logs',
		);
	}

	public static function maybe_upgrade(): void {
		$current_version = self::get_schema_version();

		if ( self::VERSION === $current_version ) {
			return;
		}

		self::create_tables();
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
			strategy varchar(50) NOT NULL DEFAULT 'match_competitor',
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
			KEY enabled_updated_at (enabled, updated_at),
			KEY priority (priority),
			KEY last_checked_at (last_checked_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['competitors']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			domain varchar(191) DEFAULT NULL,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			default_currency varchar(10) NOT NULL DEFAULT 'NOK',
			request_delay_seconds int(10) unsigned NOT NULL DEFAULT 2,
			request_timeout_seconds int(10) unsigned DEFAULT NULL,
			price_extraction_mode varchar(50) NOT NULL DEFAULT 'auto',
			price_selector varchar(255) DEFAULT NULL,
			regular_price_selector varchar(255) DEFAULT NULL,
			sale_price_selector varchar(255) DEFAULT NULL,
			sku_selector varchar(255) DEFAULT NULL,
			gtin_selector varchar(255) DEFAULT NULL,
			monitored_price_field varchar(50) NOT NULL DEFAULT 'sale_price_first',
			stock_selector varchar(255) DEFAULT NULL,
			stock_in_text varchar(255) DEFAULT NULL,
			stock_out_text varchar(255) DEFAULT NULL,
			json_ld_enabled tinyint(1) NOT NULL DEFAULT 1,
			meta_tags_enabled tinyint(1) NOT NULL DEFAULT 1,
			visible_regex_enabled tinyint(1) NOT NULL DEFAULT 1,
			requires_javascript tinyint(1) NOT NULL DEFAULT 0,
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY name (name),
			KEY domain (domain),
			KEY enabled (enabled),
			KEY requires_javascript (requires_javascript),
			KEY enabled_updated_at (enabled, updated_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['competitor_links']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			monitored_product_id bigint(20) unsigned NOT NULL,
			competitor_id bigint(20) unsigned DEFAULT NULL,
			competitor_name varchar(191) NOT NULL,
			competitor_url text NOT NULL,
			match_type varchar(50) NOT NULL DEFAULT 'unknown',
			enabled tinyint(1) NOT NULL DEFAULT 1,
			is_primary tinyint(1) NOT NULL DEFAULT 0,
			price_field_override varchar(50) DEFAULT NULL,
			last_price decimal(20,4) DEFAULT NULL,
			last_currency varchar(10) DEFAULT NULL,
			last_stock_status varchar(50) DEFAULT NULL,
			last_checked_at datetime DEFAULT NULL,
			last_error text NULL,
			consecutive_failures int(10) unsigned NOT NULL DEFAULT 0,
			next_check_after datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY monitored_product_id (monitored_product_id),
			KEY competitor_id (competitor_id),
			KEY competitor_name (competitor_name),
			KEY enabled (enabled),
			KEY is_primary (is_primary),
			KEY enabled_last_checked_at (enabled, last_checked_at),
			KEY enabled_next_check_after (enabled, next_check_after),
			KEY monitored_enabled (monitored_product_id, enabled),
			KEY competitor_enabled (competitor_id, enabled),
			KEY next_check_after (next_check_after),
			KEY last_checked_at (last_checked_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['product_groups']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			description text NULL,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			pricing_mode varchar(50) NOT NULL DEFAULT 'shared_price',
			primary_product_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY name (name),
			KEY enabled (enabled),
			KEY primary_product_id (primary_product_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['product_group_members']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			group_id bigint(20) unsigned NOT NULL,
			monitored_product_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			role varchar(30) NOT NULL DEFAULT 'member',
			enabled tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY group_product (group_id, product_id),
			KEY group_id (group_id),
			KEY monitored_product_id (monitored_product_id),
			KEY product_id (product_id),
			KEY enabled (enabled)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['price_observations']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			competitor_link_id bigint(20) unsigned NOT NULL,
			monitored_product_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			observed_price decimal(20,4) DEFAULT NULL,
			observed_regular_price decimal(20,4) DEFAULT NULL,
			observed_sale_price decimal(20,4) DEFAULT NULL,
			observed_sku varchar(191) DEFAULT NULL,
			observed_gtin varchar(191) DEFAULT NULL,
			price_field varchar(50) DEFAULT NULL,
			currency varchar(10) DEFAULT NULL,
			stock_status varchar(50) DEFAULT NULL,
			extraction_method varchar(100) DEFAULT NULL,
			http_status int(10) unsigned DEFAULT NULL,
			success tinyint(1) NOT NULL DEFAULT 0,
			error_message text NULL,
			response_time_ms int(10) unsigned DEFAULT NULL,
			checked_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY competitor_link_id (competitor_link_id),
			KEY monitored_product_id (monitored_product_id),
			KEY product_id (product_id),
			KEY success (success),
			KEY checked_at (checked_at),
			KEY success_checked_at (success, checked_at),
			KEY product_checked_at (product_id, checked_at),
			KEY competitor_checked_at (competitor_link_id, checked_at)
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
			suggestion_type varchar(50) NOT NULL DEFAULT 'price_match_down',
			status varchar(30) NOT NULL DEFAULT 'pending',
			reason text NULL,
			margin_after_change decimal(10,2) DEFAULT NULL,
			rule_details longtext NULL,
			warnings text NULL,
			group_id bigint(20) unsigned DEFAULT NULL,
			applies_to_group tinyint(1) NOT NULL DEFAULT 0,
			group_action_status varchar(30) DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			approved_at datetime DEFAULT NULL,
			rejected_at datetime DEFAULT NULL,
			approved_by bigint(20) unsigned DEFAULT NULL,
			rejected_by bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY monitored_product_id (monitored_product_id),
			KEY competitor_link_id (competitor_link_id),
			KEY product_id (product_id),
			KEY group_id (group_id),
			KEY applies_to_group (applies_to_group),
			KEY suggestion_type (suggestion_type),
			KEY status (status),
			KEY status_type (status, suggestion_type),
			KEY status_created_at (status, created_at),
			KEY product_status (product_id, status),
			KEY created_at (created_at),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['price_match_sessions']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			monitored_product_id bigint(20) unsigned NOT NULL,
			suggestion_id bigint(20) unsigned DEFAULT NULL,
			status varchar(30) NOT NULL DEFAULT 'active',
			original_regular_price decimal(20,4) DEFAULT NULL,
			original_sale_price decimal(20,4) DEFAULT NULL,
			original_active_price decimal(20,4) DEFAULT NULL,
			original_sale_start datetime DEFAULT NULL,
			original_sale_end datetime DEFAULT NULL,
			matched_price decimal(20,4) DEFAULT NULL,
			matched_regular_price decimal(20,4) DEFAULT NULL,
			matched_sale_price decimal(20,4) DEFAULT NULL,
			matched_at datetime DEFAULT NULL,
			matched_by bigint(20) unsigned DEFAULT NULL,
			restore_strategy varchar(50) NOT NULL DEFAULT 'previous_active_price',
			recovery_strategy varchar(50) NOT NULL DEFAULT 'suggest_only',
			last_competitor_price decimal(20,4) DEFAULT NULL,
			last_lowest_competitor_price decimal(20,4) DEFAULT NULL,
			last_checked_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			ended_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY monitored_product_id (monitored_product_id),
			KEY suggestion_id (suggestion_id),
			KEY status (status),
			KEY matched_at (matched_at),
			KEY last_checked_at (last_checked_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['approval_tokens']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			suggestion_id bigint(20) unsigned NOT NULL,
			action varchar(30) NOT NULL,
			token_hash varchar(255) NOT NULL,
			expires_at datetime NOT NULL,
			used_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			used_ip varchar(100) DEFAULT NULL,
			used_user_agent varchar(255) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY suggestion_id (suggestion_id),
			KEY token_hash (token_hash),
			KEY expires_at (expires_at),
			KEY used_at (used_at),
			KEY action (action)
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
			KEY event_created_at (event, created_at),
			KEY level_created_at (level, created_at),
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
