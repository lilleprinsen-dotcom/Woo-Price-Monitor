<?php
/**
 * Admin asset loading.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Assets;

use Lilleprinsen\PriceMonitor\Admin\AdminPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminAssets {
	public function enqueue( string $hook_suffix ): void {
		unset( $hook_suffix );

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( AdminPage::SLUG !== $page ) {
			return;
		}

		wp_enqueue_style(
			'lpm-admin',
			LPM_PLUGIN_URL . 'assets/admin.css',
			array(),
			LPM_VERSION
		);

		wp_enqueue_script(
			'lpm-admin',
			LPM_PLUGIN_URL . 'assets/admin.js',
			array(),
			LPM_VERSION,
			true
		);
	}
}
