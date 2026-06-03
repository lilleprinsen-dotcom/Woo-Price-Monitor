<?php
/**
 * Log-backed notification channel.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Notifications;

use Lilleprinsen\PriceMonitor\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LogNotificationChannel implements NotificationInterface {
	private Repository $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * @param array<string, mixed> $context Notification context.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 */
	public function send( string $event, string $message, array $context = array(), ?int $product_id = null, array $settings = array() ): bool {
		if ( ! $this->should_send( $event, $context, $settings ) ) {
			return false;
		}

		$context['channel'] = 'log';
		$context['note']    = __( 'Notification event logged for debugging/audit. Direct WhatsApp is not implemented.', 'lilleprinsen-price-monitor' );

		return $this->repository->write_log(
			'info',
			'notification_' . sanitize_key( $event ),
			$message,
			$context,
			$product_id
		);
	}

	/**
	 * @param array<string, mixed> $context Notification context.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 */
	private function should_send( string $event, array $context, array $settings ): bool {
		if ( in_array( $event, array( 'test', 'webhook_test' ), true ) ) {
			return true;
		}

		if ( 'failed_check' === $event ) {
			return ! empty( $settings['notify_on_failed_check'] );
		}

		if ( str_starts_with( $event, 'price_suggestion_' ) ) {
			if ( 'blocked' === (string) ( $context['status'] ?? '' ) || 'price_suggestion_blocked' === $event ) {
				return ! empty( $settings['notify_on_blocked_suggestion'] );
			}

			return ! empty( $settings['notify_on_new_suggestion'] );
		}

		return true;
	}
}
