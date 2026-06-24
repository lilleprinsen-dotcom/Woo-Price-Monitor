<?php
/**
 * Schema management for competitor discovery tables.
 *
 * @package LillePrinsen\PriceMonitor\Database
 */

namespace LillePrinsen\PriceMonitor\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles dedicated discovery table creation and upgrades.
 */
class DiscoverySchema {
    public const VERSION = '1.0.0';

    private const OPTION_NAME = 'lpm_discovery_schema_version';

    /**
     * Return discovery table names.
     *
     * @return array<string,string>
     */
    public static function table_names(): array {
        global $wpdb;

        return array(
            'discovery_products'              => $wpdb->prefix . 'lpm_discovery_products',
            'discovered_competitor_products'  => $wpdb->prefix . 'lpm_discovered_competitor_products',
            'discovery_match_suggestions'     => $wpdb->prefix . 'lpm_discovery_match_suggestions',
            'discovery_runs'                  => $wpdb->prefix . 'lpm_discovery_runs',
        );
    }

    /**
     * Create or upgrade tables when needed.
     */
    public static function maybe_upgrade(): void {
        $installed = (string) get_option( self::OPTION_NAME, '' );

        if ( self::VERSION === $installed ) {
            return;
        }

        self::create_tables();
        update_option( self::OPTION_NAME, self::VERSION, false );
    }

    /**
     * Create discovery tables.
     */
    public static function create_tables(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $tables          = self::table_names();

        $sql = array();

        $sql[] = "CREATE TABLE {$tables['discovery_products']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            sku VARCHAR(191) NULL,
            gtin VARCHAR(191) NULL,
            mpn VARCHAR(191) NULL,
            brand VARCHAR(191) NULL,
            normalized_sku VARCHAR(191) NULL,
            normalized_gtin VARCHAR(191) NULL,
            normalized_mpn VARCHAR(191) NULL,
            last_discovery_at DATETIME NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'selected',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY product_variation (product_id, variation_id),
            KEY enabled (enabled),
            KEY normalized_sku (normalized_sku),
            KEY normalized_gtin (normalized_gtin),
            KEY normalized_mpn (normalized_mpn),
            KEY status (status),
            KEY last_discovery_at (last_discovery_at)
        ) $charset_collate";

        $sql[] = "CREATE TABLE {$tables['discovered_competitor_products']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            competitor_id BIGINT UNSIGNED NOT NULL,
            url_hash CHAR(64) NOT NULL,
            canonical_url_hash CHAR(64) NULL,
            url TEXT NOT NULL,
            canonical_url TEXT NULL,
            domain VARCHAR(191) NULL,
            title TEXT NULL,
            brand VARCHAR(191) NULL,
            sku VARCHAR(191) NULL,
            gtin VARCHAR(191) NULL,
            mpn VARCHAR(191) NULL,
            normalized_sku VARCHAR(191) NULL,
            normalized_gtin VARCHAR(191) NULL,
            normalized_mpn VARCHAR(191) NULL,
            regular_price DECIMAL(18,4) NULL,
            sale_price DECIMAL(18,4) NULL,
            currency VARCHAR(10) NULL,
            stock_status VARCHAR(30) NULL,
            image_url TEXT NULL,
            raw_metadata LONGTEXT NULL,
            extraction_status VARCHAR(30) NOT NULL DEFAULT 'unknown',
            extraction_source VARCHAR(100) NULL,
            content_hash CHAR(64) NULL,
            last_checked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY competitor_url (competitor_id, url_hash),
            KEY competitor_id (competitor_id),
            KEY canonical_url_hash (canonical_url_hash),
            KEY normalized_sku (normalized_sku),
            KEY normalized_gtin (normalized_gtin),
            KEY normalized_mpn (normalized_mpn),
            KEY extraction_status (extraction_status),
            KEY last_checked_at (last_checked_at)
        ) $charset_collate";

        $sql[] = "CREATE TABLE {$tables['discovery_match_suggestions']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            discovery_product_id BIGINT UNSIGNED NOT NULL,
            discovered_product_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            competitor_id BIGINT UNSIGNED NOT NULL,
            competitor_url TEXT NOT NULL,
            match_type VARCHAR(50) NOT NULL,
            confidence_score DECIMAL(5,2) NOT NULL DEFAULT 0,
            confidence_label VARCHAR(30) NOT NULL,
            explanation TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            fingerprint CHAR(64) NOT NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            rejected_by BIGINT UNSIGNED NULL,
            rejected_at DATETIME NULL,
            competitor_link_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY suggestion_fingerprint (fingerprint),
            KEY discovery_product_id (discovery_product_id),
            KEY discovered_product_id (discovered_product_id),
            KEY product_id (product_id),
            KEY competitor_id (competitor_id),
            KEY status (status),
            KEY confidence_label (confidence_label),
            KEY match_type (match_type)
        ) $charset_collate";

        $sql[] = "CREATE TABLE {$tables['discovery_runs']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            competitor_id BIGINT UNSIGNED NULL,
            source VARCHAR(50) NOT NULL DEFAULT 'manual',
            status VARCHAR(30) NOT NULL DEFAULT 'queued',
            cursor_value TEXT NULL,
            processed_count INT UNSIGNED NOT NULL DEFAULT 0,
            suggestion_count INT UNSIGNED NOT NULL DEFAULT 0,
            failure_count INT UNSIGNED NOT NULL DEFAULT 0,
            limit_count INT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY competitor_id (competitor_id),
            KEY status (status),
            KEY source (source),
            KEY started_at (started_at)
        ) $charset_collate";

        foreach ( $sql as $statement ) {
            dbDelta( $statement );
        }
    }
}
