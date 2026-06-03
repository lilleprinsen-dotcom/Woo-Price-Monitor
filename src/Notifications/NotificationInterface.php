<?php
/**
 * Notification channel contract.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface NotificationInterface {
	/**
	 * @param array<string, mixed> $context Notification context.
	 */
	public function send( string $event, string $message, array $context = array(), ?int $product_id = null ): bool;
}
