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

	private const MAX_QUEUE_URLS = 250;

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
	 * Crawl a small same-domain set of competitor pages and look for selected product SKUs.
	 *
	 * @param array<string,mixed> $competitor Competitor profile.
	 * @param array<int,object>   $products Selected discovery products.
	 * @param array<int,mixed>    $seed_urls Seed URL rows or raw URLs.
	 * @return array{success:bool,urls:array<int,string>,message:string,technical_details:string,request_count:int,matched_products:array<int,int>}
	 */
	public function crawl_for_selected_skus( array $competitor, array $products, array $seed_urls, int $request_budget ): array {
		$domain = $this->competitor_domain( $competitor );
		if ( '' === $domain ) {
			return array(
				'success'           => false,
				'urls'              => array(),
				'message'           => 'Add a competitor website before scanning for SKUs.',
				'technical_details' => 'Competitor domain is empty.',
				'request_count'     => 0,
				'matched_products'  => array(),
			);
		}

		$needles = $this->product_sku_needles( $products );
		if ( empty( $needles ) ) {
			return array(
				'success'           => true,
				'urls'              => array(),
				'message'           => 'No selected products have SKUs to crawl for.',
				'technical_details' => '',
				'request_count'     => 0,
				'matched_products'  => array(),
			);
		}

		$settings      = $this->settings->get_all();
		$ports         = array_map( 'absint', $this->settings->get_list( 'discovery_allow_ports' ) );
		$max_pages     = max( 1, min( absint( $settings['discovery_max_crawl_pages_per_run'] ?? 8 ), max( 1, $request_budget ) ) );
		$queue         = array();
		$queued        = array();
		$visited       = array();
		$urls          = array();
		$matched       = array();
		$errors        = array();
		$requests      = 0;

		foreach ( $this->crawl_start_urls( $competitor, $seed_urls ) as $start_url ) {
			$this->enqueue_crawl_url( $queue, $queued, $start_url, 0, $domain, $ports );
		}

		while ( ! empty( $queue ) && $requests < $max_pages ) {
			$item = array_shift( $queue );
			if ( ! is_array( $item ) ) {
				continue;
			}
			$page_url = (string) ( $item['url'] ?? '' );
			$depth    = absint( $item['depth'] ?? 0 );
			if ( '' === $page_url || isset( $visited[ $page_url ] ) ) {
				continue;
			}
			$visited[ $page_url ] = true;

			$response = wp_remote_get(
				$page_url,
				array(
					'timeout'     => absint( $settings['discovery_request_timeout'] ?? 12 ),
					'redirection' => 0,
					'user-agent'  => $this->user_agent(),
					'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml,application/xml,text/xml' ),
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
				$next_url = $this->url_service->resolve( (string) $next, $page_url );
				$this->enqueue_crawl_url( $queue, $queued, $next_url, $depth, $domain, $ports );
				continue;
			}

			if ( $code < 200 || $code >= 400 ) {
				$errors[] = 'HTTP status ' . $code . ' for ' . $page_url;
				continue;
			}

			$body = (string) wp_remote_retrieve_body( $response );
			if ( '' === trim( $body ) ) {
				continue;
			}

			$page_matches = $this->matched_product_ids_in_html( $body, $needles );
			foreach ( $page_matches as $product_id ) {
				$matched[ $product_id ] = $product_id;
			}
			if ( ! empty( $page_matches ) && $this->looks_like_product_or_identifier_page( $page_url, $body ) ) {
				$urls[] = $page_url;
			}

			foreach ( $this->sku_matched_urls_from_html( $body, $page_url, $needles, $domain ) as $matched_url ) {
				$urls[] = $matched_url;
			}

			if ( $depth < 1 ) {
				foreach ( $this->crawlable_urls_from_html( $body, $page_url, $domain ) as $next_url ) {
					if ( count( $queue ) >= self::MAX_QUEUE_URLS ) {
						break;
					}
					$this->enqueue_crawl_url( $queue, $queued, $next_url, $depth + 1, $domain, $ports );
				}
			}
		}

		$urls = array_values( array_unique( array_filter( array_map( array( $this->url_service, 'normalize' ), $urls ) ) ) );

		return array(
			'success'           => ! empty( $urls ) || empty( $errors ),
			'urls'              => $urls,
			'message'           => sprintf( 'Crawled %1$d competitor pages and found %2$d possible product pages.', $requests, count( $urls ) ),
			'technical_details' => implode( "\n", array_unique( $errors ) ),
			'request_count'     => $requests,
			'matched_products'  => array_values( $matched ),
		);
	}

	/**
	 * Extract product-looking URLs whose link text or URL mentions a selected SKU.
	 *
	 * @param array<int,array<string,mixed>> $needles Selected SKU needles.
	 * @return array<int,string>
	 */
	public function sku_matched_urls_from_html( string $html, string $base_url, array $needles, string $domain ): array {
		$urls = array();
		if ( preg_match_all( '#<a\s+[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$href = html_entity_decode( (string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$text = html_entity_decode( wp_strip_all_tags( (string) $match[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$url  = $this->url_service->resolve( $href, $base_url );
				if ( '' === $url || ! $this->url_service->matches_domain( $url, $domain ) ) {
					continue;
				}
				if ( ! $this->text_mentions_any_sku( $href . ' ' . $text, $needles ) ) {
					continue;
				}
				if ( ! $this->is_crawlable_url( $url ) ) {
					continue;
				}
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
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

	/**
	 * Start URLs for the bounded crawl.
	 *
	 * @param array<string,mixed> $competitor Competitor profile.
	 * @param array<int,mixed>    $seed_urls Seed rows or raw URLs.
	 * @return array<int,string>
	 */
	private function crawl_start_urls( array $competitor, array $seed_urls ): array {
		$domain = $this->competitor_domain( $competitor );
		$urls   = array();
		if ( '' !== $domain ) {
			$urls[] = 'https://' . $domain . '/';
		}
		foreach ( $seed_urls as $seed ) {
			$url = is_object( $seed ) ? (string) ( $seed->url ?? '' ) : (string) $seed;
			if ( '' !== trim( $url ) ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( array_filter( array_map( array( $this->url_service, 'normalize' ), $urls ) ) ) );
	}

	/**
	 * Queue one crawl URL if safe.
	 *
	 * @param array<int,array{url:string,depth:int}> $queue Queue by reference.
	 * @param array<string,bool>                     $queued Queued map by reference.
	 * @param array<int,int|string>                  $ports Allowed ports.
	 */
	private function enqueue_crawl_url( array &$queue, array &$queued, string $url, int $depth, string $domain, array $ports ): void {
		$url = $this->url_service->normalize( $url );
		if ( '' === $url || isset( $queued[ $url ] ) ) {
			return;
		}
		if ( ! $this->url_service->is_safe_url( $url, $ports ) || ! $this->url_service->matches_domain( $url, $domain ) ) {
			return;
		}
		if ( ! $this->is_crawlable_url( $url ) ) {
			return;
		}
		$queued[ $url ] = true;
		$queue[] = array(
			'url'   => $url,
			'depth' => $depth,
		);
	}

	/**
	 * Product SKU needles keyed by selected discovery product.
	 *
	 * @param array<int,object> $products Selected products.
	 * @return array<int,array{id:int,raw:string,normalized:string}>
	 */
	private function product_sku_needles( array $products ): array {
		$needles = array();
		foreach ( $products as $product ) {
			$raw = trim( (string) ( $product->sku ?? '' ) );
			$normalized = $this->normalize_identifier( (string) ( $product->normalized_sku ?? $raw ) );
			if ( '' === $raw && '' === $normalized ) {
				continue;
			}
			$needles[] = array(
				'id'         => (int) ( $product->id ?? 0 ),
				'raw'        => strtolower( $raw ),
				'normalized' => strtolower( $normalized ),
			);
		}

		return $needles;
	}

	/**
	 * Selected products mentioned in HTML.
	 *
	 * @param array<int,array<string,mixed>> $needles Selected SKU needles.
	 * @return array<int,int>
	 */
	private function matched_product_ids_in_html( string $html, array $needles ): array {
		$matched = array();
		foreach ( $needles as $needle ) {
			if ( $this->text_mentions_sku( $html, (string) ( $needle['raw'] ?? '' ), (string) ( $needle['normalized'] ?? '' ) ) ) {
				$id = absint( $needle['id'] ?? 0 );
				if ( $id > 0 ) {
					$matched[] = $id;
				}
			}
		}

		return array_values( array_unique( $matched ) );
	}

	/**
	 * Extract same-domain crawl candidates.
	 *
	 * @return array<int,string>
	 */
	private function crawlable_urls_from_html( string $html, string $base_url, string $domain ): array {
		$urls = array();
		foreach ( $this->source_service->extract_listing_urls( $html, $base_url ) as $url ) {
			if ( $this->url_service->matches_domain( $url, $domain ) && $this->is_crawlable_url( $url ) ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * A conservative crawl URL filter.
	 */
	private function is_crawlable_url( string $url ): bool {
		$normalized = strtolower( $this->url_service->normalize( $url ) );
		if ( '' === $normalized ) {
			return false;
		}
		foreach ( $this->settings->get_list( 'discovery_exclude_url_patterns' ) as $pattern ) {
			if ( '' !== $pattern && str_contains( $normalized, strtolower( $pattern ) ) ) {
				return false;
			}
		}
		if ( preg_match( '#\.(?:jpg|jpeg|png|gif|webp|svg|pdf|zip|css|js|woff2?|ttf|mp4|mov)(?:\?|$)#i', $normalized ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Decide if a SKU page/link should be queued as a possible product page.
	 */
	private function looks_like_product_or_identifier_page( string $url, string $content ): bool {
		if ( $this->url_service->looks_like_product_url( $url, array(), $this->settings->get_list( 'discovery_exclude_url_patterns' ), $this->settings->get_list( 'discovery_product_url_patterns' ) ) ) {
			return true;
		}

		$text = strtolower( $content );
		$has_identifier_label = str_contains( $text, 'sku' ) || str_contains( $text, 'varenummer' ) || str_contains( $text, 'ean' ) || str_contains( $text, 'gtin' );
		$has_price_signal     = str_contains( $text, 'product:price' ) || str_contains( $text, '"price"' ) || str_contains( $text, ' pris' ) || str_contains( $text, ' kr' );

		return $has_identifier_label && $has_price_signal;
	}

	/**
	 * Check text against all selected SKU needles.
	 *
	 * @param array<int,array<string,mixed>> $needles Selected SKU needles.
	 */
	private function text_mentions_any_sku( string $text, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( $this->text_mentions_sku( $text, (string) ( $needle['raw'] ?? '' ), (string) ( $needle['normalized'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check text for raw or normalized SKU.
	 */
	private function text_mentions_sku( string $text, string $raw, string $normalized ): bool {
		$plain = strtolower( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' !== $raw && false !== strpos( $plain, strtolower( $raw ) ) ) {
			return true;
		}

		$normalized_plain = $this->normalize_identifier( $plain );
		$normalized_sku   = '' !== $normalized ? $normalized : $this->normalize_identifier( $raw );

		return '' !== $normalized_sku && false !== strpos( strtolower( $normalized_plain ), strtolower( $normalized_sku ) );
	}

	/**
	 * Normalize SKU-like identifiers.
	 */
	private function normalize_identifier( string $value ): string {
		return (string) preg_replace( '/[^a-z0-9]+/i', '', $value );
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
