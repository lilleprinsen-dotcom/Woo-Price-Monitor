<?php
/**
 * Notification dispatcher.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NotificationService {
	/**
	 * @var array<int, NotificationInterface>
	 */
	private array $channels;

	/**
	 * @param array<int, NotificationInterface> $channels Enabled channels.
	 */
	public function __construct( array $channels = array() ) {
		$this->channels = $channels;
	}

	/**
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 * @param array<string, mixed> $context Notification context.
	 */
	public function send( string $event, string $message, array $settings, array $context = array(), ?int $product_id = null, bool $force = false ): bool {
		if ( ! $force && empty( $settings['notifications_enabled'] ) ) {
			return false;
		}

		$sent = false;

		foreach ( $this->channels as $channel ) {
			$sent = $channel->send( $event, $message, $context, $product_id ) || $sent;
		}

		return $sent;
	}
}
