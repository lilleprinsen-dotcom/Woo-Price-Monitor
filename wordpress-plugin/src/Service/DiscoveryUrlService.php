<?php
/**
 * URL normalization and safety helpers for discovery.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

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

		$host  = strtolower( rtrim( (string) $parts['host'], '.' ) );
		$path  = isset( $parts['path'] ) ? preg_replace( '#/+#', '/', (string) $parts['path'] ) : '/';
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
		if ( '' === $url || preg_match( '#^(mailto|tel|javascript|data):#i', $url ) ) {
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

		return is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( rtrim( (string) $parts['host'], '.' ) ) : '';
	}

	/**
	 * Check if URL is safe to request.
	 *
	 * @param array<int,int|string> $allowed_ports Allowed ports.
	 */
	public function is_safe_url( string $url, array $allowed_ports = array( 80, 443 ) ): bool {
		$url   = $this->normalize( $url );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		$port = isset( $parts['port'] ) ? absint( $parts['port'] ) : ( 'https' === $scheme ? 443 : 80 );
		$allowed_ports = array_map( 'absint', $allowed_ports ?: array( 80, 443 ) );
		if ( ! in_array( $port, $allowed_ports, true ) ) {
			return false;
		}

		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! $this->is_private_or_reserved_ip( $host );
		}

		foreach ( $this->resolve_host_ips( $host ) as $ip ) {
			if ( $this->is_private_or_reserved_ip( $ip ) ) {
				return false;
			}
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
	 * Determine whether a URL should be considered a likely product URL.
	 *
	 * @param array<int,string> $include_patterns Include patterns.
	 * @param array<int,string> $exclude_patterns Exclude patterns.
	 * @param array<int,string> $product_patterns Product patterns.
	 */
	public function looks_like_product_url( string $url, array $include_patterns, array $exclude_patterns, array $product_patterns ): bool {
		$normalized = strtolower( $this->normalize( $url ) );
		if ( '' === $normalized ) {
			return false;
		}

		foreach ( $exclude_patterns as $pattern ) {
			if ( '' !== $pattern && str_contains( $normalized, strtolower( $pattern ) ) ) {
				return false;
			}
		}

		if ( ! empty( $include_patterns ) ) {
			$matched = false;
			foreach ( $include_patterns as $pattern ) {
				if ( '' !== $pattern && str_contains( $normalized, strtolower( $pattern ) ) ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				return false;
			}
		}

		foreach ( $product_patterns as $pattern ) {
			$pattern = strtolower( trim( $pattern ) );
			if ( '' === $pattern ) {
				continue;
			}
			if ( strlen( $pattern ) <= 1 ) {
				if ( preg_match( '#/(?:' . preg_quote( $pattern, '#' ) . ')(?:/|-|_|$)#', $normalized ) ) {
					return true;
				}
				continue;
			}
			if ( str_contains( $normalized, $pattern ) ) {
				return true;
			}
		}

		return preg_match( '#/[a-z0-9\-_]+/[0-9]{4,}#i', $normalized ) || preg_match( '#[?&](product|variant|sku)=#i', $normalized );
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
	 * Resolve host IPs when possible.
	 *
	 * @return array<int,string>
	 */
	private function resolve_host_ips( string $host ): array {
		$ips = array();

		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_A + DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ip'] ) ) {
						$ips[] = (string) $record['ip'];
					}
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = (string) $record['ipv6'];
					}
				}
			}
		}

		if ( empty( $ips ) && function_exists( 'gethostbynamel' ) ) {
			$resolved = @gethostbynamel( $host );
			if ( is_array( $resolved ) ) {
				$ips = array_merge( $ips, $resolved );
			}
		}

		return array_values( array_unique( $ips ) );
	}

	/**
	 * Private/reserved network check.
	 */
	private function is_private_or_reserved_ip( string $ip ): bool {
		return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
}
