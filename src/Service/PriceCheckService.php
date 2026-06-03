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
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, error: string, http_status: int, response_time_ms: int}
	 */
	public function test_check( array $competitor_link, array $settings ): array {
		$checked_at = current_time( 'mysql' );
		$started_at = microtime( true );
		$url        = esc_url_raw( (string) ( $competitor_link['competitor_url'] ?? '' ) );

		if ( '' === $url || ! function_exists( 'wp_remote_get' ) ) {
			return $this->record_observation(
				$competitor_link,
				$this->failure( __( 'WordPress HTTP is unavailable or the competitor URL is invalid.', 'lilleprinsen-price-monitor' ), 0, $this->elapsed_ms( $started_at ) ),
				$checked_at
			);
		}

		$timeout = isset( $settings['request_timeout_seconds'] ) ? absint( $settings['request_timeout_seconds'] ) : 8;
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
			return $this->record_observation(
				$competitor_link,
				$this->failure( $response->get_error_message(), 0, $this->elapsed_ms( $started_at ) ),
				$checked_at
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$response_time_ms = $this->elapsed_ms( $started_at );

		if ( 200 !== $status ) {
			return $this->record_observation(
				$competitor_link,
				$this->failure(
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'Competitor page returned HTTP %d.', 'lilleprinsen-price-monitor' ),
						$status
					),
					$status,
					$response_time_ms
				),
				$checked_at
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$parsed = $this->parser->parse( $body );

		if ( empty( $parsed['success'] ) || null === $parsed['price'] ) {
			return $this->record_observation(
				$competitor_link,
				$this->failure( $parsed['error'], $status, $response_time_ms ),
				$checked_at
			);
		}

		return $this->record_observation(
			$competitor_link,
			array(
				'success'           => true,
				'price'             => (float) $parsed['price'],
				'currency'          => (string) $parsed['currency'],
				'extraction_method' => (string) $parsed['extraction_method'],
				'error'             => '',
				'http_status'       => $status,
				'response_time_ms'  => $response_time_ms,
			),
			$checked_at
		);
	}

	/**
	 * @param array<string, mixed> $competitor_link Competitor link row.
	 * @param array{success: bool, price: float|null, currency: string, extraction_method: string, error: string, http_status: int, response_time_ms: int} $result Check result.
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, error: string, http_status: int, response_time_ms: int}
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
				'stock_status'         => $competitor_link['last_stock_status'] ?? null,
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

	private function elapsed_ms( float $started_at ): int {
		return max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
	}

	/**
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, error: string, http_status: int, response_time_ms: int}
	 */
	private function failure( string $error, int $http_status, int $response_time_ms ): array {
		return array(
			'success'           => false,
			'price'             => null,
			'currency'          => '',
			'extraction_method' => '',
			'error'             => '' !== $error ? $error : __( 'Could not detect a competitor price.', 'lilleprinsen-price-monitor' ),
			'http_status'       => $http_status,
			'response_time_ms'  => $response_time_ms,
		);
	}
}
