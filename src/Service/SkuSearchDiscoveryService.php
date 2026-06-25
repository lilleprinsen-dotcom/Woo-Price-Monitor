<?php
/**
 * SKU-focused competitor search discovery.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and fetches safe competitor search URLs for selected product SKUs.
 */
class SkuSearchDiscoveryService {
	private DiscoveryUrlService $url_service;
	private DiscoverySourceService $source_service;
	private DiscoverySettings $settings;

	/** Constructor. */
	public function __construct( DiscoveryUrlService $url_service, DiscoverySourceService $source_service, DiscoverySettings $settings ) {
		$this->url_service    = $url_service;
		$this->source_service = $source_service;
		$this->settings       = $settings;
	}

	/**
	 * Search one competitor website for one selected product SKU.
	 *
	 * @param array<string,mixed> $competitor Competitor profile.
	 * @param object              $product Selected discovery product row.
	 * @return array{success:bool,urls:array<int,string>,message:string,technical_details:string,request_count:int,sku:string,discovery_product_id:int}
	 */
	public function discover_for_product( array $competitor, object $product ): array {
		$sku = trim( (string) ( $product->sku ?? '' ) );
		if ( '' === $sku ) {
			return $this->failure( 'This selected product has no SKU to search for.', 'Missing selected product SKU.', 0, $sku, (int) ( $product->id ?? 0 ) );
		}

		$domain = $this->competitor_domain( $competitor );
		if ( '' === $domain ) {
			return $this->failure( 'Add a competitor website before scanning for SKUs.', 'Competitor domain is empty.', 0, $sku, (int) ( $product->id ?? 0 ) );
		}

		$settings      = $this->settings->get_all();
		$request_limit = max( 1, min( 10, absint( $settings['discovery_search_urls_per_sku'] ?? 4 ) ) );
		$templates     = array_slice( $this->search_templates( $competitor ), 0, $request_limit );
		$ports         = array_map( 'absint', $this->settings->get_list( 'discovery_allow_ports' ) );
		$urls          = array();
		$requests      = 0;
		$errors        = array();

		foreach ( $templates as $template ) {
			$search_url = $this->build_search_url( $domain, $template, $sku );
			if ( '' === $search_url || ! $this->url_service->is_safe_url( $search_url, $ports ) || ! $this->url_service->matches_domain( $search_url, $domain ) ) {
				continue;
			}

			$response = wp_remote_get(
				$search_url,
				array(
					'timeout'     => absint( $settings['discovery_request_timeout'] ?? 12 ),
					'redirection' => 0,
					'user-agent'  => $this->user_agent(),
					'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
				)
			);
			++$requests;

			if ( is_wp_error( $response ) ) {
				$errors[] = $response->get_error_message();
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 300 && $code < 400 ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				$next     = is_array( $location ) ? reset( $location ) : $location;
				$next_url = $this->url_service->resolve( (string) $next, $search_url );
				if ( '' !== $next_url && $this->url_service->is_safe_url( $next_url, $ports ) && $this->url_service->matches_domain( $next_url, $domain ) ) {
					$urls[] = $next_url;
				}
				continue;
			}

			if ( $code < 200 || $code >= 400 ) {
				$errors[] = 'HTTP status ' . $code . ' for ' . $search_url;
				continue;
			}

			$body = (string) wp_remote_retrieve_body( $response );
			if ( '' === trim( $body ) ) {
				continue;
			}

			$seed = (object) array(
				'include_patterns'      => '',
				'exclude_patterns'      => '',
				'product_url_patterns'  => '',
			);
			$candidates = $this->source_service->extract_listing_urls( $body, $search_url );
			$candidates = $this->source_service->filter_candidate_urls( $candidates, $seed, array( 'domain' => $domain ) );

			foreach ( $candidates as $candidate ) {
				$urls[] = $candidate;
			}

			if ( $this->page_mentions_sku( $body, $sku ) && $this->url_service->looks_like_product_url( $search_url, array(), $this->settings->get_list( 'discovery_exclude_url_patterns' ), $this->settings->get_list( 'discovery_product_url_patterns' ) ) ) {
				$urls[] = $search_url;
			}
		}

		$urls = array_values( array_unique( array_map( array( $this->url_service, 'normalize' ), $urls ) ) );
		$urls = array_values( array_filter( $urls ) );

		return array(
			'success'              => ! empty( $urls ) || empty( $errors ),
			'urls'                 => $urls,
			'message'              => sprintf( 'Found %1$d possible pages for SKU %2$s.', count( $urls ), $sku ),
			'technical_details'    => implode( "\n", array_unique( $errors ) ),
			'request_count'        => $requests,
			'sku'                  => $sku,
			'discovery_product_id' => (int) ( $product->id ?? 0 ),
		);
	}

	/**
	 * Return search URL templates.
	 *
	 * @param array<string,mixed> $competitor Competitor profile.
	 * @return array<int,string>
	 */
	public function search_templates( array $competitor = array() ): array {
		$templates = array();
		$notes     = json_decode( (string) ( $competitor['notes'] ?? '' ), true );
		if ( is_array( $notes ) && ! empty( $notes['search_url_templates'] ) ) {
			$raw = is_array( $notes['search_url_templates'] ) ? $notes['search_url_templates'] : explode( ',', (string) $notes['search_url_templates'] );
			foreach ( $raw as $template ) {
				$template = trim( sanitize_text_field( (string) $template ) );
				if ( '' !== $template ) {
					$templates[] = $template;
				}
			}
		}

		foreach ( $this->settings->get_list( 'discovery_sku_search_url_templates' ) as $template ) {
			$templates[] = $template;
		}

		return array_values( array_unique( array_filter( $templates ) ) );
	}

	/** Build one absolute search URL. */
	public function build_search_url( string $domain, string $template, string $sku ): string {
		$domain = preg_replace( '#^https?://#i', '', trim( $domain ) );
		$domain = trim( (string) $domain, "/ \t\n\r\0\x0B" );
		if ( '' === $domain ) {
			return '';
		}

		$value = rawurlencode( $sku );
		$url   = str_replace( array( '{sku}', '{query}', '%s' ), $value, $template );
		if ( false === strpos( $url, '://' ) ) {
			$url = 'https://' . $domain . '/' . ltrim( $url, '/' );
		}

		return $this->url_service->normalize( $url );
	}

	/** Get competitor domain from domain or URL-like fields. */
	private function competitor_domain( array $competitor ): string {
		$domain = trim( (string) ( $competitor['domain'] ?? '' ) );
		if ( '' !== $domain ) {
			return preg_replace( '#^https?://#i', '', rtrim( $domain, '/' ) );
		}

		$url = trim( (string) ( $competitor['website'] ?? $competitor['url'] ?? '' ) );
		if ( '' === $url ) {
			return '';
		}

		if ( false === strpos( $url, '://' ) ) {
			$url = 'https://' . $url;
		}

		return (string) wp_parse_url( $url, PHP_URL_HOST );
	}

	/** Check for the raw or normalized SKU in a page. */
	private function page_mentions_sku( string $html, string $sku ): bool {
		$plain = strtolower( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$sku_l = strtolower( $sku );
		if ( '' !== $sku_l && false !== strpos( $plain, $sku_l ) ) {
			return true;
		}

		$normalized_plain = preg_replace( '/[^a-z0-9]+/i', '', $plain );
		$normalized_sku   = preg_replace( '/[^a-z0-9]+/i', '', $sku );

		return '' !== $normalized_sku && false !== strpos( (string) $normalized_plain, (string) strtolower( $normalized_sku ) );
	}

	/** Failure response. */
	private function failure( string $message, string $technical, int $request_count, string $sku, int $discovery_product_id ): array {
		return array(
			'success'              => false,
			'urls'                 => array(),
			'message'              => $message,
			'technical_details'    => $technical,
			'request_count'        => $request_count,
			'sku'                  => $sku,
			'discovery_product_id' => $discovery_product_id,
		);
	}

	/** Request User-Agent. */
	private function user_agent(): string {
		$version = defined( 'LPM_VERSION' ) ? LPM_VERSION : 'unknown';
		$site    = wp_parse_url( home_url(), PHP_URL_HOST );

		return 'Lilleprinsen Price Monitor/' . $version . ' Competitor SKU Scan; ' . $site;
	}
}
