<?php
/**
 * Plugin deactivation tasks.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor;

use Lilleprinsen\PriceMonitor\Jobs\CompetitorDiscoveryJob;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivator {
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( CompetitorDiscoveryJob::ACTION, array(), 'lilleprinsen-price-monitor' );
		}
	}
}
