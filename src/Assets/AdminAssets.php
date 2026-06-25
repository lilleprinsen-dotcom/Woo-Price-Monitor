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

		if ( 'lpm-competitor-prices' === $page ) {
			wp_enqueue_script(
				'lpm-discovery-admin',
				LPM_PLUGIN_URL . 'assets/discovery-admin.js',
				array(),
				LPM_VERSION,
				true
			);

			return;
		}

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

		wp_localize_script(
			'lpm-admin',
			'LPM_ADMIN',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lpm_admin_ajax' ),
				'i18n'    => array(
					'searching'      => __( 'Searching...', 'lilleprinsen-price-monitor' ),
					'noProducts'     => __( 'No products found.', 'lilleprinsen-price-monitor' ),
					'loading'        => __( 'Loading...', 'lilleprinsen-price-monitor' ),
					'error'          => __( 'Something went wrong.', 'lilleprinsen-price-monitor' ),
					'confirmReject'  => __( 'Reject this suggestion?', 'lilleprinsen-price-monitor' ),
					'confirmApprove' => __( 'Record dry-run approval? WooCommerce price will not be changed.', 'lilleprinsen-price-monitor' ),
				),
			)
		);
	}
}
