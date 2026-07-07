<?php
/**
 * Conservative seed/listing/sitemap URL discovery.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts candidate product URLs from explicitly configured competitor seed URLs.
 */
class DiscoverySourceService {
	private DiscoveryUrlService $url_service;
	private DiscoverySettings $settings;

	/**
	 * Constructor.
	 */
	public function __construct( DiscoveryUrlService $url_service, DiscoverySettings $settings ) {
		$this->url_service = $url_service;
		$this->settings    = $settings;
	}

	/**
	 * Fetch one seed URL and extract candidate product URLs.
	 *
	 * @param object              $seed Seed row.
	 * @param array<string,mixed> $competitor Competitor profile.
	 * @return array{success:bool,urls:array<int,string>,message:string,technical_details:string,request_count:int}
	 */
	public function discover_from_seed( object $seed, array $competitor ): array {
		$url      = $this->url_service->normalize( (string) $seed->url );
		$settings = $this->settings->get_all();
		$ports    = array_map( 'absint', $this->settings->get_list( 'discovery_allow_ports' ) );

		if ( '' === $url || ! $this->url_service->is_safe_url( $url, $ports ) ) {
			return $this->failure( 'This source page is not safe to request.', 'Unsafe seed URL.' );
		}

		$domain = (string) ( $competitor['domain'] ?? '' );
		if ( ! empty( $settings['discovery_same_domain_only'] ) && '' !== $domain && ! $this->url_service->matches_domain( $url, $domain ) ) {
			return $this->failure( 'This source page is outside the competitor website.', 'Seed URL failed same-domain check.' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => absint( $settings['discovery_request_timeout'] ?? 12 ),
				'redirection' => 0,
				'user-agent'  => $this->user_agent(),
				'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml,application/xml,text/xml' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->failure( 'We could not read this source page.', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 && $code < 400 ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			$next     = is_array( $location ) ? reset( $location ) : $location;
			$next_url = $this->url_service->resolve( (string) $next, $url );
			if ( '' === $next_url || ! $this->url_service->is_safe_url( $next_url, $ports ) || ( ! empty( $settings['discovery_same_domain_only'] ) && '' !== $domain && ! $this->url_service->matches_domain( $next_url, $domain ) ) ) {
				return $this->failure( 'The source page redirected somewhere unsafe.', 'Unsafe redirect target.' );
			}

			$response = wp_remote_get(
				$next_url,
				array(
					'timeout'     => absint( $settings['discovery_request_timeout'] ?? 12 ),
					'redirection' => 0,
					'user-agent'  => $this->user_agent(),
				)
			);
			$url = $next_url;
		}

		if ( is_wp_error( $response ) ) {
			return $this->failure( 'We could not read this source page.', $response->get_error_message(), 2 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return $this->failure( 'We could not read this source page.', 'HTTP status ' . $code );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			return $this->failure( 'This source page was empty.', 'Empty response.' );
		}

		$source_type = sanitize_key( (string) $seed->source_type );
		if ( 'product' === $source_type ) {
			return array(
				'success'           => true,
				'urls'              => array( $url ),
				'message'           => 'Found one product page.',
				'technical_details' => '',
				'request_count'     => 1,
			);
		}

		$urls = 'sitemap' === $source_type ? $this->extract_sitemap_urls( $body ) : $this->extract_listing_urls( $body, $url );
		$urls = $this->filter_candidate_urls( $urls, $seed, $competitor );

		return array(
			'success'           => true,
			'urls'              => $urls,
			'message'           => sprintf( 'Found %d possible product pages.', count( $urls ) ),
			'technical_details' => '',
			'request_count'     => 1,
		);
	}

	/**
	 * Extract URLs from listing anchors.
	 *
	 * @return array<int,string>
	 */
	public function extract_listing_urls( string $html, string $base_url ): array {
		$urls = array();
		if ( preg_match_all( '#<a\s+[^>]*href\s*=\s*(["\'])(.*?)\1#is', $html, $matches ) ) {
			foreach ( $matches[2] as $href ) {
				$url = $this->url_service->resolve( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $base_url );
				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Extract URLs from sitemap XML.
	 *
	 * @return array<int,string>
	 */
	public function extract_sitemap_urls( string $xml ): array {
		$urls = array();
		if ( preg_match_all( '#<loc>\s*(.*?)\s*</loc>#is', $xml, $matches ) ) {
			foreach ( $matches[1] as $loc ) {
				$url = $this->url_service->normalize( html_entity_decode( trim( $loc ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Filter candidate URLs by domain and patterns.
	 *
	 * @param array<int,string>   $urls Raw URLs.
	 * @param array<string,mixed> $competitor Competitor profile.
	 * @return array<int,string>
	 */
	public function filter_candidate_urls( array $urls, object $seed, array $competitor ): array {
		$settings = $this->settings->get_all();
		$domain   = (string) ( $competitor['domain'] ?? '' );
		$include  = $this->list_from_seed_or_setting( $seed->include_patterns ?? '', 'discovery_include_url_patterns' );
		$exclude  = $this->list_from_seed_or_setting( $seed->exclude_patterns ?? '', 'discovery_exclude_url_patterns' );
		$product  = $this->list_from_seed_or_setting( $seed->product_url_patterns ?? '', 'discovery_product_url_patterns' );
		$out      = array();
		$ports    = array_map( 'absint', $this->settings->get_list( 'discovery_allow_ports' ) );

		foreach ( $urls as $url ) {
			$url = $this->url_service->normalize( $url );
			if ( '' === $url || isset( $out[ $url ] ) ) {
				continue;
			}
			if ( ! $this->url_service->is_safe_url( $url, $ports ) ) {
				continue;
			}
			if ( ! empty( $settings['discovery_same_domain_only'] ) && '' !== $domain && ! $this->url_service->matches_domain( $url, $domain ) ) {
				continue;
			}
			if ( ! $this->url_service->looks_like_product_url( $url, $include, $exclude, $product ) ) {
				continue;
			}
			$out[ $url ] = $url;
		}

		return array_values( $out );
	}

	/**
	 * Parse pattern list from seed override or setting.
	 *
	 * @return array<int,string>
	 */
	private function list_from_seed_or_setting( string $seed_value, string $setting_key ): array {
		if ( '' !== trim( $seed_value ) ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', $seed_value ) ) ) );
		}

		return $this->settings->get_list( $setting_key );
	}

	/**
	 * Failure response.
	 *
	 * @return array{success:bool,urls:array<int,string>,message:string,technical_details:string,request_count:int}
	 */
	private function failure( string $message, string $technical, int $request_count = 1 ): array {
		return array(
			'success'           => false,
			'urls'              => array(),
			'message'           => $message,
			'technical_details' => $technical,
			'request_count'     => $request_count,
		);
	}

	/**
	 * Request User-Agent.
	 */
	private function user_agent(): string {
		$version = defined( 'LPM_VERSION' ) ? LPM_VERSION : 'unknown';
		$site    = wp_parse_url( home_url(), PHP_URL_HOST );

		return 'Lilleprinsen Price Monitor/' . $version . ' Competitor Price Assistant; ' . $site;
	}
}
