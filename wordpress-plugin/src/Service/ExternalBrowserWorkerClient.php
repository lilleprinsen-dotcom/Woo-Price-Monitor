<?php
/**
 * Optional external browser worker client.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calls the configured Playwright worker for explicitly opted-in competitors.
 */
class ExternalBrowserWorkerClient implements ExternalProductPageFetcherInterface {
	private DiscoveryUrlService $url_service;

	public function __construct( ?DiscoveryUrlService $url_service = null ) {
		$this->url_service = $url_service ?? new DiscoveryUrlService();
	}

	/**
	 * Interface convenience. Use is_configured() when current settings are available.
	 */
	public function is_enabled(): bool {
		return $this->is_configured( array() );
	}

	/**
	 * @param array<string,mixed> $settings Plugin settings.
	 */
	public function is_configured( array $settings ): bool {
		$settings = $this->effective_settings( $settings );

		return ! empty( $settings['external_browser_worker_enabled'] )
			&& '' !== trim( (string) ( $settings['external_browser_worker_endpoint'] ?? '' ) )
			&& '' !== trim( (string) ( $settings['external_browser_worker_secret'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $competitor Competitor profile.
	 * @param array<string,mixed> $settings Plugin settings.
	 */
	public function should_use_for_search( array $competitor, array $settings, bool $internal_failed ): bool {
		$config = $this->competitor_config( $competitor );
		if ( ! $this->is_configured( $settings ) || empty( $config['search_enabled'] ) ) {
			return false;
		}

		return 'always' === $config['mode'] || ( $internal_failed && 'js' === $config['mode'] && ! empty( $competitor['requires_javascript'] ) );
	}

	/**
	 * @param array<string,mixed> $competitor Competitor profile.
	 */
	public function is_always_mode( array $competitor ): bool {
		$config = $this->competitor_config( $competitor );

		return 'always' === $config['mode'];
	}

	/**
	 * @param array<string,mixed> $competitor Competitor profile.
	 * @param array<string,mixed> $settings Plugin settings.
	 */
	public function should_use_for_product( array $competitor, array $settings, bool $internal_failed = false ): bool {
		$config = $this->competitor_config( $competitor );
		if ( ! $this->is_configured( $settings ) || empty( $config['product_enabled'] ) ) {
			return false;
		}

		return 'always' === $config['mode'] || ( 'js' === $config['mode'] && ( ! empty( $competitor['requires_javascript'] ) || $internal_failed ) );
	}

	/**
	 * @param array<string,mixed> $settings Plugin settings.
	 * @param array<string,mixed> $competitor Competitor profile.
	 * @param object $product Selected product row.
	 * @return array{success:bool,urls:array<int,string>,message:string,technical_details:string,request_count:int,searched_url:string}
	 */
	public function search( string $search_url, array $settings, array $competitor, object $product ): array {
		$settings = $this->effective_settings( $settings );
		$domain = $this->competitor_domain( $competitor );
		if ( '' === $domain || ! $this->url_service->matches_domain( $search_url, $domain ) ) {
			return $this->search_failure( 'Worker search skipped because the URL does not match the competitor domain.', 0 );
		}

		$response = $this->request(
			'/v1/search',
			array(
				'url'            => $search_url,
				'competitorDomain' => $domain,
				'expected'       => $this->expected_product_payload( $product ),
				'maxCandidates'  => max( 1, min( 25, absint( $settings['external_browser_worker_max_candidates'] ?? 8 ) ) ),
				'timeoutMs'      => max( 5000, min( 60000, absint( $settings['external_browser_worker_timeout_seconds'] ?? 20 ) * 1000 ) ),
			),
			$settings
		);

		if ( empty( $response['success'] ) ) {
			return $this->search_failure( (string) ( $response['error'] ?? 'External browser worker search failed.' ), (int) ( $response['http_status'] ?? 0 ) );
		}

		$data = is_array( $response['data'] ?? null ) ? $response['data'] : array();
		$urls = array();
		foreach ( (array) ( $data['candidates'] ?? array() ) as $candidate ) {
			$url = $this->url_service->normalize( (string) ( is_array( $candidate ) ? ( $candidate['url'] ?? '' ) : '' ) );
			if ( '' !== $url && $this->url_service->matches_domain( $url, $domain ) ) {
				$urls[] = $url;
			}
		}

		$diagnostics = array_filter(
			array_merge(
				array( 'External browser worker rendered search page.' ),
				array_map( 'strval', (array) ( $data['diagnostics'] ?? array() ) )
			)
		);

		return array(
			'success'           => ! empty( $urls ),
			'urls'              => array_values( array_unique( $urls ) ),
			'message'           => ! empty( $urls ) ? 'External browser worker found rendered candidate URLs.' : 'External browser worker found no rendered product candidates.',
			'technical_details' => implode( "\n", $diagnostics ),
			'request_count'     => 1,
			'searched_url'      => $search_url,
		);
	}

	/**
	 * @param array<string,mixed> $settings Plugin settings.
	 * @param array<string,mixed> $competitor Competitor profile.
	 * @return array<string,mixed>
	 */
	public function product( string $url, array $settings, array $competitor, $expected_product = null ): array {
		$settings = $this->effective_settings( $settings );
		$domain = $this->competitor_domain( $competitor );
		if ( '' === $domain || ! $this->url_service->matches_domain( $url, $domain ) ) {
			return $this->product_failure( 'External browser worker skipped because the URL does not match the competitor domain.', 0 );
		}

		$response = $this->request(
			'/v1/product',
			array(
				'url'              => $url,
				'competitorDomain' => $domain,
				'expected'         => $this->expected_product_payload( $expected_product ),
				'extractionHints'  => $this->extraction_hints( $competitor ),
				'maxCandidates'    => max( 1, min( 25, absint( $settings['external_browser_worker_max_candidates'] ?? 8 ) ) ),
				'timeoutMs'        => max( 5000, min( 60000, absint( $settings['external_browser_worker_timeout_seconds'] ?? 20 ) * 1000 ) ),
			),
			$settings
		);

		if ( empty( $response['success'] ) ) {
			return $this->product_failure( (string) ( $response['error'] ?? 'External browser worker product extraction failed.' ), (int) ( $response['http_status'] ?? 0 ) );
		}

		$data = is_array( $response['data'] ?? null ) ? $response['data'] : array();

		return $this->normalize_product_result( $data, $url );
	}

	/**
	 * Fetch rendered HTML when an extension only needs the interface contract.
	 *
	 * @param array<string,mixed> $competitor Competitor profile.
	 * @return array{success:bool,html:string,error:string}
	 */
	public function fetch_product_html( string $url, array $competitor ): array {
		unset( $url, $competitor );

		return array(
			'success' => false,
			'html'    => '',
			'error'   => 'Use product() for structured external browser worker results.',
		);
	}

	/**
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array{success:bool,data:array<string,mixed>,error:string,http_status:int}
	 */
	private function request( string $path, array $payload, array $settings ): array {
		$settings = $this->effective_settings( $settings );
		if ( ! $this->is_configured( $settings ) || ! function_exists( 'wp_remote_post' ) ) {
			return array( 'success' => false, 'data' => array(), 'error' => 'External browser worker is not configured.', 'http_status' => 0 );
		}

		$endpoint = rtrim( esc_url_raw( (string) $settings['external_browser_worker_endpoint'] ), '/' ) . $path;
		$body     = (string) wp_json_encode( $payload );
		$secret   = (string) $settings['external_browser_worker_secret'];
		$timestamp = (string) time();
		$nonce     = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$body_hash = hash( 'sha256', $body );
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body_hash, $secret );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => max( 5, min( 65, absint( $settings['external_browser_worker_timeout_seconds'] ?? 20 ) + 5 ) ),
				'headers' => array(
					'Content-Type'       => 'application/json',
					'X-LPM-Timestamp'    => $timestamp,
					'X-LPM-Nonce'        => $nonce,
					'X-LPM-Body-SHA256'  => $body_hash,
					'X-LPM-Signature'    => $signature,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'data' => array(), 'error' => $response->get_error_message(), 'http_status' => 0 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 ) {
			return array( 'success' => false, 'data' => array(), 'error' => is_array( $data ) ? (string) ( $data['error'] ?? 'External browser worker returned HTTP ' . $status ) : 'External browser worker returned HTTP ' . $status, 'http_status' => $status );
		}
		if ( ! is_array( $data ) ) {
			return array( 'success' => false, 'data' => array(), 'error' => 'External browser worker returned malformed JSON.', 'http_status' => $status );
		}

		return array( 'success' => ! empty( $data['success'] ), 'data' => $data, 'error' => (string) ( $data['error'] ?? '' ), 'http_status' => $status );
	}

	/**
	 * @return array{mode:string,search_enabled:int,product_enabled:int}
	 */
	private function competitor_config( array $competitor ): array {
		$notes = json_decode( (string) ( $competitor['notes'] ?? '' ), true );
		$notes = is_array( $notes ) ? $notes : array();
		$mode  = sanitize_key( (string) ( $notes['external_browser_worker_mode'] ?? ( ! empty( $notes['external_browser_worker_enabled'] ) ? 'js' : 'internal' ) ) );

		return array(
			'mode'            => in_array( $mode, array( 'internal', 'js', 'always' ), true ) ? $mode : 'internal',
			'search_enabled'  => empty( $notes['external_browser_worker_search_enabled'] ) ? 0 : 1,
			'product_enabled' => empty( $notes['external_browser_worker_product_enabled'] ) ? 0 : 1,
		);
	}

	/**
	 * Discovery services often receive discovery-only settings. Merge the shared
	 * plugin option so worker settings are available without changing job shape.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 * @return array<string,mixed>
	 */
	private function effective_settings( array $settings ): array {
		$stored = function_exists( 'get_option' ) ? get_option( Settings::OPTION_NAME, array() ) : array();

		if ( ! is_array( $stored ) ) {
			return $settings;
		}

		foreach ( $stored as $key => $value ) {
			if ( is_string( $key ) && str_starts_with( $key, 'external_browser_worker_' ) && ! array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $value;
			}
		}

		return $settings;
	}

	private function competitor_domain( array $competitor ): string {
		$domain = trim( (string) ( $competitor['domain'] ?? $competitor['competitor_profile_domain'] ?? '' ) );
		if ( '' === $domain ) {
			return '';
		}
		$with_scheme = preg_match( '#^https?://#i', $domain ) ? $domain : 'https://' . $domain;
		$host        = wp_parse_url( $with_scheme, PHP_URL_HOST );

		return strtolower( trim( (string) $host, '.' ) );
	}

	private function search_failure( string $message, int $status ): array {
		return array(
			'success'           => false,
			'urls'              => array(),
			'message'           => $message,
			'technical_details' => 'External browser worker: ' . $message,
			'request_count'     => $status > 0 ? 1 : 0,
			'searched_url'      => '',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function product_failure( string $message, int $status ): array {
		return array(
			'success'             => false,
			'message'             => 'We could not read this product page with the external browser worker.',
			'error'               => $message,
			'technical_details'   => 'External browser worker: ' . $message,
			'http_status'         => $status,
			'requires_javascript' => true,
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function expected_product_payload( $product ): array {
		if ( null === $product ) {
			return array(
				'sku'   => '',
				'ean'   => '',
				'title' => '',
				'brand' => '',
			);
		}
		$product = is_array( $product ) ? (object) $product : $product;

		return array(
			'sku'   => (string) ( $product->sku ?? '' ),
			'ean'   => (string) ( $product->gtin ?? $product->ean ?? '' ),
			'title' => (string) ( $product->product_name ?? $product->title ?? $product->name ?? '' ),
			'brand' => (string) ( $product->brand ?? '' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function extraction_hints( array $competitor ): array {
		return array(
			'priceSelector'        => (string) ( $competitor['price_selector'] ?? '' ),
			'regularPriceSelector' => (string) ( $competitor['regular_price_selector'] ?? '' ),
			'salePriceSelector'    => (string) ( $competitor['sale_price_selector'] ?? '' ),
			'skuSelector'          => (string) ( $competitor['sku_selector'] ?? '' ),
			'gtinSelector'         => (string) ( $competitor['gtin_selector'] ?? '' ),
			'stockSelector'        => (string) ( $competitor['stock_selector'] ?? '' ),
		);
	}

	/**
	 * @param array<string,mixed> $data Worker product response.
	 * @return array<string,mixed>
	 */
	private function normalize_product_result( array $data, string $fallback_url ): array {
		$regular = $this->normalize_price( $data['regular_price'] ?? $data['regularPrice'] ?? null );
		$sale    = $this->normalize_price( $data['sale_price'] ?? $data['salePrice'] ?? null );
		$price   = $this->normalize_price( $data['monitored_price'] ?? $data['price'] ?? $sale ?? $regular );
		if ( null !== $price && null === $sale && null === $regular ) {
			$sale = $price;
		}

		return array(
			'success'              => null !== $price,
			'message'              => null !== $price ? 'External browser worker extracted product data.' : 'External browser worker did not detect a price.',
			'technical_details'    => implode( "\n", array_map( 'strval', (array) ( $data['diagnostics'] ?? array() ) ) ),
			'url'                  => $this->url_service->normalize( (string) ( $data['url'] ?? $fallback_url ) ),
			'title'                => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'sku'                  => sanitize_text_field( (string) ( $data['sku'] ?? '' ) ),
			'gtin'                 => sanitize_text_field( (string) ( $data['gtin'] ?? $data['ean'] ?? '' ) ),
			'mpn'                  => sanitize_text_field( (string) ( $data['mpn'] ?? '' ) ),
			'brand'                => sanitize_text_field( (string) ( $data['brand'] ?? '' ) ),
			'regular_price'        => $regular,
			'sale_price'           => $sale,
			'monitored_price'      => $price,
			'monitored_price_field'=> null !== $sale ? 'sale_price' : 'regular_price',
			'currency'             => sanitize_text_field( (string) ( $data['currency'] ?? 'NOK' ) ),
			'stock_status'         => sanitize_key( (string) ( $data['stock_status'] ?? $data['stockStatus'] ?? 'unknown' ) ),
			'image_url'            => esc_url_raw( (string) ( $data['image_url'] ?? $data['imageUrl'] ?? '' ) ),
			'canonical_url'        => esc_url_raw( (string) ( $data['canonical_url'] ?? $data['canonicalUrl'] ?? '' ) ),
			'extraction_status'    => null !== $price ? 'success' : 'partial',
			'extraction_source'    => 'External browser worker',
			'requires_javascript'  => false,
				'price_candidates'     => $this->normalize_price_candidates( $data['price_candidates'] ?? array() ),
				'raw_metadata'         => array( 'external_browser_worker' => true ),
				'warnings'             => array(),
			);
	}

	/**
	 * @param mixed $value Raw price.
	 */
	private function normalize_price( $value ): ?float {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}
		$value = preg_replace( '/[^0-9,\.\-]/', '', str_replace( array( ' ', "\xc2\xa0" ), '', (string) $value ) );
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		if ( str_contains( $value, ',' ) && ! str_contains( $value, '.' ) ) {
			$value = str_replace( ',', '.', $value );
		} elseif ( str_contains( $value, ',' ) && str_contains( $value, '.' ) && strrpos( $value, ',' ) > strrpos( $value, '.' ) ) {
			$value = str_replace( '.', '', $value );
			$value = str_replace( ',', '.', $value );
		} else {
			$value = str_replace( ',', '', $value );
		}

		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * @param mixed $candidates Worker supplied price candidates.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_price_candidates( $candidates ): array {
		if ( ! is_array( $candidates ) ) {
			return array();
		}

		$normalized = array();
		foreach ( array_slice( $candidates, 0, 12 ) as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$value = $this->normalize_price( $candidate['value'] ?? $candidate['price'] ?? null );
			if ( null === $value ) {
				continue;
			}
			$normalized[] = array(
				'value'    => $value,
				'price'    => $value,
				'source'   => sanitize_key( (string) ( $candidate['source'] ?? 'external_browser_worker' ) ),
				'field'    => sanitize_key( (string) ( $candidate['field'] ?? 'sale_price' ) ),
				'selector' => sanitize_text_field( (string) ( $candidate['selector'] ?? '' ) ),
				'label'    => sanitize_text_field( (string) ( $candidate['label'] ?? '' ) ),
			);
		}

		return $normalized;
	}
}
