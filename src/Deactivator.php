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
		// No destructive deactivation cleanup is needed for the current custom tables.
	}
}
