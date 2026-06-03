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
	 */
	public function send( string $event, string $message, array $context = array(), ?int $product_id = null ): bool {
		$context['channel'] = 'log';
		$context['note']    = __( 'WhatsApp is not connected yet. This notification was logged only.', 'lilleprinsen-price-monitor' );

		return $this->repository->write_log(
			'info',
			'notification_' . sanitize_key( $event ),
			$message,
			$context,
			$product_id
		);
	}
}
