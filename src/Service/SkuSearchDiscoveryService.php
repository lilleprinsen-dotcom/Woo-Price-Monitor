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
		$sku     = trim( (string) ( $product->sku ?? '' ) );
		$gtin    = $this->product_gtin_query( $product );
		$queries = $this->identifier_search_queries( $sku, $gtin );
		if ( empty( $queries ) ) {
			return $this->failure( 'This selected product has no SKU or EAN/GTIN to search for.', 'Missing selected product SKU and EAN/GTIN.', 0, $sku, (int) ( $product->id ?? 0 ) );
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
		$source_scores = array();
		$requests      = 0;
		$errors        = array();
		$sku_evidence  = false;
		$searched_urls = array();

		if ( empty( $templates ) ) {
			return $this->failure( 'No search template is configured for this competitor.', 'no search page: add a search URL template containing {sku} or {query}.', 0, $sku, (int) ( $product->id ?? 0 ) );
		}

		foreach ( $queries as $query ) {
			foreach ( $templates as $template ) {
				if ( $requests >= $request_limit * count( $queries ) ) {
					break 2;
				}

				$search_url = $this->build_search_url( $domain, $template, (string) $query['value'] );
				if ( '' === $search_url || ! $this->url_service->is_safe_url( $search_url, $ports ) || ! $this->url_service->matches_domain( $search_url, $domain ) ) {
					continue;
				}
				$searched_urls[] = $search_url;

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
					if ( $this->is_safe_same_domain_url( $next_url, $domain, $ports ) && ! $this->looks_like_search_results_url( $next_url ) && ( $this->text_mentions_sku( $next_url, (string) $query['value'], (string) $query['normalized'] ) || $this->looks_like_redirect_product_candidate( $next_url ) ) ) {
						$this->add_candidate_url_with_source_score( $urls, $source_scores, $next_url, 540 );
						$sku_evidence = true;
						$errors[] = 'Search redirected to a possible product page: ' . $next_url;
					} elseif ( '' !== $next_url ) {
						$errors[] = 'Search redirect did not look like a safe product page: ' . $next_url;
					}
					continue;
				}

				if ( $code < 200 || $code >= 400 ) {
					$errors[] = 'HTTP status ' . $code . ' for ' . $search_url;
					continue;
				}

				$body = (string) wp_remote_retrieve_body( $response );
				if ( '' === trim( $body ) ) {
					$errors[] = 'No product URLs: empty search page for ' . $search_url;
					continue;
				}

				$needles = array(
					array(
						'id'         => (int) ( $product->id ?? 0 ),
						'raw'        => strtolower( (string) $query['value'] ),
						'normalized' => strtolower( (string) $query['normalized'] ),
					),
				);
				$candidates = $this->sku_matched_urls_from_html( $body, $search_url, $needles, $domain );
				$voyado = array();
				if ( empty( $candidates ) ) {
					$voyado = $this->voyado_elevate_product_urls_from_html( $body, (string) $query['value'], $domain, $ports, $settings, $this->raw_product_name( $product ) );
					if ( ! empty( $voyado['searched_url'] ) ) {
						$searched_urls[] = (string) $voyado['searched_url'];
					}
					if ( (int) ( $voyado['request_count'] ?? 0 ) > 0 ) {
						$requests += (int) $voyado['request_count'];
					}
					if ( ! empty( $voyado['message'] ) ) {
						$errors[] = (string) $voyado['message'];
					}
				}
				$identifier_page_candidates = array();
				if ( empty( $candidates ) && empty( $voyado['urls'] ) && empty( $voyado['hard_failure'] ) && $this->text_mentions_sku( $body, (string) $query['value'], (string) $query['normalized'] ) ) {
					$identifier_page_candidates = $this->visible_product_card_urls_from_html( $body, $search_url, $domain );
				}

				foreach ( $candidates as $candidate ) {
					$this->add_candidate_url_with_source_score( $urls, $source_scores, (string) $candidate, 520 );
					$sku_evidence = true;
				}
				$this->add_candidate_urls_with_source_score( $urls, $source_scores, (array) ( $voyado['urls'] ?? array() ), 500 );
				$sku_evidence = $sku_evidence || ! empty( $voyado['urls'] );
				foreach ( $identifier_page_candidates as $candidate ) {
					$this->add_candidate_url_with_source_score( $urls, $source_scores, (string) $candidate, 410 );
					$sku_evidence = true;
				}
				if ( ! empty( $identifier_page_candidates ) ) {
					$errors[] = 'Search results page mentioned SKU/EAN and exposed visible product-card URLs for ' . $search_url;
				}

				if ( empty( $candidates ) && empty( $voyado['urls'] ) && empty( $voyado['hard_failure'] ) && empty( $identifier_page_candidates ) ) {
					$algolia = $this->algolia_product_urls_from_html( $body, (string) $query['value'], $domain, $ports, $settings, $this->raw_product_name( $product ) );
					if ( ! empty( $algolia['searched_url'] ) ) {
						$searched_urls[] = (string) $algolia['searched_url'];
					}
					if ( (int) ( $algolia['request_count'] ?? 0 ) > 0 ) {
						$requests += (int) $algolia['request_count'];
					}
					$this->add_candidate_urls_with_source_score( $urls, $source_scores, (array) ( $algolia['urls'] ?? array() ), 450 );
					$sku_evidence = $sku_evidence || ! empty( $algolia['urls'] );
					if ( ! empty( $algolia['message'] ) ) {
						$errors[] = (string) $algolia['message'];
					}
				}

				if ( $this->text_mentions_sku( $body, (string) $query['value'], (string) $query['normalized'] ) && ! $this->looks_like_search_results_url( $search_url ) && $this->url_service->looks_like_product_url( $search_url, array(), $this->settings->get_list( 'discovery_exclude_url_patterns' ), $this->settings->get_list( 'discovery_product_url_patterns' ) ) ) {
					$this->add_candidate_url_with_source_score( $urls, $source_scores, $search_url, 540 );
					$sku_evidence = true;
				} elseif ( $this->text_mentions_sku( $body, (string) $query['value'], (string) $query['normalized'] ) && empty( $candidates ) && empty( $voyado['urls'] ) && empty( $voyado['hard_failure'] ) && empty( $identifier_page_candidates ) ) {
					$errors[] = 'Search results page mentioned SKU/EAN but did not expose a product URL for ' . $search_url;
				}

				if ( empty( $candidates ) && empty( $voyado['urls'] ) && empty( $voyado['hard_failure'] ) && empty( $identifier_page_candidates ) && ! $this->text_mentions_sku( $body, (string) $query['value'], (string) $query['normalized'] ) ) {
					$errors[] = 'No SKU/EAN on page and no product URLs found for ' . $search_url;
				}
			}
		}

		if ( ! empty( $settings['discovery_name_search_enabled'] ) ) {
			$name_queries = array_values(
				array_filter(
					$this->product_name_queries( $product ),
					static fn( $name_query ) => '' !== $name_query && strtolower( $name_query ) !== strtolower( $sku )
				)
			);
			foreach ( $name_queries as $name_query ) {
				foreach ( $templates as $template ) {
					$search_url = $this->build_search_url( $domain, $template, $name_query );
					if ( '' === $search_url || ! $this->url_service->is_safe_url( $search_url, $ports ) || ! $this->url_service->matches_domain( $search_url, $domain ) ) {
						continue;
					}
					$searched_urls[] = $search_url;

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
						if ( $this->is_safe_same_domain_url( $next_url, $domain, $ports ) && ! $this->looks_like_search_results_url( $next_url ) && ( $this->text_matches_product_name( $next_url, $name_query ) || $this->looks_like_redirect_product_candidate( $next_url ) ) ) {
							$this->add_candidate_url_with_source_score( $urls, $source_scores, $next_url, 360 );
							$errors[] = 'Name search redirected to a possible product page: ' . $next_url;
						} elseif ( '' !== $next_url ) {
							$errors[] = 'Name search redirect did not look like a safe product page: ' . $next_url;
						}
						continue;
					}

					if ( $code < 200 || $code >= 400 ) {
						$errors[] = 'HTTP status ' . $code . ' for ' . $search_url;
						continue;
					}

					$body = (string) wp_remote_retrieve_body( $response );
					if ( '' === trim( $body ) ) {
						$errors[] = 'No product URLs: empty name-search page for ' . $search_url;
						continue;
					}

					$name_candidates = $this->name_matched_urls_from_html( $body, $search_url, $name_query, $domain );
					foreach ( $name_candidates as $candidate ) {
						$this->add_candidate_url_with_source_score( $urls, $source_scores, (string) $candidate, 560 );
					}
					if ( empty( $name_candidates ) ) {
						$voyado = $this->voyado_elevate_product_urls_from_html( $body, $name_query, $domain, $ports, $settings, $this->raw_product_name( $product ) );
						if ( ! empty( $voyado['searched_url'] ) ) {
							$searched_urls[] = (string) $voyado['searched_url'];
						}
						if ( (int) ( $voyado['request_count'] ?? 0 ) > 0 ) {
							$requests += (int) $voyado['request_count'];
						}
						$this->add_candidate_urls_with_source_score( $urls, $source_scores, (array) ( $voyado['urls'] ?? array() ), 420 );
						if ( ! empty( $voyado['message'] ) ) {
							$errors[] = (string) $voyado['message'];
						}
					}
					if ( empty( $name_candidates ) && empty( $voyado['urls'] ) ) {
						$algolia = $this->algolia_product_urls_from_html( $body, $name_query, $domain, $ports, $settings, $this->raw_product_name( $product ) );
						if ( ! empty( $algolia['searched_url'] ) ) {
							$searched_urls[] = (string) $algolia['searched_url'];
						}
						if ( (int) ( $algolia['request_count'] ?? 0 ) > 0 ) {
							$requests += (int) $algolia['request_count'];
						}
						$this->add_candidate_urls_with_source_score( $urls, $source_scores, (array) ( $algolia['urls'] ?? array() ), 380 );
						if ( ! empty( $algolia['message'] ) ) {
							$errors[] = (string) $algolia['message'];
						}
					}

					if ( $this->text_matches_product_name( $body, $name_query ) && ! $this->looks_like_search_results_url( $search_url ) && $this->url_service->looks_like_product_url( $search_url, array(), $this->settings->get_list( 'discovery_exclude_url_patterns' ), $this->settings->get_list( 'discovery_product_url_patterns' ) ) ) {
						$this->add_candidate_url_with_source_score( $urls, $source_scores, $search_url, 360 );
					}
				}
			}
		}

		$urls = $this->rank_candidate_urls( $urls, $product, $sku, $gtin, $source_scores );
		if ( empty( $urls ) && empty( $errors ) ) {
			$errors[] = 'No product URLs found for this selected SKU.';
		}

		return array(
			'success'              => ! empty( $urls ),
			'urls'                 => $urls,
			'message'              => sprintf( 'Found %1$d possible pages for SKU %2$s.', count( $urls ), $sku ),
			'technical_details'    => implode( "\n", array_unique( $errors ) ),
			'request_count'        => $requests,
			'sku'                  => $sku,
			'gtin'                 => $gtin,
			'searched_urls'        => array_values( array_unique( $searched_urls ) ),
			'searched_name'        => $this->product_name_query( $product ),
			'searched_names'       => $this->product_name_queries( $product ),
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
		$max_pages       = max( 1, min( absint( $settings['discovery_max_crawl_pages_per_run'] ?? 8 ), max( 1, $request_budget ) ) );
		$candidate_limit = max( 1, min( 200, absint( $settings['discovery_max_crawl_candidate_urls'] ?? 40 ) ) );
		$queue           = array();
		$queued          = array();
		$visited         = array();
		$urls            = array();
		$matched         = array();
		$errors          = array();
		$requests        = 0;

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

			foreach ( $this->product_candidate_urls_from_html( $body, $page_url, $domain, $depth > 0 ) as $candidate_url ) {
				if ( count( array_unique( $urls ) ) >= $candidate_limit ) {
					break;
				}
				$urls[] = $candidate_url;
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
		foreach ( $this->candidate_links_from_html( $html, $base_url, $domain ) as $candidate ) {
			$url = (string) $candidate['url'];
			if ( $this->looks_like_listing_or_category_url( $url ) || ! $this->is_crawlable_url( $url ) ) {
				continue;
			}
			if ( ! $this->text_mentions_any_sku( (string) $candidate['raw'] . ' ' . (string) $candidate['text'] . ' ' . $url, $needles ) ) {
				continue;
			}
			$urls[] = $url;
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Extract bounded same-domain candidate product URLs from crawled pages.
	 *
	 * A listing page often does not show SKU in the link text. These candidates are
	 * queued for the normal extractor, which then reads the product page and matches
	 * SKU/EAN/MPN before creating suggestions.
	 *
	 * @return array<int,string>
	 */
	public function product_candidate_urls_from_html( string $html, string $base_url, string $domain, bool $broad_listing_page = false ): array {
		$urls = array();
		foreach ( $this->candidate_links_from_html( $html, $base_url, $domain ) as $candidate ) {
			$url = (string) $candidate['url'];
			$text = trim( (string) $candidate['text'] . ' ' . (string) $candidate['context'] );
			if ( ! $this->is_crawlable_url( $url ) ) {
				continue;
			}
			if ( $this->looks_like_listing_or_category_url( $url ) && ! $this->has_product_card_context( $text ) ) {
				continue;
			}
			if ( ! $this->looks_like_product_candidate_link( $url, $text, $broad_listing_page ) ) {
				continue;
			}
			$urls[] = $url;
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Extract URLs from actual visible product cards only.
	 *
	 * Search result pages can mention a SKU in the heading while also containing
	 * navigation and brand links. This strict extractor prevents those page links
	 * from being treated as product cards.
	 *
	 * @return array<int,string>
	 */
	private function visible_product_card_urls_from_html( string $html, string $base_url, string $domain ): array {
		$urls = array();
		if ( ! preg_match_all( '#<(?:article|li|div)\b([^>]*(?:product-item|js-product-item|product-card|data-product-impression)[^>]*)>(.*?)</(?:article|li|div)>#is', $html, $cards, PREG_SET_ORDER ) ) {
			return array();
		}

		foreach ( $cards as $card ) {
			$card_html = (string) $card[0];
			if ( ! $this->has_product_card_context( $card_html ) ) {
				continue;
			}

			foreach ( $this->candidate_links_from_html( $card_html, $base_url, $domain ) as $candidate ) {
				$url = (string) $candidate['url'];
				$text = trim( (string) $candidate['text'] . ' ' . (string) $candidate['context'] . ' ' . (string) $candidate['raw'] );
				if ( ! $this->is_crawlable_url( $url ) ) {
					continue;
				}
				if ( ! $this->looks_like_product_candidate_link( $url, $text, true ) ) {
					continue;
				}
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Extract product-looking URLs whose link text or URL matches the selected product name.
	 *
	 * These are only candidate pages. The normal extractor and matcher must still
	 * find SKU/EAN/MPN or other match evidence before a suggestion is created.
	 *
	 * @return array<int,string>
	 */
	public function name_matched_urls_from_html( string $html, string $base_url, string $product_name, string $domain ): array {
		$candidates = array();
		if ( '' === trim( $product_name ) ) {
			return array();
		}

		foreach ( $this->candidate_links_from_html( $html, $base_url, $domain ) as $candidate ) {
			$url = (string) $candidate['url'];
			$text = (string) $candidate['text'] . ' ' . (string) $candidate['context'];
			if ( ! $this->is_crawlable_url( $url ) || ! $this->looks_like_product_candidate_link( $url, $text, true ) ) {
				continue;
			}
			if ( $this->candidate_name_term_hits( (string) $candidate['raw'] . ' ' . (string) $candidate['text'] . ' ' . $url, $product_name ) < 2 ) {
				continue;
			}
			$direct_score = $this->candidate_match_score( $url, (string) $candidate['raw'] . ' ' . (string) $candidate['text'], $product_name );
			if ( $direct_score <= 0 ) {
				continue;
			}
			$score = max( $direct_score, $this->candidate_match_score( $url, (string) $candidate['raw'] . ' ' . (string) $candidate['text'] . ' ' . (string) $candidate['context'], $product_name ) );
			if ( $score < $this->minimum_name_candidate_score( $product_name ) ) {
				continue;
			}
			$this->add_scored_url( $candidates, $url, $score );
		}

		return $this->rank_scored_urls( $candidates );
	}

	/**
	 * Extract same-domain URL candidates from anchors, forms, buttons and common
	 * JS/data attributes used by product cards.
	 *
	 * @return array<int,array{url:string,text:string,context:string,raw:string}>
	 */
	private function candidate_links_from_html( string $html, string $base_url, string $domain ): array {
		$candidates = array();
		if ( preg_match_all( '#<a\b([^>]*)>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$tag = (string) $match[0][0];
				$attrs = $this->html_attributes_from_tag( $tag );
				$href = (string) ( $attrs['href'] ?? '' );
				$text = html_entity_decode( wp_strip_all_tags( (string) $match[2][0] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$context = $this->anchor_context_text( $html, (int) $match[0][1], strlen( $tag ) );
				$this->add_candidate_link( $candidates, $this->url_service->resolve( $href, $base_url ), $text, $context, $href, $domain );
			}
		}

		if ( preg_match_all( '#<([a-z][a-z0-9:-]*)\b([^>]*)>#is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$tag = strtolower( (string) $match[1][0] );
				if ( in_array( $tag, array( 'img', 'script', 'link', 'meta', 'source' ), true ) ) {
					continue;
				}
				$tag_html = (string) $match[0][0];
				$attrs = $this->html_attributes_from_tag( $tag_html );
				$context = $this->anchor_context_text( $html, (int) $match[0][1], strlen( $tag_html ) );
				foreach ( $attrs as $name => $value ) {
					if ( ! $this->is_url_bearing_attribute( $name ) ) {
						continue;
					}
					foreach ( $this->urls_from_attribute_value( $value, $base_url ) as $url ) {
						$this->add_candidate_link( $candidates, $url, '', $context, $value, $domain );
					}
				}
			}
		}

		return array_values( $candidates );
	}

	/**
	 * @return array<string,string>
	 */
	private function html_attributes_from_tag( string $tag ): array {
		$attrs = array();
		if ( preg_match_all( '#([a-zA-Z0-9_:\-]+)\s*=\s*(["\'])(.*?)\2#is', $tag, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$attrs[ strtolower( (string) $match[1] ) ] = html_entity_decode( (string) $match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		return $attrs;
	}

	private function is_url_bearing_attribute( string $name ): bool {
		$name = strtolower( $name );
		if ( in_array( $name, array( 'src', 'srcset', 'poster', 'style', 'class', 'id' ), true ) ) {
			return false;
		}
		if ( in_array( $name, array( 'href', 'action', 'formaction', 'onclick' ), true ) ) {
			return true;
		}

		return str_contains( $name, 'url' ) || str_contains( $name, 'href' ) || in_array( $name, array( 'data-post', 'data-mage-init', 'data-click' ), true );
	}

	/**
	 * @return array<int,string>
	 */
	private function urls_from_attribute_value( string $value, string $base_url ): array {
		$urls = array();
		$value = html_entity_decode( str_replace( '\/', '/', $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( preg_match_all( '#https?://[^\s"\'<>\\\\)]+#i', $value, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$urls[] = $this->url_service->normalize( (string) $url );
			}
		}
		if ( preg_match_all( '#(?:"|\')((?:/|\\./|\.\./)[^"\'<>\\\\)]+)(?:"|\')#', $value, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$urls[] = $this->url_service->resolve( (string) $url, $base_url );
			}
		}
		if ( preg_match( '#^(?:https?://|/|\\./|\.\./)#i', trim( $value ) ) ) {
			$urls[] = $this->url_service->resolve( trim( $value ), $base_url );
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/**
	 * @param array<string,array{url:string,text:string,context:string,raw:string}> $candidates Candidate map.
	 */
	private function add_candidate_link( array &$candidates, string $url, string $text, string $context, string $raw, string $domain ): void {
		$url = $this->url_service->normalize( $url );
		if ( '' === $url || ! $this->url_service->matches_domain( $url, $domain ) ) {
			return;
		}
		if ( isset( $candidates[ $url ] ) ) {
			$candidates[ $url ]['text'] .= ' ' . $text;
			$candidates[ $url ]['context'] .= ' ' . $context;
			$candidates[ $url ]['raw'] .= ' ' . $raw;
			return;
		}

		$candidates[ $url ] = array(
			'url'     => $url,
			'text'    => $text,
			'context' => $context,
			'raw'     => $raw,
		);
	}

	/**
	 * Rank and filter candidate product URLs before the expensive product-page
	 * extraction step. Search pages often contain image links, category links and
	 * fuzzy hosted-search hits, so title/name evidence should decide the order.
	 *
	 * @param array<int,string> $urls Candidate URLs.
	 * @return array<int,string>
	 */
	private function rank_candidate_urls( array $urls, object $product, string $sku, string $gtin, array $source_scores = array() ): array {
		$candidates = array();
		$product_name = $this->raw_product_name( $product );

		foreach ( $urls as $url ) {
			$url = $this->url_service->normalize( (string) $url );
			if ( '' === $url || $this->looks_like_search_results_url( $url ) || $this->looks_like_listing_or_category_url( $url ) ) {
				continue;
			}

			$score = 10 + $this->candidate_match_score( $url, $url, $product_name );
			if ( $this->text_mentions_sku( $url, $sku, $this->normalize_identifier( $sku ) ) ) {
				$score += 300;
			}
			if ( $this->text_mentions_sku( $url, $gtin, $this->normalize_identifier( $gtin ) ) ) {
				$score += 350;
			}
			if ( isset( $source_scores[ $url ] ) ) {
				$score += (int) $source_scores[ $url ];
			}
			$this->add_scored_url( $candidates, $url, $score );
		}

		return $this->rank_scored_urls( $candidates );
	}

	/**
	 * @param array<int,string>         $urls Candidate URL list.
	 * @param array<string,int>         $source_scores URL source priority map.
	 * @param array<int,mixed>          $candidates Ranked candidate URLs from a search source.
	 */
	private function add_candidate_urls_with_source_score( array &$urls, array &$source_scores, array $candidates, int $base_score ): void {
		foreach ( array_values( $candidates ) as $index => $candidate ) {
			$this->add_candidate_url_with_source_score( $urls, $source_scores, (string) $candidate, max( 1, $base_score - ( $index * 80 ) ) );
		}
	}

	/**
	 * @param array<int,string> $urls Candidate URL list.
	 * @param array<string,int> $source_scores URL source priority map.
	 */
	private function add_candidate_url_with_source_score( array &$urls, array &$source_scores, string $url, int $score ): void {
		$url = $this->url_service->normalize( $url );
		if ( '' === $url ) {
			return;
		}

		$urls[] = $url;
		$source_scores[ $url ] = max( (int) ( $source_scores[ $url ] ?? 0 ), $score );
	}

	/**
	 * Nearby card text for an anchor. Many product grids put the title or price in
	 * sibling elements instead of inside the clicked image/title anchor itself.
	 */
	private function anchor_context_text( string $html, int $offset, int $anchor_length ): string {
		$start = max( 0, $offset - 900 );
		$length = $anchor_length + 1800;
		$snippet = substr( $html, $start, $length );

		return html_entity_decode( wp_strip_all_tags( (string) $snippet ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private function minimum_name_candidate_score( string $product_name ): int {
		$term_count = count( $this->significant_name_terms( $product_name ) );

		return $term_count >= 4 ? 42 : 28;
	}

	private function candidate_match_score( string $url, string $context, string $product_name ): int {
		if ( '' === trim( $product_name ) || ( $this->looks_like_listing_or_category_url( $url ) && ! $this->has_product_card_context( $context ) ) ) {
			return 0;
		}

		$score = 0;
		$url_text = $this->canonical_search_text( $url );
		$context_text = $this->canonical_search_text( $context );
		foreach ( $this->significant_name_terms( $product_name ) as $term ) {
			if ( false !== strpos( $context_text, $term ) ) {
				$score += 14;
			}
			if ( false !== strpos( $url_text, $term ) ) {
				$score += 8;
			}
		}

		if ( $this->text_matches_product_name( $context . ' ' . $url, $product_name ) ) {
			$score += 20;
		}
		if ( preg_match( '/(?:^|\s)kr\s*\d|(?:^|\s)\d[\d\s.,]*,-|(?:^|\s)\d[\d\s.,]*\s*nok/i', $context ) ) {
			$score += 12;
		}
		if ( $this->url_service->looks_like_product_url( $url, array(), $this->settings->get_list( 'discovery_exclude_url_patterns' ), $this->settings->get_list( 'discovery_product_url_patterns' ) ) ) {
			$score += 8;
		}

		$product_words = ' ' . $this->canonical_search_text( $product_name ) . ' ';
		$candidate_words = ' ' . $context_text . ' ' . $url_text . ' ';
		if ( str_contains( $candidate_words, ' bundle ' ) && ! str_contains( $product_words, ' bundle ' ) ) {
			$score -= 45;
		}
		if ( str_contains( $product_words, ' bassinet ' ) && str_contains( $candidate_words, ' stroller ' ) && ! str_contains( $product_words, ' stroller ' ) ) {
			$score -= 35;
		}
		if ( str_contains( $product_words, ' double ' ) && preg_match( '/\b(?:single|singel)\b/u', $candidate_words ) && ! str_contains( $candidate_words, ' double ' ) ) {
			$score -= 70;
		}
		if ( preg_match( '/\b(?:single|singel)\b/u', $product_words ) && str_contains( $candidate_words, ' double ' ) ) {
			$score -= 70;
		}

		return max( 0, $score );
	}

	private function candidate_name_term_hits( string $text, string $product_name ): int {
		$normalized_text = $this->canonical_search_text( $text );
		$hits = 0;
		foreach ( $this->significant_name_terms( $product_name ) as $term ) {
			if ( false !== strpos( $normalized_text, $term ) ) {
				++$hits;
			}
		}

		return $hits;
	}

	/**
	 * @param array<string,array{url:string,score:int,order:int}> $candidates Candidate map.
	 */
	private function add_scored_url( array &$candidates, string $url, int $score ): void {
		$url = $this->url_service->normalize( $url );
		if ( '' === $url || $score <= 0 ) {
			return;
		}
		if ( ! isset( $candidates[ $url ] ) ) {
			$candidates[ $url ] = array(
				'url'   => $url,
				'score' => $score,
				'order' => count( $candidates ),
			);
			return;
		}
		$candidates[ $url ]['score'] = max( (int) $candidates[ $url ]['score'], $score );
	}

	/**
	 * @param array<string,array{url:string,score:int,order:int}> $candidates Candidate map.
	 * @return array<int,string>
	 */
	private function rank_scored_urls( array $candidates ): array {
		$ranked = array_values( $candidates );
		usort(
			$ranked,
			static function ( array $a, array $b ): int {
				$score = (int) $b['score'] <=> (int) $a['score'];
				return 0 !== $score ? $score : ( (int) $a['order'] <=> (int) $b['order'] );
			}
		);

		return array_values( array_column( $ranked, 'url' ) );
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
				if ( '' !== $template && $this->has_search_placeholder( $template ) ) {
					$templates[] = $template;
				}
			}
		}

		foreach ( $this->settings->get_list( 'discovery_sku_search_url_templates' ) as $template ) {
			$templates[] = $template;
		}

		return array_values( array_unique( array_filter( $templates ) ) );
	}

	private function has_search_placeholder( string $template ): bool {
		return false !== strpos( $template, '{sku}' ) || false !== strpos( $template, '{query}' ) || false !== strpos( $template, '{gtin}' ) || false !== strpos( $template, '{ean}' ) || false !== strpos( $template, '%s' );
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
	 * Check a redirected URL without trusting competitor input.
	 *
	 * @param array<int,int|string> $ports Allowed ports.
	 */
	private function is_safe_same_domain_url( string $url, string $domain, array $ports ): bool {
		return '' !== $url && $this->url_service->is_safe_url( $url, $ports ) && $this->url_service->matches_domain( $url, $domain );
	}

	/**
	 * Query a public Algolia product index exposed by some WooCommerce sites.
	 *
	 * @param array<int,int|string>  $ports Allowed ports.
	 * @param array<string,mixed>    $settings Discovery settings.
	 * @return array{urls:array<int,string>,searched_url:string,request_count:int,message:string}
	 */
	private function algolia_product_urls_from_html( string $html, string $query, string $domain, array $ports, array $settings, string $product_name = '' ): array {
		$config = $this->algolia_config_from_html( $html );
		if ( empty( $config ) ) {
			return array(
				'urls'          => array(),
				'searched_url'  => '',
				'request_count' => 0,
				'message'       => '',
			);
		}

		$endpoint = sprintf( 'https://%s-dsn.algolia.net/1/indexes/%s/query', (string) $config['application_id'], rawurlencode( (string) $config['index_name'] ) );
		if ( ! $this->url_service->is_safe_url( $endpoint, $ports ) ) {
			return array(
				'urls'          => array(),
				'searched_url'  => '',
				'request_count' => 0,
				'message'       => 'Algolia search endpoint was not safe.',
			);
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'     => absint( $settings['discovery_request_timeout'] ?? 12 ),
				'redirection' => 0,
				'user-agent'  => $this->user_agent(),
				'headers'     => array(
					'Accept'                   => 'application/json',
					'Content-Type'             => 'application/json',
					'X-Algolia-API-Key'        => (string) $config['search_api_key'],
					'X-Algolia-Application-Id' => (string) $config['application_id'],
				),
				'body'        => wp_json_encode(
					array(
						'params' => http_build_query(
							array(
								'query'                => $query,
								'hitsPerPage'          => 5,
								'attributesToRetrieve' => wp_json_encode( array( 'permalink', 'url', 'post_url', 'slug', 'post_title', 'title', 'sku' ) ),
							),
							'',
							'&',
							PHP_QUERY_RFC3986
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'urls'          => array(),
				'searched_url'  => $endpoint,
				'request_count' => 1,
				'message'       => 'Algolia product search failed: ' . $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'urls'          => array(),
				'searched_url'  => $endpoint,
				'request_count' => 1,
				'message'       => 'Algolia product search returned HTTP status ' . $code . '.',
			);
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$candidates = array();
		foreach ( is_array( $decoded['hits'] ?? null ) ? $decoded['hits'] : array() as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}
			$hit_text = $this->algolia_hit_text( $hit );
			$score = $this->algolia_hit_score( (string) $query, $product_name, $hit_text );
			if ( $score <= 0 ) {
				continue;
			}
			foreach ( array( 'permalink', 'url', 'post_url' ) as $field ) {
				$url = $this->url_service->normalize( (string) ( $hit[ $field ] ?? '' ) );
				if ( $this->is_safe_same_domain_url( $url, $domain, $ports ) && ! $this->looks_like_search_results_url( $url ) && ! $this->looks_like_listing_or_category_url( $url ) ) {
					$this->add_scored_url( $candidates, $url, $score + $this->candidate_match_score( $url, $hit_text, '' !== $product_name ? $product_name : $query ) );
					break;
				}
			}
		}

		$urls = $this->rank_scored_urls( $candidates );

		return array(
			'urls'          => $urls,
			'searched_url'  => $endpoint,
			'request_count' => 1,
			'message'       => empty( $urls ) ? 'Algolia product search returned no relevant same-domain product URLs.' : 'Algolia product search found relevant product URLs from the public product index.',
		);
	}

	/**
	 * Query a public Voyado Elevate search index when a competitor page exposes
	 * its client-side configuration.
	 *
	 * @param array<int,int|string> $ports Allowed ports.
	 * @param array<string,mixed>   $settings Discovery settings.
	 * @return array{urls:array<int,string>,searched_url:string,request_count:int,message:string}
	 */
	private function voyado_elevate_product_urls_from_html( string $html, string $query, string $domain, array $ports, array $settings, string $product_name = '' ): array {
		$config = $this->voyado_elevate_config_from_html( $html );
		if ( empty( $config ) ) {
			return array(
				'urls'          => array(),
				'searched_url'  => '',
				'request_count' => 0,
				'message'       => '',
			);
		}

		$endpoint = sprintf( 'https://%s.elevate-api.cloud/api/storefront/v3/queries/search-page', (string) $config['cluster_id'] );
		if ( ! $this->url_service->is_safe_url( $endpoint, $ports ) ) {
			return array(
				'urls'          => array(),
				'searched_url'  => '',
				'request_count' => 0,
				'message'       => 'Voyado Elevate search endpoint was not safe.',
			);
		}

		$request_url = add_query_arg(
			array(
				'market'        => (string) $config['market'],
				'locale'        => (string) $config['locale'],
				'touchpoint'    => 'DESKTOP',
				'sessionKey'    => $this->deterministic_uuid( $domain . '-session' ),
				'customerKey'   => $this->deterministic_uuid( $domain . '-customer' ),
				'q'             => $query,
				'limit'         => 8,
				'skip'          => 0,
				'sort'          => 'RELEVANCE',
				'notify'        => 'false',
				'presentCustom' => 'ajax_add_to_cart|magento_product_type|variant_key|number.product_id',
			),
			$endpoint
		);

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout'     => absint( $settings['discovery_request_timeout'] ?? 12 ),
				'redirection' => 0,
				'user-agent'  => $this->user_agent(),
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'urls'          => array(),
				'searched_url'  => $endpoint,
				'request_count' => 1,
				'message'       => 'Voyado Elevate product search failed: ' . $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$message = $this->api_error_message( (string) wp_remote_retrieve_body( $response ) );
			return array(
				'urls'          => array(),
				'searched_url'  => $endpoint,
				'request_count' => 1,
				'message'       => 'Voyado Elevate product search returned HTTP status ' . $code . ( '' !== $message ? ': ' . $message : '' ) . '.',
				'hard_failure'  => true,
			);
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$urls = array();
		$seen = array();
		$position = 0;
		$is_identifier_query = $this->is_identifier_search_query( $query );
		foreach ( $this->voyado_elevate_products_from_response( is_array( $decoded ) ? $decoded : array() ) as $product ) {
			$hit_text = $this->voyado_elevate_hit_text( $product );
			$score = $this->algolia_hit_score( $query, $product_name, $hit_text );
			$rank_bonus = max( 0, 1000 - ( $position * 100 ) );
			++$position;
			if ( $score <= 0 && ( $is_identifier_query || $rank_bonus < 600 ) ) {
				continue;
			}
			foreach ( array( 'link', 'url' ) as $field ) {
				$url = $this->url_service->resolve( (string) ( $product[ $field ] ?? '' ), 'https://' . $domain . '/' );
				if ( $this->is_safe_same_domain_url( $url, $domain, $ports ) && ! $this->looks_like_search_results_url( $url ) && ! $this->looks_like_listing_or_category_url( $url ) ) {
					$url = $this->url_service->normalize( $url );
					if ( '' !== $url && ! isset( $seen[ $url ] ) ) {
						$urls[] = $url;
						$seen[ $url ] = true;
					}
					break;
				}
			}
		}

		return array(
			'urls'          => $urls,
			'searched_url'  => $endpoint,
			'request_count' => 1,
			'message'       => empty( $urls ) ? 'Voyado Elevate product search returned no relevant same-domain product URLs.' : 'Voyado Elevate product search found relevant product URLs from the public product index.',
		);
	}

	private function deterministic_uuid( string $seed ): string {
		$hex = substr( hash( 'sha256', $seed ), 0, 32 );

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-4' . substr( $hex, 13, 3 ) . '-8' . substr( $hex, 17, 3 ) . '-' . substr( $hex, 20, 12 );
	}

	private function api_error_message( string $body ): string {
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) && ! empty( $decoded['message'] ) && is_scalar( $decoded['message'] ) ) {
			return sanitize_text_field( (string) $decoded['message'] );
		}

		return '';
	}

	/**
	 * @return array{cluster_id:string,market:string,locale:string}|array<string,mixed>
	 */
	private function voyado_elevate_config_from_html( string $html ): array {
		$decoded = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$decoded = str_replace( array( '\/', '\\/' ), '/', $decoded );
		$config_html = $this->voyado_elevate_config_html_fragment( $decoded );

		if ( ! preg_match( '/"clusterId"\s*:\s*"([a-zA-Z0-9\-]{4,})"/', $config_html, $cluster ) && ! preg_match( '/"clusterId"\s*:\s*"([a-zA-Z0-9\-]{4,})"/', $decoded, $cluster ) ) {
			return array();
		}

		$market_source = preg_match( '/"market"\s*:\s*"([A-Z]{2})"/', $config_html, $market_match ) ? $config_html : $decoded;
		$locale_source = preg_match( '/"locale"\s*:\s*"([a-z]{2}(?:-|_)[A-Z]{2})"/', $config_html, $locale_match ) ? $config_html : $decoded;

		$market = preg_match( '/"market"\s*:\s*"([A-Z]{2})"/', $market_source, $market_match ) ? (string) $market_match[1] : 'NO';
		$locale = preg_match( '/"locale"\s*:\s*"([a-z]{2}(?:-|_)[A-Z]{2})"/', $locale_source, $locale_match ) ? str_replace( '_', '-', (string) $locale_match[1] ) : 'nb-NO';

		return array(
			'cluster_id' => (string) $cluster[1],
			'market'     => $market,
			'locale'     => $locale,
		);
	}

	private function voyado_elevate_config_html_fragment( string $decoded_html ): string {
		foreach ( array( 'Bluemint_VoyadoElevate/js/search/results', 'bmvoyadoSearchResults', 'bm-voyado-results' ) as $needle ) {
			$position = strpos( $decoded_html, $needle );
			if ( false === $position ) {
				continue;
			}

			$fragment = substr( $decoded_html, $position, 8000 );
			if ( str_contains( $fragment, '"clusterId"' ) ) {
				return $fragment;
			}
		}

		return $decoded_html;
	}

	/**
	 * @param array<string,mixed> $response Voyado response.
	 * @return array<int,array<string,mixed>>
	 */
	private function voyado_elevate_products_from_response( array $response ): array {
		$products = array();
		$groups = $response['primaryList']['productGroups'] ?? array();
		foreach ( is_array( $groups ) ? $groups : array() as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( is_array( $group['products'] ?? null ) ? $group['products'] : array() as $product ) {
				if ( is_array( $product ) ) {
					$products[] = $product;
				}
			}
		}

		return $products;
	}

	/**
	 * @param array<string,mixed> $product Voyado product.
	 */
	private function voyado_elevate_hit_text( array $product ): string {
		$parts = array();
		foreach ( array( 'key', 'title', 'brand', 'link', 'url' ) as $field ) {
			if ( ! empty( $product[ $field ] ) && is_scalar( $product[ $field ] ) ) {
				$parts[] = (string) $product[ $field ];
			}
		}
		foreach ( is_array( $product['variants'] ?? null ) ? $product['variants'] : array() as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}
			foreach ( array( 'key', 'link' ) as $field ) {
				if ( ! empty( $variant[ $field ] ) && is_scalar( $variant[ $field ] ) ) {
					$parts[] = (string) $variant[ $field ];
				}
			}
		}
		foreach ( is_array( $product['custom'] ?? null ) ? $product['custom'] : array() as $values ) {
			foreach ( is_array( $values ) ? $values : array() as $value ) {
				if ( is_array( $value ) && ! empty( $value['label'] ) && is_scalar( $value['label'] ) ) {
					$parts[] = (string) $value['label'];
				}
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * @param array<string,mixed> $hit Algolia hit.
	 */
	private function algolia_hit_text( array $hit ): string {
		$parts = array();
		foreach ( array( 'post_title', 'title', 'sku', 'ean', 'gtin', 'mpn', 'brand', 'permalink', 'url', 'post_url', 'slug' ) as $field ) {
			if ( ! empty( $hit[ $field ] ) && is_scalar( $hit[ $field ] ) ) {
				$parts[] = (string) $hit[ $field ];
			}
		}

		return implode( ' ', $parts );
	}

	private function algolia_hit_score( string $query, string $product_name, string $hit_text ): int {
		$query = trim( $query );
		$query_normalized = $this->normalize_identifier( $query );
		if ( $this->is_identifier_search_query( $query ) ) {
			return $this->text_mentions_sku( $hit_text, $query, $query_normalized ) ? 500 : 0;
		}

		$name = '' !== trim( $product_name ) ? $product_name : $query;
		$score = $this->candidate_match_score( '', $hit_text, $name );

		return $score >= $this->minimum_name_candidate_score( $name ) ? $score : 0;
	}

	private function is_identifier_search_query( string $query ): bool {
		$query = trim( $query );
		$query_normalized = $this->normalize_identifier( $query );

		return '' !== $query_normalized && ! str_contains( $query, ' ' ) && strlen( $query_normalized ) >= 5;
	}

	/**
	 * Extract public Algolia search settings from wp-search-with-algolia markup.
	 *
	 * @return array{application_id:string,search_api_key:string,index_name:string}|array<string,mixed>
	 */
	private function algolia_config_from_html( string $html ): array {
		if ( ! preg_match( '#var\s+algolia\s*=\s*(\{.*?\})\s*;\s*</script>#is', $html, $match ) ) {
			return array();
		}

		$config = json_decode( html_entity_decode( (string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
		if ( ! is_array( $config ) ) {
			return array();
		}

		$app_id = strtoupper( trim( (string) ( $config['application_id'] ?? '' ) ) );
		$key    = trim( (string) ( $config['search_api_key'] ?? '' ) );
		$index  = '';
		if ( ! empty( $config['indices']['posts_product']['name'] ) ) {
			$index = (string) $config['indices']['posts_product']['name'];
		} elseif ( ! empty( $config['autocomplete']['sources'] ) && is_array( $config['autocomplete']['sources'] ) ) {
			foreach ( $config['autocomplete']['sources'] as $source ) {
				if ( is_array( $source ) && 'posts_product' === (string) ( $source['index_id'] ?? '' ) && ! empty( $source['index_name'] ) ) {
					$index = (string) $source['index_name'];
					break;
				}
			}
		}

		if ( ! preg_match( '/^[A-Z0-9]{6,}$/', $app_id ) || ! preg_match( '/^[a-zA-Z0-9]{8,}$/', $key ) || ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $index ) ) {
			return array();
		}

		return array(
			'application_id' => $app_id,
			'search_api_key' => $key,
			'index_name'     => $index,
		);
	}

	/**
	 * Exact search hits often 302 to a clean product slug that does not contain SKU/EAN.
	 */
	private function looks_like_redirect_product_candidate( string $url ): bool {
		if ( $this->looks_like_search_results_url( $url ) || $this->looks_like_listing_or_category_url( $url ) ) {
			return false;
		}
		if ( $this->url_service->looks_like_product_url( $url, array(), $this->settings->get_list( 'discovery_exclude_url_patterns' ), $this->settings->get_list( 'discovery_product_url_patterns' ) ) ) {
			return true;
		}

		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $path || '/' === $path || preg_match( '#/(?:catalogsearch|search|sok|category|kategori|brand|merke|collection|collections|blog|news|nyheter)(?:/|$)#i', $path ) ) {
			return false;
		}
		if ( preg_match( '#\.(?:jpg|jpeg|png|gif|webp|svg|pdf|zip|css|js|woff2?|ttf|mp4|mov)(?:\?|$)#i', $path ) ) {
			return false;
		}

		$leaf = trim( basename( $path ) );
		if ( preg_match( '#\.html?$#i', $leaf ) ) {
			return true;
		}

		return (bool) preg_match( '#[a-z0-9æøå]+-[a-z0-9æøå-]+#iu', $leaf );
	}

	/**
	 * Avoid treating search/listing URLs as final product pages.
	 */
	private function looks_like_search_results_url( string $url ): bool {
		$path  = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$query = strtolower( (string) wp_parse_url( $url, PHP_URL_QUERY ) );
		if ( preg_match( '#/(?:catalogsearch|search|sok|finn)(?:/|$)#i', $path ) ) {
			return true;
		}

		parse_str( $query, $params );
		foreach ( array_keys( $params ) as $key ) {
			if ( in_array( strtolower( (string) $key ), array( 's', 'q', 'query', 'search', 'keyword', 'keywords' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Product search should not extract category/listing URLs as product pages.
	 */
	private function looks_like_listing_or_category_url( string $url ): bool {
		$path = strtolower( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( '' === $path ) {
			return false;
		}

		return (bool) preg_match( '#/(?:product-category|category|kategori|collections?|brand|merke|tag|product-tag|blog|news|nyheter)(?:/|$)#i', $path );
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
	 * Conservative product-candidate link heuristic.
	 */
	private function looks_like_product_candidate_link( string $url, string $text, bool $broad_listing_page ): bool {
		$has_product_card_context = $this->has_product_card_context( $text );
		if ( $this->looks_like_listing_or_category_url( $url ) && ! $has_product_card_context ) {
			return false;
		}
		if ( $this->url_service->looks_like_product_url( $url, array(), $this->settings->get_list( 'discovery_exclude_url_patterns' ), $this->settings->get_list( 'discovery_product_url_patterns' ) ) ) {
			return true;
		}
		if ( ! $broad_listing_page ) {
			return false;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$text = trim( wp_strip_all_tags( $text ) );
		if ( '' === $path || '/' === $path || '' === $text ) {
			return false;
		}
		if ( preg_match( '#/(?:category|kategori|brand|merke|collection|collections|search|sok|blog|news|nyheter)(?:/|$)#i', $path ) ) {
			return false;
		}

		return $has_product_card_context || substr_count( trim( $path, '/' ), '/' ) >= 1 || preg_match( '#[a-z0-9]+-[a-z0-9]+#i', $path );
	}

	private function has_product_card_context( string $text ): bool {
		$raw        = strtolower( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$normalized = strtolower( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		return str_contains( $raw, 'product-item' )
			|| str_contains( $raw, 'js-product-item' )
			|| str_contains( $raw, 'data-name=' )
			|| str_contains( $raw, 'data-product-impression' )
			|| str_contains( $raw, 'standard-product-price' )
			|| preg_match( '/(?:^|\s)\d[\d\s.,]*\s*kr\b/u', $normalized );
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

	/**
	 * Identifier search terms for a selected product, in confidence order.
	 *
	 * @return array<int,array{type:string,value:string,normalized:string}>
	 */
	private function identifier_search_queries( string $sku, string $gtin ): array {
		$queries = array();
		foreach ( array( 'sku' => $sku, 'gtin' => $gtin ) as $type => $value ) {
			$value      = trim( $value );
			$normalized = $this->normalize_identifier( $value );
			if ( '' === $value && '' === $normalized ) {
				continue;
			}
			$key = strtolower( '' !== $normalized ? $normalized : $value );
			$queries[ $key ] = array(
				'type'       => $type,
				'value'      => $value,
				'normalized' => $normalized,
			);
		}

		return array_values( $queries );
	}

	private function product_gtin_query( object $product ): string {
		foreach ( array( 'gtin', 'ean', 'normalized_gtin' ) as $key ) {
			if ( ! empty( $product->{$key} ) ) {
				return trim( (string) $product->{$key} );
			}
		}

		return '';
	}

	/**
	 * Build a safe competitor-search query from a selected product name.
	 */
	private function product_name_query( object $product ): string {
		$queries = $this->product_name_queries( $product );

		return (string) ( $queries[0] ?? '' );
	}

	/**
	 * Build bounded search queries from a selected product name.
	 *
	 * Competitors often omit generation/year/color words, so try a few shorter
	 * variants after the full title while keeping the request count capped.
	 *
	 * @return array<int,string>
	 */
	private function product_name_queries( object $product ): array {
		$raw_name = $this->raw_product_name( $product );
		if ( '' === $raw_name ) {
			return array();
		}

		$without_parentheses = preg_replace( '/\([^)]*\)/u', ' ', $raw_name ) ?: $raw_name;
		$queries = array(
			$this->format_product_name_query( $raw_name ),
			$this->format_product_name_query( $without_parentheses ),
		);

		$base_terms = preg_split( '/\s+/u', $this->format_product_name_query( $without_parentheses ) ) ?: array();
		$model_terms = array_values(
			array_filter(
				$base_terms,
				static function ( string $term ): bool {
					$normalized = strtolower( $term );
					if ( in_array( $normalized, array( 'gen', 'generation', 'version', 'modell', 'model' ), true ) ) {
						return false;
					}

					return ! preg_match( '/^(?:19|20)\d{2}$/', $normalized );
				}
			)
		);

		foreach ( array( 6, 5, 4 ) as $length ) {
			if ( count( $model_terms ) >= $length ) {
				$queries[] = implode( ' ', array_slice( $model_terms, 0, $length ) );
			}
		}
		$queries = array_merge( $queries, $this->synonym_product_name_queries( $without_parentheses ) );

		$deduped = array();
		foreach ( $queries as $query ) {
			$query = trim( (string) $query );
			if ( '' === $query ) {
				continue;
			}
			$key = strtolower( $query );
			if ( isset( $deduped[ $key ] ) ) {
				continue;
			}
			$deduped[ $key ] = substr( $query, 0, 90 );
		}

		return array_slice( array_values( $deduped ), 0, 8 );
	}

	/**
	 * Build a few competitor-friendly name queries from common retail synonyms.
	 *
	 * @return array<int,string>
	 */
	private function synonym_product_name_queries( string $name ): array {
		$formatted = $this->format_product_name_query( $name );
		if ( '' === $formatted ) {
			return array();
		}

		$queries = array();
		foreach ( $this->retail_term_groups() as $canonical => $terms ) {
			$canonical_text = $this->canonical_search_text( $formatted );
			if ( ! preg_match( '/\b' . preg_quote( $canonical, '/' ) . '\b/u', $canonical_text ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( $term === $canonical ) {
					continue;
				}
				$query = preg_replace( '/\b' . preg_quote( $canonical, '/' ) . '\b/iu', $term, $canonical_text ) ?: '';
				$queries[] = $this->format_product_name_query( $query );
			}
		}

		return array_values( array_filter( array_unique( $queries ) ) );
	}

	private function raw_product_name( object $product ): string {
		$name = '';
		foreach ( array( 'product_name', 'title', 'name' ) as $key ) {
			if ( ! empty( $product->{$key} ) ) {
				$name = (string) $product->{$key};
				break;
			}
		}

		if ( '' === $name && function_exists( 'get_the_title' ) ) {
			$lookup_id = absint( $product->variation_id ?? 0 );
			if ( $lookup_id <= 0 ) {
				$lookup_id = absint( $product->product_id ?? 0 );
			}
			if ( $lookup_id > 0 ) {
				$name = (string) get_the_title( $lookup_id );
			}
		}

		return $name;
	}

	private function format_product_name_query( string $name ): string {
		$name = html_entity_decode( wp_strip_all_tags( $name ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $name ) ?: '';
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) ?: '' );
		if ( '' === $name ) {
			return '';
		}

		$terms = array_slice( preg_split( '/\s+/u', $name ) ?: array(), 0, 10 );

		return substr( implode( ' ', $terms ), 0, 90 );
	}

	/**
	 * Check whether text has enough meaningful overlap with a selected product name.
	 */
	private function text_matches_product_name( string $text, string $product_name ): bool {
		$terms = $this->significant_name_terms( $product_name );
		if ( empty( $terms ) ) {
			return false;
		}

		$normalized_text = $this->canonical_search_text( $text );
		$hits = 0;
		foreach ( $terms as $term ) {
			if ( false !== strpos( $normalized_text, $term ) ) {
				++$hits;
			}
		}

		if ( count( $terms ) <= 2 ) {
			return $hits === count( $terms );
		}

		return $hits >= 2;
	}

	/**
	 * Significant name words for candidate-page filtering.
	 *
	 * @return array<int,string>
	 */
	private function significant_name_terms( string $product_name ): array {
		$normalized = $this->canonical_search_text( $product_name );
		$raw_terms  = preg_split( '/\s+/u', trim( $normalized ) ) ?: array();
		$stop_words = array( 'and', 'the', 'for', 'with', 'og', 'med', 'til', 'på', 'i', 'av', 'gen' );
		$terms      = array();

		foreach ( $raw_terms as $term ) {
			$term = trim( $term );
			if ( '' === $term || in_array( $term, $stop_words, true ) ) {
				continue;
			}
			if ( strlen( $term ) < 3 && ! preg_match( '/\d/', $term ) ) {
				continue;
			}
			$terms[] = $term;
		}

		return array_values( array_unique( array_slice( $terms, 0, 8 ) ) );
	}

	private function canonical_search_text( string $text ): string {
		$text = strtolower( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$text = str_replace( array( '&ndash;', '&mdash;', '–', '—' ), ' ', $text );
		$text = preg_replace( '/[^a-z0-9æøå]+/iu', ' ', (string) $text );
		$text = str_replace( array( 'æ', 'ø', 'å' ), array( 'ae', 'o', 'a' ), (string) $text );
		$text = preg_replace( '/\bblack\s+on\s+black\b/u', ' black ', (string) $text );
		$text = preg_replace( '/\bmidnight\s+black\b/u', ' black ', (string) $text );
		$text = preg_replace( '/\bmid\s+blue\b/u', ' blue ', (string) $text );

		foreach ( $this->retail_term_groups() as $canonical => $terms ) {
			foreach ( $terms as $term ) {
				$text = preg_replace( '/\b' . preg_quote( $term, '/' ) . '\b/u', ' ' . $canonical . ' ', (string) $text );
			}
		}

		return trim( preg_replace( '/\s+/', ' ', (string) $text ) );
	}

	/**
	 * @return array<string,array<int,string>>
	 */
	private function retail_term_groups(): array {
		return array(
			'bassinet' => array( 'bassinet', 'bag', 'liggedel' ),
			'stroller' => array( 'stroller', 'vogn', 'barnevogn', 'trille', 'triller' ),
			'double'   => array( 'double', 'dobbel', 'soskenvogn', 'søskenvogn' ),
			'single'   => array( 'single', 'singel', 'enkel' ),
			'black'    => array( 'black', 'sort' ),
			'blue'     => array( 'blue', 'bla', 'blå' ),
			'bundle'   => array( 'bundle', 'package', 'pakke', 'vognpakke', 'inkl', 'ink', 'incl', 'included', 'inkludert', 'with' ),
			'kit'      => array( 'kit', 'sett' ),
		);
	}

	/** Build one absolute search URL. */
	public function build_search_url( string $domain, string $template, string $sku ): string {
		$domain = preg_replace( '#^https?://#i', '', trim( $domain ) );
		$domain = trim( (string) $domain, "/ \t\n\r\0\x0B" );
		if ( '' === $domain ) {
			return '';
		}

		$value = rawurlencode( $sku );
		$url   = str_replace( array( '{sku}', '{query}', '{gtin}', '{ean}', '%s' ), $value, $template );
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
