<?php
/**
 * Manual competitor price check service.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

use Lilleprinsen\PriceMonitor\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PriceCheckService {
	private PriceParser $parser;

	private ?Repository $repository;

	public function __construct( ?PriceParser $parser = null, ?Repository $repository = null ) {
		$this->parser     = $parser ?? new PriceParser();
		$this->repository = $repository;
	}

	/**
	 * @param array<string, mixed> $competitor_link Competitor link row.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, stock_status: string, error: string, http_status: int, response_time_ms: int}
	 */
	public function test_check( array $competitor_link, array $settings ): array {
		$checked_at = current_time( 'mysql' );
		$started_at = microtime( true );
		$url        = esc_url_raw( (string) ( $competitor_link['competitor_url'] ?? '' ) );
		$profile    = $this->get_profile_for_link( $competitor_link );

		return $this->record_observation( $competitor_link, $this->fetch_and_parse( $url, $settings, $profile, $started_at ), $checked_at );
	}

	/**
	 * @param array<string, mixed> $profile Competitor profile row.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, stock_status: string, error: string, http_status: int, response_time_ms: int}
	 */
	public function test_url_with_profile( string $url, array $profile, array $settings ): array {
		return $this->fetch_and_parse( esc_url_raw( $url ), $settings, $profile, microtime( true ) );
	}

	/**
	 * @param array<string, mixed>|null $profile Competitor profile row.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, stock_status: string, error: string, http_status: int, response_time_ms: int}
	 */
	private function fetch_and_parse( string $url, array $settings, ?array $profile, float $started_at ): array {
		if ( '' === $url || ! function_exists( 'wp_remote_get' ) ) {
			return $this->failure( __( 'WordPress HTTP is unavailable or the competitor URL is invalid.', 'lilleprinsen-price-monitor' ), 0, $this->elapsed_ms( $started_at ) );
		}

		if ( $profile && ! empty( $profile['requires_javascript'] ) ) {
			return $this->failure(
				__( 'Competitor profile is marked as requiring JavaScript rendering. Internal checker cannot render this page.', 'lilleprinsen-price-monitor' ),
				0,
				$this->elapsed_ms( $started_at ),
				'requires_javascript'
			);
		}

		$timeout = isset( $settings['request_timeout_seconds'] ) ? absint( $settings['request_timeout_seconds'] ) : 8;

		if ( $profile && ! empty( $profile['request_timeout_seconds'] ) ) {
			$timeout = absint( $profile['request_timeout_seconds'] );
		}

		$timeout = min( 30, max( 1, $timeout ) );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => $timeout,
				'redirection'         => 3,
				'limit_response_size' => 1048576,
				'reject_unsafe_urls'  => true,
				'user-agent'          => 'Lilleprinsen Price Monitor/' . ( defined( 'LPM_VERSION' ) ? LPM_VERSION : '0.1.0' ) . '; manual admin price check',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->failure( $response->get_error_message(), 0, $this->elapsed_ms( $started_at ) );
		}

		$status           = (int) wp_remote_retrieve_response_code( $response );
		$response_time_ms = $this->elapsed_ms( $started_at );

		if ( 200 !== $status ) {
			return $this->failure(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Competitor page returned HTTP %d.', 'lilleprinsen-price-monitor' ),
					$status
				),
				$status,
				$response_time_ms
			);
		}

		$body   = (string) wp_remote_retrieve_body( $response );
		$parsed = $this->parser->parse( $body, $this->get_parser_rules( $profile, $settings ) );

		if ( empty( $parsed['success'] ) || null === $parsed['price'] ) {
			return $this->failure( $parsed['error'], $status, $response_time_ms );
		}

		return array(
			'success'           => true,
			'price'             => (float) $parsed['price'],
			'currency'          => (string) $parsed['currency'],
			'extraction_method' => (string) $parsed['extraction_method'],
			'stock_status'      => (string) ( $parsed['stock_status'] ?? '' ),
			'error'             => '',
			'http_status'       => $status,
			'response_time_ms'  => $response_time_ms,
		);
	}

	/**
	 * @param array<string, mixed> $competitor_link Competitor link row.
	 * @param array{success: bool, price: float|null, currency: string, extraction_method: string, stock_status: string, error: string, http_status: int, response_time_ms: int} $result Check result.
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, stock_status: string, error: string, http_status: int, response_time_ms: int}
	 */
	private function record_observation( array $competitor_link, array $result, string $checked_at ): array {
		if ( ! $this->repository ) {
			return $result;
		}

		$competitor_link_id   = absint( $competitor_link['id'] ?? 0 );
		$monitored_product_id = absint( $competitor_link['monitored_product_id'] ?? 0 );
		$product_id           = absint( $competitor_link['product_id'] ?? 0 );

		if ( $product_id <= 0 && $monitored_product_id > 0 ) {
			$monitored_product = $this->repository->get_monitored_product( $monitored_product_id );
			$product_id        = $monitored_product ? absint( $monitored_product['product_id'] ?? 0 ) : 0;
		}

		if ( $competitor_link_id <= 0 || $monitored_product_id <= 0 || $product_id <= 0 ) {
			return $result;
		}

		$this->repository->create_price_observation(
			array(
				'competitor_link_id'   => $competitor_link_id,
				'monitored_product_id' => $monitored_product_id,
				'product_id'           => $product_id,
				'observed_price'       => $result['success'] ? $result['price'] : null,
				'currency'             => $result['success'] ? $result['currency'] : null,
				'stock_status'         => $result['stock_status'] ?? null,
				'extraction_method'    => $result['extraction_method'],
				'http_status'          => $result['http_status'],
				'success'              => $result['success'] ? 1 : 0,
				'error_message'        => $result['success'] ? null : $result['error'],
				'response_time_ms'     => $result['response_time_ms'],
				'checked_at'           => $checked_at,
			)
		);

		return $result;
	}

	/**
	 * @param array<string, mixed> $competitor_link Competitor link row.
	 * @return array<string, mixed>|null
	 */
	private function get_profile_for_link( array $competitor_link ): ?array {
		if ( ! $this->repository || empty( $competitor_link['competitor_id'] ) ) {
			return null;
		}

		return $this->repository->get_competitor( (int) $competitor_link['competitor_id'] );
	}

	/**
	 * @param array<string, mixed>|null $profile Competitor profile row.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 * @return array<string, mixed>
	 */
	private function get_parser_rules( ?array $profile, array $settings ): array {
		if ( ! $profile ) {
			return array(
				'default_currency' => (string) ( $settings['default_currency'] ?? 'NOK' ),
			);
		}

		$profile['default_currency'] = (string) ( $profile['default_currency'] ?? ( $settings['default_currency'] ?? 'NOK' ) );

		return $profile;
	}

	private function elapsed_ms( float $started_at ): int {
		return max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
	}

	/**
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, stock_status: string, error: string, http_status: int, response_time_ms: int}
	 */
	private function failure( string $error, int $http_status, int $response_time_ms, string $extraction_method = '' ): array {
		return array(
			'success'           => false,
			'price'             => null,
			'currency'          => '',
			'extraction_method' => $extraction_method,
			'stock_status'      => '',
			'error'             => '' !== $error ? $error : __( 'Could not detect a competitor price.', 'lilleprinsen-price-monitor' ),
			'http_status'       => $http_status,
			'response_time_ms'  => $response_time_ms,
		);
	}
}
