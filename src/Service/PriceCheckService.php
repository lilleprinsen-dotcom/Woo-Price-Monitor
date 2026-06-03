<?php
/**
 * Manual competitor price check service.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PriceCheckService {
	private PriceParser $parser;

	public function __construct( ?PriceParser $parser = null ) {
		$this->parser = $parser ?? new PriceParser();
	}

	/**
	 * @param array<string, mixed> $competitor_link Competitor link row.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, error: string, http_status: int}
	 */
	public function test_check( array $competitor_link, array $settings ): array {
		$url = esc_url_raw( (string) ( $competitor_link['competitor_url'] ?? '' ) );

		if ( '' === $url || ! function_exists( 'wp_remote_get' ) ) {
			return $this->failure( __( 'WordPress HTTP is unavailable or the competitor URL is invalid.', 'lilleprinsen-price-monitor' ), 0 );
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
			return $this->failure( $response->get_error_message(), 0 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return $this->failure(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Competitor page returned HTTP %d.', 'lilleprinsen-price-monitor' ),
					$status
				),
				$status
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$parsed = $this->parser->parse( $body );

		if ( null === $parsed['price'] ) {
			return $this->failure( $parsed['error'], $status );
		}

		return array(
			'success'           => true,
			'price'             => (float) $parsed['price'],
			'currency'          => $parsed['currency'],
			'extraction_method' => $parsed['extraction_method'],
			'error'             => '',
			'http_status'       => $status,
		);
	}

	/**
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, error: string, http_status: int}
	 */
	private function failure( string $error, int $http_status ): array {
		return array(
			'success'           => false,
			'price'             => null,
			'currency'          => '',
			'extraction_method' => '',
			'error'             => '' !== $error ? $error : __( 'Could not detect a competitor price.', 'lilleprinsen-price-monitor' ),
			'http_status'       => $http_status,
		);
	}
}
