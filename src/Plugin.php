<?php
/**
 * Main admin-only plugin coordinator.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor;

use Lilleprinsen\PriceMonitor\Admin\AdminMenu;
use Lilleprinsen\PriceMonitor\Admin\AdminNoticeStore;
use Lilleprinsen\PriceMonitor\Admin\AdminPage;
use Lilleprinsen\PriceMonitor\Admin\Notices;
use Lilleprinsen\PriceMonitor\Admin\ProductSearchService;
use Lilleprinsen\PriceMonitor\Assets\AdminAssets;
use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Database\Schema;
use Lilleprinsen\PriceMonitor\Jobs\CheckCompetitorLinkJob;
use Lilleprinsen\PriceMonitor\Jobs\JobScheduler;
use Lilleprinsen\PriceMonitor\Notifications\LogNotificationChannel;
use Lilleprinsen\PriceMonitor\Notifications\NotificationService;
use Lilleprinsen\PriceMonitor\Service\PriceCheckService;
use Lilleprinsen\PriceMonitor\Service\PriceRecoveryService;
use Lilleprinsen\PriceMonitor\Service\PriceUpdateService;
use Lilleprinsen\PriceMonitor\Service\PricingRuleService;
use Lilleprinsen\PriceMonitor\Service\SuggestionService;
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

		$settings             = new Settings();
		$repository           = new Repository();
		$price_recovery       = new PriceRecoveryService();
		$pricing_rules        = new PricingRuleService();
		$price_check          = new PriceCheckService( null, $repository );
		$suggestion_service   = new SuggestionService( $repository, $price_recovery, $pricing_rules );
		$notification_service = new NotificationService( array( new LogNotificationChannel( $repository ) ) );
		$price_update         = new PriceUpdateService( $repository, $price_recovery );
		$check_job            = new CheckCompetitorLinkJob( $repository, $settings, $price_check, $suggestion_service, $notification_service );
		$job_scheduler        = new JobScheduler( $settings, $check_job, $repository );
		$product_search       = new ProductSearchService( $repository );
		$notice_store         = new AdminNoticeStore();
		$admin_page           = new AdminPage( $repository, $settings, $price_check, $price_recovery, $suggestion_service, $notification_service, $job_scheduler, $price_update, $product_search, $notice_store );

		add_action( 'admin_init', array( Schema::class, 'maybe_upgrade' ) );
		add_action( 'admin_init', array( $settings, 'handle_settings_save' ) );
		add_action( 'admin_init', array( $admin_page, 'handle_actions' ) );
		add_action( 'admin_menu', array( new AdminMenu( $admin_page ), 'register' ) );
		add_action( 'admin_notices', array( new Notices(), 'render' ) );
		add_action( 'admin_enqueue_scripts', array( new AdminAssets(), 'enqueue' ) );
		$job_scheduler->register();
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
