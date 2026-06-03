<?php
/**
 * Webhook notification channel for Make, Zapier, and similar providers.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Notifications;

use Lilleprinsen\PriceMonitor\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WebhookNotificationChannel implements NotificationInterface {
	private Repository $repository;

	private NotificationMessageBuilder $builder;

	public function __construct( Repository $repository, ?NotificationMessageBuilder $builder = null ) {
		$this->repository = $repository;
		$this->builder    = $builder ?? new NotificationMessageBuilder( $repository );
	}

	/**
	 * @param array<string, mixed> $context Notification context.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 */
	public function send( string $event, string $message, array $context = array(), ?int $product_id = null, array $settings = array() ): bool {
		if ( ! $this->should_send( $event, $context, $settings ) ) {
			return false;
		}

		$url = isset( $settings['webhook_url'] ) ? esc_url_raw( (string) $settings['webhook_url'] ) : '';

		if ( '' === $url || ! $this->is_valid_http_url( $url ) || ! function_exists( 'wp_remote_post' ) ) {
			$this->repository->write_log( 'warning', 'webhook_notification_skipped', __( 'Webhook notification skipped because the webhook URL is missing or invalid.', 'lilleprinsen-price-monitor' ), array( 'event' => $event ), $product_id );
			return false;
		}

		$payload = $this->builder->build_payload( $event, $message, $context, $product_id );
		$body    = wp_json_encode( $payload );

		if ( false === $body ) {
			$this->repository->write_log( 'error', 'webhook_notification_failed', __( 'Webhook notification payload could not be encoded.', 'lilleprinsen-price-monitor' ), array( 'event' => $event ), $product_id );
			return false;
		}

		$headers = array(
			'Content-Type' => 'application/json',
			'User-Agent'   => 'Lilleprinsen Price Monitor/' . ( defined( 'LPM_VERSION' ) ? LPM_VERSION : '0.1.0' ) . '; webhook notification',
		);

		$secret = isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '';

		if ( '' !== $secret ) {
			$headers['X-LPM-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
			$headers['X-LPM-Signature-Algorithm'] = 'HMAC-SHA256';
		}

		$timeout = isset( $settings['request_timeout_seconds'] ) ? absint( $settings['request_timeout_seconds'] ) : 8;
		$timeout = min( 30, max( 1, $timeout ) );
		$started = microtime( true );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'             => $timeout,
				'redirection'         => 2,
				'limit_response_size' => 65536,
				'reject_unsafe_urls'  => true,
				'headers'             => $headers,
				'body'                => $body,
			)
		);

		$response_time_ms = max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) );

		if ( is_wp_error( $response ) ) {
			$this->repository->write_log(
				'error',
				'webhook_notification_failed',
				__( 'Webhook notification failed.', 'lilleprinsen-price-monitor' ),
				array(
					'event'            => $event,
					'error'            => $response->get_error_message(),
					'response_time_ms' => $response_time_ms,
				),
				$product_id
			);
			return false;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			$this->repository->write_log(
				'error',
				'webhook_notification_failed',
				__( 'Webhook notification returned a non-success HTTP status.', 'lilleprinsen-price-monitor' ),
				array(
					'event'            => $event,
					'http_status'      => $status,
					'response_time_ms' => $response_time_ms,
				),
				$product_id
			);
			return false;
		}

		$this->repository->write_log(
			'info',
			'webhook_notification_sent',
			__( 'Webhook notification sent.', 'lilleprinsen-price-monitor' ),
			array(
				'event'            => $event,
				'http_status'      => $status,
				'response_time_ms' => $response_time_ms,
			),
			$product_id
		);

		return true;
	}

	/**
	 * @param array<string, mixed> $context Notification context.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 */
	private function should_send( string $event, array $context, array $settings ): bool {
		if ( ! empty( $context['force_webhook_test'] ) && 'webhook_test' === $event ) {
			return true;
		}

		if ( empty( $settings['webhook_notifications_enabled'] ) ) {
			return false;
		}

		$context = $this->builder->hydrate_context( $context );

		if ( 'failed_check' === $event ) {
			return ! empty( $settings['webhook_send_on_failed_check'] );
		}

		if ( str_starts_with( $event, 'price_suggestion_' ) ) {
			$status = (string) ( $context['status'] ?? '' );
			$type   = (string) ( $context['suggestion_type'] ?? '' );

			if ( $this->is_recovery_suggestion_type( $type ) ) {
				return ! empty( $settings['webhook_send_on_recovery_suggestion'] );
			}

			if ( 'blocked' === $status || 'price_suggestion_blocked' === $event ) {
				return ! empty( $settings['webhook_send_on_blocked_suggestion'] );
			}

			return ! empty( $settings['webhook_send_on_new_suggestion'] );
		}

		return false;
	}

	private function is_recovery_suggestion_type( string $type ): bool {
		return in_array(
			$type,
			array(
				'price_match_up',
				'restore_previous_active_price',
				'restore_previous_regular_price',
				'restore_previous_sale_price',
			),
			true
		);
	}

	private function is_valid_http_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& ! empty( $parts['host'] )
			&& ! empty( $parts['scheme'] )
			&& in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true );
	}
}
