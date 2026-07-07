<?php
/**
 * WooCommerce admin menu registration.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

use Lilleprinsen\PriceMonitor\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminMenu {
	private AdminPage $admin_page;

	public function __construct( AdminPage $admin_page ) {
		$this->admin_page = $admin_page;
	}

	public function register(): void {
		if ( ! Plugin::can_manage() ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'Lilleprinsen Price Monitor', 'lilleprinsen-price-monitor' ),
			__( 'Price Monitor', 'lilleprinsen-price-monitor' ),
			Plugin::required_capability(),
			AdminPage::SLUG,
			array( $this->admin_page, 'render' )
		);
	}
}
