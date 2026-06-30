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
			$discovery_script_path = defined( 'LPM_PLUGIN_FILE' ) ? plugin_dir_path( LPM_PLUGIN_FILE ) . 'assets/discovery-admin.js' : '';
			$discovery_script_version = is_readable( $discovery_script_path ) ? (string) filemtime( $discovery_script_path ) : LPM_VERSION;

			wp_enqueue_script(
				'lpm-discovery-admin',
				LPM_PLUGIN_URL . 'assets/discovery-admin.js',
				array(),
				$discovery_script_version,
				true
			);

			wp_localize_script(
				'lpm-discovery-admin',
				'LPM_DISCOVERY',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'lpm_discovery_ajax' ),
					'i18n'    => array(
						'starting'       => __( 'Starting discovery...', 'lilleprinsen-price-monitor' ),
						'processing'     => __( 'Searching selected products...', 'lilleprinsen-price-monitor' ),
						'complete'       => __( 'Manual discovery complete.', 'lilleprinsen-price-monitor' ),
						'largeRun'       => __( 'This run has many product/competitor checks. It will process in small batches and may take a few minutes.', 'lilleprinsen-price-monitor' ),
						'activeLink'     => __( 'Active monitored link', 'lilleprinsen-price-monitor' ),
						'cancelled'      => __( 'Manual discovery cancelled.', 'lilleprinsen-price-monitor' ),
						'error'          => __( 'Manual discovery failed. See Details for more information.', 'lilleprinsen-price-monitor' ),
						'confirmLarge'   => __( 'This will search only selected products, but it may take a few minutes. Continue?', 'lilleprinsen-price-monitor' ),
					),
				)
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
