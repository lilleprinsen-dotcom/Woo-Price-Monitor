<?php
/**
 * URL normalization and safety helpers for discovery.
 *
 * @package LillePrinsen\PriceMonitor\Service
 */

namespace LillePrinsen\PriceMonitor\Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles safe competitor URL normalization.
 */
class DiscoveryUrlService {
    /**
     * Normalize a URL for storage and deduplication.
     */
    public function normalize( string $url ): string {
        $url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $url = esc_url_raw( $url );

        if ( '' === $url ) {
            return '';
        }

        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return '';
        }

        $scheme = strtolower( (string) $parts['scheme'] );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return '';
        }

        $host = strtolower( (string) $parts['host'] );
        $path = isset( $parts['path'] ) ? preg_replace( '#/+#', '/', (string) $parts['path'] ) : '/';
        $query = $this->clean_query( $parts['query'] ?? '' );

        $normalized = $scheme . '://' . $host;
        if ( ! empty( $parts['port'] ) ) {
            $normalized .= ':' . absint( $parts['port'] );
        }
        $normalized .= $path ?: '/';
        if ( '' !== $query ) {
            $normalized .= '?' . $query;
        }

        return $normalized;
    }

    /**
     * Resolve a URL relative to a base URL.
     */
    public function resolve( string $url, string $base_url ): string {
        $url = trim( $url );
        if ( '' === $url ) {
            return '';
        }

        if ( preg_match( '#^https?://#i', $url ) ) {
            return $this->normalize( $url );
        }

        $base = wp_parse_url( $base_url );
        if ( ! is_array( $base ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
            return '';
        }

        if ( str_starts_with( $url, '//' ) ) {
            return $this->normalize( $base['scheme'] . ':' . $url );
        }

        if ( str_starts_with( $url, '/' ) ) {
            return $this->normalize( $base['scheme'] . '://' . $base['host'] . $url );
        }

        $base_path = isset( $base['path'] ) ? dirname( (string) $base['path'] ) : '';

        return $this->normalize( $base['scheme'] . '://' . $base['host'] . '/' . trim( $base_path . '/' . $url, '/' ) );
    }

    /**
     * Hash a normalized URL.
     */
    public function hash_url( string $url ): string {
        return hash( 'sha256', $this->normalize( $url ) );
    }

    /**
     * Get domain from URL.
     */
    public function get_domain( string $url ): string {
        $parts = wp_parse_url( $url );

        return is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
    }

    /**
     * Check if URL is safe to request.
     */
    public function is_safe_url( string $url ): bool {
        $url   = $this->normalize( $url );
        $parts = wp_parse_url( $url );

        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }

        if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
            return false;
        }

        $host = strtolower( (string) $parts['host'] );
        if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) || str_ends_with( $host, '.local' ) ) {
            return false;
        }

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return ! $this->is_private_ip( $host );
        }

        return true;
    }

    /**
     * Check if URL belongs to a configured competitor domain.
     */
    public function matches_domain( string $url, string $domain ): bool {
        $host   = $this->get_domain( $url );
        $domain = strtolower( preg_replace( '#^https?://#', '', trim( $domain ) ) );
        $domain = preg_replace( '#/.*$#', '', $domain );
        $domain = preg_replace( '#^www\.#', '', (string) $domain );
        $host   = preg_replace( '#^www\.#', '', $host );

        return '' !== $domain && ( $host === $domain || str_ends_with( $host, '.' . $domain ) );
    }

    /**
     * Remove common tracking parameters.
     */
    private function clean_query( string $query ): string {
        if ( '' === $query ) {
            return '';
        }

        parse_str( $query, $params );
        $clean = array();

        foreach ( $params as $key => $value ) {
            $lower = strtolower( (string) $key );
            if ( str_starts_with( $lower, 'utm_' ) || in_array( $lower, array( 'gclid', 'fbclid', 'msclkid', 'yclid', 'mc_cid', 'mc_eid' ), true ) ) {
                continue;
            }
            $clean[ $key ] = $value;
        }

        return http_build_query( $clean, '', '&', PHP_QUERY_RFC3986 );
    }

    /**
     * Private network check for literal IPs.
     */
    private function is_private_ip( string $ip ): bool {
        return false === filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
