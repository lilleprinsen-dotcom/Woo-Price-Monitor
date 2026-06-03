<?php
/**
 * Plugin deactivation tasks.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivator {
	public static function deactivate(): void {
		// No scheduled jobs or frontend hooks are registered in this foundation version.
	}
}
