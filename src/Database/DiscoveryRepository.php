<?php
/**
 * Repository for competitor discovery data.
 *
 * @package LillePrinsen\PriceMonitor\Database
 */

namespace LillePrinsen\PriceMonitor\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Data access for selected products, extracted competitor pages and suggestions.
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

        $table = $this->table( 'discovery_products' );
        $now   = current_time( 'mysql' );

        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE product_id = %d AND variation_id = %d LIMIT 1",
                $product_id,
                $variation_id
            )
        );

        $data = array(
            'product_id'       => $product_id,
            'variation_id'     => $variation_id,
            'enabled'          => 1,
            'priority'         => 'normal',
            'sku'              => $identifiers['sku'] ?? '',
            'gtin'             => $identifiers['gtin'] ?? '',
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
     * Get a selected product by ID.
     */
    public function get_discovery_product( int $id ): ?object {
        global $wpdb;

        $table = $this->table( 'discovery_products' );
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

        return $row ?: null;
    }

    /**
     * Get a selected product by WooCommerce product ID.
     */
    public function get_discovery_product_by_product_id( int $product_id, int $variation_id = 0 ): ?object {
        global $wpdb;

        $table = $this->table( 'discovery_products' );
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE product_id = %d AND variation_id = %d LIMIT 1",
                $product_id,
                $variation_id
            )
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

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY updated_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );
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
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE enabled = 1 AND (sku LIKE %s OR gtin LIKE %s OR mpn LIKE %s OR brand LIKE %s)",
                    $like,
                    $like,
                    $like,
                    $like
                )
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

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY priority DESC, updated_at DESC LIMIT %d",
                $limit
            )
        );
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
            'selected'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1" ),
            'with_sku'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1 AND normalized_sku <> ''" ),
            'with_gtin'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1 AND normalized_gtin <> ''" ),
            'with_mpn'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1 AND normalized_mpn <> ''" ),
            'missing_id'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1 AND normalized_sku = '' AND normalized_gtin = '' AND normalized_mpn = ''" ),
            'duplicates'   => $this->count_duplicate_identifiers(),
        );
    }

    /**
     * Store extracted competitor product.
     *
     * @param int                  $competitor_id Competitor ID.
     * @param string               $url URL.
     * @param array<string,mixed>  $extracted Extracted data.
     */
    public function store_discovered_product( int $competitor_id, string $url, array $extracted ): int {
        global $wpdb;

        $table = $this->table( 'discovered_competitor_products' );
        $now   = current_time( 'mysql' );

        $url_hash       = (string) ( $extracted['url_hash'] ?? hash( 'sha256', $url ) );
        $canonical_hash = ! empty( $extracted['canonical_url_hash'] ) ? (string) $extracted['canonical_url_hash'] : null;

        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE competitor_id = %d AND url_hash = %s LIMIT 1",
                $competitor_id,
                $url_hash
            )
        );

        $data = array(
            'competitor_id'       => $competitor_id,
            'url_hash'            => $url_hash,
            'canonical_url_hash'  => $canonical_hash,
            'url'                 => $url,
            'canonical_url'       => (string) ( $extracted['canonical_url'] ?? '' ),
            'domain'              => (string) ( $extracted['domain'] ?? '' ),
            'title'               => (string) ( $extracted['title'] ?? '' ),
            'brand'               => (string) ( $extracted['brand'] ?? '' ),
            'sku'                 => (string) ( $extracted['sku'] ?? '' ),
            'gtin'                => (string) ( $extracted['gtin'] ?? '' ),
            'mpn'                 => (string) ( $extracted['mpn'] ?? '' ),
            'normalized_sku'      => (string) ( $extracted['normalized_sku'] ?? '' ),
            'normalized_gtin'     => (string) ( $extracted['normalized_gtin'] ?? '' ),
            'normalized_mpn'      => (string) ( $extracted['normalized_mpn'] ?? '' ),
            'regular_price'       => isset( $extracted['regular_price'] ) && '' !== $extracted['regular_price'] ? (float) $extracted['regular_price'] : null,
            'sale_price'          => isset( $extracted['sale_price'] ) && '' !== $extracted['sale_price'] ? (float) $extracted['sale_price'] : null,
            'currency'            => (string) ( $extracted['currency'] ?? '' ),
            'stock_status'        => (string) ( $extracted['stock_status'] ?? 'unknown' ),
            'image_url'           => (string) ( $extracted['image_url'] ?? '' ),
            'raw_metadata'        => wp_json_encode( $extracted['raw_metadata'] ?? array() ),
            'extraction_status'   => (string) ( $extracted['extraction_status'] ?? 'unknown' ),
            'extraction_source'   => (string) ( $extracted['extraction_source'] ?? '' ),
            'content_hash'        => (string) ( $extracted['content_hash'] ?? '' ),
            'last_checked_at'     => $now,
            'updated_at'          => $now,
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
     * Get discovered competitor product.
     */
    public function get_discovered_product( int $id ): ?object {
        global $wpdb;

        $table = $this->table( 'discovered_competitor_products' );
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

        return $row ?: null;
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

        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE fingerprint = %s LIMIT 1", $fingerprint )
        );

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

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY confidence_score DESC, created_at DESC LIMIT %d OFFSET %d",
                $status,
                $per_page,
                $offset
            )
        );
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
     * Mark a suggestion approved.
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
            array( 'id' => $id )
        );
    }

    /**
     * Mark a suggestion rejected.
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
            array( 'id' => $id )
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

        $table       = $this->table( 'discovery_match_suggestions' );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows        = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT discovery_product_id, COUNT(*) AS total FROM {$table} WHERE status = 'pending' AND discovery_product_id IN ({$placeholders}) GROUP BY discovery_product_id",
                ...$ids
            )
        );

        $counts = array();
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
    public function finish_run( int $run_id, string $status, int $processed, int $suggestions, int $failures, string $error = '' ): void {
        global $wpdb;

        $wpdb->update(
            $this->table( 'discovery_runs' ),
            array(
                'status'           => sanitize_key( $status ),
                'processed_count'  => $processed,
                'suggestion_count' => $suggestions,
                'failure_count'    => $failures,
                'last_error'       => $error,
                'completed_at'     => current_time( 'mysql' ),
                'updated_at'       => current_time( 'mysql' ),
            ),
            array( 'id' => $run_id )
        );
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
     * Get a discovery table name.
     */
    private function table( string $key ): string {
        $tables = DiscoverySchema::table_names();

        return $tables[ $key ];
    }
}
