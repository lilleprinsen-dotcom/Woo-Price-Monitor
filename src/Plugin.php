<?php
/**
 * Main admin-only plugin coordinator.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor;

use Lilleprinsen\PriceMonitor\Admin\AdminMenu;
use Lilleprinsen\PriceMonitor\Admin\AdminPage;
use Lilleprinsen\PriceMonitor\Admin\Notices;
use Lilleprinsen\PriceMonitor\Assets\AdminAssets;
use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private static ?self $instance = null;

	private bool $initialized = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		$settings   = new Settings();
		$repository = new Repository();
		$admin_page = new AdminPage( $repository, $settings );

		add_action( 'admin_init', array( $settings, 'handle_settings_save' ) );
		add_action( 'admin_menu', array( new AdminMenu( $admin_page ), 'register' ) );
		add_action( 'admin_notices', array( new Notices(), 'render' ) );
		add_action( 'admin_enqueue_scripts', array( new AdminAssets(), 'enqueue' ) );
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	public static function required_capability(): string {
		return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	public static function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	private function __construct() {}
}
