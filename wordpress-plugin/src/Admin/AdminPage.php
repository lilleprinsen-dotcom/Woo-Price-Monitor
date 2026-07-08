<?php
/**
 * Admin page renderer and action controller.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Database\DiscoveryRepository;
use Lilleprinsen\PriceMonitor\Database\Schema;
use Lilleprinsen\PriceMonitor\Jobs\JobScheduler;
use Lilleprinsen\PriceMonitor\Notifications\NotificationService;
use Lilleprinsen\PriceMonitor\Notifications\LogNotificationChannel;
use Lilleprinsen\PriceMonitor\Notifications\WebhookNotificationChannel;
use Lilleprinsen\PriceMonitor\Plugin;
use Lilleprinsen\PriceMonitor\Service\GroupSuggestionService;
use Lilleprinsen\PriceMonitor\Service\CompetitorPlatformDetector;
use Lilleprinsen\PriceMonitor\Service\CompetitorStrategyService;
use Lilleprinsen\PriceMonitor\Service\PriceCheckService;
use Lilleprinsen\PriceMonitor\Service\PriceRecoveryService;
use Lilleprinsen\PriceMonitor\Service\PriceUpdateService;
use Lilleprinsen\PriceMonitor\Service\ProductIdentifierService;
use Lilleprinsen\PriceMonitor\Service\RetentionService;
use Lilleprinsen\PriceMonitor\Service\SuggestionService;
use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;
use Lilleprinsen\PriceMonitor\Settings\Settings;
use Lilleprinsen\PriceMonitor\Admin\Tabs\DashboardTab;
use Lilleprinsen\PriceMonitor\Admin\Tabs\LogsTab;
use Lilleprinsen\PriceMonitor\Admin\Tabs\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminPage {
	public const SLUG = 'lilleprinsen-price-monitor';

	private const IMPORT_TRANSIENT_PREFIX = 'lpm_csv_import_preview_';

	private const EXPORT_MAX_ROWS = 1000;

	private const BULK_MAX_IDS = 100;

	private Repository $repository;

	private Settings $settings;

	private PriceCheckService $price_check_service;

	private PriceRecoveryService $price_recovery_service;

	private SuggestionService $suggestion_service;

	private NotificationService $notification_service;

	private JobScheduler $job_scheduler;

	private PriceUpdateService $price_update_service;

	private GroupSuggestionService $group_suggestion_service;

	private ProductSearchService $product_search_service;

	private AdminNoticeStore $notice_store;

	private CsvImportService $csv_import_service;

	private RetentionService $retention_service;

	private AdminActionHandler $action_handler;

	private DashboardTab $dashboard_tab;

	private CompetitorStrategyService $competitor_strategy_service;

	private SettingsTab $settings_tab;

	private LogsTab $logs_tab;

	private ?DiscoveryAdminPage $discovery_admin_page = null;

	private DiscoveryRepository $discovery_repository;

	private ProductIdentifierService $product_identifier_service;

	public function __construct( Repository $repository, Settings $settings, ?PriceCheckService $price_check_service = null, ?PriceRecoveryService $price_recovery_service = null, ?SuggestionService $suggestion_service = null, ?NotificationService $notification_service = null, ?JobScheduler $job_scheduler = null, ?PriceUpdateService $price_update_service = null, ?ProductSearchService $product_search_service = null, ?AdminNoticeStore $notice_store = null, ?CsvImportService $csv_import_service = null, ?RetentionService $retention_service = null, ?GroupSuggestionService $group_suggestion_service = null, ?CompetitorStrategyService $competitor_strategy_service = null ) {
		$this->repository             = $repository;
		$this->settings               = $settings;
		$this->price_check_service    = $price_check_service ?? new PriceCheckService( null, $repository );
		$this->price_recovery_service = $price_recovery_service ?? new PriceRecoveryService();
		$this->group_suggestion_service = $group_suggestion_service ?? new GroupSuggestionService( $repository );
		$this->suggestion_service     = $suggestion_service ?? new SuggestionService( $repository, $this->price_recovery_service, null, $this->group_suggestion_service );
		$this->notification_service   = $notification_service ?? new NotificationService( array( new LogNotificationChannel( $repository ), new WebhookNotificationChannel( $repository ) ) );
		$this->job_scheduler          = $job_scheduler ?? new JobScheduler( $settings, new \Lilleprinsen\PriceMonitor\Jobs\CheckCompetitorLinkJob( $repository, $settings, $this->price_check_service, $this->suggestion_service, $this->notification_service ), $repository );
		$this->price_update_service   = $price_update_service ?? new PriceUpdateService( $repository, $this->price_recovery_service, $this->group_suggestion_service );
		$this->product_search_service = $product_search_service ?? new ProductSearchService( $repository );
		$this->notice_store           = $notice_store ?? new AdminNoticeStore();
		$this->csv_import_service     = $csv_import_service ?? new CsvImportService( $repository );
		$this->retention_service      = $retention_service ?? new RetentionService( $repository, $settings );
		$this->action_handler         = new AdminActionHandler( $this );
		$this->dashboard_tab          = new DashboardTab();
		$this->competitor_strategy_service = $competitor_strategy_service ?? new CompetitorStrategyService();
		$this->settings_tab           = new SettingsTab();
		$this->logs_tab               = new LogsTab( $repository, $settings );
		$discovery_settings           = new DiscoverySettings( $settings );
		$this->discovery_repository   = new DiscoveryRepository();
		$this->product_identifier_service = new ProductIdentifierService( $discovery_settings );
	}

	public function set_discovery_admin_page( DiscoveryAdminPage $discovery_admin_page ): void {
		$this->discovery_admin_page = $discovery_admin_page;
	}

	public function handle_actions(): void {
		$this->action_handler->handle();
	}

	public function render(): void {
		if ( ! Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access Lilleprinsen Price Monitor.', 'lilleprinsen-price-monitor' ) );
		}

		$tabs               = $this->get_tabs();
		$active_tab         = $this->get_active_tab( $tabs );
		$settings           = $this->settings->get_all();
		$counts             = $this->get_empty_dashboard_counts();
		$table_status       = $this->get_empty_table_status();
		$competitor_strategy = $this->get_empty_competitor_strategy();
		$woocommerce_active = Plugin::is_woocommerce_active();

		if ( 'dashboard' === $active_tab ) {
			$counts              = $this->repository->get_dashboard_counts();
			$table_status        = $this->repository->get_table_status();
			$competitor_strategy = $this->competitor_strategy_service->analyze(
				$this->repository->get_competitor_strategy_observations()
			);
		} elseif ( 'logs' === $active_tab ) {
			$table_status = $this->repository->get_table_status();
		}

		include LPM_PLUGIN_DIR . 'templates/admin/app-shell.php';
	}

	public function render_admin_notices(): void {
		$dynamic_notice = $this->notice_store->pull();
		$dynamic_shown  = false;

		if ( is_array( $dynamic_notice ) && ! empty( $dynamic_notice['message'] ) ) {
			printf(
				'<div class="lpm-notice lpm-notice-%1$s">%2$s</div>',
				esc_attr( in_array( (string) $dynamic_notice['type'], array( 'success', 'error', 'warning' ), true ) ? (string) $dynamic_notice['type'] : 'success' ),
				esc_html( (string) $dynamic_notice['message'] )
			);
			$dynamic_shown = true;
		}

		if ( $dynamic_shown ) {
			return;
		}

		$notice = isset( $_GET['lpm_notice'] ) ? sanitize_key( wp_unslash( $_GET['lpm_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$type    = isset( $_GET['lpm_notice_type'] ) ? sanitize_key( wp_unslash( $_GET['lpm_notice_type'] ) ) : 'success';
		$message = $this->get_notice_message( $notice );

		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="lpm-notice lpm-notice-%1$s">%2$s</div>',
			esc_attr( in_array( $type, array( 'success', 'error', 'warning' ), true ) ? $type : 'success' ),
			esc_html( $message )
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function get_tabs(): array {
		return array(
			'dashboard'   => __( 'Overview', 'lilleprinsen-price-monitor' ),
			'products'    => __( 'Products', 'lilleprinsen-price-monitor' ),
			'competitors' => __( 'Competitors', 'lilleprinsen-price-monitor' ),
			'approvals'   => __( 'Suggestions', 'lilleprinsen-price-monitor' ),
			'settings_logs' => __( 'Settings & Logs', 'lilleprinsen-price-monitor' ),
		);
	}

	/**
	 * @param array<string, string> $tabs Registered tabs.
	 */
	private function get_active_tab( array $tabs ): string {
		$requested_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$legacy_tabs   = array(
			'settings'      => 'settings_logs',
			'logs'          => 'settings_logs',
			'history'       => 'settings_logs',
			'import_export' => 'settings_logs',
			'groups'        => 'settings_logs',
		);

		if ( isset( $legacy_tabs[ $requested_tab ] ) ) {
			return $legacy_tabs[ $requested_tab ];
		}

		return array_key_exists( $requested_tab, $tabs ) ? $requested_tab : 'dashboard';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_empty_dashboard_counts(): array {
		return array(
			'monitored_products'          => 0,
			'active_competitor_links'     => 0,
			'pending_suggestions'         => 0,
			'blocked_suggestions'         => 0,
			'recovery_suggestions'        => 0,
			'recent_failed_checks'        => 0,
			'checks_last_24h'             => 0,
			'failed_checks_last_24h'      => 0,
			'last_successful_check_time'  => '',
			'failed_logs'                 => 0,
			'active_price_match_sessions' => 0,
			'competitor_health'           => array(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_empty_table_status(): array {
		return array(
			'schema_version'          => '',
			'expected_schema_version' => Schema::VERSION,
			'tables'                  => array(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_empty_competitor_strategy(): array {
		return array(
			'leader_window_hours' => 48,
			'campaign_days'       => 7,
			'rows_used'           => 0,
			'events_analyzed'     => 0,
			'competitors_analyzed' => 0,
			'leaders'             => 0,
			'followers'           => 0,
			'campaign_runners'    => 0,
			'competitors'         => array(),
		);
	}

	/**
	 * @param array<string, mixed> $counts Dashboard counts.
	 * @param array<string, mixed> $settings Current settings.
	 * @param array<string, mixed> $table_status Schema status.
	 */
	public function render_dashboard( array $counts, array $settings, array $table_status, bool $woocommerce_active, array $competitor_strategy = array() ): void {
		$this->dashboard_tab->render( $counts, $settings, $table_status, $woocommerce_active, $competitor_strategy );
	}

	public function render_manual_discovery_modal(): void {
		if ( ! $this->discovery_admin_page ) {
			return;
		}
		?>
		<div class="lpm-discovery-modal" data-lpm-discovery-modal hidden>
			<div class="lpm-discovery-modal-backdrop" data-lpm-close-discovery-modal></div>
			<div class="lpm-discovery-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="lpm-discovery-modal-title">
				<div class="lpm-discovery-modal-header">
					<div>
						<p class="lpm-drawer-kicker"><?php esc_html_e( 'Find matches', 'lilleprinsen-price-monitor' ); ?></p>
						<h2 id="lpm-discovery-modal-title"><?php esc_html_e( 'Searching competitor products', 'lilleprinsen-price-monitor' ); ?></h2>
						<p><?php esc_html_e( 'You can close this window and continue working. Found matches are saved in Suggestions until you approve or reject them.', 'lilleprinsen-price-monitor' ); ?></p>
					</div>
					<button type="button" class="button-link lpm-drawer-close" data-lpm-close-discovery-modal aria-label="<?php esc_attr_e( 'Close discovery results', 'lilleprinsen-price-monitor' ); ?>">×</button>
				</div>
				<div class="lpm-discovery-modal-body">
					<?php $this->discovery_admin_page->render_manual_discovery_panel(); ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_products(): void {
		$search_query   = $this->get_search_query();
		$search_results = '' !== $search_query ? $this->product_search_service->search( $search_query, 20 ) : array();
		$page           = $this->get_positive_query_arg( 'lpm_products_page', 1 );
		$per_page       = (int) $this->settings->get( 'rows_per_page', 25 );
		$rows           = $this->repository->get_monitored_products( $page, $per_page );
		$total          = $this->repository->count_monitored_products();
		$link_counts    = $this->repository->count_competitor_links_for_monitored_products( wp_list_pluck( $rows, 'id' ) );
		$pending_counts = $this->repository->count_pending_price_suggestions_for_monitored_products( wp_list_pluck( $rows, 'id' ) );
		$group_names    = $this->repository->get_group_names_for_monitored_products( wp_list_pluck( $rows, 'id' ) );
		$discovery_rows = $this->get_discovery_rows_for_monitored_rows( $rows );
		$match_counts   = $this->discovery_repository->get_pending_suggestion_counts_by_discovery_product_ids( wp_list_pluck( $discovery_rows, 'id' ) );
		$active_competitor_count = $this->repository->count_active_competitors();
		$editing_rules  = $this->get_editing_monitored_product_rules();
		?>
		<div class="lpm-products-layout">
			<section class="lpm-card">
				<div class="lpm-card-header">
					<h2><?php esc_html_e( 'Find product', 'lilleprinsen-price-monitor' ); ?></h2>
				</div>

				<p><?php esc_html_e( 'Only selected products are monitored. This keeps checks fast and safe even with large catalogs.', 'lilleprinsen-price-monitor' ); ?></p>

				<form method="get" class="lpm-inline-form" data-lpm-product-search-form>
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
					<input type="hidden" name="tab" value="products" />
					<label class="screen-reader-text" for="lpm-product-search"><?php esc_html_e( 'Search products', 'lilleprinsen-price-monitor' ); ?></label>
					<input id="lpm-product-search" type="search" name="lpm_product_search" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Product name, SKU or ID', 'lilleprinsen-price-monitor' ); ?>" autocomplete="off" data-lpm-product-search-input />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Search', 'lilleprinsen-price-monitor' ); ?></button>
				</form>

				<div data-lpm-product-search-status class="lpm-field-description"><?php esc_html_e( 'Type at least 3 characters, or enter a product ID.', 'lilleprinsen-price-monitor' ); ?></div>
				<div data-lpm-product-search-results>
					<?php if ( '' !== $search_query ) : ?>
						<?php $this->render_product_search_results( $search_results, $search_query ); ?>
					<?php endif; ?>
				</div>

				<details class="lpm-advanced-panel lpm-card-spaced">
					<summary><?php esc_html_e( 'Bulk add products', 'lilleprinsen-price-monitor' ); ?></summary>
					<form method="post" class="lpm-stacked-form">
						<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
						<input type="hidden" name="lpm_action" value="bulk_add_monitored_products" />
						<label class="lpm-field">
							<span><?php esc_html_e( 'SKU, product ID or EAN/GTIN', 'lilleprinsen-price-monitor' ); ?></span>
							<textarea name="product_identifiers" rows="4" placeholder="<?php esc_attr_e( 'One per line, or separated by comma', 'lilleprinsen-price-monitor' ); ?>"></textarea>
							<small><?php esc_html_e( 'Added products are also used for competitor match discovery. The full WooCommerce catalog is never scanned.', 'lilleprinsen-price-monitor' ); ?></small>
						</label>
						<button type="submit" class="button"><?php esc_html_e( 'Add selected products', 'lilleprinsen-price-monitor' ); ?></button>
					</form>
				</details>
			</section>
		</div>

		<?php if ( $editing_rules ) : ?>
			<?php $this->render_monitored_rules_editor( $editing_rules ); ?>
		<?php endif; ?>

		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<h2><?php esc_html_e( 'Existing monitored products', 'lilleprinsen-price-monitor' ); ?></h2>
				<span class="lpm-pill lpm-pill-muted"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</div>
			<details class="lpm-advanced-panel">
				<summary><?php esc_html_e( 'Bulk edit monitored products', 'lilleprinsen-price-monitor' ); ?></summary>
				<?php $this->render_monitored_bulk_controls(); ?>
			</details>
			<?php $this->render_monitored_products_table( $rows, $link_counts, $pending_counts, $group_names, $discovery_rows, $match_counts, $active_competitor_count ); ?>
			<?php $this->render_pagination( $total, $page, $per_page, 'lpm_products_page', array( 'tab' => 'products' ) ); ?>
			<?php if ( $this->get_positive_query_arg( 'lpm_auto_start_all_discovery', 0 ) > 0 && $active_competitor_count > 0 && $total > 0 ) : ?>
				<span hidden data-lpm-auto-start-all-discovery="1"></span>
			<?php endif; ?>
		</section>
		<?php
	}

	public function render_competitors(): void {
		$monitored_product_id = $this->get_positive_query_arg( 'monitored_product_id', 0 );

		if ( 0 >= $monitored_product_id ) {
			$this->render_competitor_profiles();
			return;
		}

		$monitored_product = $this->repository->get_monitored_product( $monitored_product_id );

		if ( ! $monitored_product ) {
			$this->render_empty_card( __( 'Monitored product not found', 'lilleprinsen-price-monitor' ), __( 'Choose a monitored product from the Products tab before managing competitor links.', 'lilleprinsen-price-monitor' ) );
			return;
		}

		$product       = $this->get_product( (int) $monitored_product['product_id'] );
		$links         = $this->repository->get_competitor_links_for_monitored_product( $monitored_product_id );
		$editing_link  = $this->get_editing_competitor_link( $monitored_product_id );
		$profiles      = $this->repository->get_competitor_profile_options();
		$recent_checks = $this->repository->get_price_observations( array( 'monitored_product_id' => $monitored_product_id ), 1, 5 );
		?>
		<div class="lpm-grid lpm-grid-two">
			<section class="lpm-card">
				<div class="lpm-card-header">
					<h2><?php esc_html_e( 'Selected product', 'lilleprinsen-price-monitor' ); ?></h2>
					<?php $this->render_status_pill( ! empty( $monitored_product['enabled'] ) ? __( 'Enabled', 'lilleprinsen-price-monitor' ) : __( 'Disabled', 'lilleprinsen-price-monitor' ), ! empty( $monitored_product['enabled'] ) ? 'ok' : 'muted' ); ?>
				</div>
				<?php $this->render_selected_product_summary( $monitored_product, $product ); ?>
			</section>

			<section class="lpm-card">
				<div class="lpm-card-header">
					<h2><?php echo esc_html( $editing_link ? __( 'Edit competitor link', 'lilleprinsen-price-monitor' ) : __( 'Add competitor link', 'lilleprinsen-price-monitor' ) ); ?></h2>
				</div>
				<?php $this->render_competitor_form( $monitored_product_id, $editing_link, $profiles ); ?>
			</section>
		</div>

		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<h2><?php esc_html_e( 'Competitor links', 'lilleprinsen-price-monitor' ); ?></h2>
				<span class="lpm-pill lpm-pill-muted"><?php echo esc_html( number_format_i18n( count( $links ) ) ); ?></span>
			</div>
			<?php $this->render_competitor_bulk_controls(); ?>
			<?php $this->render_competitor_links_table( $links, $monitored_product_id ); ?>
		</section>

		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<h2><?php esc_html_e( 'Recent checks', 'lilleprinsen-price-monitor' ); ?></h2>
				<span class="lpm-pill lpm-pill-muted"><?php esc_html_e( 'Latest 5', 'lilleprinsen-price-monitor' ); ?></span>
			</div>
			<?php $this->render_observations_table( $recent_checks ); ?>
		</section>
		<?php
	}

	public function render_approvals(): void {
		$view        = $this->get_approval_view();
		$page        = $this->get_positive_query_arg( 'lpm_approvals_page', 1 );
		$per_page    = (int) $this->settings->get( 'rows_per_page', 25 );
		$settings    = $this->settings->get_all();
		$filters     = array( 'view' => $view );
		$show_price_suggestions = ! in_array( $view, array( 'match_suggestions' ), true );
		$suggestions = $show_price_suggestions ? $this->repository->get_price_suggestions( $filters, $page, $per_page ) : array();
		$total       = $show_price_suggestions ? $this->repository->count_price_suggestions( $filters ) : 0;
		$counts      = $this->repository->get_suggestion_counts();
		$confirm_id  = $this->get_positive_query_arg( 'lpm_confirm_update', 0 );
		$highlight_id = $this->get_positive_query_arg( 'lpm_suggestion_id', 0 );
		?>
		<div class="lpm-grid lpm-grid-summary lpm-inbox-summary">
			<?php
			$this->render_summary_card( __( 'Pending', 'lilleprinsen-price-monitor' ), $counts['pending'], __( 'Ready for dry-run review', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Blocked', 'lilleprinsen-price-monitor' ), $counts['blocked'], __( 'Safety limits tripped', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Approved dry-run', 'lilleprinsen-price-monitor' ), $counts['approved_dry_run'], __( 'No price updates made', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Rejected', 'lilleprinsen-price-monitor' ), $counts['rejected'], __( 'Dismissed suggestions', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Recovery suggestions', 'lilleprinsen-price-monitor' ), $counts['recovery'], __( 'Price-up or restore plans', 'lilleprinsen-price-monitor' ) );
			?>
		</div>

		<?php
		if ( $confirm_id > 0 ) {
			$this->render_real_update_confirmation( $confirm_id, $settings );
		}
		?>

		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<div>
					<h2><?php esc_html_e( 'Pricing inbox', 'lilleprinsen-price-monitor' ); ?></h2>
					<p class="lpm-card-subtitle"><?php echo esc_html( $this->real_updates_enabled( $settings ) ? __( 'Review match suggestions and price suggestions here. Real updates require confirmation and only run for a single approved product.', 'lilleprinsen-price-monitor' ) : __( 'Review match suggestions and price suggestions here. Dry-run approvals do not update WooCommerce prices.', 'lilleprinsen-price-monitor' ) ); ?></p>
				</div>
				<?php $this->render_status_pill( $this->real_updates_enabled( $settings ) ? __( 'Real updates enabled', 'lilleprinsen-price-monitor' ) : __( 'Dry-run only', 'lilleprinsen-price-monitor' ), $this->real_updates_enabled( $settings ) ? 'warning' : 'ok' ); ?>
			</div>
			<?php $this->render_approval_quick_filters( $view ); ?>
			<?php $this->render_approval_filters( $view ); ?>
			<div class="lpm-approval-details" data-lpm-suggestion-details <?php echo $highlight_id > 0 ? 'data-lpm-initial-suggestion="' . esc_attr( (string) $highlight_id ) . '"' : ''; ?>>
				<p class="lpm-empty"><?php esc_html_e( 'Select a suggestion to view product, rule, warning, and recovery details.', 'lilleprinsen-price-monitor' ); ?></p>
			</div>
			<?php if ( $show_price_suggestions ) : ?>
				<?php $this->render_approvals_table( $suggestions, $settings ); ?>
				<?php $this->render_pagination( $total, $page, $per_page, 'lpm_approvals_page', array( 'tab' => 'approvals', 'lpm_approval_view' => $view ) ); ?>
			<?php endif; ?>
			<?php $this->render_match_suggestions_inbox( $view ); ?>
		</section>
		<?php
	}

	private function render_approval_quick_filters( string $active_view ): void {
		$filters = array(
			'pending'           => __( 'Needs review', 'lilleprinsen-price-monitor' ),
			'match_suggestions' => __( 'Match suggestions', 'lilleprinsen-price-monitor' ),
			'price_suggestions' => __( 'Price suggestions', 'lilleprinsen-price-monitor' ),
			'blocked'           => __( 'Blocked', 'lilleprinsen-price-monitor' ),
			'rejected'          => __( 'Rejected', 'lilleprinsen-price-monitor' ),
			'failed'            => __( 'Failed', 'lilleprinsen-price-monitor' ),
			'approved_dry_run'  => __( 'Approved', 'lilleprinsen-price-monitor' ),
			'recovery'          => __( 'Recovery', 'lilleprinsen-price-monitor' ),
		);
		?>
		<nav class="lpm-quick-filters" aria-label="<?php esc_attr_e( 'Quick inbox filters', 'lilleprinsen-price-monitor' ); ?>">
			<?php foreach ( $filters as $view_key => $label ) : ?>
				<a class="<?php echo esc_attr( $active_view === $view_key ? 'is-active' : '' ); ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'approvals', 'lpm_approval_view' => $view_key ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	private function render_match_suggestions_inbox( string $view ): void {
		$status = $this->match_suggestion_status_for_view( $view );
		if ( '' === $status ) {
			return;
		}

		$suggestions = $this->discovery_repository->get_suggestions( $status, 1, 25 );
		$total       = $this->discovery_repository->count_suggestions( $status );
		?>
		<div class="lpm-card-spaced lpm-unified-inbox-section">
			<div class="lpm-card-header">
				<div>
					<h3><?php esc_html_e( 'Match suggestions', 'lilleprinsen-price-monitor' ); ?></h3>
					<p class="lpm-card-subtitle"><?php esc_html_e( 'Approve matches only after confirming color, pack size and variant. Approved matches become active monitored competitor links.', 'lilleprinsen-price-monitor' ); ?></p>
				</div>
				<span class="lpm-pill lpm-pill-muted"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</div>
			<?php if ( empty( $suggestions ) ) : ?>
				<?php $this->render_empty_state( __( 'No match suggestions in this filter.', 'lilleprinsen-price-monitor' ) ); ?>
			<?php else : ?>
				<table class="lpm-compact-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Our product', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Competitor product/store', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Suggestion type', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Evidence / reason', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Competitor price', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Confidence', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Warning', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $suggestions as $suggestion ) : ?>
							<?php
							$product    = $this->discovery_repository->get_discovery_product( (int) $suggestion->discovery_product_id );
							$discovered = $this->discovery_repository->get_discovered_product( (int) $suggestion->discovered_product_id );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( function_exists( 'get_the_title' ) ? get_the_title( (int) $suggestion->product_id ) : sprintf( __( 'Product #%d', 'lilleprinsen-price-monitor' ), (int) $suggestion->product_id ) ); ?></strong><br>
									<small><?php echo esc_html( $product ? sprintf( 'SKU: %1$s | EAN: %2$s | Brand: %3$s', (string) $product->sku, (string) $product->gtin, (string) $product->brand ) : '' ); ?></small>
								</td>
								<td>
									<strong><?php echo esc_html( $discovered ? (string) $discovered->title : $this->shorten_text( (string) $suggestion->competitor_url, 70 ) ); ?></strong><br>
									<small><?php echo esc_html( $discovered ? sprintf( 'SKU: %1$s | EAN: %2$s | Brand: %3$s', (string) $discovered->sku, (string) $discovered->gtin, (string) $discovered->brand ) : '' ); ?></small>
								</td>
								<td><?php echo esc_html( $this->plain_match_type_label( (string) $suggestion->match_type ) ); ?></td>
								<td><?php echo esc_html( $this->shorten_text( (string) $suggestion->explanation, 140 ) ); ?></td>
								<td><?php echo esc_html( $discovered ? $this->format_nullable_value( $this->effective_discovered_price( $discovered ) ) . ' ' . (string) $discovered->currency : '—' ); ?></td>
								<td><strong><?php echo esc_html( (string) $suggestion->confidence_label ); ?></strong></td>
								<td><?php echo esc_html( $this->plain_match_warning( (string) $suggestion->confidence_label, (string) $suggestion->match_type ) ); ?></td>
								<td>
									<div class="lpm-actions">
										<?php if ( 'pending' === (string) $suggestion->status ) : ?>
											<?php $this->render_match_suggestion_action_form( (int) $suggestion->id, 'approve_suggestion', __( 'Approve', 'lilleprinsen-price-monitor' ) ); ?>
											<?php $this->render_match_suggestion_action_form( (int) $suggestion->id, 'reject_suggestion', __( 'Reject', 'lilleprinsen-price-monitor' ), 'link-delete' ); ?>
										<?php endif; ?>
										<?php $this->render_match_suggestion_action_form( (int) $suggestion->id, 'retest_suggestion', __( 'Retest', 'lilleprinsen-price-monitor' ) ); ?>
										<a class="button button-small" href="<?php echo esc_url( (string) $suggestion->competitor_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View details', 'lilleprinsen-price-monitor' ); ?></a>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_match_suggestion_action_form( int $suggestion_id, string $action, string $label, string $class = '' ): void {
		?>
		<form method="post" class="lpm-inline-action">
			<?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?>
			<input type="hidden" name="lpm_discovery_action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="suggestion_id" value="<?php echo esc_attr( (string) $suggestion_id ); ?>" />
			<button type="submit" class="button button-small <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function match_suggestion_status_for_view( string $view ): string {
		if ( in_array( $view, array( 'pending', 'match_suggestions' ), true ) ) {
			return 'pending';
		}
		if ( 'rejected' === $view ) {
			return 'rejected';
		}
		if ( 'approved_dry_run' === $view || 'all' === $view ) {
			return 'approved';
		}

		return '';
	}

	private function plain_match_type_label( string $match_type ): string {
		if ( str_contains( $match_type, 'ean' ) || str_contains( $match_type, 'gtin' ) ) {
			return __( 'Exact EAN match', 'lilleprinsen-price-monitor' );
		}
		if ( str_contains( $match_type, 'sku' ) ) {
			return __( 'Exact SKU match', 'lilleprinsen-price-monitor' );
		}
		if ( str_contains( $match_type, 'mpn' ) ) {
			return __( 'MPN and brand match', 'lilleprinsen-price-monitor' );
		}
		return __( 'Possible title/image match', 'lilleprinsen-price-monitor' );
	}

	private function plain_match_warning( string $confidence, string $match_type ): string {
		$confidence = strtolower( $confidence );
		if ( 'high' === $confidence && ( str_contains( $match_type, 'sku' ) || str_contains( $match_type, 'ean' ) || str_contains( $match_type, 'gtin' ) || str_contains( $match_type, 'mpn' ) ) ) {
			return __( 'Identifier matched. Still confirm variant before approval.', 'lilleprinsen-price-monitor' );
		}

		return __( 'Confirm color, pack size and variant before approval.', 'lilleprinsen-price-monitor' );
	}

	private function effective_discovered_price( object $discovered ) {
		foreach ( array( 'sale_price', 'regular_price', 'price' ) as $field ) {
			if ( isset( $discovered->{$field} ) && '' !== (string) $discovered->{$field} ) {
				return $discovered->{$field};
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $settings Current settings.
	 */
	public function render_settings( array $settings ): void {
		$this->settings_tab->render( $settings );
	}

	/**
	 * @param array<string, mixed> $settings Current settings.
	 * @param array<string, mixed> $table_status Schema status.
	 */
	public function render_settings_logs( array $settings, array $table_status ): void {
		?>
		<section class="lpm-card lpm-control-strip">
			<div>
				<p class="lpm-drawer-kicker"><?php esc_html_e( 'Safety state', 'lilleprinsen-price-monitor' ); ?></p>
				<h2><?php esc_html_e( 'Settings, logs and advanced tools', 'lilleprinsen-price-monitor' ); ?></h2>
				<p><?php esc_html_e( 'Daily work should happen in Products, Competitors and Suggestions. Open these sections when you need automation, safety limits, diagnostics or imports.', 'lilleprinsen-price-monitor' ); ?></p>
			</div>
			<div class="lpm-status-cluster">
				<?php $this->render_status_pill( ! empty( $settings['dry_run_mode'] ) ? __( 'Dry-run on', 'lilleprinsen-price-monitor' ) : __( 'Dry-run off', 'lilleprinsen-price-monitor' ), ! empty( $settings['dry_run_mode'] ) ? 'ok' : 'danger' ); ?>
				<?php $this->render_status_pill( $this->real_updates_enabled( $settings ) ? __( 'Real updates possible', 'lilleprinsen-price-monitor' ) : __( 'Real updates blocked', 'lilleprinsen-price-monitor' ), $this->real_updates_enabled( $settings ) ? 'danger' : 'ok' ); ?>
				<?php $this->render_status_pill( ! empty( $settings['scheduled_checks_enabled'] ) ? sprintf( __( 'Checks every %d h', 'lilleprinsen-price-monitor' ), (int) $settings['scheduled_check_interval_hours'] ) : __( 'Schedules off', 'lilleprinsen-price-monitor' ), ! empty( $settings['scheduled_checks_enabled'] ) ? 'warning' : 'muted' ); ?>
			</div>
		</section>

		<details class="lpm-settings-section" open>
			<summary><?php esc_html_e( 'Safety', 'lilleprinsen-price-monitor' ); ?></summary>
			<p class="lpm-field-description"><?php esc_html_e( 'Dry-run, real-update blocking and manual approval are the core safety controls. Keep dry-run on until staging checks are complete.', 'lilleprinsen-price-monitor' ); ?></p>
		</details>

		<details class="lpm-settings-section">
			<summary><?php esc_html_e( 'Automation', 'lilleprinsen-price-monitor' ); ?></summary>
			<?php $this->render_settings( $settings ); ?>
		</details>

		<?php if ( $this->discovery_admin_page ) : ?>
			<details class="lpm-settings-section">
				<summary><?php esc_html_e( 'Advanced settings', 'lilleprinsen-price-monitor' ); ?></summary>
				<?php $this->discovery_admin_page->render_embedded( 'settings' ); ?>
			</details>
		<?php endif; ?>

		<details class="lpm-settings-section" open>
			<summary><?php esc_html_e( 'Logs', 'lilleprinsen-price-monitor' ); ?></summary>
			<?php $this->render_logs( $table_status ); ?>
		</details>

		<details class="lpm-settings-section">
			<summary><?php esc_html_e( 'Debug', 'lilleprinsen-price-monitor' ); ?></summary>
			<?php $this->render_history(); ?>
		</details>

		<details class="lpm-settings-section">
			<summary><?php esc_html_e( 'Import / Export', 'lilleprinsen-price-monitor' ); ?></summary>
			<?php $this->render_import_export(); ?>
			<?php $this->render_groups(); ?>
		</details>
		<?php
	}

	public function render_logs( array $table_status ): void {
		$this->logs_tab->render( $table_status );
	}

	public function render_history(): void {
		$filters      = $this->get_observation_filters();
		$page         = $this->get_positive_query_arg( 'lpm_history_page', 1 );
		$per_page     = (int) $this->settings->get( 'rows_per_page', 25 );
		$observations = $this->repository->get_price_observations( $filters, $page, $per_page );
		$total        = $this->repository->count_price_observations( $filters );
		$sessions     = $this->repository->get_active_price_match_sessions( 10 );
		?>
		<section class="lpm-card">
			<div class="lpm-card-header">
				<h2><?php esc_html_e( 'Active price match sessions', 'lilleprinsen-price-monitor' ); ?></h2>
				<span class="lpm-pill lpm-pill-muted"><?php esc_html_e( 'Latest 10', 'lilleprinsen-price-monitor' ); ?></span>
			</div>
			<?php $this->render_active_price_match_sessions_table( $sessions ); ?>
		</section>

		<section class="lpm-card">
			<div class="lpm-card-header">
				<div>
					<h2><?php esc_html_e( 'Price observation history', 'lilleprinsen-price-monitor' ); ?></h2>
					<p class="lpm-card-subtitle"><?php esc_html_e( 'One row is stored for every manual or batch competitor check. Raw HTML is never stored.', 'lilleprinsen-price-monitor' ); ?></p>
				</div>
				<span class="lpm-pill lpm-pill-muted"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</div>
			<?php $this->render_observation_filters( $filters ); ?>
			<?php $this->render_observations_table( $observations ); ?>
			<?php $this->render_pagination( $total, $page, $per_page, 'lpm_history_page', array_merge( array( 'tab' => 'history' ), $filters ) ); ?>
		</section>
		<?php
	}

	public function render_import_export(): void {
		$token   = isset( $_GET['import_token'] ) ? sanitize_key( wp_unslash( $_GET['import_token'] ) ) : '';
		$preview = '' !== $token ? $this->get_import_preview( $token ) : null;
		?>
		<div class="lpm-grid lpm-grid-two">
			<section class="lpm-card">
				<div class="lpm-card-header">
					<div>
						<h2><?php esc_html_e( 'CSV import', 'lilleprinsen-price-monitor' ); ?></h2>
						<p class="lpm-card-subtitle"><?php printf( esc_html__( 'Preview first. Max %1$d rows or %2$d KB per upload.', 'lilleprinsen-price-monitor' ), (int) CsvImportService::MAX_ROWS, (int) floor( CsvImportService::MAX_BYTES / 1024 ) ); ?></p>
					</div>
					<?php $this->render_status_pill( __( 'Dry-run preview', 'lilleprinsen-price-monitor' ), 'ok' ); ?>
				</div>
				<form method="post" enctype="multipart/form-data" class="lpm-stacked-form">
					<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
					<input type="hidden" name="lpm_action" value="preview_csv_import" />
					<label class="lpm-field">
						<span><?php esc_html_e( 'CSV file', 'lilleprinsen-price-monitor' ); ?></span>
						<input type="file" name="lpm_csv_file" accept=".csv,text/csv" required />
					</label>
					<p class="lpm-field-description"><?php esc_html_e( 'Use product_id first, or sku if product_id is blank. Title search is not used during import.', 'lilleprinsen-price-monitor' ); ?></p>
					<div class="lpm-form-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Preview import', 'lilleprinsen-price-monitor' ); ?></button>
					</div>
				</form>
				<div class="lpm-form-actions">
					<?php $this->render_export_action_form( 'download_csv_template', __( 'Download sample CSV template', 'lilleprinsen-price-monitor' ) ); ?>
				</div>
			</section>

			<section class="lpm-card">
				<div class="lpm-card-header">
					<h2><?php esc_html_e( 'Supported columns', 'lilleprinsen-price-monitor' ); ?></h2>
				</div>
				<p><?php esc_html_e( 'Columns can be left blank when they are not needed. competitor_name is required only when competitor_url is present.', 'lilleprinsen-price-monitor' ); ?></p>
				<code>product_id, sku, competitor_name, competitor_url, match_type, enabled, priority, strategy, min_margin_percent, min_price, check_frequency_hours, notes</code>
				<table class="lpm-compact-table lpm-card-spaced">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Example', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Value', 'lilleprinsen-price-monitor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr><td><?php esc_html_e( 'Strategy', 'lilleprinsen-price-monitor' ); ?></td><td><code>match_competitor</code></td></tr>
						<tr><td><?php esc_html_e( 'Match type', 'lilleprinsen-price-monitor' ); ?></td><td><code>exact</code></td></tr>
						<tr><td><?php esc_html_e( 'Enabled', 'lilleprinsen-price-monitor' ); ?></td><td><code>yes</code> / <code>no</code></td></tr>
					</tbody>
				</table>
			</section>
		</div>

		<?php if ( $preview ) : ?>
			<?php $this->render_import_preview( $preview, $token ); ?>
		<?php endif; ?>

		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<div>
					<h2><?php esc_html_e( 'CSV exports', 'lilleprinsen-price-monitor' ); ?></h2>
					<p class="lpm-card-subtitle"><?php printf( esc_html__( 'Exports are capped at %d rows by default.', 'lilleprinsen-price-monitor' ), self::EXPORT_MAX_ROWS ); ?></p>
				</div>
				<?php $this->render_status_pill( __( 'Bounded', 'lilleprinsen-price-monitor' ), 'ok' ); ?>
			</div>
			<div class="lpm-actions">
				<?php $this->render_export_action_form( 'export_csv', __( 'Export monitored products and links', 'lilleprinsen-price-monitor' ), 'monitored_links' ); ?>
				<?php $this->render_export_action_form( 'export_csv', __( 'Export pending suggestions', 'lilleprinsen-price-monitor' ), 'pending_suggestions' ); ?>
				<?php $this->render_export_action_form( 'export_csv', __( 'Export recent failed checks', 'lilleprinsen-price-monitor' ), 'failed_checks' ); ?>
				<?php $this->render_export_action_form( 'export_csv', __( 'Export price observations', 'lilleprinsen-price-monitor' ), 'price_observations' ); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $preview Preview data.
	 */
	private function render_import_preview( array $preview, string $token ): void {
		$summary      = isset( $preview['summary'] ) && is_array( $preview['summary'] ) ? $preview['summary'] : array();
		$valid_rows   = isset( $preview['valid_rows'] ) && is_array( $preview['valid_rows'] ) ? $preview['valid_rows'] : array();
		$invalid_rows = isset( $preview['invalid_rows'] ) && is_array( $preview['invalid_rows'] ) ? $preview['invalid_rows'] : array();
		?>
		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<div>
					<h2><?php esc_html_e( 'Import preview', 'lilleprinsen-price-monitor' ); ?></h2>
					<p class="lpm-card-subtitle"><?php esc_html_e( 'Review these results before confirming. Invalid rows are never committed.', 'lilleprinsen-price-monitor' ); ?></p>
				</div>
				<?php $this->render_status_pill( ! empty( $summary['truncated'] ) ? __( 'Truncated', 'lilleprinsen-price-monitor' ) : __( 'Ready', 'lilleprinsen-price-monitor' ), ! empty( $summary['truncated'] ) ? 'warning' : 'ok' ); ?>
			</div>
			<div class="lpm-grid lpm-grid-summary">
				<?php
				$this->render_summary_card( __( 'Valid rows', 'lilleprinsen-price-monitor' ), (int) ( $summary['valid_rows'] ?? 0 ), __( 'Can be imported', 'lilleprinsen-price-monitor' ) );
				$this->render_summary_card( __( 'Rows with warnings', 'lilleprinsen-price-monitor' ), (int) ( $summary['rows_with_warnings'] ?? 0 ), __( 'Import with caveats', 'lilleprinsen-price-monitor' ) );
				$this->render_summary_card( __( 'Invalid rows', 'lilleprinsen-price-monitor' ), (int) ( $summary['invalid_rows'] ?? 0 ), __( 'Will be skipped', 'lilleprinsen-price-monitor' ) );
				$this->render_summary_card( __( 'Products found', 'lilleprinsen-price-monitor' ), (int) ( $summary['products_found'] ?? 0 ), __( 'ID or SKU match', 'lilleprinsen-price-monitor' ) );
				$this->render_summary_card( __( 'Products not found', 'lilleprinsen-price-monitor' ), (int) ( $summary['products_not_found'] ?? 0 ), __( 'Invalid', 'lilleprinsen-price-monitor' ) );
				$this->render_summary_card( __( 'Duplicate links', 'lilleprinsen-price-monitor' ), (int) ( $summary['duplicate_links'] ?? 0 ), __( 'Will be skipped', 'lilleprinsen-price-monitor' ) );
				?>
			</div>

			<?php if ( ! empty( $summary['truncated'] ) ) : ?>
				<p class="lpm-danger-note"><?php printf( esc_html__( 'Only the first %d rows were previewed. Split larger imports into smaller CSV files.', 'lilleprinsen-price-monitor' ), (int) CsvImportService::MAX_ROWS ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $valid_rows ) ) : ?>
				<form method="post" class="lpm-form-actions">
					<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
					<input type="hidden" name="lpm_action" value="confirm_csv_import" />
					<input type="hidden" name="import_token" value="<?php echo esc_attr( $token ); ?>" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm import valid rows', 'lilleprinsen-price-monitor' ); ?></button>
				</form>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Valid and warning rows', 'lilleprinsen-price-monitor' ); ?></h3>
			<?php $this->render_import_valid_rows_table( array_slice( $valid_rows, 0, 100 ) ); ?>

			<h3><?php esc_html_e( 'Invalid rows', 'lilleprinsen-price-monitor' ); ?></h3>
			<?php $this->render_import_invalid_rows_table( array_slice( $invalid_rows, 0, 100 ) ); ?>
		</section>
		<?php
	}

	private function render_import_valid_rows_table( array $rows ): void {
		if ( empty( $rows ) ) {
			$this->render_empty_state( __( 'No valid rows in this preview.', 'lilleprinsen-price-monitor' ) );
			return;
		}
		?>
		<table class="lpm-compact-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Row', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Product ID', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'SKU', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Matched by', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'URL', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rules', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Warnings', 'lilleprinsen-price-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $row['row_number'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['product_id'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['sku'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['product_match'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $this->format_nullable_value( $row['competitor_name'] ?? null ) ); ?></td>
						<td><?php echo esc_html( $this->shorten_text( (string) ( $row['competitor_url'] ?? '' ), 48 ) ); ?></td>
						<td><?php echo esc_html( $this->get_import_rule_summary( $row ) ); ?></td>
						<td><?php echo esc_html( $this->join_messages( $row['warnings'] ?? array() ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_import_invalid_rows_table( array $rows ): void {
		if ( empty( $rows ) ) {
			$this->render_empty_state( __( 'No invalid rows in this preview.', 'lilleprinsen-price-monitor' ) );
			return;
		}
		?>
		<table class="lpm-compact-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Row', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Product ID', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'SKU', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Errors', 'lilleprinsen-price-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $row['row_number'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['product_id'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['sku'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $this->join_messages( $row['errors'] ?? array() ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_export_action_form( string $action, string $label, string $export_type = '' ): void {
		?>
		<form method="post" class="lpm-inline-action">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="<?php echo esc_attr( $action ); ?>" />
			<?php if ( '' !== $export_type ) : ?>
				<input type="hidden" name="export_type" value="<?php echo esc_attr( $export_type ); ?>" />
			<?php endif; ?>
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	public function handle_add_monitored_product(): void {
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$product    = $this->get_product( $product_id );

		if ( ! $product ) {
			$this->redirect_to_tab( 'products', 'product_not_found', array( 'lpm_notice_type' => 'error' ) );
		}

		$result = $this->repository->add_monitored_product( $product_id, $product->get_sku() );

		if ( ! empty( $result['success'] ) ) {
			$this->sync_product_to_discovery_selection( $product_id, $product );
			$this->repository->write_log( 'info', 'monitored_product_added', __( 'Product added to monitoring.', 'lilleprinsen-price-monitor' ), array( 'monitored_product_id' => (int) $result['id'] ), $product_id );
			$this->redirect_to_tab( 'products', 'monitoring_added' );
		}

		if ( 'already_monitored' === ( $result['code'] ?? '' ) || 'monitoring_reenabled' === ( $result['code'] ?? '' ) ) {
			$this->sync_product_to_discovery_selection( $product_id, $product );
			$this->repository->write_log( 'info', 'monitored_product_reenabled', __( 'Product monitoring was already present or re-enabled.', 'lilleprinsen-price-monitor' ), array( 'monitored_product_id' => (int) ( $result['id'] ?? 0 ) ), $product_id );
			$this->redirect_to_tab( 'products', (string) $result['code'] );
		}

		$this->repository->write_log( 'error', 'monitored_product_add_failed', __( 'Could not add product to monitoring.', 'lilleprinsen-price-monitor' ), array( 'product_id' => $product_id ), $product_id );
		$this->redirect_to_tab( 'products', 'monitoring_add_failed', array( 'lpm_notice_type' => 'error' ) );
	}

	public function handle_bulk_add_monitored_products(): void {
		$raw   = isset( $_POST['product_identifiers'] ) ? sanitize_textarea_field( wp_unslash( $_POST['product_identifiers'] ) ) : '';
		$items = preg_split( '/[\s,;]+/', $raw );
		$added = 0;
		$seen  = array();

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}

			$product_id = $this->resolve_product_id_from_admin_identifier( $item );
			if ( $product_id <= 0 || isset( $seen[ $product_id ] ) ) {
				continue;
			}

			$product = $this->get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$result = $this->repository->add_monitored_product( $product_id, method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '' );
			if ( ! empty( $result['success'] ) || in_array( (string) ( $result['code'] ?? '' ), array( 'already_monitored', 'monitoring_reenabled' ), true ) ) {
				$this->sync_product_to_discovery_selection( $product_id, $product );
				++$added;
				$seen[ $product_id ] = true;
			}
		}

		$this->repository->write_log( 'info', 'monitored_products_bulk_added', __( 'Products bulk-added to monitoring.', 'lilleprinsen-price-monitor' ), array( 'count' => $added ) );
		$extra_args = array( 'lpm_notice_type' => $added > 0 ? 'success' : 'error' );
		if ( $added > 0 && $this->repository->count_active_competitors() > 0 ) {
			$extra_args['lpm_auto_start_all_discovery'] = 1;
		}
		$this->redirect_to_tab( 'products', $added > 0 ? 'monitoring_added' : 'product_not_found', $extra_args );
	}

	public function handle_monitored_status_action( string $action ): void {
		$monitored_product_id = isset( $_POST['monitored_product_id'] ) ? absint( wp_unslash( $_POST['monitored_product_id'] ) ) : 0;
		$monitored_product    = $this->repository->get_monitored_product( $monitored_product_id );

		if ( ! $monitored_product ) {
			$this->redirect_to_tab( 'products', 'monitored_not_found', array( 'lpm_notice_type' => 'error' ) );
		}

		$enabled = 'enable_monitored' === $action;
		$updated = $this->repository->set_monitored_product_enabled( $monitored_product_id, $enabled );
		$event   = 'remove_monitored' === $action ? 'monitored_product_removed' : ( $enabled ? 'monitored_product_enabled' : 'monitored_product_disabled' );

		if ( $updated ) {
			$this->repository->write_log(
				'info',
				$event,
				'remove_monitored' === $action ? __( 'Product removed from active monitoring by soft-disable.', 'lilleprinsen-price-monitor' ) : __( 'Product monitoring status changed.', 'lilleprinsen-price-monitor' ),
				array( 'monitored_product_id' => $monitored_product_id ),
				(int) $monitored_product['product_id']
			);
			$this->redirect_to_tab( 'products', 'monitoring_status_updated' );
		}

		$this->redirect_to_tab( 'products', 'monitoring_status_failed', array( 'lpm_notice_type' => 'error' ) );
	}

	public function handle_update_monitored_rules(): void {
		$monitored_product_id = isset( $_POST['monitored_product_id'] ) ? absint( wp_unslash( $_POST['monitored_product_id'] ) ) : 0;
		$monitored_product    = $this->repository->get_monitored_product( $monitored_product_id );

		if ( ! $monitored_product ) {
			$this->redirect_to_tab( 'products', 'monitored_not_found', array( 'lpm_notice_type' => 'error' ) );
		}

		$check_frequency_hours = isset( $_POST['check_frequency_hours'] ) ? absint( wp_unslash( $_POST['check_frequency_hours'] ) ) : 24;

		if ( $check_frequency_hours < 1 || $check_frequency_hours > 720 ) {
			$this->redirect_to_tab( 'products', 'monitored_rules_invalid', array( 'lpm_notice_type' => 'error', 'edit_rules_id' => $monitored_product_id ) );
		}

		$data = array(
			'enabled'               => ! empty( $_POST['enabled'] ) ? 1 : 0,
			'priority'              => isset( $_POST['priority'] ) ? sanitize_key( wp_unslash( $_POST['priority'] ) ) : 'normal',
			'strategy'              => isset( $_POST['strategy'] ) ? sanitize_key( wp_unslash( $_POST['strategy'] ) ) : 'match_competitor',
			'min_margin_percent'    => $this->sanitize_decimal_post_value( 'min_margin_percent' ),
			'min_price'             => $this->sanitize_decimal_post_value( 'min_price' ),
			'check_frequency_hours' => $check_frequency_hours,
		);

		$updated = $this->repository->update_monitored_product_rules( $monitored_product_id, $data );

		if ( $updated ) {
			$this->repository->write_log(
				'info',
				'monitored_product_rules_updated',
				__( 'Product monitoring rules updated.', 'lilleprinsen-price-monitor' ),
				array(
					'monitored_product_id' => $monitored_product_id,
					'before'               => $this->monitored_rule_log_snapshot( $monitored_product ),
					'after'                => $data,
				),
				(int) $monitored_product['product_id']
			);
			$this->redirect_to_tab( 'products', 'monitored_rules_updated' );
		}

		$this->redirect_to_tab( 'products', 'monitored_rules_update_failed', array( 'lpm_notice_type' => 'error', 'edit_rules_id' => $monitored_product_id ) );
	}

	public function handle_bulk_monitored_products(): void {
		$ids = $this->get_selected_ids_from_post( 'monitored_product_ids' );

		if ( empty( $ids ) ) {
			$this->redirect_to_tab( 'products', 'bulk_no_selection', array( 'lpm_notice_type' => 'warning' ) );
		}

		$bulk_action = isset( $_POST['bulk_monitored_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_monitored_action'] ) ) : '';
		$updated     = 0;

		foreach ( $ids as $id ) {
			$monitored_product = $this->repository->get_monitored_product( $id );

			if ( ! $monitored_product ) {
				continue;
			}

			$success = false;

			switch ( $bulk_action ) {
				case 'enable':
					$success = $this->repository->set_monitored_product_enabled( $id, true );
					break;
				case 'disable':
				case 'remove':
					$success = $this->repository->set_monitored_product_enabled( $id, false );
					break;
				case 'set_priority':
				case 'set_strategy':
				case 'set_check_frequency':
				case 'set_min_margin':
				case 'set_min_price':
					$success = $this->repository->update_monitored_product_rules( $id, $this->build_bulk_monitored_rule_data( $monitored_product, $bulk_action ) );
					break;
				default:
					$this->redirect_to_tab( 'products', 'bulk_action_invalid', array( 'lpm_notice_type' => 'error' ) );
			}

			if ( $success ) {
				$updated++;
				$this->repository->write_log(
					'info',
					'bulk_monitored_product_updated',
					__( 'Bulk monitored product action applied.', 'lilleprinsen-price-monitor' ),
					array(
						'bulk_action'          => $bulk_action,
						'monitored_product_id' => $id,
					),
					(int) $monitored_product['product_id']
				);
			}
		}

		$this->set_admin_notice(
			sprintf(
				/* translators: %d: updated row count. */
				__( 'Bulk action updated %d monitored products.', 'lilleprinsen-price-monitor' ),
				$updated
			)
		);
		$this->redirect_to_tab( 'products', 'bulk_action_completed' );
	}

	public function handle_save_product_group( string $action ): void {
		$group_id = isset( $_POST['group_id'] ) ? absint( wp_unslash( $_POST['group_id'] ) ) : 0;
		$name     = isset( $_POST['group_name'] ) ? sanitize_text_field( wp_unslash( $_POST['group_name'] ) ) : '';

		if ( '' === trim( $name ) ) {
			$this->redirect_to_tab( 'groups', 'product_group_name_required', array( 'lpm_notice_type' => 'error' ) );
		}

		$data = array(
			'name'               => $name,
			'description'        => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'enabled'            => ! empty( $_POST['enabled'] ),
			'pricing_mode'       => isset( $_POST['pricing_mode'] ) ? sanitize_key( wp_unslash( $_POST['pricing_mode'] ) ) : 'shared_price',
			'primary_product_id' => isset( $_POST['primary_product_id'] ) ? absint( wp_unslash( $_POST['primary_product_id'] ) ) : 0,
		);

		if ( 'update_product_group' === $action ) {
			if ( $group_id <= 0 || ! $this->repository->get_product_group( $group_id ) ) {
				$this->redirect_to_tab( 'groups', 'product_group_not_found', array( 'lpm_notice_type' => 'error' ) );
			}

			$updated = $this->repository->update_product_group( $group_id, $data );
			$this->repository->write_log( 'info', 'product_group_updated', __( 'Product group updated.', 'lilleprinsen-price-monitor' ), array( 'group_id' => $group_id ) );
			$this->redirect_to_tab( 'groups', $updated ? 'product_group_updated' : 'product_group_update_failed', array( 'lpm_notice_type' => $updated ? 'success' : 'error', 'manage_group_id' => $group_id ) );
		}

		$new_id = $this->repository->create_product_group( $data );

		if ( $new_id > 0 ) {
			$this->repository->write_log( 'info', 'product_group_created', __( 'Product group created.', 'lilleprinsen-price-monitor' ), array( 'group_id' => $new_id ) );
			$this->redirect_to_tab( 'groups', 'product_group_created', array( 'manage_group_id' => $new_id ) );
		}

		$this->redirect_to_tab( 'groups', 'product_group_create_failed', array( 'lpm_notice_type' => 'error' ) );
	}

	public function handle_product_group_action( string $action ): void {
		$group_id = isset( $_POST['group_id'] ) ? absint( wp_unslash( $_POST['group_id'] ) ) : 0;

		if ( $group_id <= 0 || ! $this->repository->get_product_group( $group_id ) ) {
			$this->redirect_to_tab( 'groups', 'product_group_not_found', array( 'lpm_notice_type' => 'error' ) );
		}

		if ( 'delete_product_group' === $action ) {
			$updated = $this->repository->delete_empty_product_group( $group_id );
		} else {
			$updated = $this->repository->set_product_group_enabled( $group_id, 'enable_product_group' === $action );
		}

		$this->repository->write_log( 'info', 'product_group_status_changed', __( 'Product group status changed.', 'lilleprinsen-price-monitor' ), array( 'group_id' => $group_id, 'action' => $action ) );
		$this->redirect_to_tab( 'groups', $updated ? 'product_group_updated' : 'product_group_update_failed', array( 'lpm_notice_type' => $updated ? 'success' : 'error' ) );
	}

	public function handle_product_group_member_action( string $action ): void {
		$group_id = isset( $_POST['group_id'] ) ? absint( wp_unslash( $_POST['group_id'] ) ) : 0;

		if ( $group_id <= 0 || ! $this->repository->get_product_group( $group_id ) ) {
			$this->redirect_to_tab( 'groups', 'product_group_not_found', array( 'lpm_notice_type' => 'error' ) );
		}

		if ( 'add_product_group_member' === $action ) {
			$monitored_product_id = isset( $_POST['monitored_product_id'] ) ? absint( wp_unslash( $_POST['monitored_product_id'] ) ) : 0;
			$role                 = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : 'member';
			$member_id            = $this->repository->add_product_group_member( $group_id, $monitored_product_id, $role );

			$this->repository->write_log( 'info', 'product_group_member_added', __( 'Product added to group.', 'lilleprinsen-price-monitor' ), array( 'group_id' => $group_id, 'monitored_product_id' => $monitored_product_id, 'member_id' => $member_id ) );
			$this->redirect_to_tab( 'groups', $member_id > 0 ? 'product_group_member_added' : 'product_group_member_add_failed', array( 'lpm_notice_type' => $member_id > 0 ? 'success' : 'error', 'manage_group_id' => $group_id ) );
		}

		$member_id = isset( $_POST['member_id'] ) ? absint( wp_unslash( $_POST['member_id'] ) ) : 0;

		if ( $member_id <= 0 ) {
			$this->redirect_to_tab( 'groups', 'product_group_member_not_found', array( 'lpm_notice_type' => 'error', 'manage_group_id' => $group_id ) );
		}

		if ( 'remove_product_group_member' === $action ) {
			$updated = $this->repository->remove_product_group_member( $member_id );
		} elseif ( 'set_product_group_primary_member' === $action ) {
			$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
			$updated    = $this->repository->set_product_group_primary_member( $group_id, $product_id );
		} else {
			$updated = $this->repository->set_product_group_member_enabled( $member_id, 'enable_product_group_member' === $action );
		}

		$this->repository->write_log( 'info', 'product_group_member_changed', __( 'Product group member changed.', 'lilleprinsen-price-monitor' ), array( 'group_id' => $group_id, 'member_id' => $member_id, 'action' => $action ) );
		$this->redirect_to_tab( 'groups', $updated ? 'product_group_member_updated' : 'product_group_member_update_failed', array( 'lpm_notice_type' => $updated ? 'success' : 'error', 'manage_group_id' => $group_id ) );
	}

	public function handle_bulk_competitor_links(): void {
		$ids = $this->get_selected_ids_from_post( 'competitor_link_ids' );

		if ( empty( $ids ) ) {
			$this->redirect_to_tab( 'competitors', 'bulk_no_selection', array( 'lpm_notice_type' => 'warning' ) );
		}

		$bulk_action = isset( $_POST['bulk_competitor_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_competitor_action'] ) ) : '';
		$match_type  = isset( $_POST['bulk_match_type'] ) ? sanitize_key( wp_unslash( $_POST['bulk_match_type'] ) ) : 'unknown';
		$updated     = 0;
		$redirect_id = 0;

		foreach ( $ids as $id ) {
			$link = $this->repository->get_competitor_link( $id );

			if ( ! $link ) {
				continue;
			}

			$redirect_id = (int) $link['monitored_product_id'];
			$success     = false;

			switch ( $bulk_action ) {
				case 'enable':
					$success = $this->repository->set_competitor_link_enabled( $id, true );
					break;
				case 'disable':
					$success = $this->repository->set_competitor_link_enabled( $id, false );
					break;
				case 'delete':
					$success = $this->repository->delete_competitor_link( $id );
					break;
				case 'set_match_type':
					$success = $this->repository->set_competitor_link_match_type( $id, $match_type );
					break;
				default:
					$this->redirect_to_competitors( $redirect_id, 'bulk_action_invalid', 'error' );
			}

			if ( $success ) {
				$updated++;
				$this->repository->write_log(
					'info',
					'bulk_competitor_link_updated',
					__( 'Bulk competitor link action applied.', 'lilleprinsen-price-monitor' ),
					array(
						'bulk_action'       => $bulk_action,
						'competitor_link_id' => $id,
						'match_type'        => $match_type,
					),
					null
				);
			}
		}

		$this->set_admin_notice(
			sprintf(
				/* translators: %d: updated row count. */
				__( 'Bulk action updated %d competitor links.', 'lilleprinsen-price-monitor' ),
				$updated
			)
		);

		if ( $redirect_id > 0 ) {
			$this->redirect_to_competitors( $redirect_id, 'bulk_action_completed' );
		}

		$this->redirect_to_tab( 'competitors', 'bulk_action_completed' );
	}

	public function handle_save_competitor_profile( string $action ): void {
		$data = $this->get_competitor_profile_data_from_post();

		if ( '' === $data['name'] ) {
			$this->redirect_to_tab( 'competitors', 'competitor_profile_name_required', array( 'lpm_notice_type' => 'error' ) );
		}

		if ( 'update_competitor_profile' === $action ) {
			$profile_id = isset( $_POST['competitor_profile_id'] ) ? absint( wp_unslash( $_POST['competitor_profile_id'] ) ) : 0;
			$profile    = $this->repository->get_competitor( $profile_id );

			if ( ! $profile ) {
				$this->redirect_to_tab( 'competitors', 'competitor_profile_not_found', array( 'lpm_notice_type' => 'error' ) );
			}

			$updated = $this->repository->update_competitor( $profile_id, $data );

			if ( $updated ) {
				$this->repository->write_log( 'info', 'competitor_profile_updated', __( 'Competitor profile updated.', 'lilleprinsen-price-monitor' ), array( 'competitor_id' => $profile_id ) );
				$this->redirect_to_tab( 'competitors', 'competitor_profile_updated' );
			}

			$this->redirect_to_tab( 'competitors', 'competitor_profile_update_failed', array( 'lpm_notice_type' => 'error', 'competitor_profile_id' => $profile_id ) );
		}

		$profile_id = $this->repository->add_competitor( $data );

		if ( $profile_id > 0 ) {
			$this->repository->write_log( 'info', 'competitor_profile_added', __( 'Competitor profile added.', 'lilleprinsen-price-monitor' ), array( 'competitor_id' => $profile_id ) );
			$this->redirect_to_tab( 'competitors', 'competitor_profile_added', array( 'lpm_auto_start_competitor_id' => $profile_id ) );
		}

		$this->redirect_to_tab( 'competitors', 'competitor_profile_add_failed', array( 'lpm_notice_type' => 'error' ) );
	}

	public function handle_competitor_profile_status_action( string $action ): void {
		$profile_id = isset( $_POST['competitor_profile_id'] ) ? absint( wp_unslash( $_POST['competitor_profile_id'] ) ) : 0;
		$profile    = $this->repository->get_competitor( $profile_id );

		if ( ! $profile ) {
			$this->redirect_to_tab( 'competitors', 'competitor_profile_not_found', array( 'lpm_notice_type' => 'error' ) );
		}

		$enabled = 'enable_competitor_profile' === $action;
		$updated = $this->repository->set_competitor_enabled( $profile_id, $enabled );

		if ( $updated ) {
			$this->repository->write_log( 'info', $enabled ? 'competitor_profile_enabled' : 'competitor_profile_disabled', __( 'Competitor profile status changed.', 'lilleprinsen-price-monitor' ), array( 'competitor_id' => $profile_id ) );
			$this->redirect_to_tab( 'competitors', 'competitor_profile_status_updated' );
		}

		$this->redirect_to_tab( 'competitors', 'competitor_profile_status_failed', array( 'lpm_notice_type' => 'error' ) );
	}

	public function handle_test_competitor_profile_url(): void {
		$profile_id = isset( $_POST['competitor_profile_id'] ) ? absint( wp_unslash( $_POST['competitor_profile_id'] ) ) : 0;
		$profile    = $this->repository->get_competitor( $profile_id );
		$url        = isset( $_POST['test_url'] ) ? esc_url_raw( wp_unslash( $_POST['test_url'] ) ) : '';

		if ( ! $profile ) {
			$this->redirect_to_tab( 'competitors', 'competitor_profile_not_found', array( 'lpm_notice_type' => 'error' ) );
		}

		if ( ! $this->is_valid_http_url( $url ) ) {
			$this->redirect_to_tab( 'competitors', 'competitor_profile_test_url_invalid', array( 'lpm_notice_type' => 'error', 'competitor_profile_id' => $profile_id ) );
		}

		$result = $this->price_check_service->test_url_with_profile( $url, $profile, $this->settings->get_all() );
		$token  = $this->store_competitor_profile_test_result( $profile_id, $url, $result );
		$success = ! empty( $result['success'] );

		$this->repository->write_log(
			$success ? 'info' : 'warning',
			$success ? 'competitor_profile_test_succeeded' : 'competitor_profile_test_failed',
			$success ? __( 'Competitor profile test detected a price.', 'lilleprinsen-price-monitor' ) : __( 'Competitor profile test did not detect a price.', 'lilleprinsen-price-monitor' ),
			array(
				'competitor_id'     => $profile_id,
				'url'               => $url,
				'price'             => $result['price'],
				'currency'          => $result['currency'],
				'stock_status'      => $result['stock_status'],
				'extraction_method' => $result['extraction_method'],
				'http_status'       => $result['http_status'],
				'error'             => $result['error'],
			)
		);

		$this->set_admin_notice(
			$success
				? sprintf(
					/* translators: 1: price, 2: method. */
					__( 'Profile test detected %1$s using %2$s. No product data was saved.', 'lilleprinsen-price-monitor' ),
					$this->format_price_amount( (float) $result['price'], (string) $result['currency'] ),
					(string) $result['extraction_method']
				)
				: sprintf(
					/* translators: %s: error message. */
					__( 'Profile test failed: %s', 'lilleprinsen-price-monitor' ),
					(string) $result['error']
				),
			$success ? 'success' : 'warning'
		);

		$this->redirect_to_tab(
			'competitors',
			$success ? 'competitor_profile_test_succeeded' : 'competitor_profile_test_failed',
			array(
				'lpm_notice_type'       => $success ? 'success' : 'warning',
				'competitor_profile_id' => $profile_id,
				'profile_test_token'    => $token,
			)
		);
	}

	public function handle_preview_csv_import(): void {
		$file    = isset( $_FILES['lpm_csv_file'] ) && is_array( $_FILES['lpm_csv_file'] ) ? $_FILES['lpm_csv_file'] : array();
		$preview = $this->csv_import_service->preview_upload( $file );

		if ( empty( $preview['success'] ) ) {
			$this->set_admin_notice( (string) ( $preview['message'] ?? __( 'Could not preview CSV import.', 'lilleprinsen-price-monitor' ) ), 'error' );
			$this->redirect_to_tab( 'import_export', 'csv_import_preview_failed', array( 'lpm_notice_type' => 'error' ) );
		}

		$token = wp_generate_password( 20, false, false );
		set_transient( $this->get_import_transient_key( $token ), $preview, HOUR_IN_SECONDS );
		$this->set_admin_notice( __( 'CSV import preview is ready. Review rows before confirming.', 'lilleprinsen-price-monitor' ) );
		$this->redirect_to_tab( 'import_export', 'csv_import_preview_ready', array( 'import_token' => $token ) );
	}

	public function handle_confirm_csv_import(): void {
		$token   = isset( $_POST['import_token'] ) ? sanitize_key( wp_unslash( $_POST['import_token'] ) ) : '';
		$preview = $this->get_import_preview( $token );

		if ( ! $preview ) {
			$this->redirect_to_tab( 'import_export', 'csv_import_preview_missing', array( 'lpm_notice_type' => 'error' ) );
		}

		$summary = $this->csv_import_service->commit_preview( $preview );
		delete_transient( $this->get_import_transient_key( $token ) );
		$this->repository->write_log( 'info', 'csv_import_confirmed', __( 'CSV import confirmed.', 'lilleprinsen-price-monitor' ), $summary, null );
		$this->set_admin_notice(
			sprintf(
				/* translators: 1: imported rows, 2: created links, 3: skipped links. */
				__( 'CSV import complete: %1$d rows imported, %2$d links created, %3$d links skipped.', 'lilleprinsen-price-monitor' ),
				(int) $summary['imported_rows'],
				(int) $summary['created_links'],
				(int) $summary['skipped_links']
			)
		);
		$this->redirect_to_tab( 'import_export', 'csv_import_confirmed' );
	}

	public function handle_download_csv_template(): void {
		$this->stream_csv(
			'lpm-import-template.csv',
			$this->get_import_csv_headers(),
			array(
				array(
					'123',
					'',
					'Example Competitor',
					'https://example.com/product',
					'exact',
					'yes',
					'normal',
					'match_competitor',
					'',
					'1190',
					'24',
					'Optional note',
				),
			)
		);
	}

	public function handle_export_csv(): void {
		$export_type = isset( $_POST['export_type'] ) ? sanitize_key( wp_unslash( $_POST['export_type'] ) ) : '';

		switch ( $export_type ) {
			case 'monitored_links':
				$this->stream_monitored_links_export();
				break;
			case 'pending_suggestions':
				$this->stream_pending_suggestions_export();
				break;
			case 'failed_checks':
				$this->stream_failed_checks_export();
				break;
			case 'price_observations':
				$this->stream_price_observations_export();
				break;
			default:
				$this->redirect_to_tab( 'import_export', 'export_type_invalid', array( 'lpm_notice_type' => 'error' ) );
		}
	}

	public function render_groups(): void {
		$page         = $this->get_positive_query_arg( 'lpm_groups_page', 1 );
		$per_page     = (int) $this->settings->get( 'rows_per_page', 25 );
		$groups       = $this->repository->get_product_groups( $page, $per_page );
		$total        = $this->repository->count_product_groups();
		$editing_id   = $this->get_positive_query_arg( 'group_id', 0 );
		$editing      = $editing_id > 0 ? $this->repository->get_product_group( $editing_id ) : null;
		$manage_id    = $this->get_positive_query_arg( 'manage_group_id', 0 );
		$manage_group = $manage_id > 0 ? $this->repository->get_product_group( $manage_id ) : null;
		?>
		<div class="lpm-grid lpm-grid-two">
			<section class="lpm-card">
				<div class="lpm-card-header">
					<div>
						<h2><?php echo esc_html( $editing ? __( 'Edit product group', 'lilleprinsen-price-monitor' ) : __( 'Create product group', 'lilleprinsen-price-monitor' ) ); ?></h2>
						<p class="lpm-card-subtitle"><?php esc_html_e( 'Groups let related monitored products share pricing decisions without scanning the catalog.', 'lilleprinsen-price-monitor' ); ?></p>
					</div>
					<?php $this->render_status_pill( __( 'Admin only', 'lilleprinsen-price-monitor' ), 'ok' ); ?>
				</div>
				<?php $this->render_product_group_form( $editing ); ?>
			</section>
			<section class="lpm-card">
				<div class="lpm-card-header">
					<h2><?php esc_html_e( 'Group safety', 'lilleprinsen-price-monitor' ); ?></h2>
				</div>
				<ul class="lpm-check-list">
					<li><?php esc_html_e( 'A product can belong to one active group in this version.', 'lilleprinsen-price-monitor' ); ?></li>
					<li><?php esc_html_e( 'Dry-run group approvals log affected products and never change WooCommerce prices.', 'lilleprinsen-price-monitor' ); ?></li>
					<li><?php esc_html_e( 'Real group updates are intentionally not automatic and remain behind the existing confirmation flow.', 'lilleprinsen-price-monitor' ); ?></li>
				</ul>
			</section>
		</div>
		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<h2><?php esc_html_e( 'Product groups', 'lilleprinsen-price-monitor' ); ?></h2>
				<span class="lpm-pill lpm-pill-muted"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</div>
			<?php $this->render_product_groups_table( $groups ); ?>
			<?php $this->render_pagination( $total, $page, $per_page, 'lpm_groups_page', array( 'tab' => 'groups' ) ); ?>
		</section>
		<?php if ( $manage_group ) : ?>
			<?php $this->render_product_group_members_panel( $manage_group ); ?>
		<?php endif; ?>
		<?php
	}

	public function handle_save_competitor_link( string $action ): void {
		$monitored_product_id = isset( $_POST['monitored_product_id'] ) ? absint( wp_unslash( $_POST['monitored_product_id'] ) ) : 0;
		$monitored_product    = $this->repository->get_monitored_product( $monitored_product_id );

		if ( ! $monitored_product ) {
			$this->redirect_to_tab( 'competitors', 'monitored_not_found', array( 'lpm_notice_type' => 'error' ) );
		}

		$competitor_id = isset( $_POST['competitor_id'] ) ? absint( wp_unslash( $_POST['competitor_id'] ) ) : 0;
		$profile       = $competitor_id > 0 ? $this->repository->get_competitor( $competitor_id ) : null;
		$name          = isset( $_POST['competitor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['competitor_name'] ) ) : '';
		$url           = isset( $_POST['competitor_url'] ) ? esc_url_raw( wp_unslash( $_POST['competitor_url'] ) ) : '';
		$match_type    = isset( $_POST['match_type'] ) ? sanitize_key( wp_unslash( $_POST['match_type'] ) ) : 'unknown';
		$enabled       = ! empty( $_POST['enabled'] );
		$is_primary    = ! empty( $_POST['is_primary'] );

		if ( $competitor_id > 0 && ! $profile ) {
			$this->redirect_to_competitors( $monitored_product_id, 'competitor_profile_not_found', 'error' );
		}

		if ( '' === $name && $profile ) {
			$name = (string) $profile['name'];
		}

		if ( '' === $name ) {
			$this->redirect_to_competitors( $monitored_product_id, 'competitor_name_required', 'error' );
		}

		if ( ! $this->is_valid_http_url( $url ) ) {
			$this->redirect_to_competitors( $monitored_product_id, 'competitor_url_invalid', 'error' );
		}

		$data = array(
			'monitored_product_id' => $monitored_product_id,
			'competitor_id'        => $competitor_id,
			'competitor_name'      => $name,
			'competitor_url'       => $url,
			'match_type'           => $match_type,
			'enabled'              => $enabled ? 1 : 0,
			'is_primary'           => $is_primary ? 1 : 0,
		);

		if ( 'update_competitor_link' === $action ) {
			$link_id = isset( $_POST['competitor_link_id'] ) ? absint( wp_unslash( $_POST['competitor_link_id'] ) ) : 0;
			$link    = $this->repository->get_competitor_link( $link_id );

			if ( ! $link || (int) $link['monitored_product_id'] !== $monitored_product_id ) {
				$this->redirect_to_competitors( $monitored_product_id, 'competitor_link_not_found', 'error' );
			}

			$updated = $this->repository->update_competitor_link( $link_id, $data );

			if ( $updated ) {
				$this->repository->write_log( 'info', 'competitor_link_updated', __( 'Competitor link updated.', 'lilleprinsen-price-monitor' ), array( 'competitor_link_id' => $link_id ), (int) $monitored_product['product_id'] );
				$this->redirect_to_competitors( $monitored_product_id, 'competitor_link_updated' );
			}

			$this->redirect_to_competitors( $monitored_product_id, 'competitor_link_update_failed', 'error' );
		}

		$link_id = $this->repository->add_competitor_link( $data );

		if ( $link_id > 0 ) {
			$this->repository->write_log( 'info', 'competitor_link_added', __( 'Competitor link added.', 'lilleprinsen-price-monitor' ), array( 'competitor_link_id' => $link_id ), (int) $monitored_product['product_id'] );
			$this->redirect_to_competitors( $monitored_product_id, 'competitor_link_added' );
		}

		$this->redirect_to_competitors( $monitored_product_id, 'competitor_link_add_failed', 'error' );
	}

	public function handle_competitor_link_action( string $action ): void {
		$link_id = isset( $_POST['competitor_link_id'] ) ? absint( wp_unslash( $_POST['competitor_link_id'] ) ) : 0;
		$link    = $this->repository->get_competitor_link( $link_id );

		if ( ! $link ) {
			$this->redirect_to_tab( 'competitors', 'competitor_link_not_found', array( 'lpm_notice_type' => 'error' ) );
		}

		$monitored_product_id = (int) $link['monitored_product_id'];
		$monitored_product    = $this->repository->get_monitored_product( $monitored_product_id );
		$product_id           = $monitored_product ? (int) $monitored_product['product_id'] : null;

		if ( 'delete_competitor_link' === $action ) {
			$deleted = $this->repository->delete_competitor_link( $link_id );

			if ( $deleted ) {
				$this->repository->write_log( 'info', 'competitor_link_deleted', __( 'Competitor link deleted.', 'lilleprinsen-price-monitor' ), array( 'competitor_link_id' => $link_id ), $product_id );
				$this->redirect_to_competitors( $monitored_product_id, 'competitor_link_deleted' );
			}

			$this->redirect_to_competitors( $monitored_product_id, 'competitor_link_delete_failed', 'error' );
		}

		$enabled = 'enable_competitor_link' === $action;
		$updated = $this->repository->set_competitor_link_enabled( $link_id, $enabled );

		if ( $updated ) {
			$this->repository->write_log( 'info', $enabled ? 'competitor_link_enabled' : 'competitor_link_disabled', __( 'Competitor link status changed.', 'lilleprinsen-price-monitor' ), array( 'competitor_link_id' => $link_id ), $product_id );
			$this->redirect_to_competitors( $monitored_product_id, 'competitor_link_status_updated' );
		}

		$this->redirect_to_competitors( $monitored_product_id, 'competitor_link_status_failed', 'error' );
	}

	public function handle_test_competitor_check(): void {
		$link_id = isset( $_POST['competitor_link_id'] ) ? absint( wp_unslash( $_POST['competitor_link_id'] ) ) : 0;
		$link    = $this->repository->get_competitor_link( $link_id );

		if ( ! $link ) {
			$this->redirect_to_tab( 'competitors', 'competitor_link_not_found', array( 'lpm_notice_type' => 'error' ) );
		}

		$monitored_product_id = (int) $link['monitored_product_id'];
		$monitored_product    = $this->repository->get_monitored_product( $monitored_product_id );
		$product_id           = $monitored_product ? (int) $monitored_product['product_id'] : null;

		if ( ! $monitored_product ) {
			$this->redirect_to_competitors( $monitored_product_id, 'monitored_not_found', 'error' );
		}

		$result  = $this->price_check_service->test_check( $link, $this->settings->get_all() );
		$updated = $this->repository->update_competitor_check_result(
			$link_id,
			$result['success'] ? (float) $result['price'] : null,
			(string) $result['currency'],
			$result['success'] ? null : (string) $result['error'],
			$result['success'] ? (string) $result['stock_status'] : null
		);

		if ( ! empty( $result['success'] ) && $updated ) {
			$this->repository->write_log(
				'info',
				'competitor_check_succeeded',
				__( 'Manual competitor test check detected a price.', 'lilleprinsen-price-monitor' ),
				array(
					'competitor_link_id' => $link_id,
					'price'              => (float) $result['price'],
					'currency'           => (string) $result['currency'],
					'extraction_method'  => (string) $result['extraction_method'],
					'stock_status'       => (string) $result['stock_status'],
					'http_status'        => (int) $result['http_status'],
					'response_time_ms'   => (int) $result['response_time_ms'],
				),
				$product_id
			);
			$this->set_admin_notice(
				sprintf(
					/* translators: 1: detected price, 2: extraction method. */
					__( 'Detected %1$s using %2$s. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ),
					$this->format_price_amount( (float) $result['price'], (string) $result['currency'] ),
					(string) $result['extraction_method']
				)
			);
			$this->redirect_to_competitors( $monitored_product_id, 'competitor_check_succeeded' );
		}

		$error_message = ! empty( $result['success'] ) && ! $updated
			? __( 'Detected a price, but could not save the competitor check result.', 'lilleprinsen-price-monitor' )
			: (string) $result['error'];

		$this->repository->write_log(
			'error',
			'competitor_check_failed',
			__( 'Manual competitor test check failed.', 'lilleprinsen-price-monitor' ),
			array(
				'competitor_link_id' => $link_id,
				'error'              => $error_message,
				'http_status'        => (int) $result['http_status'],
				'response_time_ms'   => (int) $result['response_time_ms'],
				'updated'            => $updated,
			),
			$product_id
		);
		$this->notification_service->send(
			'failed_check',
			__( 'Price Monitor would send a failed check notification.', 'lilleprinsen-price-monitor' ),
			$this->settings->get_all(),
			array(
				'competitor_link_id' => $link_id,
				'competitor_url'     => (string) ( $link['competitor_url'] ?? '' ),
				'error'              => $error_message,
				'http_status'        => (int) $result['http_status'],
			),
			$product_id
		);
		$this->set_admin_notice(
			sprintf(
				/* translators: %s: error message. */
				__( 'Test check failed: %s', 'lilleprinsen-price-monitor' ),
				$error_message
			),
			'error'
		);
		$this->redirect_to_competitors( $monitored_product_id, 'competitor_check_failed', 'error' );
	}

	public function handle_create_price_suggestion(): void {
		$link_id = isset( $_POST['competitor_link_id'] ) ? absint( wp_unslash( $_POST['competitor_link_id'] ) ) : 0;
		$link    = $this->repository->get_competitor_link( $link_id );

		if ( ! $link ) {
			$this->redirect_to_tab( 'competitors', 'competitor_link_not_found', array( 'lpm_notice_type' => 'error' ) );
		}

		$monitored_product_id = (int) $link['monitored_product_id'];
		$monitored_product    = $this->repository->get_monitored_product( $monitored_product_id );

		if ( ! $monitored_product ) {
			$this->redirect_to_competitors( $monitored_product_id, 'monitored_not_found', 'error' );
		}

		$product = $this->get_product( (int) $monitored_product['product_id'] );

		if ( ! $product ) {
			$this->redirect_to_competitors( $monitored_product_id, 'product_not_found', 'error' );
		}

		$result = $this->suggestion_service->create_from_competitor_link( $monitored_product, $link, $product, $this->settings->get_all() );
		$status = (string) ( $result['status'] ?? 'error' );

		if ( 'skipped' === $status ) {
			$this->repository->write_log(
				'warning',
				'price_suggestion_skipped',
				(string) ( $result['message'] ?? __( 'Price suggestion was skipped.', 'lilleprinsen-price-monitor' ) ),
				array( 'competitor_link_id' => $link_id ),
				(int) $monitored_product['product_id']
			);
			$this->set_admin_notice( (string) $result['message'], 'warning' );
			$this->redirect_to_competitors( $monitored_product_id, 'price_suggestion_skipped', 'warning' );
		}

		if ( 'error' === $status ) {
			$this->repository->write_log(
				'error',
				'price_suggestion_failed',
				(string) ( $result['message'] ?? __( 'Could not create price suggestion.', 'lilleprinsen-price-monitor' ) ),
				array( 'competitor_link_id' => $link_id ),
				(int) $monitored_product['product_id']
			);
			$this->set_admin_notice( (string) $result['message'], 'error' );
			$this->redirect_to_competitors( $monitored_product_id, 'price_suggestion_failed', 'error' );
		}

		$event = 'blocked' === $status ? 'price_suggestion_blocked' : 'price_suggestion_created';
		$this->repository->write_log(
			'blocked' === $status ? 'warning' : 'info',
			$event,
			(string) ( $result['message'] ?? __( 'Dry-run price suggestion created.', 'lilleprinsen-price-monitor' ) ),
			array(
				'competitor_link_id' => $link_id,
				'suggestion_id'      => (int) ( $result['suggestion_id'] ?? 0 ),
				'suggestion_type'    => (string) ( $result['suggestion_type'] ?? '' ),
				'suggested_price'    => (float) ( $result['suggested_price'] ?? 0 ),
				'margin_after_change' => $result['margin_after_change'] ?? null,
				'warnings'           => $result['warnings'] ?? array(),
				'rule_details'       => $result['rule_details'] ?? array(),
			),
			(int) $monitored_product['product_id']
		);
		$this->maybe_notify_created_suggestion( $result, (int) $monitored_product['product_id'] );
		$this->set_admin_notice(
			sprintf(
				/* translators: 1: suggestion type, 2: suggested price. */
				__( 'Dry-run suggestion %1$s created for %2$s. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ),
				$this->get_suggestion_type_label( (string) ( $result['suggestion_type'] ?? 'manual_review' ) ),
				$this->format_price_amount( (float) ( $result['suggested_price'] ?? 0 ), (string) $this->settings->get( 'default_currency', 'NOK' ) )
			),
			'blocked' === $status ? 'warning' : 'success'
		);
		$this->redirect_to_competitors( $monitored_product_id, 'price_suggestion_created', 'blocked' === $status ? 'warning' : 'success' );
	}

	public function handle_approve_suggestion_dry_run(): void {
		$suggestion = $this->get_submitted_suggestion();
		$user_id    = get_current_user_id();

		if ( ! $suggestion ) {
			$this->redirect_to_approvals( 'suggestion_not_found', 'error' );
		}

		$approved = $this->repository->approve_suggestion_dry_run( (int) $suggestion['id'], $user_id );

		if ( ! $approved ) {
			$this->redirect_to_approvals( 'suggestion_approval_failed', 'error' );
		}

		$session_id = 0;

		if ( 'price_match_down' === (string) $suggestion['suggestion_type'] && empty( $suggestion['applies_to_group'] ) ) {
			$active_session = $this->repository->get_active_price_match_session_for_product( (int) $suggestion['product_id'] );

			if ( ! $active_session ) {
				$original_state = $this->price_recovery_service->get_original_price_state( (int) $suggestion['product_id'] );
				$session_id     = $this->repository->create_price_match_session(
					array_merge(
						$original_state,
						array(
							'product_id'                   => (int) $suggestion['product_id'],
							'monitored_product_id'         => (int) $suggestion['monitored_product_id'],
							'suggestion_id'                => (int) $suggestion['id'],
							'status'                       => 'active_dry_run',
							'matched_price'                => (float) $suggestion['suggested_price'],
							'matched_at'                   => current_time( 'mysql' ),
							'matched_by'                   => $user_id,
							'restore_strategy'             => 'previous_active_price',
							'recovery_strategy'            => (string) $this->settings->get( 'recovery_when_competitor_increases', 'suggest_only' ),
							'last_competitor_price'        => (float) $suggestion['competitor_price'],
							'last_lowest_competitor_price' => (float) $suggestion['competitor_price'],
							'last_checked_at'              => current_time( 'mysql' ),
						)
					)
				);

				if ( $session_id > 0 ) {
					$this->repository->write_log(
						'info',
						'dry_run_price_match_session_created',
						__( 'Dry-run price match session created after approval. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ),
						array(
							'suggestion_id' => (int) $suggestion['id'],
							'session_id'    => $session_id,
						),
						(int) $suggestion['product_id']
					);
				}
			}
		}

		if ( ! empty( $suggestion['applies_to_group'] ) && ! empty( $suggestion['group_id'] ) ) {
			$members = $this->repository->get_product_group_members( (int) $suggestion['group_id'], true );
			$session_ids = array();

			if ( 'price_match_down' === (string) $suggestion['suggestion_type'] ) {
				foreach ( $members as $member ) {
					$product_id = absint( $member['product_id'] ?? 0 );

					if ( $product_id <= 0 || $this->repository->get_active_price_match_session_for_product( $product_id ) ) {
						continue;
					}

					$original_state = $this->price_recovery_service->get_original_price_state( $product_id );
					$member_session_id = $this->repository->create_price_match_session(
						array_merge(
							$original_state,
							array(
								'product_id'                   => $product_id,
								'monitored_product_id'         => absint( $member['monitored_product_id'] ?? 0 ),
								'suggestion_id'                => (int) $suggestion['id'],
								'status'                       => 'active_dry_run',
								'matched_price'                => (float) $suggestion['suggested_price'],
								'matched_at'                   => current_time( 'mysql' ),
								'matched_by'                   => $user_id,
								'restore_strategy'             => 'previous_active_price',
								'recovery_strategy'            => (string) $this->settings->get( 'recovery_when_competitor_increases', 'suggest_only' ),
								'last_competitor_price'        => (float) $suggestion['competitor_price'],
								'last_lowest_competitor_price' => (float) $suggestion['competitor_price'],
								'last_checked_at'              => current_time( 'mysql' ),
							)
						)
					);

					if ( $member_session_id > 0 ) {
						$session_ids[] = $member_session_id;
					}
				}
			}

			$this->repository->write_log(
				'info',
				'group_suggestion_approved_dry_run',
				__( 'Dry-run group suggestion approval recorded. WooCommerce prices were not changed.', 'lilleprinsen-price-monitor' ),
				array(
					'suggestion_id' => (int) $suggestion['id'],
					'group_id'      => (int) $suggestion['group_id'],
					'member_product_ids' => wp_list_pluck( $members, 'product_id' ),
					'dry_run_session_ids' => $session_ids,
				),
				(int) $suggestion['product_id']
			);
		}

		$this->repository->write_log(
			'info',
			'price_suggestion_approved_dry_run',
			__( 'Dry-run approval recorded. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ),
			array(
				'suggestion_id' => (int) $suggestion['id'],
				'session_id'    => $session_id,
			),
			(int) $suggestion['product_id']
		);
		$this->set_admin_notice( __( 'Dry-run approval recorded. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ) );
		$this->redirect_to_approvals( 'suggestion_approved_dry_run', 'success', 'approved_dry_run' );
	}

	public function handle_reject_suggestion(): void {
		$suggestion = $this->get_submitted_suggestion();

		if ( ! $suggestion ) {
			$this->redirect_to_approvals( 'suggestion_not_found', 'error' );
		}

		$rejected = $this->repository->reject_suggestion( (int) $suggestion['id'], get_current_user_id() );

		if ( ! $rejected ) {
			$this->redirect_to_approvals( 'suggestion_reject_failed', 'error' );
		}

		$this->repository->write_log(
			'info',
			'price_suggestion_rejected',
			__( 'Price suggestion rejected.', 'lilleprinsen-price-monitor' ),
			array( 'suggestion_id' => (int) $suggestion['id'] ),
			(int) $suggestion['product_id']
		);
		$this->redirect_to_approvals( 'suggestion_rejected', 'success', 'rejected' );
	}

	public function handle_update_suggested_price(): void {
		$suggestion = $this->get_submitted_suggestion();

		if ( ! $suggestion ) {
			$this->redirect_to_approvals( 'suggestion_not_found', 'error' );
		}

		$raw_price = isset( $_POST['suggested_price'] ) ? str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['suggested_price'] ) ) ) : '';

		if ( '' === $raw_price || ! is_numeric( $raw_price ) || (float) $raw_price <= 0 ) {
			$this->redirect_to_approvals( 'suggested_price_invalid', 'error' );
		}

		$new_price = round( (float) $raw_price, 4 );
		$updated   = $this->repository->update_suggested_price( (int) $suggestion['id'], $new_price );

		if ( ! $updated ) {
			$this->redirect_to_approvals( 'suggested_price_update_failed', 'error' );
		}

		$this->repository->write_log(
			'info',
			'price_suggestion_adjusted',
			__( 'Suggested price adjusted before dry-run approval.', 'lilleprinsen-price-monitor' ),
			array(
				'suggestion_id' => (int) $suggestion['id'],
				'old_price'     => (float) $suggestion['suggested_price'],
				'new_price'     => $new_price,
			),
			(int) $suggestion['product_id']
		);
		$this->redirect_to_approvals( 'suggested_price_updated', 'success', $this->get_submitted_approval_view() );
	}

	public function handle_run_small_check_batch_now(): void {
		$result = $this->job_scheduler->run_one_small_batch_now();

		if ( ! empty( $result['locked'] ) ) {
			$this->set_admin_notice( __( 'Small batch skipped because another batch is running.', 'lilleprinsen-price-monitor' ), 'warning' );
			$this->redirect_to_tab( 'dashboard', 'small_batch_locked', array( 'lpm_notice_type' => 'warning' ) );
		}

		$this->set_admin_notice(
			sprintf(
				/* translators: 1: processed count, 2: failed count, 3: skipped count, 4: suggested count. */
				__( 'Small batch finished: %1$d processed, %2$d failed, %3$d skipped, %4$d suggestions.', 'lilleprinsen-price-monitor' ),
				(int) $result['processed'],
				(int) $result['failed'],
				(int) $result['skipped'],
				(int) $result['suggested']
			)
		);
		$this->redirect_to_tab( 'dashboard', 'small_batch_completed' );
	}

	public function handle_send_test_notification(): void {
		$settings = $this->settings->get_all();
		$this->notification_service->send(
			'test',
			__( 'Price Monitor test notification. WhatsApp is not connected yet; this was logged only.', 'lilleprinsen-price-monitor' ),
			$settings,
			array(
				'provider' => (string) ( $settings['whatsapp_provider'] ?? 'none' ),
				'phone'    => (string) ( $settings['notification_phone_number'] ?? '' ),
			),
			null,
			true
		);
		$this->redirect_to_tab( 'settings', 'test_notification_sent' );
	}

	public function handle_send_test_webhook(): void {
		$settings = $this->settings->get_all();
		$channel  = new WebhookNotificationChannel( $this->repository );
		$sent     = $channel->send(
			'webhook_test',
			__( 'Lilleprinsen Price Monitor webhook test. This payload can be forwarded to WhatsApp by Make, Zapier, or another webhook provider.', 'lilleprinsen-price-monitor' ),
			array(
				'force_webhook_test' => 1,
				'status'             => 'test',
				'reason'             => __( 'Admin-triggered webhook test.', 'lilleprinsen-price-monitor' ),
				'created_at'         => current_time( 'mysql' ),
			),
			null,
			$settings
		);

		$this->redirect_to_tab( 'settings', $sent ? 'test_webhook_sent' : 'test_webhook_failed', array( 'lpm_notice_type' => $sent ? 'success' : 'error' ) );
	}

	public function handle_run_retention_cleanup(): void {
		$summary = $this->retention_service->run_cleanup();

		$this->set_admin_notice(
			sprintf(
				/* translators: 1: logs deleted, 2: observations deleted, 3: token rows deleted. */
				__( 'Cleanup finished: %1$d logs deleted, %2$d observations deleted and %3$d token rows deleted.', 'lilleprinsen-price-monitor' ),
				(int) $summary['logs_deleted'],
				(int) $summary['observations_deleted'],
				(int) ( $summary['tokens_deleted'] ?? 0 )
			)
		);
		$this->redirect_to_tab( 'settings', 'retention_cleanup_completed' );
	}

	public function handle_end_price_match_session(): void {
		$session_id = isset( $_POST['session_id'] ) ? absint( wp_unslash( $_POST['session_id'] ) ) : 0;
		$session    = $this->repository->get_price_match_session( $session_id );

		if ( ! $session ) {
			$this->redirect_to_tab( 'history', 'price_match_session_not_found', array( 'lpm_notice_type' => 'error' ) );
		}

		if ( 'active_dry_run' !== (string) $session['status'] ) {
			$this->redirect_to_tab( 'history', 'price_match_session_end_requires_real_update', array( 'lpm_notice_type' => 'warning' ) );
		}

		$ended = $this->repository->end_price_match_session( $session_id, 'ended_manual_dry_run' );

		if ( $ended ) {
			$this->repository->write_log(
				'info',
				'price_match_session_ended_dry_run',
				__( 'Dry-run price match session ended manually. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ),
				array( 'session_id' => $session_id ),
				(int) $session['product_id']
			);
			$this->redirect_to_tab( 'history', 'price_match_session_ended' );
		}

		$this->redirect_to_tab( 'history', 'price_match_session_end_failed', array( 'lpm_notice_type' => 'error' ) );
	}

	public function handle_approve_and_update_price(): void {
		$suggestion = $this->get_submitted_suggestion();

		if ( ! $suggestion ) {
			$this->redirect_to_approvals( 'suggestion_not_found', 'error' );
		}

		check_admin_referer( 'lpm_real_price_update_' . (int) $suggestion['id'], 'lpm_real_update_nonce' );

		if ( empty( $this->settings->get( 'require_confirmation_for_real_updates', 1 ) ) ) {
			$this->redirect_to_approvals( 'real_update_confirmation_required', 'error' );
		}

		$result = ! empty( $suggestion['applies_to_group'] )
			? $this->price_update_service->apply_group_suggestion( (int) $suggestion['id'], $this->settings->get_all(), get_current_user_id() )
			: $this->price_update_service->apply_suggestion( (int) $suggestion['id'], $this->settings->get_all(), get_current_user_id() );

		if ( empty( $result['success'] ) ) {
			$this->set_admin_notice( (string) $result['message'], 'error' );
			$this->redirect_to_approvals( 'real_price_update_failed', 'error' );
		}

		$this->set_admin_notice( (string) $result['message'], ! empty( $result['group_action_status'] ) && 'partial' === (string) $result['group_action_status'] ? 'warning' : 'success' );
		$this->redirect_to_approvals( 'real_price_update_applied', 'success', 'approved_real_update' );
	}

	/**
	 * @return float|string
	 */
	private function sanitize_decimal_post_value( string $key ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}

		$value = str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );

		if ( '' === $value ) {
			return '';
		}

		return is_numeric( $value ) ? max( 0, round( (float) $value, 4 ) ) : '';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_competitor_profile_data_from_post(): array {
		$request_timeout_seconds = isset( $_POST['request_timeout_seconds'] ) ? sanitize_text_field( wp_unslash( $_POST['request_timeout_seconds'] ) ) : '';
		$domain                  = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		$platform                = isset( $_POST['competitor_platform'] ) ? sanitize_key( wp_unslash( $_POST['competitor_platform'] ) ) : 'auto';
		$platform_search_url     = isset( $_POST['competitor_platform_search_url'] ) ? esc_url_raw( wp_unslash( $_POST['competitor_platform_search_url'] ) ) : '';
		$detected_platform       = CompetitorPlatformDetector::detect( $domain, $platform_search_url, '', $platform );

		return array(
			'name'                    => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'domain'                  => $domain,
			'enabled'                 => ! empty( $_POST['enabled'] ) ? 1 : 0,
			'default_currency'        => isset( $_POST['default_currency'] ) ? sanitize_text_field( wp_unslash( $_POST['default_currency'] ) ) : 'NOK',
			'request_delay_seconds'   => isset( $_POST['request_delay_seconds'] ) ? absint( wp_unslash( $_POST['request_delay_seconds'] ) ) : 2,
			'request_timeout_seconds' => '' !== $request_timeout_seconds ? absint( $request_timeout_seconds ) : '',
			'price_extraction_mode'   => isset( $_POST['price_extraction_mode'] ) ? sanitize_key( wp_unslash( $_POST['price_extraction_mode'] ) ) : 'auto',
			'price_selector'          => isset( $_POST['price_selector'] ) ? sanitize_text_field( wp_unslash( $_POST['price_selector'] ) ) : '',
			'regular_price_selector'  => isset( $_POST['regular_price_selector'] ) ? sanitize_text_field( wp_unslash( $_POST['regular_price_selector'] ) ) : '',
			'sale_price_selector'     => isset( $_POST['sale_price_selector'] ) ? sanitize_text_field( wp_unslash( $_POST['sale_price_selector'] ) ) : '',
			'sku_selector'            => isset( $_POST['sku_selector'] ) ? sanitize_text_field( wp_unslash( $_POST['sku_selector'] ) ) : '',
			'gtin_selector'           => isset( $_POST['gtin_selector'] ) ? sanitize_text_field( wp_unslash( $_POST['gtin_selector'] ) ) : '',
			'monitored_price_field'   => isset( $_POST['monitored_price_field'] ) ? sanitize_key( wp_unslash( $_POST['monitored_price_field'] ) ) : 'sale_price_first',
			'stock_selector'          => isset( $_POST['stock_selector'] ) ? sanitize_text_field( wp_unslash( $_POST['stock_selector'] ) ) : '',
			'stock_in_text'           => isset( $_POST['stock_in_text'] ) ? sanitize_text_field( wp_unslash( $_POST['stock_in_text'] ) ) : '',
			'stock_out_text'          => isset( $_POST['stock_out_text'] ) ? sanitize_text_field( wp_unslash( $_POST['stock_out_text'] ) ) : '',
			'json_ld_enabled'         => ! empty( $_POST['json_ld_enabled'] ) ? 1 : 0,
			'meta_tags_enabled'       => ! empty( $_POST['meta_tags_enabled'] ) ? 1 : 0,
			'visible_regex_enabled'   => ! empty( $_POST['visible_regex_enabled'] ) ? 1 : 0,
			'requires_javascript'     => ! empty( $_POST['requires_javascript'] ) || ! empty( $detected_platform['requires_javascript'] ) ? 1 : 0,
			'notes'                   => $this->notes_with_onboarding_settings_from_post( isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function get_external_worker_mode_options(): array {
		return array(
			'internal' => __( 'Internal checker only', 'lilleprinsen-price-monitor' ),
			'js'       => __( 'External worker for JS-heavy pages', 'lilleprinsen-price-monitor' ),
			'always'   => __( 'External worker always', 'lilleprinsen-price-monitor' ),
		);
	}

	/**
	 * @return array{mode:string,search_enabled:int,product_enabled:int}
	 */
	private function competitor_worker_settings_from_notes( string $notes ): array {
		$decoded = '' !== trim( $notes ) && '{' === substr( trim( $notes ), 0, 1 ) ? json_decode( $notes, true ) : array();
		$decoded = is_array( $decoded ) ? $decoded : array();
		$mode    = sanitize_key( (string) ( $decoded['external_browser_worker_mode'] ?? ( ! empty( $decoded['external_browser_worker_enabled'] ) ? 'js' : 'internal' ) ) );

		return array(
			'mode'            => in_array( $mode, array( 'internal', 'js', 'always' ), true ) ? $mode : 'internal',
			'search_enabled'  => empty( $decoded['external_browser_worker_search_enabled'] ) ? 0 : 1,
			'product_enabled' => empty( $decoded['external_browser_worker_product_enabled'] ) ? 0 : 1,
		);
	}

	/**
	 * @return array{platform:string,search_url:string,templates:array<int,string>,signals:array<int,string>,confidence:string,label:string}
	 */
	private function competitor_onboarding_settings_from_notes( string $notes ): array {
		$decoded = '' !== trim( $notes ) && '{' === substr( trim( $notes ), 0, 1 ) ? json_decode( $notes, true ) : array();
		$decoded = is_array( $decoded ) ? $decoded : array();
		$platform = sanitize_key( (string) ( $decoded['platform'] ?? 'auto' ) );
		$options  = CompetitorPlatformDetector::platform_options();
		if ( ! isset( $options[ $platform ] ) ) {
			$platform = 'auto';
		}

		return array(
			'platform'   => $platform,
			'search_url' => sanitize_text_field( (string) ( $decoded['platform_search_url'] ?? '' ) ),
			'templates'  => isset( $decoded['search_url_templates'] ) && is_array( $decoded['search_url_templates'] ) ? array_values( array_map( 'strval', $decoded['search_url_templates'] ) ) : array(),
			'signals'    => isset( $decoded['platform_detection_signals'] ) && is_array( $decoded['platform_detection_signals'] ) ? array_values( array_map( 'strval', $decoded['platform_detection_signals'] ) ) : array(),
			'confidence' => sanitize_key( (string) ( $decoded['platform_detection_confidence'] ?? '' ) ),
			'label'      => (string) ( $options[ $platform ]['label'] ?? $options['auto']['label'] ),
		);
	}

	private function notes_with_onboarding_settings_from_post( string $notes ): string {
		$notes = $this->notes_with_worker_settings_from_post( $notes );
		$decoded = json_decode( $notes, true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		$platform = isset( $_POST['competitor_platform'] ) ? sanitize_key( wp_unslash( $_POST['competitor_platform'] ) ) : 'auto';
		$search_url = isset( $_POST['competitor_platform_search_url'] ) ? esc_url_raw( wp_unslash( $_POST['competitor_platform_search_url'] ) ) : '';
		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		$detection = CompetitorPlatformDetector::detect( $domain, $search_url, '', $platform );

		$decoded['platform'] = $detection['platform'];
		$decoded['platform_search_url'] = $search_url;
		$decoded['platform_detection_confidence'] = $detection['confidence'];
		$decoded['platform_detection_signals'] = $detection['signals'];
		if ( ! empty( $detection['templates'] ) ) {
			$decoded['search_url_templates'] = $detection['templates'];
		}

		if ( 'auto' === $platform && ! empty( $detection['requires_javascript'] ) ) {
			$decoded['platform_requires_javascript_hint'] = true;
		}

		return (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	private function notes_with_worker_settings_from_post( string $notes ): string {
		$trimmed = trim( $notes );
		$decoded = '' !== $trimmed && '{' === substr( $trimmed, 0, 1 ) ? json_decode( $trimmed, true ) : array();
		$decoded = is_array( $decoded ) ? $decoded : array();
		if ( empty( $decoded ) && '' !== $trimmed ) {
			$decoded['admin_notes'] = $trimmed;
		}

		$mode = isset( $_POST['external_worker_mode'] ) ? sanitize_key( wp_unslash( $_POST['external_worker_mode'] ) ) : 'internal';
		if ( ! in_array( $mode, array( 'internal', 'js', 'always' ), true ) ) {
			$mode = 'internal';
		}

		$decoded['external_browser_worker_mode'] = $mode;
		$decoded['external_browser_worker_search_enabled'] = ! empty( $_POST['external_worker_search_enabled'] );
		$decoded['external_browser_worker_product_enabled'] = ! empty( $_POST['external_worker_product_enabled'] );
		unset( $decoded['external_browser_worker_enabled'] );

		return (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param array<string, mixed> $result Test check result.
	 */
	private function store_competitor_profile_test_result( int $profile_id, string $url, array $result ): string {
		$token = wp_generate_password( 20, false, false );
		set_transient(
			$this->get_competitor_profile_test_transient_key( $token ),
			array_merge(
				$result,
				array(
					'competitor_id' => $profile_id,
					'url'           => $url,
				)
			),
			15 * MINUTE_IN_SECONDS
		);

		return $token;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function get_competitor_profile_test_result(): ?array {
		$token = isset( $_GET['profile_test_token'] ) ? sanitize_key( wp_unslash( $_GET['profile_test_token'] ) ) : '';

		if ( '' === $token ) {
			return null;
		}

		$result = get_transient( $this->get_competitor_profile_test_transient_key( $token ) );

		return is_array( $result ) ? $result : null;
	}

	private function get_competitor_profile_test_transient_key( string $token ): string {
		return 'lpm_profile_test_' . get_current_user_id() . '_' . sanitize_key( $token );
	}

	/**
	 * @return array<int, int>
	 */
	private function get_selected_ids_from_post( string $key ): array {
		if ( empty( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST[ $key ] ) ) ) ) );

		return array_slice( $ids, 0, self::BULK_MAX_IDS );
	}

	/**
	 * @param array<string, mixed> $monitored_product Current monitored product row.
	 * @return array<string, mixed>
	 */
	private function build_bulk_monitored_rule_data( array $monitored_product, string $bulk_action ): array {
		$data = $this->monitored_rule_log_snapshot( $monitored_product );

		if ( 'set_priority' === $bulk_action ) {
			$data['priority'] = isset( $_POST['bulk_priority'] ) ? sanitize_key( wp_unslash( $_POST['bulk_priority'] ) ) : $data['priority'];
		}

		if ( 'set_strategy' === $bulk_action ) {
			$data['strategy'] = isset( $_POST['bulk_strategy'] ) ? sanitize_key( wp_unslash( $_POST['bulk_strategy'] ) ) : $data['strategy'];
		}

		if ( 'set_check_frequency' === $bulk_action ) {
			$frequency = isset( $_POST['bulk_check_frequency_hours'] ) ? absint( wp_unslash( $_POST['bulk_check_frequency_hours'] ) ) : (int) $data['check_frequency_hours'];
			$data['check_frequency_hours'] = Settings::sanitize_check_interval_hours( $frequency );
		}

		if ( 'set_min_margin' === $bulk_action ) {
			$data['min_margin_percent'] = $this->sanitize_decimal_post_value( 'bulk_min_margin_percent' );
		}

		if ( 'set_min_price' === $bulk_action ) {
			$data['min_price'] = $this->sanitize_decimal_post_value( 'bulk_min_price' );
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $monitored_product Monitored product row.
	 * @return array<string, mixed>
	 */
	private function monitored_rule_log_snapshot( array $monitored_product ): array {
		return array(
			'enabled'               => (int) ( $monitored_product['enabled'] ?? 0 ),
			'priority'              => (string) ( $monitored_product['priority'] ?? '' ),
			'strategy'              => (string) ( $monitored_product['strategy'] ?? '' ),
			'min_margin_percent'    => (string) ( $monitored_product['min_margin_percent'] ?? '' ),
			'min_price'             => (string) ( $monitored_product['min_price'] ?? '' ),
			'check_frequency_hours' => (int) ( $monitored_product['check_frequency_hours'] ?? 0 ),
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function get_submitted_suggestion(): ?array {
		$suggestion_id = isset( $_POST['suggestion_id'] ) ? absint( wp_unslash( $_POST['suggestion_id'] ) ) : 0;

		if ( $suggestion_id <= 0 ) {
			return null;
		}

		return $this->repository->get_price_suggestion( $suggestion_id );
	}

	/**
	 * @param array<string, mixed> $result Suggestion service result.
	 */
	private function maybe_notify_created_suggestion( array $result, int $product_id ): void {
		$settings = $this->settings->get_all();
		$status   = (string) ( $result['status'] ?? '' );

		if ( ! in_array( $status, array( 'pending', 'blocked' ), true ) ) {
			return;
		}

		$this->notification_service->send(
			'price_suggestion_' . $status,
			__( 'Price Monitor would send a suggestion notification.', 'lilleprinsen-price-monitor' ),
			$settings,
			$result,
			$product_id
		);
	}

	private function render_product_search_results( array $products, string $search_query ): void {
		?>
		<div class="lpm-results">
			<h3><?php esc_html_e( 'Search results', 'lilleprinsen-price-monitor' ); ?></h3>
			<?php if ( empty( $products ) ) : ?>
				<p class="lpm-empty"><?php printf( esc_html__( 'No products found for "%s".', 'lilleprinsen-price-monitor' ), esc_html( $search_query ) ); ?></p>
			<?php else : ?>
				<table class="lpm-compact-table lpm-product-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Image', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Product name', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Product ID', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'SKU', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Current price', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Stock status', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Action', 'lilleprinsen-price-monitor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $products as $product ) : ?>
							<tr>
								<td><?php echo wp_kses_post( (string) ( $product['thumbnail'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $product['name'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $product['id'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $product['sku'] ?? '—' ) ); ?></td>
								<td><?php echo wp_kses_post( (string) ( $product['price_html'] ?? '—' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $product['stock_status'] ?? __( 'Unknown', 'lilleprinsen-price-monitor' ) ) ); ?></td>
								<td><?php $this->render_add_monitoring_form( (int) ( $product['id'] ?? 0 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_monitored_products_table( array $rows, array $link_counts, array $pending_counts = array(), array $group_names = array(), array $discovery_rows = array(), array $match_counts = array(), int $active_competitor_count = 0 ): void {
		if ( empty( $rows ) ) {
			$this->render_empty_state( __( 'No products are monitored yet.', 'lilleprinsen-price-monitor' ) );
			return;
		}
		?>
		<table class="lpm-compact-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Select', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Product', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'SKU', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'EAN/GTIN', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Brand', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Monitoring status', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Competitor matches', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Pending suggestions', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last checked', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $product = $this->get_product( (int) $row['product_id'] ); ?>
					<?php $discovery_row = $discovery_rows[ (int) $row['id'] ] ?? null; ?>
					<?php $discovery_id = $discovery_row ? (int) $discovery_row->id : 0; ?>
					<tr data-lpm-monitored-row="<?php echo esc_attr( (string) $row['id'] ); ?>" tabindex="0">
						<td><input form="lpm-products-bulk-form" type="checkbox" name="monitored_product_ids[]" value="<?php echo esc_attr( (string) $row['id'] ); ?>" /></td>
						<td>
							<div class="lpm-product-cell">
								<?php echo wp_kses_post( $product ? $this->get_product_thumbnail( $product ) : '' ); ?>
								<span>
									<?php echo esc_html( $product ? $this->get_product_name( $product ) : sprintf( __( 'Product #%d', 'lilleprinsen-price-monitor' ), (int) $row['product_id'] ) ); ?>
									<small><?php printf( esc_html__( 'ID %d', 'lilleprinsen-price-monitor' ), (int) $row['product_id'] ); ?></small>
								</span>
							</div>
						</td>
						<td><?php echo esc_html( $product ? $this->get_product_sku( $product ) : (string) ( $row['sku'] ?? '—' ) ); ?></td>
						<td><?php echo esc_html( $product ? $this->get_product_gtin( $product ) : '—' ); ?></td>
						<td><?php echo esc_html( $product ? $this->get_product_brand( $product ) : '—' ); ?></td>
						<td>
							<?php $this->render_status_pill( ! empty( $row['enabled'] ) ? __( 'Active', 'lilleprinsen-price-monitor' ) : __( 'Paused', 'lilleprinsen-price-monitor' ), ! empty( $row['enabled'] ) ? 'ok' : 'muted' ); ?>
							<?php if ( ! empty( $group_names[ (int) $row['id'] ] ) ) : ?>
								<?php $this->render_status_pill( (string) $group_names[ (int) $row['id'] ], 'ok' ); ?>
							<?php endif; ?>
							<details class="lpm-row-details">
								<summary><?php esc_html_e( 'Rules', 'lilleprinsen-price-monitor' ); ?></summary>
								<?php printf( esc_html__( 'Strategy: %s', 'lilleprinsen-price-monitor' ), esc_html( (string) $row['strategy'] ) ); ?><br>
								<?php printf( esc_html__( 'Priority: %s', 'lilleprinsen-price-monitor' ), esc_html( (string) $row['priority'] ) ); ?><br>
								<?php printf( esc_html__( 'Min margin: %s', 'lilleprinsen-price-monitor' ), esc_html( $this->format_percent_value( $row['min_margin_percent'] ?? null ) ) ); ?><br>
								<?php printf( esc_html__( 'Min price: %s', 'lilleprinsen-price-monitor' ), esc_html( $this->format_nullable_value( $row['min_price'] ?? null ) ) ); ?><br>
								<?php printf( esc_html__( 'Frequency: %d h', 'lilleprinsen-price-monitor' ), (int) $row['check_frequency_hours'] ); ?>
							</details>
						</td>
						<td>
							<?php
							printf(
								esc_html__( '%1$s / %2$s competitors matched', 'lilleprinsen-price-monitor' ),
								esc_html( number_format_i18n( (int) ( $link_counts[ (int) $row['id'] ] ?? 0 ) ) ),
								esc_html( number_format_i18n( $active_competitor_count ) )
							);
							?>
							<br><small><?php echo esc_html( $discovery_row && ! empty( $discovery_row->last_discovery_at ) ? sprintf( __( 'Last discovery: %s', 'lilleprinsen-price-monitor' ), $this->format_datetime( $discovery_row->last_discovery_at ) ) : __( 'Discovery not run yet', 'lilleprinsen-price-monitor' ) ); ?></small>
						</td>
						<td>
							<?php
							printf(
								esc_html__( 'Price: %1$s · Matches: %2$s', 'lilleprinsen-price-monitor' ),
								esc_html( number_format_i18n( (int) ( $pending_counts[ (int) $row['id'] ] ?? 0 ) ) ),
								esc_html( number_format_i18n( $discovery_id > 0 ? (int) ( $match_counts[ $discovery_id ] ?? 0 ) : 0 ) )
							);
							?>
						</td>
						<td><?php echo esc_html( $this->format_datetime( $row['last_checked_at'] ?? null ) ); ?></td>
						<td>
							<div class="lpm-actions">
								<button type="button" class="button button-small button-primary" data-lpm-start-product="<?php echo esc_attr( (string) $discovery_id ); ?>" <?php disabled( $discovery_id <= 0 ); ?>><?php esc_html_e( 'Find matches', 'lilleprinsen-price-monitor' ); ?></button>
								<button type="button" class="button button-small" data-lpm-open-product="<?php echo esc_attr( (string) $row['id'] ); ?>"><?php esc_html_e( 'View matches', 'lilleprinsen-price-monitor' ); ?></button>
								<details class="lpm-row-actions">
									<summary><?php esc_html_e( 'More', 'lilleprinsen-price-monitor' ); ?></summary>
									<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'products', 'edit_rules_id' => (int) $row['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Rules', 'lilleprinsen-price-monitor' ); ?></a>
									<?php $this->render_monitored_action_form( (int) $row['id'], ! empty( $row['enabled'] ) ? 'disable_monitored' : 'enable_monitored', ! empty( $row['enabled'] ) ? __( 'Pause', 'lilleprinsen-price-monitor' ) : __( 'Activate', 'lilleprinsen-price-monitor' ) ); ?>
									<?php $this->render_monitored_action_form( (int) $row['id'], 'remove_monitored', __( 'Remove', 'lilleprinsen-price-monitor' ), 'link-delete' ); ?>
								</details>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_monitored_bulk_controls(): void {
		?>
		<form id="lpm-products-bulk-form" method="post" class="lpm-filters">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="bulk_monitored_products" />
			<label>
				<span><?php esc_html_e( 'Bulk action', 'lilleprinsen-price-monitor' ); ?></span>
				<select name="bulk_monitored_action">
					<option value="enable"><?php esc_html_e( 'Enable selected', 'lilleprinsen-price-monitor' ); ?></option>
					<option value="disable"><?php esc_html_e( 'Disable selected', 'lilleprinsen-price-monitor' ); ?></option>
					<option value="set_priority"><?php esc_html_e( 'Set priority', 'lilleprinsen-price-monitor' ); ?></option>
					<option value="set_strategy"><?php esc_html_e( 'Set strategy', 'lilleprinsen-price-monitor' ); ?></option>
					<option value="set_check_frequency"><?php esc_html_e( 'Set check frequency', 'lilleprinsen-price-monitor' ); ?></option>
					<option value="set_min_margin"><?php esc_html_e( 'Set min margin', 'lilleprinsen-price-monitor' ); ?></option>
					<option value="set_min_price"><?php esc_html_e( 'Set min price', 'lilleprinsen-price-monitor' ); ?></option>
					<option value="remove"><?php esc_html_e( 'Disable selected monitoring rows', 'lilleprinsen-price-monitor' ); ?></option>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Priority', 'lilleprinsen-price-monitor' ); ?></span>
				<select name="bulk_priority">
					<?php foreach ( $this->get_priority_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Strategy', 'lilleprinsen-price-monitor' ); ?></span>
				<select name="bulk_strategy">
					<?php foreach ( $this->get_pricing_strategy_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Frequency hours', 'lilleprinsen-price-monitor' ); ?></span>
				<select name="bulk_check_frequency_hours">
					<?php foreach ( Settings::check_interval_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( 24, (int) $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Min margin', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="number" min="0" step="0.01" name="bulk_min_margin_percent" />
			</label>
			<label>
				<span><?php esc_html_e( 'Min price', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="number" min="0" step="0.01" name="bulk_min_price" />
			</label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply to selected', 'lilleprinsen-price-monitor' ); ?></button>
		</form>
		<?php
	}

	private function render_product_group_form( ?array $group ): void {
		$is_edit = is_array( $group );
		?>
		<form method="post" class="lpm-stacked-form">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="<?php echo esc_attr( $is_edit ? 'update_product_group' : 'create_product_group' ); ?>" />
			<?php if ( $is_edit ) : ?>
				<input type="hidden" name="group_id" value="<?php echo esc_attr( (string) $group['id'] ); ?>" />
			<?php endif; ?>
			<label class="lpm-field">
				<span><?php esc_html_e( 'Group name', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="group_name" required maxlength="191" value="<?php echo esc_attr( $is_edit ? (string) $group['name'] : '' ); ?>" />
			</label>
			<label class="lpm-field">
				<span><?php esc_html_e( 'Description', 'lilleprinsen-price-monitor' ); ?></span>
				<textarea name="description" rows="3"><?php echo esc_textarea( $is_edit ? (string) ( $group['description'] ?? '' ) : '' ); ?></textarea>
			</label>
			<label class="lpm-field">
				<span><?php esc_html_e( 'Pricing mode', 'lilleprinsen-price-monitor' ); ?></span>
				<select name="pricing_mode">
					<?php foreach ( $this->get_group_pricing_mode_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $is_edit ? (string) $group['pricing_mode'] : 'shared_price', $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="lpm-field">
				<span><?php esc_html_e( 'Primary product ID', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="number" min="0" step="1" name="primary_product_id" value="<?php echo esc_attr( $is_edit ? (string) ( $group['primary_product_id'] ?? '' ) : '' ); ?>" />
				<small><?php esc_html_e( 'Optional. You can also set primary from the members table.', 'lilleprinsen-price-monitor' ); ?></small>
			</label>
			<label class="lpm-field lpm-field-checkbox">
				<input type="hidden" name="enabled" value="0" />
				<input type="checkbox" name="enabled" value="1" <?php checked( $is_edit ? ! empty( $group['enabled'] ) : true ); ?> />
				<span><strong><?php esc_html_e( 'Enabled', 'lilleprinsen-price-monitor' ); ?></strong></span>
			</label>
			<div class="lpm-form-actions">
				<button type="submit" class="button button-primary"><?php echo esc_html( $is_edit ? __( 'Save group', 'lilleprinsen-price-monitor' ) : __( 'Create group', 'lilleprinsen-price-monitor' ) ); ?></button>
				<?php if ( $is_edit ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'groups' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Cancel edit', 'lilleprinsen-price-monitor' ); ?></a>
				<?php endif; ?>
			</div>
		</form>
		<?php
	}

	private function render_product_groups_table( array $groups ): void {
		if ( empty( $groups ) ) {
			$this->render_empty_state( __( 'No product groups have been created yet.', 'lilleprinsen-price-monitor' ) );
			return;
		}
		?>
		<table class="lpm-compact-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Group name', 'lilleprinsen-price-monitor' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Enabled', 'lilleprinsen-price-monitor' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Pricing mode', 'lilleprinsen-price-monitor' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Primary product', 'lilleprinsen-price-monitor' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Products', 'lilleprinsen-price-monitor' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Health', 'lilleprinsen-price-monitor' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last suggestion', 'lilleprinsen-price-monitor' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $groups as $group ) : ?>
					<?php $health = $this->repository->get_product_group_health( (int) $group['id'] ); ?>
					<tr>
						<td><strong><?php echo esc_html( (string) $group['name'] ); ?></strong><?php if ( ! empty( $group['description'] ) ) : ?><small><?php echo esc_html( $this->shorten_text( (string) $group['description'], 80 ) ); ?></small><?php endif; ?></td>
						<td><?php $this->render_status_pill( ! empty( $group['enabled'] ) ? __( 'Yes', 'lilleprinsen-price-monitor' ) : __( 'No', 'lilleprinsen-price-monitor' ), ! empty( $group['enabled'] ) ? 'ok' : 'muted' ); ?></td>
						<td><?php echo esc_html( $this->get_group_pricing_mode_label( (string) $group['pricing_mode'] ) ); ?></td>
						<td><?php echo esc_html( ! empty( $group['primary_product_id'] ) ? (string) $group['primary_product_id'] : '—' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $group['member_count'] ?? 0 ) ) ); ?></td>
						<td>
							<div class="lpm-inline-meta">
								<?php $this->render_status_pill( sprintf( __( '%d real', 'lilleprinsen-price-monitor' ), (int) $health['active_real'] ), (int) $health['active_real'] > 0 ? 'ok' : 'muted' ); ?>
								<?php $this->render_status_pill( sprintf( __( '%d dry-run', 'lilleprinsen-price-monitor' ), (int) $health['active_dry_run'] ), (int) $health['active_dry_run'] > 0 ? 'warning' : 'muted' ); ?>
								<?php $this->render_status_pill( sprintf( __( '%d warnings', 'lilleprinsen-price-monitor' ), (int) $health['safety_warnings'] ), (int) $health['safety_warnings'] > 0 ? 'danger' : 'ok' ); ?>
							</div>
							<?php if ( ! empty( $health['warnings'] ) ) : ?>
								<small><?php echo esc_html( implode( ' ', array_map( 'strval', (array) $health['warnings'] ) ) ); ?></small>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $this->format_datetime( $group['last_suggestion'] ?? null ) ); ?></td>
						<td><div class="lpm-actions">
							<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'groups', 'manage_group_id' => (int) $group['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Manage members', 'lilleprinsen-price-monitor' ); ?></a>
							<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'groups', 'group_id' => (int) $group['id'], 'manage_group_id' => (int) $group['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'lilleprinsen-price-monitor' ); ?></a>
							<?php $this->render_product_group_action_form( (int) $group['id'], ! empty( $group['enabled'] ) ? 'disable_product_group' : 'enable_product_group', ! empty( $group['enabled'] ) ? __( 'Disable', 'lilleprinsen-price-monitor' ) : __( 'Enable', 'lilleprinsen-price-monitor' ) ); ?>
							<?php $this->render_product_group_action_form( (int) $group['id'], 'delete_product_group', __( 'Delete if empty', 'lilleprinsen-price-monitor' ), 'link-delete' ); ?>
						</div></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_product_group_members_panel( array $group ): void {
		$members  = $this->repository->get_product_group_members( (int) $group['id'] );
		$query    = isset( $_GET['lpm_group_member_search'] ) ? sanitize_text_field( wp_unslash( $_GET['lpm_group_member_search'] ) ) : '';
		$products = '' !== $query ? $this->product_search_service->search( $query, 20 ) : array();
		?>
		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<div>
					<h2><?php printf( esc_html__( 'Members: %s', 'lilleprinsen-price-monitor' ), esc_html( (string) $group['name'] ) ); ?></h2>
					<p class="lpm-card-subtitle"><?php esc_html_e( 'Search monitored products by name, SKU or ID. Results are limited to 20.', 'lilleprinsen-price-monitor' ); ?></p>
				</div>
				<?php $this->render_status_pill( $this->get_group_pricing_mode_label( (string) $group['pricing_mode'] ), 'muted' ); ?>
			</div>
			<form method="get" class="lpm-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="tab" value="groups" />
				<input type="hidden" name="manage_group_id" value="<?php echo esc_attr( (string) $group['id'] ); ?>" />
				<input type="search" name="lpm_group_member_search" value="<?php echo esc_attr( $query ); ?>" placeholder="<?php esc_attr_e( 'Product name, SKU or ID', 'lilleprinsen-price-monitor' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'lilleprinsen-price-monitor' ); ?></button>
			</form>
			<?php $this->render_product_group_member_search_results( (int) $group['id'], $products, $query ); ?>
			<?php $this->render_product_group_members_table( (int) $group['id'], $members ); ?>
		</section>
		<?php
	}

	private function render_product_group_member_search_results( int $group_id, array $products, string $query ): void {
		if ( '' === $query ) {
			return;
		}

		$rows = array();
		foreach ( $products as $product ) {
			$monitored = $this->repository->get_monitored_product_by_product_id( (int) ( $product['id'] ?? 0 ) );
			if ( $monitored ) {
				$rows[] = array( 'product' => $product, 'monitored' => $monitored );
			}
		}
		?>
		<div class="lpm-results">
			<h3><?php esc_html_e( 'Monitored product results', 'lilleprinsen-price-monitor' ); ?></h3>
			<?php if ( empty( $rows ) ) : ?>
				<p class="lpm-empty"><?php esc_html_e( 'No monitored products matched this search. Add the product to monitoring first.', 'lilleprinsen-price-monitor' ); ?></p>
			<?php else : ?>
				<table class="lpm-compact-table">
					<thead><tr><th><?php esc_html_e( 'Product', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'SKU', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Price', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Action', 'lilleprinsen-price-monitor' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $row['product']['name'] ?? '' ) ); ?> <small><?php printf( esc_html__( 'ID %d', 'lilleprinsen-price-monitor' ), (int) $row['product']['id'] ); ?></small></td>
								<td><?php echo esc_html( (string) ( $row['product']['sku'] ?? '' ) ); ?></td>
								<td><?php echo wp_kses_post( (string) ( $row['product']['price_html'] ?? '—' ) ); ?></td>
								<td><?php $this->render_product_group_member_action_form( $group_id, 0, 'add_product_group_member', __( 'Add member', 'lilleprinsen-price-monitor' ), '', (int) $row['monitored']['id'], (int) $row['monitored']['product_id'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_product_group_members_table( int $group_id, array $members ): void {
		if ( empty( $members ) ) {
			$this->render_empty_state( __( 'No products have been added to this group yet.', 'lilleprinsen-price-monitor' ) );
			return;
		}
		?>
		<table class="lpm-compact-table">
			<thead><tr><th><?php esc_html_e( 'Product', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'SKU', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Role', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Enabled', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Min price', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $members as $member ) : ?>
					<?php $product = $this->get_product( (int) $member['product_id'] ); ?>
					<tr>
						<td><?php echo esc_html( $product ? $this->get_product_name( $product ) : sprintf( __( 'Product #%d', 'lilleprinsen-price-monitor' ), (int) $member['product_id'] ) ); ?> <small><?php echo esc_html( (string) $member['product_id'] ); ?></small></td>
						<td><?php echo esc_html( (string) ( $member['sku'] ?? '' ) ); ?></td>
						<td><?php $this->render_status_pill( 'primary' === (string) $member['role'] ? __( 'Primary', 'lilleprinsen-price-monitor' ) : __( 'Member', 'lilleprinsen-price-monitor' ), 'primary' === (string) $member['role'] ? 'ok' : 'muted' ); ?></td>
						<td><?php $this->render_status_pill( ! empty( $member['enabled'] ) ? __( 'Yes', 'lilleprinsen-price-monitor' ) : __( 'No', 'lilleprinsen-price-monitor' ), ! empty( $member['enabled'] ) ? 'ok' : 'muted' ); ?></td>
						<td><?php echo esc_html( $this->format_nullable_value( $member['min_price'] ?? null ) ); ?></td>
						<td><div class="lpm-actions">
							<?php if ( 'primary' !== (string) $member['role'] ) : ?>
								<?php $this->render_product_group_member_action_form( $group_id, (int) $member['id'], 'set_product_group_primary_member', __( 'Set primary', 'lilleprinsen-price-monitor' ), '', 0, (int) $member['product_id'] ); ?>
							<?php endif; ?>
							<?php $this->render_product_group_member_action_form( $group_id, (int) $member['id'], ! empty( $member['enabled'] ) ? 'disable_product_group_member' : 'enable_product_group_member', ! empty( $member['enabled'] ) ? __( 'Disable', 'lilleprinsen-price-monitor' ) : __( 'Enable', 'lilleprinsen-price-monitor' ) ); ?>
							<?php $this->render_product_group_member_action_form( $group_id, (int) $member['id'], 'remove_product_group_member', __( 'Remove', 'lilleprinsen-price-monitor' ), 'link-delete' ); ?>
						</div></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_product_group_action_form( int $group_id, string $action, string $label, string $class = '' ): void {
		?>
		<form method="post" class="lpm-inline-action">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="group_id" value="<?php echo esc_attr( (string) $group_id ); ?>" />
			<button type="submit" class="button button-small <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function render_product_group_member_action_form( int $group_id, int $member_id, string $action, string $label, string $class = '', int $monitored_product_id = 0, int $product_id = 0 ): void {
		?>
		<form method="post" class="lpm-inline-action">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="group_id" value="<?php echo esc_attr( (string) $group_id ); ?>" />
			<?php if ( $member_id > 0 ) : ?><input type="hidden" name="member_id" value="<?php echo esc_attr( (string) $member_id ); ?>" /><?php endif; ?>
			<?php if ( $monitored_product_id > 0 ) : ?><input type="hidden" name="monitored_product_id" value="<?php echo esc_attr( (string) $monitored_product_id ); ?>" /><input type="hidden" name="role" value="member" /><?php endif; ?>
			<?php if ( $product_id > 0 ) : ?><input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product_id ); ?>" /><?php endif; ?>
			<button type="submit" class="button button-small <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function render_competitor_bulk_controls(): void {
		?>
		<form id="lpm-competitors-bulk-form" method="post" class="lpm-filters">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="bulk_competitor_links" />
			<label>
				<span><?php esc_html_e( 'Bulk action', 'lilleprinsen-price-monitor' ); ?></span>
				<select name="bulk_competitor_action">
					<option value="enable"><?php esc_html_e( 'Enable selected', 'lilleprinsen-price-monitor' ); ?></option>
					<option value="disable"><?php esc_html_e( 'Disable selected', 'lilleprinsen-price-monitor' ); ?></option>
					<option value="set_match_type"><?php esc_html_e( 'Set match type', 'lilleprinsen-price-monitor' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete selected', 'lilleprinsen-price-monitor' ); ?></option>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Match type', 'lilleprinsen-price-monitor' ); ?></span>
				<select name="bulk_match_type">
					<?php foreach ( $this->get_match_type_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply to selected', 'lilleprinsen-price-monitor' ); ?></button>
		</form>
		<?php
	}

	private function render_monitored_rules_editor( array $monitored_product ): void {
		$product = $this->get_product( (int) $monitored_product['product_id'] );
		?>
		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<div>
					<h2><?php esc_html_e( 'Edit product pricing rules', 'lilleprinsen-price-monitor' ); ?></h2>
					<p class="lpm-card-subtitle"><?php echo esc_html( $product ? $this->get_product_name( $product ) : sprintf( __( 'Product #%d', 'lilleprinsen-price-monitor' ), (int) $monitored_product['product_id'] ) ); ?></p>
				</div>
				<?php $this->render_status_pill( __( 'Product override', 'lilleprinsen-price-monitor' ), 'warning' ); ?>
			</div>
			<form method="post" class="lpm-stacked-form">
				<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
				<input type="hidden" name="lpm_action" value="update_monitored_rules" />
				<input type="hidden" name="monitored_product_id" value="<?php echo esc_attr( (string) $monitored_product['id'] ); ?>" />

				<label class="lpm-field lpm-field-checkbox">
					<input type="hidden" name="enabled" value="0" />
					<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $monitored_product['enabled'] ) ); ?> />
					<span><strong><?php esc_html_e( 'Enabled', 'lilleprinsen-price-monitor' ); ?></strong></span>
				</label>

				<label class="lpm-field">
					<span><?php esc_html_e( 'Priority', 'lilleprinsen-price-monitor' ); ?></span>
					<select name="priority">
						<?php foreach ( $this->get_priority_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $monitored_product['priority'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="lpm-field">
					<span><?php esc_html_e( 'Pricing strategy', 'lilleprinsen-price-monitor' ); ?></span>
					<select name="strategy">
						<?php foreach ( $this->get_pricing_strategy_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $monitored_product['strategy'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="lpm-field">
					<span><?php esc_html_e( 'Minimum margin percent', 'lilleprinsen-price-monitor' ); ?></span>
					<input type="number" min="0" step="0.01" name="min_margin_percent" value="<?php echo esc_attr( $this->format_decimal_for_optional_input( $monitored_product['min_margin_percent'] ?? null ) ); ?>" />
					<small><?php esc_html_e( 'Leave empty to use the global default margin setting.', 'lilleprinsen-price-monitor' ); ?></small>
				</label>

				<label class="lpm-field">
					<span><?php esc_html_e( 'Minimum price', 'lilleprinsen-price-monitor' ); ?></span>
					<input type="number" min="0" step="0.01" name="min_price" value="<?php echo esc_attr( $this->format_decimal_for_optional_input( $monitored_product['min_price'] ?? null ) ); ?>" />
					<small><?php esc_html_e( 'Suggestions below this product-level floor are blocked.', 'lilleprinsen-price-monitor' ); ?></small>
				</label>

				<label class="lpm-field">
					<span><?php esc_html_e( 'Check frequency hours', 'lilleprinsen-price-monitor' ); ?></span>
					<select name="check_frequency_hours">
						<?php foreach ( Settings::check_interval_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (int) $monitored_product['check_frequency_hours'], (int) $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<div class="lpm-form-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save rules', 'lilleprinsen-price-monitor' ); ?></button>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'products' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'lilleprinsen-price-monitor' ); ?></a>
				</div>
			</form>
		</section>
		<?php
	}

	private function render_competitor_profiles(): void {
		$page              = $this->get_positive_query_arg( 'lpm_competitor_profiles_page', 1 );
		$per_page          = (int) $this->settings->get( 'rows_per_page', 25 );
		$profiles          = $this->repository->get_competitors( $page, $per_page );
		$total             = $this->repository->count_competitors();
		$selected_product_count = $this->discovery_repository->count_selected_products();
		$editing_profile   = $this->get_editing_competitor_profile();
		$linked_profile_id = $this->get_positive_query_arg( 'linked_competitor_id', 0 );
		$test_result       = $this->get_competitor_profile_test_result();
		$auto_start_competitor_id = $this->get_positive_query_arg( 'lpm_auto_start_competitor_id', 0 );
		?>
		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<div>
					<h2><?php esc_html_e( 'Competitors', 'lilleprinsen-price-monitor' ); ?></h2>
					<p class="lpm-card-subtitle"><?php esc_html_e( 'Manage competitor stores, health, extraction tests and match discovery from one place.', 'lilleprinsen-price-monitor' ); ?></p>
				</div>
				<span class="lpm-pill lpm-pill-muted"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</div>
			<?php $this->render_competitor_profiles_table( $profiles, $selected_product_count ); ?>
			<?php $this->render_pagination( $total, $page, $per_page, 'lpm_competitor_profiles_page', array( 'tab' => 'competitors' ) ); ?>
			<?php if ( $auto_start_competitor_id > 0 && $selected_product_count > 0 ) : ?>
				<span hidden data-lpm-auto-start-competitor="<?php echo esc_attr( (string) $auto_start_competitor_id ); ?>"></span>
			<?php endif; ?>
		</section>

		<details class="lpm-settings-section" <?php echo $editing_profile ? 'open' : ''; ?>>
			<summary><?php echo esc_html( $editing_profile ? __( 'Edit competitor', 'lilleprinsen-price-monitor' ) : __( 'Add competitor', 'lilleprinsen-price-monitor' ) ); ?></summary>
			<section class="lpm-card">
				<p class="lpm-card-subtitle"><?php esc_html_e( 'Start with name, domain, currency and search setup. Advanced extraction settings stay collapsed unless needed.', 'lilleprinsen-price-monitor' ); ?></p>
				<?php $this->render_competitor_profile_form( $editing_profile ); ?>
			</section>
		</details>

		<details class="lpm-settings-section" <?php echo $test_result ? 'open' : ''; ?>>
			<summary><?php esc_html_e( 'Test competitor product page', 'lilleprinsen-price-monitor' ); ?></summary>
			<section class="lpm-card">
				<?php if ( $editing_profile ) : ?>
					<p><?php esc_html_e( 'Paste one real product page to confirm price, identifiers and stock before using this competitor in discovery.', 'lilleprinsen-price-monitor' ); ?></p>
					<?php $this->render_competitor_profile_test_form( $editing_profile ); ?>
				<?php else : ?>
					<p><?php esc_html_e( 'Choose Edit on a competitor first, then test one product page.', 'lilleprinsen-price-monitor' ); ?></p>
				<?php endif; ?>
				<?php if ( $test_result ) : ?>
					<?php $this->render_competitor_profile_test_result( $test_result ); ?>
				<?php endif; ?>
			</section>
		</details>

		<?php if ( $linked_profile_id > 0 ) : ?>
			<?php $this->render_competitor_profile_linked_products( $linked_profile_id ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<string, mixed>|null $profile Competitor profile row.
	 */
	private function render_competitor_profile_form( ?array $profile ): void {
		$is_edit = is_array( $profile );
		$profile = $profile ?? array(
			'id'                      => 0,
			'name'                    => '',
			'domain'                  => '',
			'enabled'                 => 1,
			'default_currency'        => 'NOK',
			'request_delay_seconds'   => 2,
			'request_timeout_seconds' => '',
			'price_extraction_mode'   => 'auto',
			'price_selector'          => '',
			'regular_price_selector'  => '',
			'sale_price_selector'     => '',
			'sku_selector'            => '',
			'gtin_selector'           => '',
			'monitored_price_field'   => 'sale_price_first',
			'stock_selector'          => '',
			'stock_in_text'           => '',
			'stock_out_text'          => '',
			'json_ld_enabled'         => 1,
			'meta_tags_enabled'       => 1,
			'visible_regex_enabled'   => 1,
			'requires_javascript'     => 0,
			'notes'                   => '',
		);
		$worker_settings = $this->competitor_worker_settings_from_notes( (string) ( $profile['notes'] ?? '' ) );
		$onboarding_settings = $this->competitor_onboarding_settings_from_notes( (string) ( $profile['notes'] ?? '' ) );
		?>
		<form method="post" class="lpm-stacked-form">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="<?php echo esc_attr( $is_edit ? 'update_competitor_profile' : 'add_competitor_profile' ); ?>" />
			<?php if ( $is_edit ) : ?>
				<input type="hidden" name="competitor_profile_id" value="<?php echo esc_attr( (string) $profile['id'] ); ?>" />
			<?php endif; ?>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Name', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="name" maxlength="191" required value="<?php echo esc_attr( (string) $profile['name'] ); ?>" />
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Domain', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="domain" maxlength="191" value="<?php echo esc_attr( (string) ( $profile['domain'] ?? '' ) ); ?>" placeholder="example.no" data-lpm-platform-domain />
			</label>

			<section class="lpm-onboarding-wizard" data-lpm-platform-wizard>
				<div class="lpm-card-header">
					<div>
						<h3><?php esc_html_e( 'Search setup', 'lilleprinsen-price-monitor' ); ?></h3>
						<p class="lpm-card-subtitle"><?php esc_html_e( 'Auto-detect the platform or paste one search result URL so discovery knows where to look.', 'lilleprinsen-price-monitor' ); ?></p>
					</div>
					<span class="lpm-pill lpm-pill-muted" data-lpm-platform-badge><?php echo esc_html( (string) $onboarding_settings['label'] ); ?></span>
				</div>
				<div class="lpm-grid lpm-grid-two">
					<label class="lpm-field">
						<span><?php esc_html_e( 'Platform', 'lilleprinsen-price-monitor' ); ?></span>
						<select name="competitor_platform" data-lpm-platform-select>
							<?php foreach ( CompetitorPlatformDetector::platform_options() as $value => $option ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $onboarding_settings['platform'], $value ); ?>><?php echo esc_html( (string) $option['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
							<small><?php esc_html_e( 'Auto-detect works for common WooCommerce, Magento, Shopify, Algolia and Voyado patterns.', 'lilleprinsen-price-monitor' ); ?></small>
					</label>
					<label class="lpm-field">
						<span><?php esc_html_e( 'Example search page URL', 'lilleprinsen-price-monitor' ); ?></span>
						<input type="url" name="competitor_platform_search_url" value="<?php echo esc_attr( (string) $onboarding_settings['search_url'] ); ?>" placeholder="https://competitor.no/search?q=10201031" data-lpm-platform-search-url />
							<small><?php esc_html_e( 'Search the competitor for one known product, then paste the result URL here.', 'lilleprinsen-price-monitor' ); ?></small>
					</label>
				</div>
				<div class="lpm-platform-preview" data-lpm-platform-preview>
					<p><strong><?php esc_html_e( 'Recommended setup', 'lilleprinsen-price-monitor' ); ?></strong></p>
					<p data-lpm-platform-summary><?php esc_html_e( 'Enter a domain or choose a platform to see the recommended search setup.', 'lilleprinsen-price-monitor' ); ?></p>
					<?php if ( ! empty( $onboarding_settings['templates'] ) ) : ?>
						<ul data-lpm-platform-generated="1">
							<?php foreach ( array_slice( $onboarding_settings['templates'], 0, 4 ) as $template ) : ?>
								<li><code><?php echo esc_html( (string) $template ); ?></code></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( ! empty( $onboarding_settings['signals'] ) ) : ?>
						<details class="lpm-context">
							<summary><?php esc_html_e( 'Detection signals', 'lilleprinsen-price-monitor' ); ?></summary>
							<ul><?php foreach ( array_slice( $onboarding_settings['signals'], 0, 5 ) as $signal ) : ?><li><?php echo esc_html( (string) $signal ); ?></li><?php endforeach; ?></ul>
						</details>
					<?php endif; ?>
				</div>
			</section>

			<label class="lpm-field lpm-field-checkbox">
				<input type="hidden" name="enabled" value="0" />
				<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $profile['enabled'] ) ); ?> />
				<span><strong><?php esc_html_e( 'Active competitor', 'lilleprinsen-price-monitor' ); ?></strong></span>
			</label>

			<details class="lpm-advanced-panel">
				<summary><?php esc_html_e( 'Advanced extraction and timing', 'lilleprinsen-price-monitor' ); ?></summary>
				<p class="lpm-field-description"><?php esc_html_e( 'Only change these if automatic extraction fails or this competitor needs special timing.', 'lilleprinsen-price-monitor' ); ?></p>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Default currency', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="default_currency" maxlength="10" value="<?php echo esc_attr( (string) ( $profile['default_currency'] ?? 'NOK' ) ); ?>" />
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Request delay seconds', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="number" min="0" max="3600" step="1" name="request_delay_seconds" value="<?php echo esc_attr( (string) ( $profile['request_delay_seconds'] ?? 2 ) ); ?>" />
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Request timeout seconds', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="number" min="1" max="30" step="1" name="request_timeout_seconds" value="<?php echo esc_attr( $this->format_decimal_for_optional_input( $profile['request_timeout_seconds'] ?? null ) ); ?>" />
				<small><?php esc_html_e( 'Leave empty to use the global monitoring timeout.', 'lilleprinsen-price-monitor' ); ?></small>
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Extraction mode', 'lilleprinsen-price-monitor' ); ?></span>
				<select name="price_extraction_mode">
					<?php foreach ( $this->get_extraction_mode_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $profile['price_extraction_mode'] ?? 'auto' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Current/active price selector', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="price_selector" maxlength="255" value="<?php echo esc_attr( (string) ( $profile['price_selector'] ?? '' ) ); ?>" placeholder=".price" />
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Regular price selector', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="regular_price_selector" maxlength="255" value="<?php echo esc_attr( (string) ( $profile['regular_price_selector'] ?? '' ) ); ?>" placeholder=".regular-price" />
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Sale price selector', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="sale_price_selector" maxlength="255" value="<?php echo esc_attr( (string) ( $profile['sale_price_selector'] ?? '' ) ); ?>" placeholder="[itemprop=&quot;price&quot;]" />
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'SKU selector', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="sku_selector" maxlength="255" value="<?php echo esc_attr( (string) ( $profile['sku_selector'] ?? '' ) ); ?>" placeholder="[itemprop=&quot;sku&quot;]" />
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'EAN/GTIN selector', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="gtin_selector" maxlength="255" value="<?php echo esc_attr( (string) ( $profile['gtin_selector'] ?? '' ) ); ?>" placeholder="[itemprop=&quot;gtin13&quot;]" />
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Monitored price field', 'lilleprinsen-price-monitor' ); ?></span>
				<select name="monitored_price_field">
					<?php foreach ( $this->get_monitored_price_field_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $profile['monitored_price_field'] ?? 'sale_price_first' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<small><?php esc_html_e( 'Use sale price first by default so mapped sale prices beat generic regular-price meta tags.', 'lilleprinsen-price-monitor' ); ?></small>
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Stock selector', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="stock_selector" maxlength="255" value="<?php echo esc_attr( (string) ( $profile['stock_selector'] ?? '' ) ); ?>" placeholder="#stock-status" />
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Stock in text', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="stock_in_text" maxlength="255" value="<?php echo esc_attr( (string) ( $profile['stock_in_text'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'På lager', 'lilleprinsen-price-monitor' ); ?>" />
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Stock out text', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="stock_out_text" maxlength="255" value="<?php echo esc_attr( (string) ( $profile['stock_out_text'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Utsolgt', 'lilleprinsen-price-monitor' ); ?>" />
			</label>

			<label class="lpm-field lpm-field-checkbox">
				<input type="hidden" name="json_ld_enabled" value="0" />
				<input type="checkbox" name="json_ld_enabled" value="1" <?php checked( ! empty( $profile['json_ld_enabled'] ) ); ?> />
				<span><strong><?php esc_html_e( 'Enable JSON-LD', 'lilleprinsen-price-monitor' ); ?></strong></span>
			</label>

			<label class="lpm-field lpm-field-checkbox">
				<input type="hidden" name="meta_tags_enabled" value="0" />
				<input type="checkbox" name="meta_tags_enabled" value="1" <?php checked( ! empty( $profile['meta_tags_enabled'] ) ); ?> />
				<span><strong><?php esc_html_e( 'Enable meta tags', 'lilleprinsen-price-monitor' ); ?></strong></span>
			</label>

			<label class="lpm-field lpm-field-checkbox">
				<input type="hidden" name="visible_regex_enabled" value="0" />
				<input type="checkbox" name="visible_regex_enabled" value="1" <?php checked( ! empty( $profile['visible_regex_enabled'] ) ); ?> />
				<span><strong><?php esc_html_e( 'Enable visible regex fallback', 'lilleprinsen-price-monitor' ); ?></strong></span>
			</label>

			<label class="lpm-field lpm-field-checkbox">
				<input type="hidden" name="requires_javascript" value="0" />
				<input type="checkbox" name="requires_javascript" value="1" <?php checked( ! empty( $profile['requires_javascript'] ) ); ?> />
				<span><strong><?php esc_html_e( 'Requires JavaScript', 'lilleprinsen-price-monitor' ); ?></strong></span>
			</label>

			<details class="lpm-advanced-panel lpm-sub-panel">
				<summary><strong><?php esc_html_e( 'External browser worker', 'lilleprinsen-price-monitor' ); ?></strong></summary>
				<p class="lpm-field-description"><?php esc_html_e( 'Optional for JS-heavy competitors. The internal checker remains default and no match is approved automatically.', 'lilleprinsen-price-monitor' ); ?></p>
				<label class="lpm-field">
					<span><?php esc_html_e( 'Scraping mode', 'lilleprinsen-price-monitor' ); ?></span>
					<select name="external_worker_mode">
						<?php foreach ( $this->get_external_worker_mode_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $worker_settings['mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="lpm-field lpm-field-checkbox">
					<input type="hidden" name="external_worker_search_enabled" value="0" />
					<input type="checkbox" name="external_worker_search_enabled" value="1" <?php checked( ! empty( $worker_settings['search_enabled'] ) ); ?> />
					<span><strong><?php esc_html_e( 'Allow worker for search pages', 'lilleprinsen-price-monitor' ); ?></strong></span>
				</label>
				<label class="lpm-field lpm-field-checkbox">
					<input type="hidden" name="external_worker_product_enabled" value="0" />
					<input type="checkbox" name="external_worker_product_enabled" value="1" <?php checked( ! empty( $worker_settings['product_enabled'] ) ); ?> />
					<span><strong><?php esc_html_e( 'Allow worker for product pages', 'lilleprinsen-price-monitor' ); ?></strong></span>
				</label>
			</details>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Notes', 'lilleprinsen-price-monitor' ); ?></span>
				<textarea name="notes" rows="3"><?php echo esc_textarea( (string) ( $profile['notes'] ?? '' ) ); ?></textarea>
			</label>
			</details>

			<div class="lpm-form-actions">
				<button type="submit" class="button button-primary"><?php echo esc_html( $is_edit ? __( 'Save competitor profile', 'lilleprinsen-price-monitor' ) : __( 'Add competitor profile', 'lilleprinsen-price-monitor' ) ); ?></button>
				<?php if ( $is_edit ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'competitors' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Cancel edit', 'lilleprinsen-price-monitor' ); ?></a>
				<?php endif; ?>
			</div>
		</form>
		<?php
	}

	/**
	 * @param array<string, mixed> $profile Competitor profile row.
	 */
	private function render_competitor_profile_test_form( array $profile ): void {
		?>
		<form method="post" class="lpm-stacked-form lpm-card-spaced">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="test_competitor_profile_url" />
			<input type="hidden" name="competitor_profile_id" value="<?php echo esc_attr( (string) $profile['id'] ); ?>" />
			<label class="lpm-field">
				<span><?php esc_html_e( 'Test URL', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="url" name="test_url" required placeholder="https://example.no/product" />
			</label>
			<div class="lpm-form-actions">
				<button type="submit" class="button"><?php esc_html_e( 'Test extraction rule', 'lilleprinsen-price-monitor' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * @param array<string, mixed> $result Profile test result.
	 */
	private function render_competitor_profile_test_result( array $result ): void {
		?>
		<div class="lpm-card-spaced">
			<h3><?php esc_html_e( 'Last profile test', 'lilleprinsen-price-monitor' ); ?></h3>
			<table class="lpm-status-table">
				<tbody>
					<tr><th scope="row"><?php esc_html_e( 'URL', 'lilleprinsen-price-monitor' ); ?></th><td><?php echo esc_html( $this->shorten_text( (string) ( $result['url'] ?? '' ), 80 ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Result', 'lilleprinsen-price-monitor' ); ?></th><td><?php $this->render_status_pill( ! empty( $result['success'] ) ? __( 'Success', 'lilleprinsen-price-monitor' ) : __( 'Failed', 'lilleprinsen-price-monitor' ), ! empty( $result['success'] ) ? 'ok' : 'danger' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Price', 'lilleprinsen-price-monitor' ); ?></th><td><?php echo esc_html( $this->format_nullable_value( $result['price'] ?? null ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Regular price', 'lilleprinsen-price-monitor' ); ?></th><td><?php echo esc_html( $this->format_nullable_value( $result['regular_price'] ?? null ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Sale price', 'lilleprinsen-price-monitor' ); ?></th><td><?php echo esc_html( $this->format_nullable_value( $result['sale_price'] ?? null ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'SKU', 'lilleprinsen-price-monitor' ); ?></th><td><?php echo esc_html( $this->format_nullable_value( $result['sku'] ?? null ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'EAN/GTIN', 'lilleprinsen-price-monitor' ); ?></th><td><?php echo esc_html( $this->format_nullable_value( $result['gtin'] ?? null ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Selected price field', 'lilleprinsen-price-monitor' ); ?></th><td><?php echo esc_html( $this->format_nullable_value( $result['price_field'] ?? null ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Currency', 'lilleprinsen-price-monitor' ); ?></th><td><?php echo esc_html( $this->format_nullable_value( $result['currency'] ?? null ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Stock status', 'lilleprinsen-price-monitor' ); ?></th><td><?php echo esc_html( $this->format_nullable_value( $result['stock_status'] ?? null ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Extraction method', 'lilleprinsen-price-monitor' ); ?></th><td><?php echo esc_html( $this->format_nullable_value( $result['extraction_method'] ?? null ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'HTTP status', 'lilleprinsen-price-monitor' ); ?></th><td><?php echo esc_html( $this->format_nullable_value( $result['http_status'] ?? null ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Error / warning', 'lilleprinsen-price-monitor' ); ?></th><td><?php echo esc_html( $this->format_nullable_value( $result['error'] ?? null ) ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_competitor_profiles_table( array $profiles, int $selected_product_count = 0 ): void {
		if ( empty( $profiles ) ) {
			$this->render_empty_state( __( 'No competitor profiles have been added yet.', 'lilleprinsen-price-monitor' ) );
			return;
		}
		?>
		<table class="lpm-compact-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Product coverage', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Pending matches', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last successful check', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last issue', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $profiles as $profile ) : ?>
					<?php
					$observations = (int) ( $profile['observation_count'] ?? 0 );
					$successes    = (int) ( $profile['successful_observation_count'] ?? 0 );
					$success_rate = $observations > 0 ? sprintf( '%d%%', (int) round( ( $successes / $observations ) * 100 ) ) : '—';
					$platform     = $this->competitor_onboarding_settings_from_notes( (string) ( $profile['notes'] ?? '' ) );
					$pending_matches = $this->discovery_repository->count_suggestions_for_competitor( (int) $profile['id'], 'pending' );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( (string) $profile['name'] ); ?></strong><br>
							<small><?php echo esc_html( $this->format_nullable_value( $profile['domain'] ?? null ) ); ?></small>
						</td>
						<td>
							<?php $this->render_status_pill( ! empty( $profile['enabled'] ) ? __( 'Active', 'lilleprinsen-price-monitor' ) : __( 'Paused', 'lilleprinsen-price-monitor' ), ! empty( $profile['enabled'] ) ? 'ok' : 'muted' ); ?>
							<span class="lpm-inline-meta"><?php echo esc_html( (string) $platform['label'] ); ?></span>
							<?php if ( ! empty( $profile['requires_javascript'] ) ) : ?>
								<?php $this->render_status_pill( __( 'JS-heavy', 'lilleprinsen-price-monitor' ), 'warning' ); ?>
							<?php endif; ?>
							<details class="lpm-row-details">
								<summary><?php esc_html_e( 'Details', 'lilleprinsen-price-monitor' ); ?></summary>
								<?php printf( esc_html__( 'Delay: %d seconds', 'lilleprinsen-price-monitor' ), (int) ( $profile['request_delay_seconds'] ?? 0 ) ); ?><br>
								<?php printf( esc_html__( 'Extraction: %s', 'lilleprinsen-price-monitor' ), esc_html( (string) ( $profile['price_extraction_mode'] ?? 'auto' ) ) ); ?>
							</details>
						</td>
						<td>
							<?php
							printf(
								esc_html__( '%1$s / %2$s products matched', 'lilleprinsen-price-monitor' ),
								esc_html( number_format_i18n( (int) ( $profile['link_count'] ?? 0 ) ) ),
								esc_html( number_format_i18n( $selected_product_count ) )
							);
							?>
							<br><small><?php echo esc_html( $selected_product_count > (int) ( $profile['link_count'] ?? 0 ) ? __( 'Run discovery to check remaining selected products.', 'lilleprinsen-price-monitor' ) : __( 'All selected products have active links for this competitor.', 'lilleprinsen-price-monitor' ) ); ?></small>
						</td>
						<td><?php echo esc_html( number_format_i18n( $pending_matches ) ); ?></td>
						<td>
							<strong><?php echo esc_html( $success_rate ); ?></strong><br>
							<small><?php printf( esc_html__( 'Last check: %s', 'lilleprinsen-price-monitor' ), esc_html( $this->format_datetime( $profile['last_check'] ?? null ) ) ); ?></small>
						</td>
						<td><?php echo esc_html( $this->format_nullable_value( $profile['last_error'] ?? null ) ); ?></td>
						<td>
							<div class="lpm-actions">
								<button type="button" class="button button-small button-primary" data-lpm-start-competitor="<?php echo esc_attr( (string) $profile['id'] ); ?>" <?php disabled( $selected_product_count <= 0 || empty( $profile['enabled'] ) ); ?>><?php esc_html_e( 'Find matches', 'lilleprinsen-price-monitor' ); ?></button>
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'competitors', 'competitor_profile_id' => (int) $profile['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Test/Edit', 'lilleprinsen-price-monitor' ); ?></a>
								<details class="lpm-row-actions">
									<summary><?php esc_html_e( 'More', 'lilleprinsen-price-monitor' ); ?></summary>
									<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'competitors', 'linked_competitor_id' => (int) $profile['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Linked products', 'lilleprinsen-price-monitor' ); ?></a>
									<?php $this->render_competitor_profile_action_form( (int) $profile['id'], ! empty( $profile['enabled'] ) ? 'disable_competitor_profile' : 'enable_competitor_profile', ! empty( $profile['enabled'] ) ? __( 'Pause', 'lilleprinsen-price-monitor' ) : __( 'Activate', 'lilleprinsen-price-monitor' ) ); ?>
								</details>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_competitor_profile_linked_products( int $competitor_id ): void {
		$profile  = $this->repository->get_competitor( $competitor_id );
		$page     = $this->get_positive_query_arg( 'lpm_linked_products_page', 1 );
		$per_page = (int) $this->settings->get( 'rows_per_page', 25 );
		$rows     = $this->repository->get_competitor_linked_products( $competitor_id, $page, $per_page );
		$total    = $this->repository->count_competitor_linked_products( $competitor_id );

		if ( ! $profile ) {
			return;
		}
		?>
		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<div>
					<h2><?php printf( esc_html__( 'Linked products: %s', 'lilleprinsen-price-monitor' ), esc_html( (string) $profile['name'] ) ); ?></h2>
					<p class="lpm-card-subtitle"><?php esc_html_e( 'Direct competitor links attached to this profile.', 'lilleprinsen-price-monitor' ); ?></p>
				</div>
				<span class="lpm-pill lpm-pill-muted"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</div>
			<?php if ( empty( $rows ) ) : ?>
				<?php $this->render_empty_state( __( 'No links are attached to this profile yet.', 'lilleprinsen-price-monitor' ) ); ?>
			<?php else : ?>
				<table class="lpm-compact-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Product', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Product ID', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'SKU', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Competitor URL', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last price', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last checked', 'lilleprinsen-price-monitor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php $product = $this->get_product( (int) $row['product_id'] ); ?>
							<tr>
								<td><?php echo esc_html( $product ? $this->get_product_name( $product ) : sprintf( __( 'Product #%d', 'lilleprinsen-price-monitor' ), (int) $row['product_id'] ) ); ?></td>
								<td><?php echo esc_html( (string) $row['product_id'] ); ?></td>
								<td><?php echo esc_html( (string) ( $row['sku'] ?? '' ) ); ?></td>
								<td><a href="<?php echo esc_url( (string) $row['competitor_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $this->shorten_text( (string) $row['competitor_url'], 56 ) ); ?></a></td>
								<td><?php echo esc_html( $this->format_nullable_value( $row['last_price'] ?? null ) ); ?></td>
								<td><?php echo esc_html( $this->format_datetime( $row['last_checked_at'] ?? null ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php $this->render_pagination( $total, $page, $per_page, 'lpm_linked_products_page', array( 'tab' => 'competitors', 'linked_competitor_id' => $competitor_id ) ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_competitor_picker(): void {
		$page        = $this->get_positive_query_arg( 'lpm_competitor_picker_page', 1 );
		$per_page    = (int) $this->settings->get( 'rows_per_page', 25 );
		$rows        = $this->repository->get_monitored_products( $page, $per_page );
		$total       = $this->repository->count_monitored_products();
		$link_counts = $this->repository->count_competitor_links_for_monitored_products( wp_list_pluck( $rows, 'id' ) );
		?>
		<section class="lpm-card">
			<div class="lpm-card-header">
				<h2><?php esc_html_e( 'Choose a monitored product', 'lilleprinsen-price-monitor' ); ?></h2>
				<span class="lpm-pill lpm-pill-muted"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</div>
			<p><?php esc_html_e( 'Use the Products tab to add products first, then manage direct competitor URLs here.', 'lilleprinsen-price-monitor' ); ?></p>
			<?php $this->render_monitored_products_table( $rows, $link_counts ); ?>
			<?php $this->render_pagination( $total, $page, $per_page, 'lpm_competitor_picker_page', array( 'tab' => 'competitors' ) ); ?>
		</section>
		<?php
	}

	private function render_selected_product_summary( array $monitored_product, ?object $product ): void {
		?>
		<table class="lpm-status-table">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Product name', 'lilleprinsen-price-monitor' ); ?></th>
					<td><?php echo esc_html( $product ? $this->get_product_name( $product ) : __( 'Product unavailable', 'lilleprinsen-price-monitor' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Product ID', 'lilleprinsen-price-monitor' ); ?></th>
					<td><?php echo esc_html( (string) $monitored_product['product_id'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'SKU', 'lilleprinsen-price-monitor' ); ?></th>
					<td><?php echo esc_html( (string) ( $monitored_product['sku'] ?? '' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Current WooCommerce price', 'lilleprinsen-price-monitor' ); ?></th>
					<td><?php echo wp_kses_post( $product ? $this->get_product_price_html( $product ) : '&mdash;' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Stock status', 'lilleprinsen-price-monitor' ); ?></th>
					<td><?php echo esc_html( $product ? $this->get_product_stock_status( $product ) : __( 'Unknown', 'lilleprinsen-price-monitor' ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	private function render_competitor_form( int $monitored_product_id, ?array $editing_link, array $profiles ): void {
		$is_edit = is_array( $editing_link );
		$selected_profile_id = $is_edit ? absint( $editing_link['competitor_id'] ?? 0 ) : 0;
		?>
		<form method="post" class="lpm-stacked-form">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="<?php echo esc_attr( $is_edit ? 'update_competitor_link' : 'add_competitor_link' ); ?>" />
			<input type="hidden" name="monitored_product_id" value="<?php echo esc_attr( (string) $monitored_product_id ); ?>" />
			<?php if ( $is_edit ) : ?>
				<input type="hidden" name="competitor_link_id" value="<?php echo esc_attr( (string) $editing_link['id'] ); ?>" />
			<?php endif; ?>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Competitor profile', 'lilleprinsen-price-monitor' ); ?></span>
				<select name="competitor_id">
					<option value="0"><?php esc_html_e( 'No profile / custom name', 'lilleprinsen-price-monitor' ); ?></option>
					<?php foreach ( $profiles as $profile ) : ?>
						<option value="<?php echo esc_attr( (string) $profile['id'] ); ?>" <?php selected( $selected_profile_id, (int) $profile['id'] ); ?>>
							<?php
							echo esc_html(
								trim(
									(string) $profile['name'] . (
										! empty( $profile['domain'] )
											? ' (' . (string) $profile['domain'] . ')'
											: ''
									)
								)
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<small><?php esc_html_e( 'Choose a profile to reuse domain, timeout, selector, and JavaScript requirements. Leave custom for one-off links.', 'lilleprinsen-price-monitor' ); ?></small>
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Competitor name', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="competitor_name" maxlength="191" value="<?php echo esc_attr( $is_edit ? (string) $editing_link['competitor_name'] : '' ); ?>" />
				<small><?php esc_html_e( 'Required for custom links. If a profile is selected and this is blank, the profile name is used.', 'lilleprinsen-price-monitor' ); ?></small>
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Competitor URL', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="url" name="competitor_url" required value="<?php echo esc_attr( $is_edit ? (string) $editing_link['competitor_url'] : '' ); ?>" placeholder="https://example.com/product" />
			</label>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Match type', 'lilleprinsen-price-monitor' ); ?></span>
				<select name="match_type">
					<?php foreach ( $this->get_match_type_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $is_edit ? (string) $editing_link['match_type'] : 'unknown', $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="lpm-field lpm-field-checkbox">
				<input type="hidden" name="enabled" value="0" />
				<input type="checkbox" name="enabled" value="1" <?php checked( $is_edit ? ! empty( $editing_link['enabled'] ) : true ); ?> />
				<span><strong><?php esc_html_e( 'Enabled', 'lilleprinsen-price-monitor' ); ?></strong></span>
			</label>

			<label class="lpm-field lpm-field-checkbox">
				<input type="hidden" name="is_primary" value="0" />
				<input type="checkbox" name="is_primary" value="1" <?php checked( $is_edit ? ! empty( $editing_link['is_primary'] ) : false ); ?> />
				<span>
					<strong><?php esc_html_e( 'Primary competitor for recovery', 'lilleprinsen-price-monitor' ); ?></strong>
					<small><?php esc_html_e( 'Only one primary competitor is kept per monitored product.', 'lilleprinsen-price-monitor' ); ?></small>
				</span>
			</label>

			<div class="lpm-form-actions">
				<button type="submit" class="button button-primary"><?php echo esc_html( $is_edit ? __( 'Save competitor link', 'lilleprinsen-price-monitor' ) : __( 'Add competitor link', 'lilleprinsen-price-monitor' ) ); ?></button>
				<?php if ( $is_edit ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'competitors', 'monitored_product_id' => $monitored_product_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Cancel edit', 'lilleprinsen-price-monitor' ); ?></a>
				<?php endif; ?>
			</div>
		</form>
		<?php
	}

	private function render_competitor_links_table( array $links, int $monitored_product_id ): void {
		if ( empty( $links ) ) {
			$this->render_empty_state( __( 'No competitor links have been added for this monitored product.', 'lilleprinsen-price-monitor' ) );
			return;
		}
		?>
		<table class="lpm-compact-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Select', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Competitor link', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last read', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $links as $link ) : ?>
					<tr>
						<td><input form="lpm-competitors-bulk-form" type="checkbox" name="competitor_link_ids[]" value="<?php echo esc_attr( (string) $link['id'] ); ?>" /></td>
						<td>
							<strong><?php echo esc_html( (string) $link['competitor_name'] ); ?></strong><br>
							<?php if ( ! empty( $link['competitor_profile_name'] ) ) : ?>
								<small><?php echo esc_html( (string) $link['competitor_profile_name'] ); ?></small>
								<?php if ( ! empty( $link['competitor_requires_javascript'] ) ) : ?>
									<?php $this->render_status_pill( __( 'JS', 'lilleprinsen-price-monitor' ), 'warning' ); ?>
								<?php endif; ?>
							<?php else : ?>
								<small><?php echo esc_html( __( 'Custom link', 'lilleprinsen-price-monitor' ) ); ?></small>
							<?php endif; ?>
							<br><a href="<?php echo esc_url( (string) $link['competitor_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $this->shorten_text( (string) $link['competitor_url'], 64 ) ); ?></a>
						</td>
						<td>
							<?php $this->render_status_pill( ! empty( $link['enabled'] ) ? __( 'Active', 'lilleprinsen-price-monitor' ) : __( 'Paused', 'lilleprinsen-price-monitor' ), ! empty( $link['enabled'] ) ? 'ok' : 'muted' ); ?>
							<?php if ( ! empty( $link['is_primary'] ) ) : ?>
								<?php $this->render_status_pill( __( 'Primary', 'lilleprinsen-price-monitor' ), 'ok' ); ?>
							<?php endif; ?>
							<?php if ( ! empty( $link['identity_drift_detected_at'] ) ) : ?>
								<?php $this->render_status_pill( __( 'Review match', 'lilleprinsen-price-monitor' ), 'danger' ); ?>
							<?php endif; ?>
							<details class="lpm-row-details">
								<summary><?php esc_html_e( 'Details', 'lilleprinsen-price-monitor' ); ?></summary>
								<?php printf( esc_html__( 'Match type: %s', 'lilleprinsen-price-monitor' ), esc_html( (string) $link['match_type'] ) ); ?><br>
								<?php printf( esc_html__( 'Stock: %s', 'lilleprinsen-price-monitor' ), esc_html( $this->format_nullable_value( $link['last_stock_status'] ?? null ) ) ); ?><br>
								<?php printf( esc_html__( 'Approved SKU: %s', 'lilleprinsen-price-monitor' ), esc_html( $this->format_nullable_value( $link['approved_sku'] ?? null ) ) ); ?><br>
								<?php printf( esc_html__( 'Approved EAN/GTIN: %s', 'lilleprinsen-price-monitor' ), esc_html( $this->format_nullable_value( $link['approved_gtin'] ?? null ) ) ); ?><br>
								<?php printf( esc_html__( 'Approved MPN: %s', 'lilleprinsen-price-monitor' ), esc_html( $this->format_nullable_value( $link['approved_mpn'] ?? null ) ) ); ?>
								<?php if ( ! empty( $link['identity_drift_reason'] ) ) : ?>
									<br><strong><?php esc_html_e( 'Identity warning:', 'lilleprinsen-price-monitor' ); ?></strong> <?php echo esc_html( (string) $link['identity_drift_reason'] ); ?>
								<?php endif; ?>
							</details>
						</td>
						<td>
							<strong><?php echo esc_html( $this->format_nullable_value( $link['last_price'] ?? null ) ); ?></strong>
							<?php echo esc_html( $this->format_nullable_value( $link['last_currency'] ?? null ) ); ?><br>
							<small><?php echo esc_html( $this->format_datetime( $link['last_checked_at'] ?? null ) ); ?></small>
							<?php if ( ! empty( $link['last_error'] ) ) : ?>
								<br><small class="lpm-warning-text"><?php echo esc_html( $this->shorten_text( (string) $link['last_error'], 60 ) ); ?></small>
							<?php endif; ?>
						</td>
						<td>
							<div class="lpm-actions">
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'competitors', 'monitored_product_id' => $monitored_product_id, 'competitor_link_id' => (int) $link['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'lilleprinsen-price-monitor' ); ?></a>
								<details class="lpm-row-actions">
									<summary><?php esc_html_e( 'More', 'lilleprinsen-price-monitor' ); ?></summary>
									<?php $this->render_competitor_action_form( (int) $link['id'], 'test_competitor_check', __( 'Test check', 'lilleprinsen-price-monitor' ) ); ?>
									<?php if ( ! empty( $link['last_price'] ) ) : ?>
										<?php $this->render_competitor_action_form( (int) $link['id'], 'create_price_suggestion', __( 'Create suggestion', 'lilleprinsen-price-monitor' ) ); ?>
									<?php endif; ?>
									<?php $this->render_competitor_action_form( (int) $link['id'], ! empty( $link['enabled'] ) ? 'disable_competitor_link' : 'enable_competitor_link', ! empty( $link['enabled'] ) ? __( 'Pause', 'lilleprinsen-price-monitor' ) : __( 'Activate', 'lilleprinsen-price-monitor' ) ); ?>
									<?php $this->render_competitor_action_form( (int) $link['id'], 'delete_competitor_link', __( 'Delete', 'lilleprinsen-price-monitor' ), 'link-delete' ); ?>
								</details>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_approval_filters( string $active_view ): void {
		?>
		<nav class="lpm-filter-tabs" aria-label="<?php esc_attr_e( 'Suggestion filters', 'lilleprinsen-price-monitor' ); ?>">
			<?php foreach ( $this->get_approval_view_options() as $view => $label ) : ?>
				<a
					class="lpm-filter-tab <?php echo esc_attr( $active_view === $view ? 'is-active' : '' ); ?>"
					href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'approvals', 'lpm_approval_view' => $view ), admin_url( 'admin.php' ) ) ); ?>"
				>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	private function render_approvals_table( array $suggestions, array $settings ): void {
		if ( empty( $suggestions ) ) {
			$this->render_empty_state( __( 'No suggestions match the current inbox filter.', 'lilleprinsen-price-monitor' ) );
			return;
		}
		$currency             = (string) ( $settings['default_currency'] ?? 'NOK' );
		$real_updates_enabled = $this->real_updates_enabled( $settings );
		?>
		<table class="lpm-compact-table lpm-approvals-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Product', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Suggestion type', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Current price', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Competitor price', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Suggested price', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Difference', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Margin after', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Warnings', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rule summary', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reason', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Created', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Token links', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $suggestions as $suggestion ) : ?>
					<?php
					$product    = $this->get_product( (int) $suggestion['product_id'] );
					$can_review = in_array( (string) $suggestion['status'], array( 'pending', 'blocked' ), true );
					$can_real_update = $can_review && 'pending' === (string) $suggestion['status'] && $real_updates_enabled && $this->suggestion_type_allows_real_update( (string) $suggestion['suggestion_type'], $settings ) && ( empty( $suggestion['applies_to_group'] ) || 'manual_review_only' !== (string) ( $suggestion['group_action_status'] ?? '' ) );
					?>
					<tr>
						<td>
							<div class="lpm-product-cell">
								<?php echo wp_kses_post( $product ? $this->get_product_thumbnail( $product ) : '' ); ?>
								<span>
									<?php echo esc_html( $product ? $this->get_product_name( $product ) : sprintf( __( 'Product #%d', 'lilleprinsen-price-monitor' ), (int) $suggestion['product_id'] ) ); ?>
									<small><?php printf( esc_html__( 'ID %d', 'lilleprinsen-price-monitor' ), (int) $suggestion['product_id'] ); ?></small>
								</span>
							</div>
						</td>
						<td>
							<?php echo esc_html( $this->get_suggestion_type_label( (string) $suggestion['suggestion_type'] ) ); ?>
							<?php if ( ! empty( $suggestion['applies_to_group'] ) ) : ?>
								<div class="lpm-inline-meta">
									<?php $this->render_status_pill( __( 'Group', 'lilleprinsen-price-monitor' ), 'warning' ); ?>
									<small>
										<?php
										printf(
											/* translators: 1: group name, 2: member count. */
											esc_html__( '%1$s, %2$d products', 'lilleprinsen-price-monitor' ),
											esc_html( (string) ( $suggestion['group_name'] ?? __( 'Price group', 'lilleprinsen-price-monitor' ) ) ),
											(int) ( $suggestion['group_member_count'] ?? 0 )
										);
										?>
									</small>
								</div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $this->format_price_amount( (float) $suggestion['current_price'], $currency ) ); ?></td>
						<td><?php echo esc_html( $this->format_price_amount( (float) $suggestion['competitor_price'], $currency ) ); ?></td>
						<td><?php echo esc_html( $this->format_price_amount( (float) $suggestion['suggested_price'], $currency ) ); ?></td>
						<td><?php echo esc_html( $this->format_price_amount( (float) $suggestion['difference'], $currency ) ); ?></td>
						<td><?php echo esc_html( $this->format_percent_value( $suggestion['margin_after_change'] ?? null ) ); ?></td>
						<td><?php $this->render_warnings_summary( (string) ( $suggestion['warnings'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $this->get_rule_details_summary( (string) ( $suggestion['rule_details'] ?? '' ) ) ); ?></td>
						<td><?php $this->render_status_pill( $this->get_suggestion_status_label( (string) $suggestion['status'] ), $this->get_suggestion_status_pill_type( (string) $suggestion['status'] ) ); ?></td>
						<td class="lpm-suggestion-reason">
							<?php echo esc_html( $this->shorten_text( (string) ( $suggestion['reason'] ?? '' ), 120 ) ); ?>
							<?php $this->render_recovery_details_summary( (string) ( $suggestion['rule_details'] ?? '' ), (float) $suggestion['current_price'], (float) $suggestion['competitor_price'], (float) $suggestion['suggested_price'], $currency ); ?>
						</td>
						<td><?php echo esc_html( $this->format_datetime( $suggestion['created_at'] ?? null ) ); ?></td>
						<td>
							<?php $this->render_token_link_status( (string) $suggestion['status'], $settings ); ?>
						</td>
						<td>
							<div class="lpm-actions lpm-inbox-actions">
								<?php if ( $can_review ) : ?>
									<?php $this->render_suggestion_price_form( $suggestion ); ?>
									<?php if ( $can_real_update ) : ?>
										<a class="button button-small button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'approvals', 'lpm_confirm_update' => (int) $suggestion['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( ! empty( $suggestion['applies_to_group'] ) ? __( 'Approve and update group prices', 'lilleprinsen-price-monitor' ) : __( 'Approve and update price', 'lilleprinsen-price-monitor' ) ); ?></a>
									<?php else : ?>
										<?php $this->render_suggestion_action_form( (int) $suggestion['id'], 'approve_suggestion_dry_run', ! empty( $suggestion['applies_to_group'] ) ? __( 'Approve dry-run for group', 'lilleprinsen-price-monitor' ) : __( 'Approve dry-run', 'lilleprinsen-price-monitor' ), '', 'data-lpm-approve-suggestion="' . esc_attr( (string) $suggestion['id'] ) . '"' ); ?>
									<?php endif; ?>
									<?php $this->render_suggestion_action_form( (int) $suggestion['id'], 'reject_suggestion', __( 'Reject', 'lilleprinsen-price-monitor' ), 'link-delete', 'data-lpm-reject-suggestion="' . esc_attr( (string) $suggestion['id'] ) . '"' ); ?>
								<?php endif; ?>
								<button type="button" class="button button-small" data-lpm-view-suggestion="<?php echo esc_attr( (string) $suggestion['id'] ); ?>"><?php esc_html_e( 'Details', 'lilleprinsen-price-monitor' ); ?></button>
								<a class="button button-small" href="<?php echo esc_url( $this->get_product_admin_url( (int) $suggestion['product_id'] ) ); ?>"><?php esc_html_e( 'View product', 'lilleprinsen-price-monitor' ); ?></a>
								<?php if ( ! empty( $suggestion['competitor_url'] ) ) : ?>
									<a class="button button-small" href="<?php echo esc_url( (string) $suggestion['competitor_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open competitor', 'lilleprinsen-price-monitor' ); ?></a>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Current settings.
	 */
	private function render_real_update_confirmation( int $suggestion_id, array $settings ): void {
		if ( ! $this->real_updates_enabled( $settings ) ) {
			$this->render_empty_card( __( 'Real updates are disabled', 'lilleprinsen-price-monitor' ), __( 'Enable every real-update safety setting before confirming a WooCommerce price change.', 'lilleprinsen-price-monitor' ) );
			return;
		}

		$suggestion = $this->repository->get_price_suggestion( $suggestion_id );

		if ( ! $suggestion ) {
			$this->render_empty_card( __( 'Suggestion not found', 'lilleprinsen-price-monitor' ), __( 'Choose a pending suggestion from the inbox.', 'lilleprinsen-price-monitor' ) );
			return;
		}

		if ( ! empty( $suggestion['applies_to_group'] ) ) {
			$this->render_group_real_update_confirmation( $suggestion, $settings );
			return;
		}

		$product = $this->get_product( (int) $suggestion['product_id'] );
		$currency = (string) ( $settings['default_currency'] ?? 'NOK' );
		$current_price = $product && method_exists( $product, 'get_price' ) ? (float) $product->get_price() : (float) $suggestion['current_price'];
		?>
		<section class="lpm-card lpm-card-spaced lpm-confirm-update">
			<div class="lpm-card-header">
				<h2><?php esc_html_e( 'Confirm WooCommerce price update', 'lilleprinsen-price-monitor' ); ?></h2>
				<?php $this->render_status_pill( __( 'Changes product price', 'lilleprinsen-price-monitor' ), 'danger' ); ?>
			</div>
			<table class="lpm-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Product', 'lilleprinsen-price-monitor' ); ?></th>
						<td><?php echo esc_html( $product ? $this->get_product_name( $product ) : sprintf( __( 'Product #%d', 'lilleprinsen-price-monitor' ), (int) $suggestion['product_id'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Current WooCommerce price', 'lilleprinsen-price-monitor' ); ?></th>
						<td><?php echo esc_html( $this->format_price_amount( $current_price, $currency ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Suggested price', 'lilleprinsen-price-monitor' ); ?></th>
						<td><?php echo esc_html( $this->format_price_amount( (float) $suggestion['suggested_price'], $currency ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Suggestion type', 'lilleprinsen-price-monitor' ); ?></th>
						<td><?php echo esc_html( $this->get_suggestion_type_label( (string) $suggestion['suggestion_type'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Difference', 'lilleprinsen-price-monitor' ); ?></th>
						<td><?php echo esc_html( $this->format_price_amount( (float) $suggestion['difference'], $currency ) ); ?></td>
					</tr>
				</tbody>
			</table>
			<p class="lpm-danger-note"><?php esc_html_e( 'This approval will change the WooCommerce product price using WooCommerce CRUD APIs. Scheduled checks never perform this action.', 'lilleprinsen-price-monitor' ); ?></p>
			<form method="post" class="lpm-form-actions">
				<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
				<?php wp_nonce_field( 'lpm_real_price_update_' . (int) $suggestion['id'], 'lpm_real_update_nonce' ); ?>
				<input type="hidden" name="lpm_action" value="approve_and_update_price" />
				<input type="hidden" name="suggestion_id" value="<?php echo esc_attr( (string) $suggestion['id'] ); ?>" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm and update price', 'lilleprinsen-price-monitor' ); ?></button>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'approvals' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'lilleprinsen-price-monitor' ); ?></a>
			</form>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $suggestion Suggestion row.
	 * @param array<string, mixed> $settings Current settings.
	 */
	private function render_group_real_update_confirmation( array $suggestion, array $settings ): void {
		$currency = (string) ( $settings['default_currency'] ?? 'NOK' );
		$group    = $this->repository->get_product_group( (int) $suggestion['group_id'] );
		$members  = $this->repository->get_product_group_members( (int) $suggestion['group_id'], true );
		$report   = $group ? $this->group_suggestion_service->validate_group_members(
			$group,
			$members,
			(float) $suggestion['suggested_price'],
			$settings,
			array(
				'real_update'              => true,
				'check_product_exists'     => true,
				'block_conflicting_sessions' => 'price_match_down' === (string) $suggestion['suggestion_type'],
				'current_suggestion_id'    => (int) $suggestion['id'],
				'expected_current_prices'  => array( (int) $suggestion['product_id'] => (float) $suggestion['current_price'] ),
			)
		) : array( 'success' => false, 'warnings' => array( __( 'Product group was not found.', 'lilleprinsen-price-monitor' ) ), 'blocked_products' => array(), 'affected_products' => array() );
		$allow_partial = ! empty( $settings['allow_partial_group_price_updates'] );
		$can_submit    = ! empty( $report['success'] ) || ( $allow_partial && ! empty( $report['eligible_products'] ) );
		?>
		<section class="lpm-card lpm-card-spaced lpm-confirm-update">
			<div class="lpm-card-header">
				<div>
					<h2><?php esc_html_e( 'Confirm group WooCommerce price update', 'lilleprinsen-price-monitor' ); ?></h2>
					<p class="lpm-card-subtitle"><?php echo esc_html( $group ? (string) $group['name'] : __( 'Unknown group', 'lilleprinsen-price-monitor' ) ); ?></p>
				</div>
				<?php $this->render_status_pill( __( 'Changes multiple product prices', 'lilleprinsen-price-monitor' ), 'danger' ); ?>
			</div>
			<p class="lpm-danger-note"><?php esc_html_e( 'This approval will update every eligible enabled group member using WooCommerce CRUD APIs. No automatic checks or token links can perform this action.', 'lilleprinsen-price-monitor' ); ?></p>
			<?php if ( count( $members ) >= 5 ) : ?>
				<p class="lpm-danger-note"><?php esc_html_e( 'This group has many products. Review every row before confirming.', 'lilleprinsen-price-monitor' ); ?></p>
			<?php endif; ?>
			<table class="lpm-compact-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Product', 'lilleprinsen-price-monitor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Current price', 'lilleprinsen-price-monitor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'New price', 'lilleprinsen-price-monitor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Safety', 'lilleprinsen-price-monitor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $members as $member ) : ?>
						<?php
						$product_id = absint( $member['product_id'] ?? 0 );
						$product    = $this->get_product( $product_id );
						$current    = $product && method_exists( $product, 'get_price' ) ? (float) $product->get_price() : null;
						$blocked    = $this->get_group_block_reasons_for_product( (array) ( $report['blocked_products'] ?? array() ), $product_id );
						?>
						<tr>
							<td>
								<?php echo esc_html( $product ? $this->get_product_name( $product ) : sprintf( __( 'Product #%d', 'lilleprinsen-price-monitor' ), $product_id ) ); ?>
								<small><?php printf( esc_html__( 'ID %d', 'lilleprinsen-price-monitor' ), $product_id ); ?></small>
							</td>
							<td><?php echo esc_html( null === $current ? '—' : $this->format_price_amount( $current, $currency ) ); ?></td>
							<td><?php echo esc_html( $this->format_price_amount( (float) $suggestion['suggested_price'], $currency ) ); ?></td>
							<td>
								<?php if ( empty( $blocked ) ) : ?>
									<?php $this->render_status_pill( __( 'Eligible', 'lilleprinsen-price-monitor' ), 'ok' ); ?>
								<?php else : ?>
									<?php $this->render_status_pill( __( 'Blocked', 'lilleprinsen-price-monitor' ), 'danger' ); ?>
									<small><?php echo esc_html( implode( ' ', $blocked ) ); ?></small>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="lpm-field-description">
				<?php
				printf(
					/* translators: %s: partial update setting. */
					esc_html__( 'Partial group updates: %s.', 'lilleprinsen-price-monitor' ),
					$allow_partial ? esc_html__( 'allowed by settings', 'lilleprinsen-price-monitor' ) : esc_html__( 'disabled; any blocked member stops the whole group update', 'lilleprinsen-price-monitor' )
				);
				?>
			</p>
			<form method="post" class="lpm-form-actions">
				<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
				<?php wp_nonce_field( 'lpm_real_price_update_' . (int) $suggestion['id'], 'lpm_real_update_nonce' ); ?>
				<input type="hidden" name="lpm_action" value="approve_and_update_price" />
				<input type="hidden" name="suggestion_id" value="<?php echo esc_attr( (string) $suggestion['id'] ); ?>" />
				<button type="submit" class="button button-primary" <?php disabled( ! $can_submit ); ?>><?php esc_html_e( 'Confirm and update group prices', 'lilleprinsen-price-monitor' ); ?></button>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'approvals' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'lilleprinsen-price-monitor' ); ?></a>
			</form>
		</section>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $blocked_products Blocked products.
	 * @return array<int, string>
	 */
	private function get_group_block_reasons_for_product( array $blocked_products, int $product_id ): array {
		foreach ( $blocked_products as $blocked ) {
			if ( is_array( $blocked ) && absint( $blocked['product_id'] ?? 0 ) === $product_id ) {
				return array_map( 'strval', (array) ( $blocked['reasons'] ?? array() ) );
			}
		}

		return array();
	}

	private function render_log_filters( array $filters ): void {
		?>
		<form method="get" class="lpm-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="logs" />
			<label>
				<span><?php esc_html_e( 'Level', 'lilleprinsen-price-monitor' ); ?></span>
				<select name="level">
					<option value=""><?php esc_html_e( 'All levels', 'lilleprinsen-price-monitor' ); ?></option>
					<?php foreach ( array( 'info', 'warning', 'error', 'debug' ) as $level ) : ?>
						<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $filters['level'], $level ); ?>><?php echo esc_html( ucfirst( $level ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Event', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="event" value="<?php echo esc_attr( (string) $filters['event'] ); ?>" placeholder="competitor_link_added" />
			</label>
			<label>
				<span><?php esc_html_e( 'Product ID', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="number" min="1" name="product_id" value="<?php echo esc_attr( (string) $filters['product_id'] ); ?>" />
			</label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter logs', 'lilleprinsen-price-monitor' ); ?></button>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'logs' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Clear', 'lilleprinsen-price-monitor' ); ?></a>
		</form>
		<?php
	}

	private function render_observation_filters( array $filters ): void {
		?>
		<form method="get" class="lpm-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="history" />
			<label>
				<span><?php esc_html_e( 'Product ID', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="number" min="1" name="product_id" value="<?php echo esc_attr( (string) $filters['product_id'] ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'Competitor link ID', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="number" min="1" name="competitor_link_id" value="<?php echo esc_attr( (string) $filters['competitor_link_id'] ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'Result', 'lilleprinsen-price-monitor' ); ?></span>
				<select name="status">
					<option value=""><?php esc_html_e( 'All results', 'lilleprinsen-price-monitor' ); ?></option>
					<option value="success" <?php selected( $filters['status'], 'success' ); ?>><?php esc_html_e( 'Success', 'lilleprinsen-price-monitor' ); ?></option>
					<option value="failed" <?php selected( $filters['status'], 'failed' ); ?>><?php esc_html_e( 'Failed', 'lilleprinsen-price-monitor' ); ?></option>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Date from', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="date" name="date_from" value="<?php echo esc_attr( (string) $filters['date_from'] ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'Date to', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="date" name="date_to" value="<?php echo esc_attr( (string) $filters['date_to'] ); ?>" />
			</label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter history', 'lilleprinsen-price-monitor' ); ?></button>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'history' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Clear', 'lilleprinsen-price-monitor' ); ?></a>
		</form>
		<?php
	}

	private function render_logs_table( array $logs ): void {
		if ( empty( $logs ) ) {
			$this->render_empty_state( __( 'No logs match the current filters.', 'lilleprinsen-price-monitor' ) );
			return;
		}
		?>
		<table class="lpm-compact-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Level', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Product ID', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Context summary', 'lilleprinsen-price-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $this->format_datetime( $log['created_at'] ?? null ) ); ?></td>
						<td><?php $this->render_status_pill( (string) $log['level'], 'error' === $log['level'] ? 'danger' : ( 'warning' === $log['level'] ? 'warning' : 'muted' ) ); ?></td>
						<td><code><?php echo esc_html( (string) $log['event'] ); ?></code></td>
						<td><?php echo esc_html( $this->format_nullable_value( $log['product_id'] ?? null ) ); ?></td>
						<td><?php echo esc_html( $this->shorten_text( (string) ( $log['message'] ?? '' ), 90 ) ); ?></td>
						<td><?php $this->render_context_summary( (string) ( $log['context'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_active_price_match_sessions_table( array $sessions ): void {
		if ( empty( $sessions ) ) {
			$this->render_empty_state( __( 'No active price match sessions are currently stored.', 'lilleprinsen-price-monitor' ) );
			return;
		}
		?>
		<table class="lpm-compact-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Product', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Original active price', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Matched price', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Matched at', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Recovery strategy', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last lowest competitor price', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sessions as $session ) : ?>
					<tr>
						<td>
							<?php printf( esc_html__( 'Product #%d', 'lilleprinsen-price-monitor' ), (int) $session['product_id'] ); ?>
							<?php if ( ! empty( $session['sku'] ) ) : ?>
								<small><?php echo esc_html( (string) $session['sku'] ); ?></small>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $this->format_nullable_value( $session['original_active_price'] ?? null ) ); ?></td>
						<td><?php echo esc_html( $this->format_nullable_value( $session['matched_price'] ?? null ) ); ?></td>
						<td><?php echo esc_html( $this->format_datetime( $session['matched_at'] ?? null ) ); ?></td>
						<td><?php echo esc_html( $this->format_nullable_value( $session['recovery_strategy'] ?? null ) ); ?></td>
						<td><?php echo esc_html( $this->format_nullable_value( $session['last_lowest_competitor_price'] ?? null ) ); ?></td>
						<td><?php $this->render_status_pill( (string) $session['status'], 'active_dry_run' === (string) $session['status'] ? 'ok' : 'warning' ); ?></td>
						<td>
							<div class="lpm-actions">
								<?php if ( ! empty( $session['suggestion_id'] ) ) : ?>
									<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'approvals', 'lpm_approval_view' => 'all' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View related suggestion', 'lilleprinsen-price-monitor' ); ?></a>
								<?php endif; ?>
								<?php if ( 'active_dry_run' === (string) $session['status'] ) : ?>
									<form method="post" class="lpm-inline-action-form">
										<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
										<input type="hidden" name="lpm_action" value="end_price_match_session" />
										<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session['id'] ); ?>" />
										<button type="submit" class="button button-small"><?php esc_html_e( 'End session', 'lilleprinsen-price-monitor' ); ?></button>
									</form>
								<?php else : ?>
									<span class="lpm-field-description"><?php esc_html_e( 'Real sessions end only through guarded restore/update flow.', 'lilleprinsen-price-monitor' ); ?></span>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_observations_table( array $observations ): void {
		if ( empty( $observations ) ) {
			$this->render_empty_state( __( 'No price observations match the current filters.', 'lilleprinsen-price-monitor' ) );
			return;
		}
		?>
		<table class="lpm-compact-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Product ID', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Price', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Currency', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Method', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'HTTP status', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Error', 'lilleprinsen-price-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $observations as $observation ) : ?>
					<tr>
						<td><?php echo esc_html( $this->format_datetime( $observation['checked_at'] ?? null ) ); ?></td>
						<td><?php echo esc_html( (string) ( $observation['product_id'] ?? '' ) ); ?></td>
						<td>
							<?php
							$competitor_name = (string) ( $observation['competitor_name'] ?? '' );
							echo esc_html( '' !== $competitor_name ? $competitor_name : sprintf( __( 'Link #%d', 'lilleprinsen-price-monitor' ), (int) ( $observation['competitor_link_id'] ?? 0 ) ) );
							?>
						</td>
						<td><?php echo esc_html( $this->format_nullable_value( $observation['observed_price'] ?? null ) ); ?></td>
						<td><?php echo esc_html( $this->format_nullable_value( $observation['currency'] ?? null ) ); ?></td>
						<td><?php echo esc_html( $this->format_nullable_value( $observation['extraction_method'] ?? null ) ); ?></td>
						<td><?php echo esc_html( $this->format_nullable_value( $observation['http_status'] ?? null ) ); ?></td>
						<td><?php $this->render_status_pill( ! empty( $observation['success'] ) ? __( 'Success', 'lilleprinsen-price-monitor' ) : __( 'Failed', 'lilleprinsen-price-monitor' ), ! empty( $observation['success'] ) ? 'ok' : 'danger' ); ?></td>
						<td><?php echo esc_html( $this->shorten_text( (string) ( $observation['error_message'] ?? '' ), 80 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_schema_status_table( array $table_status ): void {
		$tables = isset( $table_status['tables'] ) && is_array( $table_status['tables'] ) ? $table_status['tables'] : array();
		?>
		<table class="lpm-compact-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Table', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Exists', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rows', 'lilleprinsen-price-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tables as $table ) : ?>
					<tr>
						<td><code><?php echo esc_html( (string) $table['name'] ); ?></code></td>
						<td><?php $this->render_status_pill( ! empty( $table['exists'] ) ? __( 'Yes', 'lilleprinsen-price-monitor' ) : __( 'No', 'lilleprinsen-price-monitor' ), ! empty( $table['exists'] ) ? 'ok' : 'warning' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $table['count'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_add_monitoring_form( int $product_id ): void {
		?>
		<form method="post" class="lpm-inline-action" data-lpm-add-monitoring-form>
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="add_monitored_product" />
			<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product_id ); ?>" />
			<button type="submit" class="button button-small" data-lpm-add-product="<?php echo esc_attr( (string) $product_id ); ?>"><?php esc_html_e( 'Add to monitoring', 'lilleprinsen-price-monitor' ); ?></button>
		</form>
		<?php
	}

	private function render_monitored_action_form( int $monitored_product_id, string $action, string $label, string $class = '' ): void {
		?>
		<form method="post" class="lpm-inline-action">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="monitored_product_id" value="<?php echo esc_attr( (string) $monitored_product_id ); ?>" />
			<button type="submit" class="button button-small <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function render_competitor_action_form( int $competitor_link_id, string $action, string $label, string $class = '' ): void {
		?>
		<form method="post" class="lpm-inline-action">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="competitor_link_id" value="<?php echo esc_attr( (string) $competitor_link_id ); ?>" />
			<button type="submit" class="button button-small <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function render_competitor_profile_action_form( int $competitor_id, string $action, string $label, string $class = '' ): void {
		?>
		<form method="post" class="lpm-inline-action">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="competitor_profile_id" value="<?php echo esc_attr( (string) $competitor_id ); ?>" />
			<button type="submit" class="button button-small <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function render_suggestion_action_form( int $suggestion_id, string $action, string $label, string $class = '', string $button_attributes = '' ): void {
		if ( ! preg_match( '/^data-lpm-(approve|reject)-suggestion="[0-9]+"$/', $button_attributes ) ) {
			$button_attributes = '';
		}
		?>
		<form method="post" class="lpm-inline-action">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="suggestion_id" value="<?php echo esc_attr( (string) $suggestion_id ); ?>" />
			<input type="hidden" name="lpm_approval_view" value="<?php echo esc_attr( $this->get_approval_view() ); ?>" />
			<button type="submit" class="button button-small <?php echo esc_attr( $class ); ?>" <?php echo $button_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function render_suggestion_price_form( array $suggestion ): void {
		?>
		<form method="post" class="lpm-inline-action lpm-price-edit">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="update_suggested_price" />
			<input type="hidden" name="suggestion_id" value="<?php echo esc_attr( (string) $suggestion['id'] ); ?>" />
			<input type="hidden" name="lpm_approval_view" value="<?php echo esc_attr( $this->get_approval_view() ); ?>" />
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'Suggested price', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="number" min="0.01" step="0.01" name="suggested_price" value="<?php echo esc_attr( $this->format_price_for_input( (float) $suggestion['suggested_price'] ) ); ?>" />
			</label>
			<button type="submit" class="button button-small"><?php esc_html_e( 'Update', 'lilleprinsen-price-monitor' ); ?></button>
		</form>
		<?php
	}

	private function render_summary_card( string $label, int|string $value, string $description ): void {
		$display_value = is_int( $value ) ? number_format_i18n( $value ) : $value;
		?>
		<section class="lpm-card lpm-summary-card">
			<span class="lpm-summary-label"><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( $display_value ); ?></strong>
			<span><?php echo esc_html( $description ); ?></span>
		</section>
		<?php
	}

	private function render_health_card( string $label, string $value, string $description, string $status ): void {
		?>
		<section class="lpm-card lpm-summary-card">
			<span class="lpm-summary-label"><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( $value ); ?></strong>
			<span><?php echo esc_html( $description ); ?></span>
			<?php $this->render_status_pill( $this->get_health_status_label( $status ), $status ); ?>
		</section>
		<?php
	}

	private function get_health_status_label( string $status ): string {
		$labels = array(
			'ok'      => __( 'OK', 'lilleprinsen-price-monitor' ),
			'warning' => __( 'Warning', 'lilleprinsen-price-monitor' ),
			'danger'  => __( 'Review', 'lilleprinsen-price-monitor' ),
			'muted'   => __( 'Idle', 'lilleprinsen-price-monitor' ),
		);

		return $labels[ $status ] ?? __( 'Status', 'lilleprinsen-price-monitor' );
	}

	public function render_status_pill( string $label, string $status ): void {
		printf(
			'<span class="lpm-pill lpm-pill-%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( $label )
		);
	}

	/**
	 * @param array<string, mixed> $settings Current settings.
	 */
	private function render_token_link_status( string $suggestion_status, array $settings ): void {
		if ( empty( $settings['allow_token_dry_run_approval_links'] ) ) {
			$this->render_status_pill( __( 'Disabled', 'lilleprinsen-price-monitor' ), 'muted' );
			return;
		}

		if ( 'pending' === $suggestion_status ) {
			$this->render_status_pill( __( 'Approve/reject', 'lilleprinsen-price-monitor' ), 'ok' );
			return;
		}

		if ( 'blocked' === $suggestion_status ) {
			$this->render_status_pill( __( 'Reject only', 'lilleprinsen-price-monitor' ), 'warning' );
			return;
		}

		$this->render_status_pill( __( 'Unavailable', 'lilleprinsen-price-monitor' ), 'muted' );
	}

	private function render_empty_card( string $title, string $body ): void {
		?>
		<section class="lpm-card">
			<div class="lpm-card-header">
				<h2><?php echo esc_html( $title ); ?></h2>
			</div>
			<p><?php echo esc_html( $body ); ?></p>
		</section>
		<?php
	}

	private function render_empty_state( string $message ): void {
		?>
		<p class="lpm-empty"><?php echo esc_html( $message ); ?></p>
		<?php
	}

	private function render_context_summary( string $context ): void {
		if ( '' === $context ) {
			echo esc_html( '—' );
			return;
		}

		$decoded = json_decode( $context, true );
		$summary = $this->shorten_text( is_array( $decoded ) ? implode( ', ', array_keys( $decoded ) ) : $context, 70 );
		?>
		<details class="lpm-context">
			<summary><?php echo esc_html( $summary ); ?></summary>
			<pre><?php echo esc_html( $context ); ?></pre>
		</details>
		<?php
	}

	private function render_warnings_summary( string $warnings_json ): void {
		if ( '' === $warnings_json ) {
			echo esc_html( '—' );
			return;
		}

		$warnings = json_decode( $warnings_json, true );

		if ( ! is_array( $warnings ) || empty( $warnings ) ) {
			echo esc_html( '—' );
			return;
		}

		$summary = $this->shorten_text( implode( ' ', array_map( 'strval', $warnings ) ), 90 );
		?>
		<details class="lpm-context">
			<summary><?php echo esc_html( $summary ); ?></summary>
			<ul class="lpm-check-list">
				<?php foreach ( $warnings as $warning ) : ?>
					<li><?php echo esc_html( (string) $warning ); ?></li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	private function render_recovery_details_summary( string $rule_details_json, float $current_price, float $competitor_price, float $suggested_price, string $currency ): void {
		if ( '' === $rule_details_json ) {
			return;
		}

		$details = json_decode( $rule_details_json, true );

		if ( ! is_array( $details ) || empty( $details['recovery_session'] ) || ! is_array( $details['recovery_session'] ) ) {
			return;
		}

		$session = $details['recovery_session'];
		?>
		<details class="lpm-context">
			<summary><?php esc_html_e( 'Recovery details', 'lilleprinsen-price-monitor' ); ?></summary>
			<ul class="lpm-check-list">
				<li><?php printf( esc_html__( 'Original regular price: %s', 'lilleprinsen-price-monitor' ), esc_html( $this->format_nullable_value( $session['original_regular_price'] ?? null ) ) ); ?></li>
				<li><?php printf( esc_html__( 'Original sale price: %s', 'lilleprinsen-price-monitor' ), esc_html( $this->format_nullable_value( $session['original_sale_price'] ?? null ) ) ); ?></li>
				<li><?php printf( esc_html__( 'Original active price: %s', 'lilleprinsen-price-monitor' ), esc_html( $this->format_nullable_value( $session['original_active_price'] ?? null ) ) ); ?></li>
				<li><?php printf( esc_html__( 'Current WooCommerce price: %s', 'lilleprinsen-price-monitor' ), esc_html( $this->format_price_amount( $current_price, $currency ) ) ); ?></li>
				<li><?php printf( esc_html__( 'New competitor price: %s', 'lilleprinsen-price-monitor' ), esc_html( $this->format_price_amount( $competitor_price, $currency ) ) ); ?></li>
				<li><?php printf( esc_html__( 'Suggested recovery price: %s', 'lilleprinsen-price-monitor' ), esc_html( $this->format_price_amount( $suggested_price, $currency ) ) ); ?></li>
			</ul>
		</details>
		<?php
	}

	private function get_rule_details_summary( string $rule_details_json ): string {
		if ( '' === $rule_details_json ) {
			return '—';
		}

		$details = json_decode( $rule_details_json, true );

		if ( ! is_array( $details ) ) {
			return $this->shorten_text( $rule_details_json, 90 );
		}

		$parts = array();

		if ( ! empty( $details['strategy'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: pricing strategy key. */
				__( 'Strategy: %s', 'lilleprinsen-price-monitor' ),
				(string) $details['strategy']
			);
		}

		if ( ! empty( $details['rounding_mode'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: rounding mode key. */
				__( 'Rounding: %s', 'lilleprinsen-price-monitor' ),
				(string) $details['rounding_mode']
			);
		}

		if ( isset( $details['price_drop_percent'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: price drop percent. */
				__( 'Drop: %s%%', 'lilleprinsen-price-monitor' ),
				(string) $details['price_drop_percent']
			);
		}

		if ( isset( $details['price_increase_percent'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: price increase percent. */
				__( 'Increase: %s%%', 'lilleprinsen-price-monitor' ),
				(string) $details['price_increase_percent']
			);
		}

		return empty( $parts ) ? '—' : $this->shorten_text( implode( ', ', $parts ), 100 );
	}

	private function render_pagination( int $total, int $page, int $per_page, string $page_arg, array $extra_args ): void {
		$total_pages = (int) ceil( max( 0, $total ) / max( 1, $per_page ) );

		if ( $total_pages <= 1 ) {
			return;
		}

		$base_args = array_merge(
			array(
				'page' => self::SLUG,
			),
			array_filter(
				$extra_args,
				static function ( $value ): bool {
					return '' !== $value && null !== $value;
				}
			)
		);
		?>
		<nav class="lpm-pagination" aria-label="<?php esc_attr_e( 'Pagination', 'lilleprinsen-price-monitor' ); ?>">
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( $page_arg => $page - 1 ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Previous', 'lilleprinsen-price-monitor' ); ?></a>
			<?php endif; ?>
			<span><?php printf( esc_html__( 'Page %1$d of %2$d', 'lilleprinsen-price-monitor' ), (int) $page, (int) $total_pages ); ?></span>
			<?php if ( $page < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( $page_arg => $page + 1 ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Next', 'lilleprinsen-price-monitor' ); ?></a>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Current settings.
	 */
	private function render_checkbox_field( string $key, string $label, array $settings, string $description = '' ): void {
		$value = ! empty( $settings[ $key ] );
		?>
		<label class="lpm-field lpm-field-checkbox">
			<input type="hidden" name="lpm_settings[<?php echo esc_attr( $key ); ?>]" value="0" />
			<input type="checkbox" name="lpm_settings[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $value ); ?> />
			<span>
				<strong><?php echo esc_html( $label ); ?></strong>
				<?php if ( '' !== $description ) : ?>
					<small><?php echo esc_html( $description ); ?></small>
				<?php endif; ?>
			</span>
		</label>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Current settings.
	 * @param array<string, string> $options Checkbox options.
	 */
	private function render_checkbox_group_field( string $key, string $label, array $settings, array $options ): void {
		$values = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? $settings[ $key ] : array();
		?>
		<div class="lpm-field">
			<span><?php echo esc_html( $label ); ?></span>
			<input type="hidden" name="lpm_settings[<?php echo esc_attr( $key ); ?>][]" value="" />
			<?php foreach ( $options as $value => $option_label ) : ?>
				<label class="lpm-field-checkbox lpm-field-checkbox-compact">
					<input type="checkbox" name="lpm_settings[<?php echo esc_attr( $key ); ?>][]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $values, true ) ); ?> />
					<span><?php echo esc_html( $option_label ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Current settings.
	 */
	private function render_text_field( string $key, string $label, array $settings, string $placeholder = '' ): void {
		?>
		<label class="lpm-field">
			<span><?php echo esc_html( $label ); ?></span>
			<input type="text" name="lpm_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
		</label>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Current settings.
	 */
	private function render_number_field( string $key, string $label, array $settings, int $min ): void {
		?>
		<label class="lpm-field">
			<span><?php echo esc_html( $label ); ?></span>
			<input type="number" min="<?php echo esc_attr( (string) $min ); ?>" step="1" name="lpm_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" />
		</label>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Current settings.
	 */
	private function render_decimal_field( string $key, string $label, array $settings, string $description = '' ): void {
		?>
		<label class="lpm-field">
			<span><?php echo esc_html( $label ); ?></span>
			<input type="number" min="0" step="0.01" name="lpm_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" />
			<?php if ( '' !== $description ) : ?>
				<small><?php echo esc_html( $description ); ?></small>
			<?php endif; ?>
		</label>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Current settings.
	 * @param array<string, string> $options Select options.
	 */
	private function render_select_field( string $key, string $label, array $settings, array $options ): void {
		?>
		<label class="lpm-field">
			<span><?php echo esc_html( $label ); ?></span>
			<select name="lpm_settings[<?php echo esc_attr( $key ); ?>]">
				<?php foreach ( $options as $value => $option_label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $settings[ $key ], $value ); ?>><?php echo esc_html( $option_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}

	private function get_search_query(): string {
		return isset( $_GET['lpm_product_search'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['lpm_product_search'] ) ) ) : '';
	}

	private function get_approval_view(): string {
		$view = isset( $_GET['lpm_approval_view'] ) ? sanitize_key( wp_unslash( $_GET['lpm_approval_view'] ) ) : 'pending';

		return array_key_exists( $view, $this->get_approval_view_options() ) ? $view : 'pending';
	}

	private function get_submitted_approval_view(): string {
		$view = isset( $_POST['lpm_approval_view'] ) ? sanitize_key( wp_unslash( $_POST['lpm_approval_view'] ) ) : 'pending';

		return array_key_exists( $view, $this->get_approval_view_options() ) ? $view : 'pending';
	}

	/**
	 * @return array<string, string>
	 */
	private function get_approval_view_options(): array {
		return array(
			'pending'                => __( 'Pending', 'lilleprinsen-price-monitor' ),
			'match_suggestions'      => __( 'Match suggestions', 'lilleprinsen-price-monitor' ),
			'price_suggestions'      => __( 'Price suggestions', 'lilleprinsen-price-monitor' ),
			'blocked'                => __( 'Blocked', 'lilleprinsen-price-monitor' ),
			'approved_dry_run'       => __( 'Approved dry-run', 'lilleprinsen-price-monitor' ),
			'approved_real_update'   => __( 'Approved real update', 'lilleprinsen-price-monitor' ),
			'rejected'               => __( 'Rejected', 'lilleprinsen-price-monitor' ),
			'failed'                 => __( 'Failed', 'lilleprinsen-price-monitor' ),
			'price_match_down'       => __( 'Price match down', 'lilleprinsen-price-monitor' ),
			'price_match_up'         => __( 'Price match up', 'lilleprinsen-price-monitor' ),
			'restore_previous_price' => __( 'Restore previous price', 'lilleprinsen-price-monitor' ),
			'recovery'               => __( 'Recovery', 'lilleprinsen-price-monitor' ),
			'all'                    => __( 'All', 'lilleprinsen-price-monitor' ),
		);
	}

	/**
	 * @return array{level: string, event: string, product_id: string}
	 */
	private function get_log_filters(): array {
		return array(
			'level'      => isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '',
			'event'      => isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '',
			'product_id' => isset( $_GET['product_id'] ) ? (string) absint( wp_unslash( $_GET['product_id'] ) ) : '',
		);
	}

	/**
	 * @return array{product_id: string, competitor_link_id: string, status: string, date_from: string, date_to: string}
	 */
	private function get_observation_filters(): array {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		if ( ! in_array( $status, array( 'success', 'failed' ), true ) ) {
			$status = '';
		}

		return array(
			'product_id'         => isset( $_GET['product_id'] ) ? (string) absint( wp_unslash( $_GET['product_id'] ) ) : '',
			'competitor_link_id' => isset( $_GET['competitor_link_id'] ) ? (string) absint( wp_unslash( $_GET['competitor_link_id'] ) ) : '',
			'status'             => $status,
			'date_from'          => $this->get_date_query_arg( 'date_from' ),
			'date_to'            => $this->get_date_query_arg( 'date_to' ),
		);
	}

	private function get_positive_query_arg( string $key, int $default ): int {
		if ( ! isset( $_GET[ $key ] ) ) {
			return $default;
		}

		return max( 0, absint( wp_unslash( $_GET[ $key ] ) ) );
	}

	private function get_date_query_arg( string $key ): string {
		if ( ! isset( $_GET[ $key ] ) ) {
			return '';
		}

		$value = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	private function get_product( int $product_id ): ?object {
		if ( $product_id <= 0 || ! Plugin::is_woocommerce_active() || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		return is_object( $product ) ? $product : null;
	}

	private function get_product_thumbnail( object $product ): string {
		if ( ! method_exists( $product, 'get_image_id' ) ) {
			return '<span class="lpm-thumb-placeholder"></span>';
		}

		$image_id = (int) $product->get_image_id();

		if ( $image_id <= 0 ) {
			return '<span class="lpm-thumb-placeholder"></span>';
		}

		$image = wp_get_attachment_image( $image_id, array( 48, 48 ), false, array( 'class' => 'lpm-product-thumb' ) );

		return is_string( $image ) && '' !== $image ? $image : '<span class="lpm-thumb-placeholder"></span>';
	}

	private function get_product_name( object $product ): string {
		return method_exists( $product, 'get_name' ) ? (string) $product->get_name() : __( 'Untitled product', 'lilleprinsen-price-monitor' );
	}

	private function get_product_sku( object $product ): string {
		$sku = method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';

		return '' === $sku ? '—' : $sku;
	}

	private function get_product_gtin( object $product ): string {
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$value = trim( (string) $product->get_global_unique_id() );
			if ( '' !== $value ) {
				return $value;
			}
		}

		foreach ( array( '_global_unique_id', '_alg_ean', '_wpm_gtin_code', 'ean', 'gtin', 'barcode' ) as $key ) {
			if ( method_exists( $product, 'get_meta' ) ) {
				$value = trim( (string) $product->get_meta( $key, true ) );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return '—';
	}

	private function get_product_brand( object $product ): string {
		foreach ( array( 'pa_brand', 'brand' ) as $attribute ) {
			if ( method_exists( $product, 'get_attribute' ) ) {
				$value = trim( wp_strip_all_tags( (string) $product->get_attribute( $attribute ) ) );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		foreach ( array( '_brand', 'brand' ) as $key ) {
			if ( method_exists( $product, 'get_meta' ) ) {
				$value = trim( (string) $product->get_meta( $key, true ) );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return '—';
	}

	private function resolve_product_id_from_admin_identifier( string $identifier ): int {
		if ( ctype_digit( $identifier ) ) {
			return absint( $identifier );
		}

		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$product_id = (int) wc_get_product_id_by_sku( $identifier );
			if ( $product_id > 0 ) {
				return $product_id;
			}
		}

		$matches = $this->product_search_service->search( $identifier, 1 );
		if ( ! empty( $matches[0]['id'] ) ) {
			return absint( $matches[0]['id'] );
		}

		return 0;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Monitored product rows.
	 * @return array<int,object> Discovery product rows keyed by monitored product ID.
	 */
	private function get_discovery_rows_for_monitored_rows( array $rows ): array {
		$discovery_rows = array();
		foreach ( $rows as $row ) {
			$monitored_id = (int) ( $row['id'] ?? 0 );
			$product_id   = (int) ( $row['product_id'] ?? 0 );
			if ( $monitored_id <= 0 || $product_id <= 0 ) {
				continue;
			}

			$product = $this->get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$variation_id = method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ? $product_id : 0;
			$parent_id    = $variation_id > 0 && method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : $product_id;
			$discovery    = $this->discovery_repository->get_discovery_product_by_product_id( $parent_id, $variation_id );
			if ( ! $discovery || empty( $discovery->enabled ) ) {
				$this->sync_product_to_discovery_selection( $product_id, $product );
				$discovery = $this->discovery_repository->get_discovery_product_by_product_id( $parent_id, $variation_id );
			}

			if ( $discovery ) {
				$discovery_rows[ $monitored_id ] = $discovery;
			}
		}

		return $discovery_rows;
	}

	private function sync_product_to_discovery_selection( int $product_id, ?object $product = null ): void {
		$product = $product ?: $this->get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$variation_id = method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ? (int) $product_id : 0;
		$parent_id    = $variation_id > 0 && method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : $product_id;
		$identifier_product_id = $variation_id > 0 ? $variation_id : $parent_id;
		$identifiers           = $this->product_identifier_service->get_for_product_id( $identifier_product_id );
		if ( '' === (string) ( $identifiers['sku'] ?? '' ) && method_exists( $product, 'get_sku' ) ) {
			$identifiers['sku'] = (string) $product->get_sku();
		}

		$this->discovery_repository->upsert_discovery_product( $parent_id, $variation_id, $identifiers );
	}

	private function get_product_price_html( object $product ): string {
		if ( method_exists( $product, 'get_price_html' ) ) {
			$price_html = (string) $product->get_price_html();

			if ( '' !== $price_html ) {
				return $price_html;
			}
		}

		if ( method_exists( $product, 'get_price' ) && function_exists( 'wc_price' ) ) {
			$price = $product->get_price();

			return '' !== (string) $price ? wc_price( $price ) : '—';
		}

		return '—';
	}

	private function get_product_stock_status( object $product ): string {
		if ( ! method_exists( $product, 'get_stock_status' ) ) {
			return __( 'Unknown', 'lilleprinsen-price-monitor' );
		}

		$status = (string) $product->get_stock_status();

		return '' === $status ? __( 'Unknown', 'lilleprinsen-price-monitor' ) : $status;
	}

	private function get_editing_competitor_link( int $monitored_product_id ): ?array {
		$link_id = $this->get_positive_query_arg( 'competitor_link_id', 0 );

		if ( 0 >= $link_id ) {
			return null;
		}

		$link = $this->repository->get_competitor_link( $link_id );

		if ( ! $link || (int) $link['monitored_product_id'] !== $monitored_product_id ) {
			return null;
		}

		return $link;
	}

	private function get_editing_competitor_profile(): ?array {
		$profile_id = $this->get_positive_query_arg( 'competitor_profile_id', 0 );

		if ( 0 >= $profile_id ) {
			return null;
		}

		return $this->repository->get_competitor( $profile_id );
	}

	private function get_editing_monitored_product_rules(): ?array {
		$monitored_product_id = $this->get_positive_query_arg( 'edit_rules_id', 0 );

		if ( 0 >= $monitored_product_id ) {
			return null;
		}

		return $this->repository->get_monitored_product( $monitored_product_id );
	}

	/**
	 * @return array<string, string>
	 */
	private function get_match_type_options(): array {
		return array(
			'unknown'           => __( 'Unknown', 'lilleprinsen-price-monitor' ),
			'exact'             => __( 'Exact', 'lilleprinsen-price-monitor' ),
			'similar'           => __( 'Similar', 'lilleprinsen-price-monitor' ),
			'different_variant' => __( 'Different variant', 'lilleprinsen-price-monitor' ),
			'bundle'            => __( 'Bundle', 'lilleprinsen-price-monitor' ),
			'not_comparable'    => __( 'Not comparable', 'lilleprinsen-price-monitor' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function get_extraction_mode_options(): array {
		return array(
			'auto'          => __( 'Auto', 'lilleprinsen-price-monitor' ),
			'json_ld'       => __( 'JSON-LD', 'lilleprinsen-price-monitor' ),
			'meta_tags'     => __( 'Meta tags', 'lilleprinsen-price-monitor' ),
			'selector'      => __( 'Selector', 'lilleprinsen-price-monitor' ),
			'visible_regex' => __( 'Visible regex', 'lilleprinsen-price-monitor' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function get_monitored_price_field_options(): array {
		return array(
			'sale_price_first' => __( 'Sale price first, then active/regular', 'lilleprinsen-price-monitor' ),
			'sale_price'       => __( 'Sale price only', 'lilleprinsen-price-monitor' ),
			'regular_price'    => __( 'Regular price only', 'lilleprinsen-price-monitor' ),
			'price_selector'   => __( 'Current/active price selector', 'lilleprinsen-price-monitor' ),
			'lowest_price'     => __( 'Lowest mapped price', 'lilleprinsen-price-monitor' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function get_priority_options(): array {
		return array(
			'low'    => __( 'Low', 'lilleprinsen-price-monitor' ),
			'normal' => __( 'Normal', 'lilleprinsen-price-monitor' ),
			'high'   => __( 'High', 'lilleprinsen-price-monitor' ),
			'urgent' => __( 'Urgent', 'lilleprinsen-price-monitor' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function get_pricing_strategy_options(): array {
		return array(
			'notify_only'                       => __( 'Notify only', 'lilleprinsen-price-monitor' ),
			'match_competitor'                  => __( 'Match competitor', 'lilleprinsen-price-monitor' ),
			'beat_competitor_by_amount'         => __( 'Beat competitor by amount', 'lilleprinsen-price-monitor' ),
			'stay_above_competitor_by_amount'   => __( 'Stay above competitor by amount', 'lilleprinsen-price-monitor' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function get_group_pricing_mode_options(): array {
		return array(
			'shared_price'                   => __( 'Shared price', 'lilleprinsen-price-monitor' ),
			'primary_product_controls_group' => __( 'Primary product controls group', 'lilleprinsen-price-monitor' ),
			'manual_review_only'             => __( 'Manual review only', 'lilleprinsen-price-monitor' ),
		);
	}

	private function get_group_pricing_mode_label( string $mode ): string {
		$options = $this->get_group_pricing_mode_options();

		return $options[ $mode ] ?? $options['shared_price'];
	}

	/**
	 * @return array<string, string>
	 */
	private function get_rounding_mode_options(): array {
		return array(
			'none'        => __( 'None', 'lilleprinsen-price-monitor' ),
			'nearest_1'   => __( 'Nearest 1', 'lilleprinsen-price-monitor' ),
			'nearest_5'   => __( 'Nearest 5', 'lilleprinsen-price-monitor' ),
			'nearest_10'  => __( 'Nearest 10', 'lilleprinsen-price-monitor' ),
			'nearest_50'  => __( 'Nearest 50', 'lilleprinsen-price-monitor' ),
			'nearest_100' => __( 'Nearest 100', 'lilleprinsen-price-monitor' ),
			'end_9'       => __( 'End in 9', 'lilleprinsen-price-monitor' ),
			'end_99'      => __( 'End in 99', 'lilleprinsen-price-monitor' ),
			'end_95'      => __( 'End in 95', 'lilleprinsen-price-monitor' ),
		);
	}

	private function get_suggestion_type_label( string $suggestion_type ): string {
		$labels = array(
			'price_match_down'               => __( 'Lower to competitor price', 'lilleprinsen-price-monitor' ),
			'price_match_up'                 => __( 'Raise to competitor price', 'lilleprinsen-price-monitor' ),
			'restore_previous_active_price'  => __( 'Restore previous active price', 'lilleprinsen-price-monitor' ),
			'restore_previous_regular_price' => __( 'Restore previous regular price', 'lilleprinsen-price-monitor' ),
			'restore_previous_sale_price'    => __( 'Restore previous sale price', 'lilleprinsen-price-monitor' ),
			'manual_review'                  => __( 'Needs manual review', 'lilleprinsen-price-monitor' ),
			'blocked'                        => __( 'Blocked', 'lilleprinsen-price-monitor' ),
		);

		return $labels[ $suggestion_type ] ?? __( 'Manual review', 'lilleprinsen-price-monitor' );
	}

	/**
	 * @return array<string, string>
	 */
	private function get_real_update_type_options(): array {
		return array(
			'price_match_down'               => __( 'Price match down', 'lilleprinsen-price-monitor' ),
			'price_match_up'                 => __( 'Price match up', 'lilleprinsen-price-monitor' ),
			'restore_previous_active_price'  => __( 'Restore previous active price', 'lilleprinsen-price-monitor' ),
			'restore_previous_regular_price' => __( 'Restore previous regular price', 'lilleprinsen-price-monitor' ),
			'restore_previous_sale_price'    => __( 'Restore previous sale price', 'lilleprinsen-price-monitor' ),
		);
	}

	private function get_suggestion_status_label( string $status ): string {
		$labels = array(
			'pending'          => __( 'Pending', 'lilleprinsen-price-monitor' ),
			'blocked'          => __( 'Blocked', 'lilleprinsen-price-monitor' ),
			'approved_dry_run' => __( 'Approved dry-run', 'lilleprinsen-price-monitor' ),
			'approved_real_update' => __( 'Approved real update', 'lilleprinsen-price-monitor' ),
			'rejected'         => __( 'Rejected', 'lilleprinsen-price-monitor' ),
			'failed'           => __( 'Failed', 'lilleprinsen-price-monitor' ),
		);

		return $labels[ $status ] ?? __( 'Pending', 'lilleprinsen-price-monitor' );
	}

	private function get_suggestion_status_pill_type( string $status ): string {
		if ( 'blocked' === $status ) {
			return 'danger';
		}

		if ( 'pending' === $status ) {
			return 'warning';
		}

		if ( 'approved_dry_run' === $status ) {
			return 'ok';
		}

		if ( 'approved_real_update' === $status ) {
			return 'danger';
		}

		if ( 'failed' === $status ) {
			return 'danger';
		}

		return 'muted';
	}

	/**
	 * @param array<string, mixed> $counts Dashboard counts.
	 * @param array<string, mixed> $settings Current settings.
	 * @param array<string, mixed> $lock_status Batch lock status.
	 * @return array<int, string>
	 */
	private function get_health_warnings( array $counts, array $settings, bool $woocommerce_active, array $lock_status ): array {
		$warnings = array();

		if ( ! $woocommerce_active ) {
			$warnings[] = __( 'WooCommerce is inactive, so product lookups and price workflows are limited.', 'lilleprinsen-price-monitor' );
		}

		if ( empty( $settings['dry_run_mode'] ) ) {
			$warnings[] = __( 'Dry-run mode is disabled. Real updates still require every separate guardrail and confirmation.', 'lilleprinsen-price-monitor' );
		}

		if ( empty( $settings['disable_all_price_updates'] ) ) {
			$warnings[] = __( 'Emergency price update disable is off.', 'lilleprinsen-price-monitor' );
		}

		if ( $this->real_updates_enabled( $settings ) ) {
			$warnings[] = __( 'Real price updates are possible for individually confirmed suggestions.', 'lilleprinsen-price-monitor' );
		}

		if ( ! empty( $lock_status['locked'] ) ) {
			$warnings[] = __( 'A competitor check batch lock is currently active.', 'lilleprinsen-price-monitor' );
		}

		if ( ! empty( $settings['scheduled_checks_enabled'] ) && (int) ( $settings['max_urls_per_batch'] ?? 0 ) > 50 ) {
			$warnings[] = __( 'Scheduled checks are enabled with a large batch size. Keep production batches small until the store has been observed in dry-run.', 'lilleprinsen-price-monitor' );
		}

		if ( (int) ( $counts['failed_checks_last_24h'] ?? 0 ) > 20 ) {
			$warnings[] = __( 'Many competitor checks failed in the last 24 hours. Review logs before increasing batch volume.', 'lilleprinsen-price-monitor' );
		}

		if ( ! empty( $settings['scheduled_checks_enabled'] ) && $this->last_check_is_stale( (string) ( $counts['last_successful_check_time'] ?? '' ), 6 ) ) {
			$warnings[] = __( 'Scheduled checks are enabled, but no successful observation was recorded in the last 6 hours.', 'lilleprinsen-price-monitor' );
		}

		if ( ! empty( $settings['webhook_notifications_enabled'] ) && empty( $settings['webhook_url'] ) ) {
			$warnings[] = __( 'Webhook notifications are enabled without a webhook URL.', 'lilleprinsen-price-monitor' );
		}

		return $warnings;
	}

	private function last_check_is_stale( string $datetime, int $hours ): bool {
		if ( '' === $datetime ) {
			return true;
		}

		$timestamp = strtotime( $datetime );

		if ( false === $timestamp ) {
			return true;
		}

		return $timestamp < ( current_time( 'timestamp' ) - ( $hours * HOUR_IN_SECONDS ) );
	}

	/**
	 * @param array<string, mixed> $settings Current settings.
	 */
	private function real_updates_enabled( array $settings ): bool {
		return empty( $settings['dry_run_mode'] )
			&& empty( $settings['disable_all_price_updates'] )
			&& ! empty( $settings['allow_real_price_updates'] )
			&& ! empty( $settings['require_manual_approval'] )
			&& ! empty( $settings['require_confirmation_for_real_updates'] );
	}

	/**
	 * @param array<string, mixed> $settings Current settings.
	 */
	private function suggestion_type_allows_real_update( string $suggestion_type, array $settings ): bool {
		$allowed = isset( $settings['real_update_allowed_suggestion_types'] ) && is_array( $settings['real_update_allowed_suggestion_types'] )
			? $settings['real_update_allowed_suggestion_types']
			: array();

		return in_array( $suggestion_type, $allowed, true );
	}

	private function format_price_amount( float $price, string $currency ): string {
		$currency = strtoupper( sanitize_text_field( $currency ) );
		$currency = '' !== $currency ? $currency : 'NOK';

		return number_format_i18n( $price, 2 ) . ' ' . $currency;
	}

	private function format_price_for_input( float $price ): string {
		return number_format( $price, 2, '.', '' );
	}

	private function format_decimal_for_optional_input( $value ): string {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return '';
		}

		return number_format( (float) $value, 2, '.', '' );
	}

	private function format_percent_value( $value ): string {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return '—';
		}

		return number_format_i18n( (float) $value, 2 ) . '%';
	}

	private function get_product_admin_url( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return admin_url( 'edit.php?post_type=product' );
		}

		return admin_url( 'post.php?post=' . absint( $product_id ) . '&action=edit' );
	}

	private function is_valid_http_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& ! empty( $parts['host'] )
			&& ! empty( $parts['scheme'] )
			&& in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	private function redirect_to_competitors( int $monitored_product_id, string $notice, string $type = 'success' ): void {
		$this->redirect_to_tab(
			'competitors',
			$notice,
			array(
				'monitored_product_id' => $monitored_product_id,
				'lpm_notice_type'      => $type,
			)
		);
	}

	private function redirect_to_approvals( string $notice, string $type = 'success', string $view = 'pending' ): void {
		$this->redirect_to_tab(
			'approvals',
			$notice,
			array(
				'lpm_approval_view' => array_key_exists( $view, $this->get_approval_view_options() ) ? $view : 'pending',
				'lpm_notice_type'   => $type,
			)
		);
	}

	public function redirect_to_tab( string $tab, string $notice, array $extra_args = array() ): void {
		$tab = $this->normalize_redirect_tab( $tab );
		$args = array_merge(
			array(
				'page'       => self::SLUG,
				'tab'        => $tab,
				'lpm_notice' => $notice,
			),
			$extra_args
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function normalize_redirect_tab( string $tab ): string {
		$legacy_tabs = array(
			'settings'      => 'settings_logs',
			'logs'          => 'settings_logs',
			'history'       => 'settings_logs',
			'import_export' => 'settings_logs',
			'groups'        => 'settings_logs',
		);

		return $legacy_tabs[ $tab ] ?? $tab;
	}

	private function set_admin_notice( string $message, string $type = 'success' ): void {
		$this->notice_store->set( $message, $type );
	}

	private function get_import_transient_key( string $token ): string {
		return self::IMPORT_TRANSIENT_PREFIX . get_current_user_id() . '_' . sanitize_key( $token );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function get_import_preview( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}

		$preview = get_transient( $this->get_import_transient_key( $token ) );

		return is_array( $preview ) ? $preview : null;
	}

	/**
	 * @return array<int, string>
	 */
	private function get_import_csv_headers(): array {
		return array(
			'product_id',
			'sku',
			'competitor_name',
			'competitor_url',
			'match_type',
			'enabled',
			'priority',
			'strategy',
			'min_margin_percent',
			'min_price',
			'check_frequency_hours',
			'notes',
		);
	}

	/**
	 * @param array<string, mixed> $row Preview row.
	 */
	private function get_import_rule_summary( array $row ): string {
		$parts = array();

		foreach ( array( 'enabled', 'priority', 'strategy', 'min_margin_percent', 'min_price', 'check_frequency_hours' ) as $key ) {
			if ( array_key_exists( $key, $row ) && null !== $row[ $key ] && '' !== $row[ $key ] ) {
				$parts[] = $key . '=' . (string) $row[ $key ];
			}
		}

		return empty( $parts ) ? '—' : $this->shorten_text( implode( ', ', $parts ), 120 );
	}

	/**
	 * @param mixed $messages Messages.
	 */
	private function join_messages( $messages ): string {
		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return '—';
		}

		return $this->shorten_text( implode( ' ', array_map( 'strval', $messages ) ), 160 );
	}

	private function stream_monitored_links_export(): void {
		$headers = array(
			'product_id',
			'sku',
			'product_name',
			'enabled',
			'priority',
			'strategy',
			'min_margin_percent',
			'min_price',
			'check_frequency_hours',
			'competitor_name',
			'competitor_url',
			'match_type',
			'last_price',
			'last_checked_at',
			'last_error',
		);
		$rows = array();

		foreach ( $this->repository->get_monitored_products_export_rows( self::EXPORT_MAX_ROWS ) as $row ) {
			$product = $this->get_product( (int) $row['product_id'] );
			$rows[] = array(
				(int) $row['product_id'],
				(string) ( $row['sku'] ?? '' ),
				$product ? $this->get_product_name( $product ) : '',
				(int) ( $row['enabled'] ?? 0 ),
				(string) ( $row['priority'] ?? '' ),
				(string) ( $row['strategy'] ?? '' ),
				(string) ( $row['min_margin_percent'] ?? '' ),
				(string) ( $row['min_price'] ?? '' ),
				(int) ( $row['check_frequency_hours'] ?? 0 ),
				(string) ( $row['competitor_name'] ?? '' ),
				(string) ( $row['competitor_url'] ?? '' ),
				(string) ( $row['match_type'] ?? '' ),
				(string) ( $row['last_price'] ?? '' ),
				(string) ( $row['last_checked_at'] ?? '' ),
				(string) ( $row['last_error'] ?? '' ),
			);
		}

		$this->stream_csv( 'lpm-monitored-products-links.csv', $headers, $rows );
	}

	private function stream_pending_suggestions_export(): void {
		$headers     = array( 'id', 'product_id', 'competitor_name', 'suggestion_type', 'status', 'current_price', 'competitor_price', 'suggested_price', 'difference', 'margin_after_change', 'reason', 'warnings', 'rule_details', 'created_at' );
		$suggestions = $this->repository->get_price_suggestions( array( 'view' => 'pending' ), 1, self::EXPORT_MAX_ROWS );
		$rows        = array();

		foreach ( $suggestions as $suggestion ) {
			$rows[] = array(
				(int) $suggestion['id'],
				(int) $suggestion['product_id'],
				(string) ( $suggestion['competitor_name'] ?? '' ),
				(string) ( $suggestion['suggestion_type'] ?? '' ),
				(string) ( $suggestion['status'] ?? '' ),
				(string) ( $suggestion['current_price'] ?? '' ),
				(string) ( $suggestion['competitor_price'] ?? '' ),
				(string) ( $suggestion['suggested_price'] ?? '' ),
				(string) ( $suggestion['difference'] ?? '' ),
				(string) ( $suggestion['margin_after_change'] ?? '' ),
				(string) ( $suggestion['reason'] ?? '' ),
				(string) ( $suggestion['warnings'] ?? '' ),
				(string) ( $suggestion['rule_details'] ?? '' ),
				(string) ( $suggestion['created_at'] ?? '' ),
			);
		}

		$this->stream_csv( 'lpm-pending-suggestions.csv', $headers, $rows );
	}

	private function stream_failed_checks_export(): void {
		$headers = array( 'id', 'created_at', 'level', 'event', 'product_id', 'message', 'context' );
		$logs    = $this->repository->get_logs( array( 'level' => 'error', 'event' => 'competitor_check_failed' ), 1, self::EXPORT_MAX_ROWS );
		$rows    = array();

		foreach ( $logs as $log ) {
			$rows[] = array(
				(int) $log['id'],
				(string) ( $log['created_at'] ?? '' ),
				(string) ( $log['level'] ?? '' ),
				(string) ( $log['event'] ?? '' ),
				(string) ( $log['product_id'] ?? '' ),
				(string) ( $log['message'] ?? '' ),
				(string) ( $log['context'] ?? '' ),
			);
		}

		$this->stream_csv( 'lpm-recent-failed-checks.csv', $headers, $rows );
	}

	private function stream_price_observations_export(): void {
		$headers      = array( 'id', 'checked_at', 'product_id', 'monitored_product_id', 'competitor_link_id', 'competitor_name', 'observed_price', 'currency', 'extraction_method', 'http_status', 'success', 'error_message', 'response_time_ms' );
		$observations = $this->repository->get_price_observations( array(), 1, self::EXPORT_MAX_ROWS );
		$rows         = array();

		foreach ( $observations as $observation ) {
			$rows[] = array(
				(int) $observation['id'],
				(string) ( $observation['checked_at'] ?? '' ),
				(int) ( $observation['product_id'] ?? 0 ),
				(int) ( $observation['monitored_product_id'] ?? 0 ),
				(int) ( $observation['competitor_link_id'] ?? 0 ),
				(string) ( $observation['competitor_name'] ?? '' ),
				(string) ( $observation['observed_price'] ?? '' ),
				(string) ( $observation['currency'] ?? '' ),
				(string) ( $observation['extraction_method'] ?? '' ),
				(string) ( $observation['http_status'] ?? '' ),
				(int) ( $observation['success'] ?? 0 ),
				(string) ( $observation['error_message'] ?? '' ),
				(string) ( $observation['response_time_ms'] ?? '' ),
			);
		}

		$this->stream_csv( 'lpm-price-observations.csv', $headers, $rows );
	}

	/**
	 * @param array<int, string> $headers CSV headers.
	 * @param array<int, array<int, mixed>> $rows CSV rows.
	 */
	private function stream_csv( string $filename, array $headers, array $rows ): void {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

		$output = fopen( 'php://output', 'w' );

		if ( false !== $output ) {
			fputcsv( $output, $headers );

			foreach ( $rows as $row ) {
				fputcsv( $output, $row );
			}

			fclose( $output );
		}

		exit;
	}

	private function get_notice_message( string $notice ): string {
		$messages = array(
			'unknown_action'                  => __( 'Unknown action.', 'lilleprinsen-price-monitor' ),
			'competitor_prices_moved'         => __( 'Competitor discovery now lives inside Price Monitor.', 'lilleprinsen-price-monitor' ),
			'product_not_found'               => __( 'Product was not found or WooCommerce is inactive.', 'lilleprinsen-price-monitor' ),
			'monitoring_added'                => __( 'Product added to monitoring.', 'lilleprinsen-price-monitor' ),
			'already_monitored'               => __( 'Product is already monitored.', 'lilleprinsen-price-monitor' ),
			'monitoring_reenabled'            => __( 'Product monitoring was re-enabled.', 'lilleprinsen-price-monitor' ),
			'monitoring_add_failed'           => __( 'Could not add product to monitoring.', 'lilleprinsen-price-monitor' ),
			'monitored_not_found'             => __( 'Monitored product was not found.', 'lilleprinsen-price-monitor' ),
			'monitoring_status_updated'       => __( 'Monitoring status updated.', 'lilleprinsen-price-monitor' ),
			'monitoring_status_failed'        => __( 'Could not update monitoring status.', 'lilleprinsen-price-monitor' ),
			'monitored_rules_updated'         => __( 'Product monitoring rules updated.', 'lilleprinsen-price-monitor' ),
			'monitored_rules_update_failed'   => __( 'Could not update product monitoring rules.', 'lilleprinsen-price-monitor' ),
			'monitored_rules_invalid'         => __( 'Product monitoring rule values are invalid.', 'lilleprinsen-price-monitor' ),
			'bulk_no_selection'               => __( 'Select at least one row before applying a bulk action.', 'lilleprinsen-price-monitor' ),
			'bulk_action_invalid'             => __( 'Bulk action is invalid.', 'lilleprinsen-price-monitor' ),
			'bulk_action_completed'           => __( 'Bulk action completed.', 'lilleprinsen-price-monitor' ),
			'product_group_name_required'     => __( 'Product group name is required.', 'lilleprinsen-price-monitor' ),
			'product_group_created'           => __( 'Product group created.', 'lilleprinsen-price-monitor' ),
			'product_group_create_failed'     => __( 'Could not create product group.', 'lilleprinsen-price-monitor' ),
			'product_group_updated'           => __( 'Product group updated.', 'lilleprinsen-price-monitor' ),
			'product_group_update_failed'     => __( 'Could not update product group.', 'lilleprinsen-price-monitor' ),
			'product_group_not_found'         => __( 'Product group was not found.', 'lilleprinsen-price-monitor' ),
			'product_group_member_added'      => __( 'Product added to group.', 'lilleprinsen-price-monitor' ),
			'product_group_member_add_failed' => __( 'Could not add product to group. It may already belong to another active group.', 'lilleprinsen-price-monitor' ),
			'product_group_member_not_found'  => __( 'Product group member was not found.', 'lilleprinsen-price-monitor' ),
			'product_group_member_updated'    => __( 'Product group member updated.', 'lilleprinsen-price-monitor' ),
			'product_group_member_update_failed' => __( 'Could not update product group member.', 'lilleprinsen-price-monitor' ),
			'csv_import_preview_failed'       => __( 'Could not preview CSV import.', 'lilleprinsen-price-monitor' ),
			'csv_import_preview_ready'        => __( 'CSV import preview is ready.', 'lilleprinsen-price-monitor' ),
			'csv_import_preview_missing'      => __( 'CSV import preview expired or was not found.', 'lilleprinsen-price-monitor' ),
			'csv_import_confirmed'            => __( 'CSV import confirmed.', 'lilleprinsen-price-monitor' ),
			'export_type_invalid'             => __( 'Export type is invalid.', 'lilleprinsen-price-monitor' ),
			'competitor_profile_name_required' => __( 'Competitor profile name is required.', 'lilleprinsen-price-monitor' ),
			'competitor_profile_added'        => __( 'Competitor profile added.', 'lilleprinsen-price-monitor' ),
			'competitor_profile_add_failed'   => __( 'Could not add competitor profile.', 'lilleprinsen-price-monitor' ),
			'competitor_profile_updated'      => __( 'Competitor profile updated.', 'lilleprinsen-price-monitor' ),
			'competitor_profile_update_failed' => __( 'Could not update competitor profile.', 'lilleprinsen-price-monitor' ),
			'competitor_profile_not_found'    => __( 'Competitor profile was not found.', 'lilleprinsen-price-monitor' ),
			'competitor_profile_status_updated' => __( 'Competitor profile status updated.', 'lilleprinsen-price-monitor' ),
			'competitor_profile_status_failed' => __( 'Could not update competitor profile status.', 'lilleprinsen-price-monitor' ),
			'competitor_profile_test_url_invalid' => __( 'Profile test URL must be a valid http or https URL.', 'lilleprinsen-price-monitor' ),
			'competitor_profile_test_succeeded' => __( 'Competitor profile test completed.', 'lilleprinsen-price-monitor' ),
			'competitor_profile_test_failed'  => __( 'Competitor profile test failed.', 'lilleprinsen-price-monitor' ),
			'competitor_name_required'        => __( 'Competitor name is required.', 'lilleprinsen-price-monitor' ),
			'competitor_url_invalid'          => __( 'Competitor URL must be a valid http or https URL.', 'lilleprinsen-price-monitor' ),
			'competitor_link_added'           => __( 'Competitor link added.', 'lilleprinsen-price-monitor' ),
			'competitor_link_add_failed'      => __( 'Could not add competitor link.', 'lilleprinsen-price-monitor' ),
			'competitor_link_updated'         => __( 'Competitor link updated.', 'lilleprinsen-price-monitor' ),
			'competitor_link_update_failed'   => __( 'Could not update competitor link.', 'lilleprinsen-price-monitor' ),
			'competitor_link_not_found'       => __( 'Competitor link was not found.', 'lilleprinsen-price-monitor' ),
			'competitor_link_deleted'         => __( 'Competitor link deleted.', 'lilleprinsen-price-monitor' ),
			'competitor_link_delete_failed'   => __( 'Could not delete competitor link.', 'lilleprinsen-price-monitor' ),
			'competitor_link_status_updated'  => __( 'Competitor link status updated.', 'lilleprinsen-price-monitor' ),
			'competitor_link_status_failed'   => __( 'Could not update competitor link status.', 'lilleprinsen-price-monitor' ),
			'competitor_check_succeeded'      => __( 'Manual competitor check completed.', 'lilleprinsen-price-monitor' ),
			'competitor_check_failed'         => __( 'Manual competitor check failed.', 'lilleprinsen-price-monitor' ),
			'price_suggestion_created'        => __( 'Dry-run price suggestion created.', 'lilleprinsen-price-monitor' ),
			'price_suggestion_skipped'        => __( 'No suggestion was created.', 'lilleprinsen-price-monitor' ),
			'price_suggestion_failed'         => __( 'Could not create a price suggestion.', 'lilleprinsen-price-monitor' ),
			'suggestion_not_found'            => __( 'Suggestion was not found.', 'lilleprinsen-price-monitor' ),
			'suggestion_approved_dry_run'     => __( 'Dry-run approval recorded. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ),
			'suggestion_approval_failed'      => __( 'Could not approve suggestion.', 'lilleprinsen-price-monitor' ),
			'suggestion_rejected'             => __( 'Suggestion rejected.', 'lilleprinsen-price-monitor' ),
			'suggestion_reject_failed'        => __( 'Could not reject suggestion.', 'lilleprinsen-price-monitor' ),
			'suggested_price_invalid'         => __( 'Suggested price must be a positive number.', 'lilleprinsen-price-monitor' ),
			'suggested_price_updated'         => __( 'Suggested price updated.', 'lilleprinsen-price-monitor' ),
			'suggested_price_update_failed'   => __( 'Could not update suggested price.', 'lilleprinsen-price-monitor' ),
			'small_batch_completed'           => __( 'Small competitor check batch completed.', 'lilleprinsen-price-monitor' ),
			'small_batch_locked'              => __( 'Small check batch skipped because another batch is running.', 'lilleprinsen-price-monitor' ),
			'test_notification_sent'          => __( 'Test notification logged. WhatsApp is not connected yet.', 'lilleprinsen-price-monitor' ),
			'test_webhook_sent'               => __( 'Test webhook sent.', 'lilleprinsen-price-monitor' ),
			'test_webhook_failed'             => __( 'Test webhook failed. Check the webhook URL and logs.', 'lilleprinsen-price-monitor' ),
			'retention_cleanup_completed'     => __( 'Retention cleanup completed.', 'lilleprinsen-price-monitor' ),
			'price_match_session_not_found'   => __( 'Price match session was not found.', 'lilleprinsen-price-monitor' ),
			'price_match_session_ended'       => __( 'Dry-run price match session ended. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ),
			'price_match_session_end_failed'  => __( 'Could not end the price match session.', 'lilleprinsen-price-monitor' ),
			'price_match_session_end_requires_real_update' => __( 'Only dry-run sessions can be ended here. Real sessions must use a guarded restore/update flow.', 'lilleprinsen-price-monitor' ),
			'real_update_confirmation_required' => __( 'Real price update confirmation is required.', 'lilleprinsen-price-monitor' ),
			'real_price_update_failed'        => __( 'Real price update failed.', 'lilleprinsen-price-monitor' ),
			'real_price_update_applied'       => __( 'WooCommerce price updated after explicit approval.', 'lilleprinsen-price-monitor' ),
		);

		return $messages[ $notice ] ?? '';
	}

	private function format_datetime( $value ): string {
		if ( empty( $value ) ) {
			return '—';
		}

		$timestamp = strtotime( (string) $value );

		if ( false === $timestamp ) {
			return '—';
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	private function format_nullable_value( $value ): string {
		if ( null === $value || '' === $value ) {
			return '—';
		}

		return (string) $value;
	}

	private function shorten_text( string $text, int $length ): string {
		$text = trim( $text );

		if ( '' === $text ) {
			return '—';
		}

		if ( strlen( $text ) <= $length ) {
			return $text;
		}

		return substr( $text, 0, max( 0, $length - 3 ) ) . '...';
	}
}
