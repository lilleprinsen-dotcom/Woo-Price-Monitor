<?php
/**
 * Plugin Name: Lilleprinsen Price Monitor
 * Plugin URI: https://github.com/lilleprinsen-dotcom/Woo-Price-Monitor
 * Description: Admin-only WooCommerce competitor price monitoring foundation.
 * Version: 0.1.0
 * Requires PHP: 8.0
 * Author: Lilleprinsen
 * Text Domain: lilleprinsen-price-monitor
 *
 * @package LilleprinsenPriceMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'LPM_VERSION' ) ) {
	define( 'LPM_VERSION', '0.1.0' );
}

if ( ! defined( 'LPM_PLUGIN_FILE' ) ) {
	define( 'LPM_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'LPM_PLUGIN_DIR' ) ) {
	define( 'LPM_PLUGIN_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) );
}

if ( ! defined( 'LPM_PLUGIN_URL' ) ) {
	define( 'LPM_PLUGIN_URL', trailingslashit( plugin_dir_url( __FILE__ ) ) );
}

if ( ! defined( 'LPM_PLUGIN_BASENAME' ) ) {
	define( 'LPM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Lilleprinsen\\PriceMonitor\\';

		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$file           = LPM_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		Lilleprinsen\PriceMonitor\Activator::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		Lilleprinsen\PriceMonitor\Deactivator::deactivate();
	}
);

if ( function_exists( 'is_admin' ) && is_admin() ) {
	add_action(
		'plugins_loaded',
		static function (): void {
			Lilleprinsen\PriceMonitor\Plugin::instance()->init();
		}
	);
}
