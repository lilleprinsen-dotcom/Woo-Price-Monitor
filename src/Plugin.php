<?php
/**
 * Main admin-only plugin coordinator.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor;

use Lilleprinsen\PriceMonitor\Admin\AdminMenu;
use Lilleprinsen\PriceMonitor\Admin\AdminAjaxController;
use Lilleprinsen\PriceMonitor\Admin\AdminNoticeStore;
use Lilleprinsen\PriceMonitor\Admin\AdminPage;
use Lilleprinsen\PriceMonitor\Admin\CsvImportService;
use Lilleprinsen\PriceMonitor\Admin\DiscoveryAdminPage;
use Lilleprinsen\PriceMonitor\Admin\DiscoveryProductAdmin;
use Lilleprinsen\PriceMonitor\Admin\Notices;
use Lilleprinsen\PriceMonitor\Admin\ProductSearchService;
use Lilleprinsen\PriceMonitor\Admin\TokenActionHandler;
use Lilleprinsen\PriceMonitor\Assets\AdminAssets;
use Lilleprinsen\PriceMonitor\Database\DiscoveryRepository;
use Lilleprinsen\PriceMonitor\Database\DiscoverySchema;
use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Database\Schema;
use Lilleprinsen\PriceMonitor\Jobs\CheckCompetitorLinkJob;
use Lilleprinsen\PriceMonitor\Jobs\CompetitorDiscoveryJob;
use Lilleprinsen\PriceMonitor\Jobs\JobScheduler;
use Lilleprinsen\PriceMonitor\Cli\Command as CliCommand;
use Lilleprinsen\PriceMonitor\Notifications\LogNotificationChannel;
use Lilleprinsen\PriceMonitor\Notifications\NotificationService;
use Lilleprinsen\PriceMonitor\Notifications\WebhookNotificationChannel;
use Lilleprinsen\PriceMonitor\Service\ApprovalTokenService;
use Lilleprinsen\PriceMonitor\Service\CompetitorProductExtractor;
use Lilleprinsen\PriceMonitor\Service\DiscoverySourceService;
use Lilleprinsen\PriceMonitor\Service\DiscoveryUrlService;
use Lilleprinsen\PriceMonitor\Service\GroupSuggestionService;
use Lilleprinsen\PriceMonitor\Service\MatchSuggestionService;
use Lilleprinsen\PriceMonitor\Service\PriceCheckService;
use Lilleprinsen\PriceMonitor\Service\PriceRecoveryService;
use Lilleprinsen\PriceMonitor\Service\PriceUpdateService;
use Lilleprinsen\PriceMonitor\Service\PricingRuleService;
use Lilleprinsen\PriceMonitor\Service\ProductIdentifierService;
use Lilleprinsen\PriceMonitor\Service\RetentionService;
use Lilleprinsen\PriceMonitor\Service\SkuSearchDiscoveryService;
use Lilleprinsen\PriceMonitor\Service\SuggestionService;
use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;
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
		$discovery_settings   = new DiscoverySettings( $settings );
		$repository           = new Repository();
		$discovery_repository = new DiscoveryRepository();
		$price_recovery       = new PriceRecoveryService();
		$pricing_rules        = new PricingRuleService();
		$group_suggestions    = new GroupSuggestionService( $repository, $pricing_rules );
		$price_check          = new PriceCheckService( null, $repository );
		$approval_tokens      = new ApprovalTokenService( $repository );
		$suggestion_service   = new SuggestionService( $repository, $price_recovery, $pricing_rules, $group_suggestions );
		$notification_service = new NotificationService(
			array(
				new LogNotificationChannel( $repository ),
				new WebhookNotificationChannel( $repository, null, $approval_tokens ),
			)
		);
		$price_update         = new PriceUpdateService( $repository, $price_recovery, $group_suggestions );
		$check_job            = new CheckCompetitorLinkJob( $repository, $settings, $price_check, $suggestion_service, $notification_service );
		$job_scheduler        = new JobScheduler( $settings, $check_job, $repository );
		$retention_service    = new RetentionService( $repository, $settings );
		$product_search       = new ProductSearchService( $repository );
		$notice_store         = new AdminNoticeStore();
		$csv_import           = new CsvImportService( $repository );
		$admin_page           = new AdminPage( $repository, $settings, $price_check, $price_recovery, $suggestion_service, $notification_service, $job_scheduler, $price_update, $product_search, $notice_store, $csv_import, $retention_service, $group_suggestions );
		$token_handler        = new TokenActionHandler( $repository, $settings, $approval_tokens );
		$ajax_controller      = new AdminAjaxController( $repository, $settings, $product_search, $price_check, $suggestion_service, $notification_service );
		$url_service          = new DiscoveryUrlService();
		$product_identifiers  = new ProductIdentifierService( $discovery_settings );
		$extractor            = new CompetitorProductExtractor( $url_service, $discovery_settings );
		$source_service       = new DiscoverySourceService( $url_service, $discovery_settings );
		$sku_search           = new SkuSearchDiscoveryService( $url_service, $source_service, $discovery_settings );
		$match_suggestions    = new MatchSuggestionService( $discovery_repository );
		$discovery_job        = new CompetitorDiscoveryJob( $repository, $discovery_repository, $discovery_settings, $extractor, $match_suggestions, $source_service, $sku_search, $url_service );
		$discovery_admin      = new DiscoveryAdminPage( $repository, $discovery_repository, $discovery_settings, $product_identifiers, $extractor, $match_suggestions, $discovery_job, $url_service );
		$discovery_products   = new DiscoveryProductAdmin( $repository, $discovery_repository, $product_identifiers, $extractor );

		$this->maybe_upgrade_schema_for_non_admin_runtime();

		add_action( 'admin_init', array( Schema::class, 'maybe_upgrade' ) );
		add_action( 'admin_init', array( DiscoverySchema::class, 'maybe_upgrade' ) );
		add_action( 'admin_init', array( $settings, 'handle_settings_save' ) );
		add_action( 'admin_init', array( $admin_page, 'handle_actions' ) );
		add_action( 'admin_init', array( $discovery_admin, 'handle_actions' ) );
		add_action( 'admin_menu', array( new AdminMenu( $admin_page ), 'register' ) );
		add_action( 'admin_menu', array( $discovery_admin, 'register_menu' ) );
		add_action( 'admin_notices', array( new Notices(), 'render' ) );
		add_action( 'admin_enqueue_scripts', array( new AdminAssets(), 'enqueue' ) );
		add_action( 'admin_post_lpm_token_action', array( $token_handler, 'handle' ) );
		add_action( 'admin_post_nopriv_lpm_token_action', array( $token_handler, 'handle' ) );
		$ajax_controller->register();
		$job_scheduler->register();
		$discovery_job->register();
		$discovery_products->register();
		$this->register_cli( $settings, $repository, $check_job, $retention_service );
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

	private function register_cli( Settings $settings, Repository $repository, CheckCompetitorLinkJob $check_job, RetentionService $retention_service ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'lpm', new CliCommand( $settings, $repository, $check_job, $retention_service ) );
	}

	private function maybe_upgrade_schema_for_non_admin_runtime(): void {
		$is_cli  = defined( 'WP_CLI' ) && WP_CLI;
		$is_cron = function_exists( 'wp_doing_cron' ) && wp_doing_cron();

		if ( ! $is_cli && ! $is_cron ) {
			return;
		}

		Schema::maybe_upgrade();
		DiscoverySchema::maybe_upgrade();
	}

	private function __construct() {}
}
