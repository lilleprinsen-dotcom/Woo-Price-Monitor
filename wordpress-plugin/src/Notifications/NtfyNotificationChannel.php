<?php
/**
 * ntfy push notification channel for iPhone approval actions.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Notifications;

use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Service\ApprovalTokenService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NtfyNotificationChannel implements NotificationInterface {
	private Repository $repository;

	private NotificationMessageBuilder $builder;

	public function __construct( Repository $repository, ?NotificationMessageBuilder $builder = null, ?ApprovalTokenService $approval_tokens = null ) {
		$this->repository = $repository;
		$this->builder    = $builder ?? new NotificationMessageBuilder( $repository, null, $approval_tokens );
	}

	/**
	 * @param array<string, mixed> $context Notification context.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 */
	public function send( string $event, string $message, array $context = array(), ?int $product_id = null, array $settings = array() ): bool {
		if ( ! $this->should_send( $event, $context, $settings ) ) {
			return false;
		}

		$server_url = isset( $settings['ntfy_server_url'] ) ? esc_url_raw( (string) $settings['ntfy_server_url'] ) : '';
		$topic      = isset( $settings['ntfy_topic'] ) ? $this->sanitize_topic( (string) $settings['ntfy_topic'] ) : '';

		if ( '' === $server_url || '' === $topic || ! $this->is_valid_http_url( $server_url ) || ! function_exists( 'wp_remote_post' ) ) {
			$this->repository->write_log( 'warning', 'ntfy_notification_skipped', __( 'iPhone push notification skipped because the ntfy server URL or topic is missing or invalid.', 'lilleprinsen-price-monitor' ), array( 'event' => $event ), $product_id );
			return false;
		}

		$payload = $this->builder->build_payload( $event, $message, $context, $product_id, $settings );
		$body    = wp_json_encode( $this->build_ntfy_payload( $event, $message, $payload, $settings ) );

		if ( false === $body ) {
			$this->repository->write_log( 'error', 'ntfy_notification_failed', __( 'iPhone push notification payload could not be encoded.', 'lilleprinsen-price-monitor' ), array( 'event' => $event ), $product_id );
			return false;
		}

		$headers = array(
			'Content-Type' => 'application/json',
			'User-Agent'   => 'Lilleprinsen Price Monitor/' . ( defined( 'LPM_VERSION' ) ? LPM_VERSION : '0.1.0' ) . '; ntfy notification',
		);

		$token = isset( $settings['ntfy_access_token'] ) ? trim( (string) $settings['ntfy_access_token'] ) : '';
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$timeout = isset( $settings['request_timeout_seconds'] ) ? absint( $settings['request_timeout_seconds'] ) : 8;
		$timeout = min( 30, max( 1, $timeout ) );
		$started = microtime( true );

		$response = wp_remote_post(
			$this->topic_endpoint( $server_url, $topic ),
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
				'ntfy_notification_failed',
				__( 'iPhone push notification failed.', 'lilleprinsen-price-monitor' ),
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
				'ntfy_notification_failed',
				__( 'iPhone push notification returned a non-success HTTP status.', 'lilleprinsen-price-monitor' ),
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
			'ntfy_notification_sent',
			__( 'iPhone push notification sent.', 'lilleprinsen-price-monitor' ),
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
		if ( ! empty( $context['force_ntfy_test'] ) && 'ntfy_test' === $event ) {
			return true;
		}

		if ( empty( $settings['ntfy_notifications_enabled'] ) ) {
			return false;
		}

		$context = $this->builder->hydrate_context( $context );

		if ( 'failed_check' === $event ) {
			return ! empty( $settings['ntfy_send_on_failed_check'] );
		}

		if ( str_starts_with( $event, 'price_suggestion_' ) ) {
			$status = (string) ( $context['status'] ?? '' );
			$type   = (string) ( $context['suggestion_type'] ?? '' );

			if ( $this->is_recovery_suggestion_type( $type ) ) {
				return ! empty( $settings['ntfy_send_on_recovery_suggestion'] );
			}

			if ( 'blocked' === $status || 'price_suggestion_blocked' === $event ) {
				return ! empty( $settings['ntfy_send_on_blocked_suggestion'] );
			}

			return ! empty( $settings['ntfy_send_on_new_suggestion'] );
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $payload Structured notification payload.
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return array<string, mixed>
	 */
	private function build_ntfy_payload( string $event, string $fallback_message, array $payload, array $settings ): array {
		$title = 'Lilleprinsen Price Monitor';
		if ( str_starts_with( $event, 'price_suggestion_' ) ) {
			$title = sprintf(
				/* translators: %s: product name. */
				__( 'Prisvarsel: %s', 'lilleprinsen-price-monitor' ),
				(string) ( $payload['product_name'] ?? __( 'Product', 'lilleprinsen-price-monitor' ) )
			);
		} elseif ( 'ntfy_test' === $event ) {
			$title = __( 'iPhone push test', 'lilleprinsen-price-monitor' );
		}

		$review_url = isset( $payload['review_url'] ) ? esc_url_raw( (string) $payload['review_url'] ) : '';
		$data = array(
			'title'    => $this->truncate_text( $title, 120 ),
			'message'  => $this->truncate_text( (string) ( $payload['message_text'] ?? $fallback_message ), 1800 ),
			'priority' => $this->sanitize_priority( (string) ( $settings['ntfy_priority'] ?? 'default' ) ),
			'tags'     => array( 'moneybag' ),
		);

		if ( '' !== $review_url ) {
			$data['click'] = $review_url;
		}

		$actions = $this->build_actions( $payload );
		if ( ! empty( $actions ) ) {
			$data['actions'] = $actions;
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $payload Structured notification payload.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_actions( array $payload ): array {
		$actions = array();

		foreach ( array(
			'action_match_price_url' => __( 'Match price', 'lilleprinsen-price-monitor' ),
			'action_match_price_minus_1_url' => __( 'Match -1', 'lilleprinsen-price-monitor' ),
			'action_reject_url'      => __( 'Reject', 'lilleprinsen-price-monitor' ),
		) as $key => $label ) {
			$url = isset( $payload[ $key ] ) ? esc_url_raw( (string) $payload[ $key ] ) : '';
			if ( '' === $url || ! $this->is_valid_http_url( $url ) ) {
				continue;
			}

			$actions[] = array(
				'action' => 'http',
				'label'  => $label,
				'url'    => $url,
				'method' => 'GET',
				'clear'  => true,
			);
		}

		return array_slice( $actions, 0, 3 );
	}

	private function topic_endpoint( string $server_url, string $topic ): string {
		return rtrim( $server_url, '/' ) . '/' . rawurlencode( $topic );
	}

	private function sanitize_priority( string $priority ): string {
		$priority = sanitize_key( $priority );

		return in_array( $priority, array( 'min', 'low', 'default', 'high', 'urgent' ), true ) ? $priority : 'default';
	}

	private function sanitize_topic( string $topic ): string {
		$topic = sanitize_text_field( $topic );
		$topic = preg_replace( '/[^A-Za-z0-9_.-]/', '', $topic );

		return is_string( $topic ) ? substr( trim( $topic ), 0, 120 ) : '';
	}

	private function truncate_text( string $text, int $limit ): string {
		$text = trim( wp_strip_all_tags( $text ) );

		if ( strlen( $text ) <= $limit ) {
			return $text;
		}

		return substr( $text, 0, max( 0, $limit - 3 ) ) . '...';
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
