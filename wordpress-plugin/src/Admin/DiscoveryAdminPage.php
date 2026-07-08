<?php
/**
 * Admin page for Competitor Price Assistant discovery.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

use Lilleprinsen\PriceMonitor\Database\DiscoveryRepository;
use Lilleprinsen\PriceMonitor\Database\DiscoverySchema;
use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Jobs\CompetitorDiscoveryJob;
use Lilleprinsen\PriceMonitor\Service\CompetitorProductExtractor;
use Lilleprinsen\PriceMonitor\Service\CompetitorPlatformDetector;
use Lilleprinsen\PriceMonitor\Service\DiscoveryUrlService;
use Lilleprinsen\PriceMonitor\Service\MatchSuggestionService;
use Lilleprinsen\PriceMonitor\Service\ProductIdentifierService;
use Lilleprinsen\PriceMonitor\Service\SkuSearchDiscoveryService;
use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple admin workflow for selected-product competitor discovery.
 */
class DiscoveryAdminPage {
	private Repository $repository;
	private DiscoveryRepository $discovery_repository;
	private DiscoverySettings $settings;
	private ProductIdentifierService $identifiers;
	private CompetitorProductExtractor $extractor;
	private MatchSuggestionService $matcher;
	private CompetitorDiscoveryJob $job;
	private DiscoveryUrlService $url_service;
	private SkuSearchDiscoveryService $sku_search;
	private string $notice = '';
	private string $notice_type = 'success';
	/** @var array<string,mixed>|null */
	private ?array $last_test = null;
	/** @var array<string,mixed>|null */
	private ?array $last_search_test = null;

	/** Constructor. */
	public function __construct(
		Repository $repository,
		DiscoveryRepository $discovery_repository,
		DiscoverySettings $settings,
		ProductIdentifierService $identifiers,
		CompetitorProductExtractor $extractor,
		MatchSuggestionService $matcher,
		CompetitorDiscoveryJob $job,
		DiscoveryUrlService $url_service,
		SkuSearchDiscoveryService $sku_search
	) {
		$this->repository           = $repository;
		$this->discovery_repository = $discovery_repository;
		$this->settings             = $settings;
		$this->identifiers          = $identifiers;
		$this->extractor            = $extractor;
		$this->matcher              = $matcher;
		$this->job                  = $job;
		$this->url_service          = $url_service;
		$this->sku_search           = $sku_search;
	}

	/** Register submenu. */
	public function register_menu(): void {
		add_submenu_page( null, __( 'Competitor Price Assistant', 'lilleprinsen-price-monitor' ), __( 'Competitor Prices', 'lilleprinsen-price-monitor' ), 'manage_woocommerce', 'lpm-competitor-prices', array( $this, 'render' ) );
	}

	/** Handle form actions. */
	public function handle_actions(): void {
		if ( empty( $_POST['lpm_discovery_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage competitor discovery.', 'lilleprinsen-price-monitor' ) );
		}

		check_admin_referer( 'lpm_discovery_action', 'lpm_discovery_nonce' );
		DiscoverySchema::maybe_upgrade();
		$action = sanitize_key( wp_unslash( $_POST['lpm_discovery_action'] ) );

		switch ( $action ) {
			case 'save_settings':
				$this->settings->update( wp_unslash( $_POST ) );
				if ( empty( $_POST['discovery_enabled'] ) ) {
					$this->job->unschedule();
				}
				$this->set_notice( __( 'Discovery settings saved.', 'lilleprinsen-price-monitor' ) );
				break;
			case 'test_gtin_source':
				$this->handle_test_gtin_source();
				break;
			case 'add_products_by_sku':
				$this->handle_add_products_by_sku();
				break;
			case 'remove_product':
				$this->handle_remove_product();
				break;
			case 'add_seed_url':
				$this->handle_add_seed_url();
				break;
			case 'run_small_discovery':
				$this->handle_run_small_discovery();
				break;
			case 'test_product_page':
				$this->handle_test_product_page();
				break;
			case 'save_test_price_rule':
				$this->handle_save_test_price_rule();
				break;
			case 'save_competitor_search_template':
				$this->handle_save_competitor_search_template();
				break;
			case 'test_search_template':
				$this->handle_test_search_template();
				break;
			case 'retest_suggestion':
				$this->handle_retest_suggestion();
				break;
			case 'approve_suggestion':
				$this->handle_approve_suggestion();
				break;
			case 'reject_suggestion':
				$this->handle_reject_suggestion();
				break;
		}
	}

	/** Render page. */
	public function render(): void {
		DiscoverySchema::maybe_upgrade();
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'overview';
		$redirect_tab = $this->legacy_view_to_unified_tab( $view );

		if ( ! headers_sent() ) {
			$args = array(
				'page'       => AdminPage::SLUG,
				'tab'        => $redirect_tab,
				'lpm_notice' => 'competitor_prices_moved',
			);
			foreach ( array( 'paged', 's', 'competitor_id', 'discovery_product_id' ) as $key ) {
				if ( isset( $_GET[ $key ] ) ) {
					$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
				}
			}
			wp_safe_redirect(
				add_query_arg(
					$args,
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
		?>
		<div class="wrap">
			<div class="lpm-discovery-hero">
				<div>
					<p class="lpm-drawer-kicker"><?php esc_html_e( 'Approval-first competitor discovery', 'lilleprinsen-price-monitor' ); ?></p>
					<h1><?php esc_html_e( 'Competitor Price Assistant', 'lilleprinsen-price-monitor' ); ?></h1>
					<p><?php esc_html_e( 'Find competitor product pages for selected WooCommerce products only. Matches must be reviewed and approved before monitoring starts.', 'lilleprinsen-price-monitor' ); ?></p>
				</div>
				<div class="lpm-discovery-hero-actions">
					<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'lpm-competitor-prices', 'view' => 'products' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Select products', 'lilleprinsen-price-monitor' ); ?></a>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'lpm-competitor-prices', 'view' => 'competitors' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Set up competitors', 'lilleprinsen-price-monitor' ); ?></a>
				</div>
			</div>
			<?php $this->render_notice(); ?>
			<?php $this->render_tabs( $view ); ?>
			<?php
			switch ( $view ) {
				case 'products':
					$this->render_products();
					break;
				case 'competitors':
					$this->render_competitors();
					break;
				case 'suggestions':
					$this->render_suggestions();
					break;
				case 'settings':
					$this->render_settings();
					break;
				default:
					$this->render_overview();
					break;
			}
			?>
		</div>
		<?php
	}

	/** Render one discovery section inside the unified Price Monitor shell. */
	public function render_embedded( string $view ): void {
		DiscoverySchema::maybe_upgrade();
		$this->render_notice();
		switch ( $view ) {
			case 'products':
				$this->render_products();
				break;
			case 'competitors':
				$this->render_competitors();
				break;
			case 'suggestions':
				$this->render_suggestions();
				break;
			case 'settings':
				$this->render_settings();
				break;
			default:
				$this->render_overview();
				break;
		}
	}

	private function legacy_view_to_unified_tab( string $view ): string {
		if ( 'products' === $view ) {
			return 'products';
		}
		if ( 'competitors' === $view ) {
			return 'competitors';
		}
		if ( 'suggestions' === $view ) {
			return 'approvals';
		}

		return 'dashboard';
	}

	/** Render tabs. */
	private function render_tabs( string $current ): void {
		$tabs = array(
			'overview'    => __( 'Overview', 'lilleprinsen-price-monitor' ),
			'products'    => __( 'Products to Monitor', 'lilleprinsen-price-monitor' ),
			'competitors' => __( 'Find Matches', 'lilleprinsen-price-monitor' ),
			'suggestions' => __( 'Suggested Matches', 'lilleprinsen-price-monitor' ),
			'settings'    => __( 'Advanced Settings', 'lilleprinsen-price-monitor' ),
		);
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$url = add_query_arg( array( 'page' => 'lpm-competitor-prices', 'view' => $key ), admin_url( 'admin.php' ) );
			printf( '<a class="nav-tab %s" href="%s">%s</a>', esc_attr( $current === $key ? 'nav-tab-active' : '' ), esc_url( $url ), esc_html( $label ) );
		}
		echo '</h2>';
	}

	/** Overview. */
	private function render_overview(): void {
		$quality     = $this->discovery_repository->get_identifier_quality_counts();
		$pending     = $this->discovery_repository->count_suggestions( 'pending' );
		$competitors = $this->repository->get_competitors( 1, 200 );
		$health      = $this->health_by_competitor();
		$settings    = array_merge( ( new Settings() )->get_all(), $this->settings->get_all() );
		$links       = $this->repository->get_active_competitor_links_status( 25 );
		$active_competitors = array_values( array_filter( $competitors, static fn( $competitor ) => ! empty( $competitor['enabled'] ) ) );
		?>
		<div class="lpm-discovery-card lpm-production-summary">
			<div>
				<h2><?php esc_html_e( 'Competitor discovery', 'lilleprinsen-price-monitor' ); ?></h2>
				<p><?php esc_html_e( 'Select products, add competitors, run discovery, then approve matches before monitoring starts.', 'lilleprinsen-price-monitor' ); ?></p>
			</div>
			<p class="lpm-discovery-hero-actions">
				<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'lpm-competitor-prices', 'view' => 'products' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Select products', 'lilleprinsen-price-monitor' ); ?></a>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'lpm-competitor-prices', 'view' => 'competitors' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Set up competitors', 'lilleprinsen-price-monitor' ); ?></a>
			</p>
		</div>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:16px;">
			<?php $this->metric( __( 'Products selected', 'lilleprinsen-price-monitor' ), $quality['selected'] ); ?>
			<?php $this->metric( __( 'With SKU', 'lilleprinsen-price-monitor' ), $quality['with_sku'] ); ?>
			<?php $this->metric( __( 'With EAN/GTIN', 'lilleprinsen-price-monitor' ), $quality['with_gtin'] ); ?>
			<?php $this->metric( __( 'Pending suggested matches', 'lilleprinsen-price-monitor' ), $pending ); ?>
		</div>
		<?php $this->render_admin_test_status_panel( $quality['selected'], $active_competitors, $settings ); ?>
		<h2><?php esc_html_e( 'Production status', 'lilleprinsen-price-monitor' ); ?></h2>
		<div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 16px;">
			<?php $this->status_badge( __( 'Scheduled checks', 'lilleprinsen-price-monitor' ), ! empty( $settings['scheduled_checks_enabled'] ) ); ?>
			<?php $this->status_badge( __( 'Scheduled suggestions', 'lilleprinsen-price-monitor' ), ! empty( $settings['create_suggestions_from_scheduled_checks'] ) ); ?>
			<?php $this->status_badge( __( 'Dry-run mode', 'lilleprinsen-price-monitor' ), ! empty( $settings['dry_run_mode'] ) ); ?>
			<?php $this->status_badge( __( 'Real updates blocked', 'lilleprinsen-price-monitor' ), empty( $settings['allow_real_price_updates'] ) || ! empty( $settings['disable_all_price_updates'] ) ); ?>
			<?php $this->status_badge( __( 'Discovery schedule', 'lilleprinsen-price-monitor' ), ! empty( $settings['discovery_enabled'] ) ); ?>
		</div>
		<?php if ( $quality['duplicates'] > 0 ) : ?>
			<div class="notice notice-warning inline"><p><?php printf( esc_html__( '%d duplicate identifiers were found among selected products. Review these before approving low-confidence suggestions.', 'lilleprinsen-price-monitor' ), absint( $quality['duplicates'] ) ); ?></p></div>
		<?php endif; ?>
		<h2><?php esc_html_e( 'Health Status', 'lilleprinsen-price-monitor' ); ?></h2>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Status', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Last run', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Successful checks', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Failed checks', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Pending suggestions', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Approved monitored links', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Last issue', 'lilleprinsen-price-monitor' ); ?></th></tr></thead><tbody>
		<?php foreach ( $competitors as $competitor ) : $row = $health[ (int) $competitor['id'] ] ?? null; ?>
			<tr><td><?php echo esc_html( $competitor['name'] ?? '' ); ?></td><td><?php echo esc_html( $this->health_label( $row ? (string) $row->status : ( empty( $competitor['enabled'] ) ? 'paused' : 'no_recent_success' ) ) ); ?></td><td><?php echo esc_html( $row && $row->last_run_at ? (string) $row->last_run_at : __( 'Not run yet', 'lilleprinsen-price-monitor' ) ); ?></td><td><?php echo esc_html( $row ? (string) $row->success_count : '0' ); ?></td><td><?php echo esc_html( $row ? (string) $row->failure_count : '0' ); ?></td><td><?php echo esc_html( $row ? (string) $row->pending_suggestions : '0' ); ?></td><td><?php echo esc_html( (string) ( $competitor['link_count'] ?? 0 ) ); ?></td><td><?php echo esc_html( $row ? (string) $row->last_error : '' ); ?></td></tr>
		<?php endforeach; ?>
		<?php if ( empty( $competitors ) ) : ?><tr><td colspan="8"><?php esc_html_e( 'Add a competitor and test one product page to get started.', 'lilleprinsen-price-monitor' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<h2><?php esc_html_e( 'Active monitored competitor links', 'lilleprinsen-price-monitor' ); ?></h2>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Product', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Last checked', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Last price', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Last error', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Next check', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Status', 'lilleprinsen-price-monitor' ); ?></th></tr></thead><tbody>
		<?php foreach ( $links as $link ) : ?>
			<tr><td><a href="<?php echo esc_url( get_edit_post_link( (int) $link['product_id'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $link['product_id'] ) ); ?></a><br><small><?php echo esc_html( (string) ( $link['sku'] ?? '' ) ); ?></small></td><td><?php echo esc_html( (string) ( $link['competitor_profile_name'] ?? $link['competitor_name'] ?? '' ) ); ?><br><a href="<?php echo esc_url( (string) $link['competitor_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open page', 'lilleprinsen-price-monitor' ); ?></a></td><td><?php echo esc_html( (string) ( $link['last_checked_at'] ?: __( 'Not checked yet', 'lilleprinsen-price-monitor' ) ) ); ?></td><td><?php echo esc_html( isset( $link['last_price'] ) && '' !== (string) $link['last_price'] ? wc_format_decimal( $link['last_price'], 2 ) . ' ' . (string) $link['last_currency'] : '' ); ?></td><td><?php echo esc_html( (string) ( $link['last_error'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $link['next_check_after'] ?: __( 'When due', 'lilleprinsen-price-monitor' ) ) ); ?></td><td><?php echo ! empty( $link['last_error'] ) ? '<span style="color:#b32d2e;">' . esc_html__( 'Needs attention', 'lilleprinsen-price-monitor' ) . '</span>' : '<span style="color:#008a20;">' . esc_html__( 'Active', 'lilleprinsen-price-monitor' ) . '</span>'; ?><?php if ( ! empty( $link['competitor_requires_javascript'] ) ) : ?><br><small><?php esc_html_e( 'JavaScript required: internal checker may fail.', 'lilleprinsen-price-monitor' ); ?></small><?php endif; ?></td></tr>
		<?php endforeach; ?>
		<?php if ( empty( $links ) ) : ?><tr><td colspan="7"><?php esc_html_e( 'Approved suggestions will appear here as active monitored competitor links.', 'lilleprinsen-price-monitor' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<?php
	}

	/** Products tab. */
	private function render_products(): void {
		$page     = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$products = $this->discovery_repository->get_selected_products( $page, 50, $search );
		$total    = $this->discovery_repository->count_selected_products( $search );
		$counts   = $this->discovery_repository->get_pending_suggestion_counts_by_discovery_product_ids( wp_list_pluck( $products, 'id' ) );
		?>
		<h2><?php esc_html_e( 'Products to Monitor', 'lilleprinsen-price-monitor' ); ?></h2>
		<?php $this->render_step_guidance( 1 ); ?>
		<?php $this->render_manual_discovery_panel(); ?>
		<div class="postbox lpm-discovery-card" style="margin:16px 0;">
			<div class="inside">
				<div class="lpm-discovery-card-header">
					<div>
							<h3><?php esc_html_e( 'Add products', 'lilleprinsen-price-monitor' ); ?></h3>
							<p><?php esc_html_e( 'Search by product name, SKU, or product ID. Discovery only uses products added here.', 'lilleprinsen-price-monitor' ); ?></p>
					</div>
					<span class="lpm-pill lpm-pill-muted"><?php esc_html_e( 'Safe for large stores', 'lilleprinsen-price-monitor' ); ?></span>
				</div>
				<form method="get" class="lpm-discovery-product-search" data-lpm-discovery-product-search-form>
					<label class="screen-reader-text" for="lpm_discovery_product_search"><?php esc_html_e( 'Search WooCommerce products', 'lilleprinsen-price-monitor' ); ?></label>
					<input id="lpm_discovery_product_search" type="search" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Type product name, SKU, or ID', 'lilleprinsen-price-monitor' ); ?>" data-lpm-discovery-product-search-input />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Search products', 'lilleprinsen-price-monitor' ); ?></button>
				</form>
				<p class="description" data-lpm-discovery-product-search-status><?php esc_html_e( 'Search starts after 3 characters, or immediately for a numeric product ID.', 'lilleprinsen-price-monitor' ); ?></p>
				<div data-lpm-discovery-product-search-results></div>
			</div>
		</div>
		<details class="lpm-advanced-panel">
			<summary><?php esc_html_e( 'Bulk and diagnostic tools', 'lilleprinsen-price-monitor' ); ?></summary>
			<form method="post" style="margin:12px 0;">
				<?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?>
				<input type="hidden" name="lpm_discovery_action" value="add_products_by_sku" />
				<label for="lpm_sku_list"><strong><?php esc_html_e( 'Bulk add by SKU, product ID, or variation ID', 'lilleprinsen-price-monitor' ); ?></strong></label>
				<textarea id="lpm_sku_list" name="sku_list" class="large-text" rows="3" placeholder="SKU-1&#10;12345&#10;VAR-SKU"></textarea>
				<p><button class="button"><?php esc_html_e( 'Add products', 'lilleprinsen-price-monitor' ); ?></button></p>
			</form>
			<form method="post" style="margin:12px 0;">
				<?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?>
				<input type="hidden" name="lpm_discovery_action" value="test_gtin_source" />
				<button class="button"><?php esc_html_e( 'Test EAN/GTIN source', 'lilleprinsen-price-monitor' ); ?></button>
			</form>
		</details>
		<form method="get" style="margin:12px 0;"><input type="hidden" name="page" value="lpm-competitor-prices" /><input type="hidden" name="view" value="products" /><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search selected products', 'lilleprinsen-price-monitor' ); ?>" /> <button class="button"><?php esc_html_e( 'Search', 'lilleprinsen-price-monitor' ); ?></button></form>
		<?php if ( 0 === $total ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'No products are selected yet. Search for products above or paste SKUs below before running discovery; the assistant will never scan the full WooCommerce catalog.', 'lilleprinsen-price-monitor' ); ?> <a class="button button-primary" href="#lpm_discovery_product_search"><?php esc_html_e( 'Search products', 'lilleprinsen-price-monitor' ); ?></a></p></div>
		<?php endif; ?>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Product', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Identifiers', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Discovery', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th></tr></thead><tbody>
		<?php foreach ( $products as $product ) : $edit_id = (int) ( $product->variation_id ?: $product->product_id ); ?>
			<tr><td><a href="<?php echo esc_url( get_edit_post_link( $edit_id ) ); ?>"><?php echo esc_html( get_the_title( $edit_id ) ); ?></a></td><td><?php printf( esc_html__( 'SKU: %s', 'lilleprinsen-price-monitor' ), esc_html( (string) $product->sku ) ); ?><br><small><?php printf( esc_html__( 'EAN: %1$s · Brand: %2$s', 'lilleprinsen-price-monitor' ), esc_html( (string) $product->gtin ), esc_html( (string) $product->brand ) ); ?></small></td><td><?php printf( esc_html__( '%d pending', 'lilleprinsen-price-monitor' ), absint( $counts[ (int) $product->id ] ?? 0 ) ); ?><br><small><?php echo esc_html( (string) ( $product->last_discovery_at ?: __( 'Not run yet', 'lilleprinsen-price-monitor' ) ) ); ?></small></td><td><button type="button" class="button button-primary" data-lpm-start-product="<?php echo esc_attr( (string) $product->id ); ?>"><?php esc_html_e( 'Find matches now', 'lilleprinsen-price-monitor' ); ?></button> <form method="post" style="display:inline-block;margin-left:6px;"><?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?><input type="hidden" name="lpm_discovery_action" value="remove_product" /><input type="hidden" name="discovery_product_id" value="<?php echo esc_attr( (string) $product->id ); ?>" /><button class="button-link-delete"><?php esc_html_e( 'Remove', 'lilleprinsen-price-monitor' ); ?></button></form></td></tr>
		<?php endforeach; ?>
		<?php if ( empty( $products ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No products selected yet.', 'lilleprinsen-price-monitor' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<?php $this->pagination( $total, $page, 'products', $search ); ?>
		<?php
	}

	/** Competitors/test tab. */
	private function render_competitors(): void {
		$competitors = $this->repository->get_competitors( 1, 200 );
		?>
		<h2><?php esc_html_e( 'Find Matches', 'lilleprinsen-price-monitor' ); ?></h2>
		<?php $this->render_step_guidance( 3 ); ?>
		<?php $this->render_manual_discovery_panel(); ?>
		<?php if ( empty( $competitors ) ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'No competitors exist yet. Add a competitor profile below, then test one product page before running discovery.', 'lilleprinsen-price-monitor' ); ?> <a class="button button-primary" href="#competitor_name"><?php esc_html_e( 'Add competitor', 'lilleprinsen-price-monitor' ); ?></a></p></div>
		<?php endif; ?>
		<form method="post" class="lpm-discovery-card lpm-production-form" style="max-width:900px;">
			<?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?><input type="hidden" name="lpm_discovery_action" value="test_product_page" />
			<table class="form-table" role="presentation"><tr><th><label for="competitor_id"><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></label></th><td><?php $this->competitor_select( 'competitor_id', $competitors ); ?></td></tr><tr><th><label for="competitor_name"><?php esc_html_e( 'Competitor name', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="competitor_name" name="competitor_name" type="text" class="regular-text" /><p class="description"><?php esc_html_e( 'Only needed when creating a new competitor.', 'lilleprinsen-price-monitor' ); ?></p></td></tr><tr><th><label for="competitor_domain"><?php esc_html_e( 'Website/domain', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="competitor_domain" name="competitor_domain" type="text" class="regular-text" placeholder="example.no" /></td></tr><tr><th><?php esc_html_e( 'Platform setup', 'lilleprinsen-price-monitor' ); ?></th><td><select name="competitor_platform"><?php foreach ( CompetitorPlatformDetector::platform_options() as $value => $option ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( (string) $option['label'] ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Choose Auto-detect unless you know the platform. The profile will save recommended search templates for WooCommerce, Magento, Shopify, Algolia, Voyado, or custom search.', 'lilleprinsen-price-monitor' ); ?></p><input name="competitor_platform_search_url" type="url" class="large-text" placeholder="https://competitor.no/search?q=10201031" /><p class="description"><?php esc_html_e( 'Optional: paste one search-results URL to make the template more accurate.', 'lilleprinsen-price-monitor' ); ?></p></td></tr><tr><th><label for="product_url"><?php esc_html_e( 'Example product URL', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="product_url" name="product_url" type="url" class="large-text" required /><p class="description"><?php esc_html_e( 'Use a normal product page from the competitor. The checker reads server-rendered HTML only.', 'lilleprinsen-price-monitor' ); ?></p></td></tr></table>
			<details><summary><?php esc_html_e( 'Advanced selector settings', 'lilleprinsen-price-monitor' ); ?></summary><table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Price selector', 'lilleprinsen-price-monitor' ); ?></th><td><input name="price_selector" class="regular-text" placeholder=".price" /></td></tr><tr><th><?php esc_html_e( 'Sale price selector', 'lilleprinsen-price-monitor' ); ?></th><td><input name="sale_price_selector" class="regular-text" /></td></tr><tr><th><?php esc_html_e( 'SKU selector', 'lilleprinsen-price-monitor' ); ?></th><td><input name="sku_selector" class="regular-text" /></td></tr><tr><th><?php esc_html_e( 'EAN/GTIN selector', 'lilleprinsen-price-monitor' ); ?></th><td><input name="gtin_selector" class="regular-text" /></td></tr></table></details>
			<p><button class="button button-primary"><?php esc_html_e( 'Test product page', 'lilleprinsen-price-monitor' ); ?></button></p>
		</form>
		<?php $this->render_last_test(); ?>
		<h3><?php esc_html_e( 'Search setup', 'lilleprinsen-price-monitor' ); ?></h3>
		<p><?php esc_html_e( 'Paste one competitor search-results URL. The assistant turns it into a reusable template.', 'lilleprinsen-price-monitor' ); ?></p>
		<form method="post" class="lpm-discovery-card lpm-production-form" style="max-width:900px;">
			<?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?><input type="hidden" name="lpm_discovery_action" value="save_competitor_search_template" />
			<table class="form-table" role="presentation">
				<tr><th><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></th><td><?php $this->competitor_select( 'template_competitor_id', $competitors, false ); ?></td></tr>
				<tr><th><label for="lpm_search_result_url"><?php esc_html_e( 'Paste search-results URL', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="lpm_search_result_url" name="search_result_url" class="large-text" placeholder="https://competitor.no/search?q=10201031" /><p class="description"><?php esc_html_e( 'Open the competitor site, search for one of your selected product SKUs or EANs, then paste the URL from the browser address bar.', 'lilleprinsen-price-monitor' ); ?></p></td></tr>
				<tr><th><label for="lpm_search_result_value"><?php esc_html_e( 'SKU/EAN you searched for', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="lpm_search_result_value" name="search_result_value" class="regular-text" placeholder="10201031" /><p class="description"><?php esc_html_e( 'The assistant replaces this value with a reusable placeholder. Use the same SKU or EAN that appears in the pasted URL.', 'lilleprinsen-price-monitor' ); ?></p></td></tr>
				<tr><th><label for="lpm_search_url_templates"><?php esc_html_e( 'Advanced templates', 'lilleprinsen-price-monitor' ); ?></label></th><td><details class="lpm-row-details"><summary><?php esc_html_e( 'Use manual templates instead', 'lilleprinsen-price-monitor' ); ?></summary><input id="lpm_search_url_templates" name="search_url_templates" class="large-text" placeholder="?s={sku}, search?q={query}, finn?q={ean}" /><p class="description"><?php esc_html_e( 'Optional. Use {sku}, {ean}, {gtin}, or {query}. Comma-separated templates are allowed.', 'lilleprinsen-price-monitor' ); ?></p></details></td></tr>
			</table>
			<p><button class="button"><?php esc_html_e( 'Save search setup', 'lilleprinsen-price-monitor' ); ?></button></p>
		</form>
		<form method="post" style="max-width:900px;">
			<?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?><input type="hidden" name="lpm_discovery_action" value="test_search_template" />
			<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></th><td><?php $this->competitor_select( 'search_test_competitor_id', $competitors, false ); ?></td></tr><tr><th><?php esc_html_e( 'One selected product identifier', 'lilleprinsen-price-monitor' ); ?></th><td><input name="test_sku" class="regular-text" required placeholder="SKU or EAN" /> <input name="test_gtin" class="regular-text" placeholder="<?php esc_attr_e( 'Optional EAN/GTIN', 'lilleprinsen-price-monitor' ); ?>" /><p class="description"><?php esc_html_e( 'Test with the SKU and, if available, EAN/GTIN from one selected product. The checker will try both within the configured request limit.', 'lilleprinsen-price-monitor' ); ?></p></td></tr></table>
			<p><button class="button"><?php esc_html_e( 'Test search setup', 'lilleprinsen-price-monitor' ); ?></button></p>
		</form>
		<?php $this->render_last_search_test(); ?>
		<details class="lpm-advanced-panel" id="lpm-add-product-source">
			<summary><?php esc_html_e( 'Add sitemap or listing page', 'lilleprinsen-price-monitor' ); ?></summary>
		<form method="post" style="max-width:900px;">
			<?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?><input type="hidden" name="lpm_discovery_action" value="add_seed_url" />
			<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></th><td><?php $this->competitor_select( 'seed_competitor_id', $competitors, false ); ?></td></tr><tr><th><?php esc_html_e( 'Source type', 'lilleprinsen-price-monitor' ); ?></th><td><select name="source_type"><option value="listing"><?php esc_html_e( 'Listing page', 'lilleprinsen-price-monitor' ); ?></option><option value="product"><?php esc_html_e( 'Example product URL', 'lilleprinsen-price-monitor' ); ?></option><option value="sitemap"><?php esc_html_e( 'Sitemap URL', 'lilleprinsen-price-monitor' ); ?></option></select></td></tr><tr><th><?php esc_html_e( 'URL', 'lilleprinsen-price-monitor' ); ?></th><td><input name="seed_url" type="url" class="large-text" required /><p class="description"><?php esc_html_e( 'Discovery will read this page later and queue product-looking URLs within the configured limits.', 'lilleprinsen-price-monitor' ); ?></p></td></tr></table>
			<details><summary><?php esc_html_e( 'Advanced Settings', 'lilleprinsen-price-monitor' ); ?></summary><p><label><?php esc_html_e( 'Include URL patterns', 'lilleprinsen-price-monitor' ); ?><input name="include_patterns" class="large-text" /></label></p><p><label><?php esc_html_e( 'Exclude URL patterns', 'lilleprinsen-price-monitor' ); ?><input name="exclude_patterns" class="large-text" /></label></p><p><label><?php esc_html_e( 'Product URL patterns', 'lilleprinsen-price-monitor' ); ?><input name="product_url_patterns" class="large-text" /></label></p></details>
			<p><button class="button button-primary"><?php esc_html_e( 'Add source', 'lilleprinsen-price-monitor' ); ?></button></p>
		</form>
		</details>
		<h3><?php esc_html_e( 'Competitors', 'lilleprinsen-price-monitor' ); ?></h3>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Discovery setup', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th></tr></thead><tbody>
		<?php foreach ( $competitors as $competitor ) : $seeds = $this->discovery_repository->get_seed_urls_for_competitor( (int) $competitor['id'] ); $templates = $this->sku_search->search_templates( $competitor ); ?>
			<tr><td><strong><?php echo esc_html( $competitor['name'] ); ?></strong><br><small><?php echo esc_html( (string) $competitor['domain'] ); ?></small><?php if ( ! empty( $competitor['requires_javascript'] ) ) : ?><br><small style="color:#b32d2e;"><?php esc_html_e( 'JavaScript-heavy', 'lilleprinsen-price-monitor' ); ?></small><?php endif; ?></td><td><?php echo esc_html( ! empty( $templates ) ? sprintf( _n( '%d search template', '%d search templates', count( $templates ), 'lilleprinsen-price-monitor' ), count( $templates ) ) : __( 'No search template', 'lilleprinsen-price-monitor' ) ); ?> · <?php echo esc_html( sprintf( _n( '%d source', '%d sources', count( $seeds ), 'lilleprinsen-price-monitor' ), count( $seeds ) ) ); ?><?php if ( empty( $templates ) && empty( $seeds ) ) : ?><br><small style="color:#b32d2e;"><?php esc_html_e( 'Add a search URL before running discovery.', 'lilleprinsen-price-monitor' ); ?></small><?php endif; ?><details class="lpm-row-details"><summary><?php esc_html_e( 'Details', 'lilleprinsen-price-monitor' ); ?></summary><?php echo esc_html( $this->price_field_label( (string) ( $competitor['monitored_price_field'] ?? 'sale_price_first' ) ) ); ?><br><?php echo esc_html( implode( ', ', array_slice( $templates, 0, 3 ) ) ); ?></details></td><td><button type="button" class="button button-primary" data-lpm-start-competitor="<?php echo esc_attr( (string) $competitor['id'] ); ?>"><?php esc_html_e( 'Search now', 'lilleprinsen-price-monitor' ); ?></button> <details class="lpm-row-actions"><summary><?php esc_html_e( 'More', 'lilleprinsen-price-monitor' ); ?></summary><form method="post"><?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?><input type="hidden" name="lpm_discovery_action" value="run_small_discovery" /><input type="hidden" name="competitor_id" value="<?php echo esc_attr( (string) $competitor['id'] ); ?>" /><button class="button"><?php esc_html_e( 'Queue scheduled discovery', 'lilleprinsen-price-monitor' ); ?></button></form></details></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	/** Suggestions tab. */
	private function render_suggestions(): void {
		$page        = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$suggestions = $this->discovery_repository->get_suggestions( 'pending', $page, 50 );
		$total       = $this->discovery_repository->count_suggestions( 'pending' );
		?>
		<h2><?php esc_html_e( 'Suggested Matches', 'lilleprinsen-price-monitor' ); ?></h2>
		<?php $this->render_step_guidance( 6 ); ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Suggestions are never auto-approved. Check model, color, bundle size and variant before approving. Approved suggestions immediately become active monitored competitor links for recurring checks.', 'lilleprinsen-price-monitor' ); ?></p></div>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Our product', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Competitor product', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Price / stock', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Why this match was suggested', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Confidence', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Warnings', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th></tr></thead><tbody>
		<?php foreach ( $suggestions as $suggestion ) : $product = $this->discovery_repository->get_discovery_product( (int) $suggestion->discovery_product_id ); $discovered = $this->discovery_repository->get_discovered_product( (int) $suggestion->discovered_product_id ); ?>
			<tr><td><?php echo get_the_post_thumbnail( (int) $suggestion->product_id, array( 64, 64 ), array( 'style' => 'float:left;margin-right:8px;max-width:64px;height:auto;' ) ); ?><a href="<?php echo esc_url( get_edit_post_link( (int) $suggestion->product_id ) ); ?>"><?php echo esc_html( get_the_title( (int) $suggestion->product_id ) ); ?></a><br><small><?php echo esc_html( $product ? 'SKU: ' . $product->sku . ' EAN: ' . $product->gtin . ' MPN: ' . $product->mpn . ' Brand: ' . $product->brand : '' ); ?></small></td><td><?php if ( $discovered && ! empty( $discovered->image_url ) ) : ?><img src="<?php echo esc_url( (string) $discovered->image_url ); ?>" alt="" style="float:left;margin-right:8px;max-width:64px;height:auto;" /><?php endif; ?><?php echo esc_html( $discovered ? (string) $discovered->title : '' ); ?><br><small><?php echo esc_html( $discovered ? 'SKU: ' . $discovered->sku . ' EAN: ' . $discovered->gtin . ' MPN: ' . $discovered->mpn . ' Brand: ' . $discovered->brand : '' ); ?></small><br><a href="<?php echo esc_url( $suggestion->competitor_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open competitor page', 'lilleprinsen-price-monitor' ); ?></a></td><td><?php echo esc_html( $discovered ? $this->effective_price( $discovered ) . ' ' . $discovered->currency . ' / ' . $discovered->stock_status : '' ); ?></td><td><?php echo esc_html( (string) $suggestion->explanation ); ?></td><td><strong><?php echo esc_html( (string) $suggestion->confidence_label ); ?></strong><br><small><?php echo esc_html( (string) $suggestion->match_type ); ?></small></td><td><?php echo esc_html( $this->suggestion_warning_text( $suggestion, $product, $discovered ) ); ?></td><td><?php $this->suggestion_buttons( $suggestion ); ?></td></tr>
		<?php endforeach; ?>
		<?php if ( empty( $suggestions ) ) : ?><tr><td colspan="7"><?php esc_html_e( 'No pending suggestions yet.', 'lilleprinsen-price-monitor' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<?php $this->pagination( $total, $page, 'suggestions' ); ?>
		<?php
	}

	/** Settings tab. */
	private function render_settings(): void {
		$settings = $this->settings->get_all();
		?>
		<h2><?php esc_html_e( 'Advanced Settings', 'lilleprinsen-price-monitor' ); ?></h2>
		<form method="post"><?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?><input type="hidden" name="lpm_discovery_action" value="save_settings" />
			<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Where is EAN/GTIN stored?', 'lilleprinsen-price-monitor' ); ?></th><td><select name="discovery_gtin_source"><?php foreach ( $this->settings->gtin_source_options() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['discovery_gtin_source'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'This is used for selected products, data quality, matching and suggestions.', 'lilleprinsen-price-monitor' ); ?></p></td></tr><tr><th><label for="gtin_meta"><?php esc_html_e( 'EAN/GTIN meta key', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="gtin_meta" type="text" name="discovery_gtin_meta_key" value="<?php echo esc_attr( (string) $settings['discovery_gtin_meta_key'] ); ?>" /><p class="description"><?php esc_html_e( 'Examples: _alg_ean, _wpm_gtin_code, _global_unique_id, ean, gtin, barcode', 'lilleprinsen-price-monitor' ); ?></p></td></tr><tr><th><?php esc_html_e( 'Discovery schedule', 'lilleprinsen-price-monitor' ); ?></th><td><label><input type="checkbox" name="discovery_enabled" value="1" <?php checked( ! empty( $settings['discovery_enabled'] ) ); ?> /> <?php esc_html_e( 'Allow weekly discovery jobs', 'lilleprinsen-price-monitor' ); ?></label><br><label><input type="checkbox" name="discovery_rediscover_missing_links_only" value="1" <?php checked( ! empty( $settings['discovery_rediscover_missing_links_only'] ) ); ?> /> <?php esc_html_e( 'Scheduled discovery only searches selected products with no active competitor link', 'lilleprinsen-price-monitor' ); ?></label><p class="description"><?php esc_html_e( 'Recommended for production. This keeps recurring discovery focused on products that still need their first approved competitor match.', 'lilleprinsen-price-monitor' ); ?></p></td></tr><tr><th><?php esc_html_e( 'Scan selected SKUs', 'lilleprinsen-price-monitor' ); ?></th><td><label><input type="checkbox" name="discovery_sku_scan_enabled" value="1" <?php checked( ! empty( $settings['discovery_sku_scan_enabled'] ) ); ?> /> <?php esc_html_e( 'Search competitor websites for the SKUs in Products to Monitor.', 'lilleprinsen-price-monitor' ); ?></label><br><label><input type="checkbox" name="discovery_name_search_enabled" value="1" <?php checked( ! empty( $settings['discovery_name_search_enabled'] ) ); ?> /> <?php esc_html_e( 'Also search by product name when SKU search finds nothing.', 'lilleprinsen-price-monitor' ); ?></label><p class="description"><?php esc_html_e( 'This only uses products you selected, never the full catalog. Product-name results are only candidates and still need identifier confirmation before suggestions are created.', 'lilleprinsen-price-monitor' ); ?></p></td></tr><tr><th><?php esc_html_e( 'Crawl competitor pages', 'lilleprinsen-price-monitor' ); ?></th><td><label><input type="checkbox" name="discovery_sku_crawl_enabled" value="1" <?php checked( ! empty( $settings['discovery_sku_crawl_enabled'] ) ); ?> /> <?php esc_html_e( 'Start from the competitor website and added source pages, then look for selected SKUs.', 'lilleprinsen-price-monitor' ); ?></label><p class="description"><?php esc_html_e( 'Small and safe by default. It follows same-domain links only and queues possible product pages for review.', 'lilleprinsen-price-monitor' ); ?></p></td></tr><tr><th><?php esc_html_e( 'Limits', 'lilleprinsen-price-monitor' ); ?></th><td><label><?php esc_html_e( 'Crawl pages', 'lilleprinsen-price-monitor' ); ?> <input type="number" name="discovery_max_crawl_pages_per_run" min="1" max="50" value="<?php echo esc_attr( (string) $settings['discovery_max_crawl_pages_per_run'] ); ?>" /></label> <label><?php esc_html_e( 'Selected SKUs per scan', 'lilleprinsen-price-monitor' ); ?> <input type="number" name="discovery_max_sku_searches_per_run" min="1" max="200" value="<?php echo esc_attr( (string) $settings['discovery_max_sku_searches_per_run'] ); ?>" /></label> <label><?php esc_html_e( 'Search attempts per SKU', 'lilleprinsen-price-monitor' ); ?> <input type="number" name="discovery_search_urls_per_sku" min="1" max="10" value="<?php echo esc_attr( (string) $settings['discovery_search_urls_per_sku'] ); ?>" /></label> <label><?php esc_html_e( 'Product pages', 'lilleprinsen-price-monitor' ); ?> <input type="number" name="discovery_max_product_pages_per_run" min="1" max="500" value="<?php echo esc_attr( (string) $settings['discovery_max_product_pages_per_run'] ); ?>" /></label> <label><?php esc_html_e( 'Requests per batch', 'lilleprinsen-price-monitor' ); ?> <input type="number" name="discovery_max_requests_per_batch" min="1" max="100" value="<?php echo esc_attr( (string) $settings['discovery_max_requests_per_batch'] ); ?>" /></label> <label><?php esc_html_e( 'Manual products', 'lilleprinsen-price-monitor' ); ?> <input type="number" name="discovery_manual_max_products_per_run" min="1" max="200" value="<?php echo esc_attr( (string) $settings['discovery_manual_max_products_per_run'] ); ?>" /></label> <label><?php esc_html_e( 'Manual competitors', 'lilleprinsen-price-monitor' ); ?> <input type="number" name="discovery_manual_max_competitors_per_run" min="1" max="50" value="<?php echo esc_attr( (string) $settings['discovery_manual_max_competitors_per_run'] ); ?>" /></label> <label><?php esc_html_e( 'Delay seconds', 'lilleprinsen-price-monitor' ); ?> <input type="number" name="discovery_request_delay_seconds" min="0" max="30" value="<?php echo esc_attr( (string) $settings['discovery_request_delay_seconds'] ); ?>" /></label></td></tr><tr><th><?php esc_html_e( 'Safety', 'lilleprinsen-price-monitor' ); ?></th><td><label><input type="checkbox" name="discovery_same_domain_only" value="1" <?php checked( ! empty( $settings['discovery_same_domain_only'] ) ); ?> /> <?php esc_html_e( 'Only test URLs on the competitor website by default', 'lilleprinsen-price-monitor' ); ?></label></td></tr></table>
			<div class="notice notice-info inline"><p><label><input type="checkbox" name="discovery_visual_matching_enabled" value="1" <?php checked( ! empty( $settings['discovery_visual_matching_enabled'] ) ); ?> /> <?php esc_html_e( 'Use product image embeddings as extra match evidence', 'lilleprinsen-price-monitor' ); ?></label><br><label><input type="checkbox" name="discovery_visual_remote_image_embeddings_enabled" value="1" <?php checked( ! empty( $settings['discovery_visual_remote_image_embeddings_enabled'] ) ); ?> /> <?php esc_html_e( 'Allow bounded remote image fetches for local visual signatures', 'lilleprinsen-price-monitor' ); ?></label><br><span class="description"><?php esc_html_e( 'Visual matching never creates high-confidence matches by itself. Keep remote fetching off unless you want the plugin to fetch product images during matching; external embedding providers can use the lpm_visual_product_embedding filter.', 'lilleprinsen-price-monitor' ); ?></span></p></div>
			<details><summary><?php esc_html_e( 'Advanced Settings', 'lilleprinsen-price-monitor' ); ?></summary><table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'EAN/GTIN fallback meta keys', 'lilleprinsen-price-monitor' ); ?></th><td><input name="discovery_identifier_meta_keys" class="large-text" value="<?php echo esc_attr( (string) $settings['discovery_identifier_meta_keys'] ); ?>" /></td></tr><tr><th><?php esc_html_e( 'MPN meta keys', 'lilleprinsen-price-monitor' ); ?></th><td><input name="discovery_mpn_meta_keys" class="large-text" value="<?php echo esc_attr( (string) $settings['discovery_mpn_meta_keys'] ); ?>" /></td></tr><tr><th><?php esc_html_e( 'Brand meta keys', 'lilleprinsen-price-monitor' ); ?></th><td><input name="discovery_brand_meta_keys" class="large-text" value="<?php echo esc_attr( (string) $settings['discovery_brand_meta_keys'] ); ?>" /></td></tr><tr><th><?php esc_html_e( 'Search URL templates', 'lilleprinsen-price-monitor' ); ?></th><td><input name="discovery_sku_search_url_templates" class="large-text" value="<?php echo esc_attr( (string) $settings['discovery_sku_search_url_templates'] ); ?>" /><p class="description"><?php esc_html_e( 'Examples: ?s={sku}, search?q={sku}, catalogsearch/result/?q={sku}. Used only for selected Products to Monitor.', 'lilleprinsen-price-monitor' ); ?></p></td></tr><tr><th><?php esc_html_e( 'URL patterns', 'lilleprinsen-price-monitor' ); ?></th><td><input name="discovery_product_url_patterns" class="large-text" value="<?php echo esc_attr( (string) $settings['discovery_product_url_patterns'] ); ?>" /><input name="discovery_exclude_url_patterns" class="large-text" value="<?php echo esc_attr( (string) $settings['discovery_exclude_url_patterns'] ); ?>" /></td></tr></table></details>
			<p><button class="button button-primary"><?php esc_html_e( 'Save settings', 'lilleprinsen-price-monitor' ); ?></button></p>
		</form>
		<?php
	}

	private function handle_add_products_by_sku(): void {
		$raw     = isset( $_POST['sku_list'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sku_list'] ) ) : '';
		$items   = preg_split( '/[\s,;]+/', $raw );
		$added   = 0;
		$missing = 0;
		foreach ( $items as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}
			$product_id = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $item ) : 0;
			if ( ! $product_id && ctype_digit( $item ) ) {
				$product_id = absint( $item );
			}
			$product = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
			if ( $product ) {
				$variation_id = method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;
				$parent_id    = $variation_id && method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
				$this->discovery_repository->upsert_discovery_product( $parent_id, $variation_id, $this->identifiers->get_for_product( $product ) );
				++$added;
			} else {
				++$missing;
			}
		}
		$this->set_notice( sprintf( __( 'Added %1$d products. %2$d entries were not found.', 'lilleprinsen-price-monitor' ), $added, $missing ), $missing ? 'warning' : 'success' );
	}

	private function handle_test_gtin_source(): void {
		$rows   = $this->discovery_repository->get_enabled_products_for_matching( 500 );
		$result = $this->identifiers->test_gtin_source_for_selected_products( $rows );
		$this->set_notice( sprintf( __( 'EAN/GTIN source test: %1$d of %2$d selected products have values. Missing: %3$d. Duplicates: %4$d.', 'lilleprinsen-price-monitor' ), $result['with_gtin'], $result['total'], $result['missing'], $result['duplicates'] ), $result['duplicates'] ? 'warning' : 'success' );
	}

	private function handle_remove_product(): void {
		$id = absint( $_POST['discovery_product_id'] ?? 0 );
		if ( $id > 0 ) {
			$this->discovery_repository->set_discovery_product_enabled( $id, false );
		}
		$this->set_notice( __( 'Product removed from competitor discovery.', 'lilleprinsen-price-monitor' ) );
	}

	private function handle_add_seed_url(): void {
		$competitor_id = absint( $_POST['seed_competitor_id'] ?? 0 );
		$competitor    = $competitor_id ? $this->repository->get_competitor( $competitor_id ) : null;
		$url           = isset( $_POST['seed_url'] ) ? esc_url_raw( wp_unslash( $_POST['seed_url'] ) ) : '';
		$url           = $this->url_service->normalize( $url );
		if ( ! $competitor || '' === $url ) {
			$this->set_notice( __( 'Choose a competitor and enter a valid URL.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}
		$this->discovery_repository->upsert_seed_url( array( 'competitor_id' => $competitor_id, 'source_type' => sanitize_key( wp_unslash( $_POST['source_type'] ?? 'listing' ) ), 'url' => $url, 'url_hash' => $this->url_service->hash_url( $url ), 'domain' => $this->url_service->get_domain( $url ), 'include_patterns' => sanitize_text_field( wp_unslash( $_POST['include_patterns'] ?? '' ) ), 'exclude_patterns' => sanitize_text_field( wp_unslash( $_POST['exclude_patterns'] ?? '' ) ), 'product_url_patterns' => sanitize_text_field( wp_unslash( $_POST['product_url_patterns'] ?? '' ) ) ) );
		$this->set_notice( __( 'Discovery source added. Use Scan monitored SKUs when you are ready.', 'lilleprinsen-price-monitor' ) );
	}

	private function handle_run_small_discovery(): void {
		$competitor_id = absint( $_POST['competitor_id'] ?? 0 );
		if ( $this->job->enqueue_manual_batch( $competitor_id ) ) {
			$this->set_notice( __( 'Scan monitored SKUs has been queued. Check Suggested Matches in a minute; approved matches will be added to regular price monitoring.', 'lilleprinsen-price-monitor' ) );
		} else {
			$this->set_notice( __( 'Action Scheduler is not available, so Scan monitored SKUs could not be queued.', 'lilleprinsen-price-monitor' ), 'error' );
		}
	}

	private function handle_test_product_page(): void {
		$url           = isset( $_POST['product_url'] ) ? esc_url_raw( wp_unslash( $_POST['product_url'] ) ) : '';
		$competitor_id = absint( $_POST['competitor_id'] ?? 0 );
		$competitor    = $competitor_id > 0 ? $this->repository->get_competitor( $competitor_id ) : null;
		if ( ! $competitor ) {
			$name   = isset( $_POST['competitor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['competitor_name'] ) ) : '';
			$domain = isset( $_POST['competitor_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['competitor_domain'] ) ) : '';
			if ( '' === $name ) {
				$this->set_notice( __( 'Enter a competitor name before testing.', 'lilleprinsen-price-monitor' ), 'error' );
				return;
			}
			$platform    = isset( $_POST['competitor_platform'] ) ? sanitize_key( wp_unslash( $_POST['competitor_platform'] ) ) : 'auto';
			$search_url  = isset( $_POST['competitor_platform_search_url'] ) ? esc_url_raw( wp_unslash( $_POST['competitor_platform_search_url'] ) ) : '';
			$detection   = CompetitorPlatformDetector::detect( $domain, $search_url, '', $platform );
			$notes       = $this->merge_competitor_notes(
				'',
				array(
					'platform'                       => $detection['platform'],
					'platform_search_url'            => $search_url,
					'platform_detection_confidence'  => $detection['confidence'],
					'platform_detection_signals'     => $detection['signals'],
					'search_url_templates'           => $detection['templates'],
				)
			);
			$competitor_id = $this->repository->add_competitor(
				array(
					'name'                => $name,
					'domain'              => $domain,
					'enabled'             => 1,
					'requires_javascript' => ! empty( $detection['requires_javascript'] ) ? 1 : 0,
					'notes'               => $notes,
				)
			);
			$competitor    = $competitor_id > 0 ? $this->repository->get_competitor( $competitor_id ) : null;
		}
		if ( ! $competitor ) {
			$this->set_notice( __( 'The competitor profile could not be saved.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}
		$test_competitor = array_merge(
			$competitor,
			array_filter(
				array(
					'price_selector'         => sanitize_text_field( wp_unslash( $_POST['price_selector'] ?? '' ) ),
					'regular_price_selector' => sanitize_text_field( wp_unslash( $_POST['price_selector'] ?? '' ) ),
					'sale_price_selector'    => sanitize_text_field( wp_unslash( $_POST['sale_price_selector'] ?? '' ) ),
					'sku_selector'           => sanitize_text_field( wp_unslash( $_POST['sku_selector'] ?? '' ) ),
					'gtin_selector'          => sanitize_text_field( wp_unslash( $_POST['gtin_selector'] ?? '' ) ),
				),
				static fn( $value ) => '' !== (string) $value
			)
		);
		$result          = $this->extractor->test_url( $url, $test_competitor );
		$this->last_test = $result;
		$this->last_test['competitor_id'] = $competitor_id;
		$this->last_test['suggestion_count'] = 0;
		if ( empty( $result['success'] ) ) {
			$this->set_notice( (string) $result['message'], 'error' );
			return;
		}
		$this->discovery_repository->upsert_seed_url( array( 'competitor_id' => $competitor_id, 'source_type' => 'product', 'url' => $url, 'url_hash' => $this->url_service->hash_url( $url ), 'domain' => $this->url_service->get_domain( $url ) ) );
		$discovered_id  = $this->discovery_repository->store_discovered_product( $competitor_id, $url, $result );
		$discovered     = $this->discovery_repository->get_discovered_product( $discovered_id );
		$suggestion_ids = $discovered ? $this->matcher->create_suggestions( $discovered_id, $discovered, $this->discovery_repository->get_enabled_products_for_matching( 500 ) ) : array();
		$this->last_test['suggestion_count'] = count( $suggestion_ids );
		$this->set_notice( sprintf( __( '%1$s Created %2$d suggested matches for review.', 'lilleprinsen-price-monitor' ), (string) $result['message'], count( $suggestion_ids ) ) );
	}

	private function handle_save_test_price_rule(): void {
		$competitor_id = absint( $_POST['competitor_id'] ?? 0 );
		$competitor    = $competitor_id > 0 ? $this->repository->get_competitor( $competitor_id ) : null;
		if ( ! $competitor ) {
			$this->set_notice( __( 'Competitor was not found.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}

		$candidate = array(
			'field'  => sanitize_key( wp_unslash( $_POST['candidate_field'] ?? '' ) ),
			'value'  => sanitize_text_field( wp_unslash( $_POST['candidate_value'] ?? '' ) ),
			'price'  => sanitize_text_field( wp_unslash( $_POST['candidate_price'] ?? '' ) ),
			'source' => sanitize_key( wp_unslash( $_POST['candidate_source'] ?? '' ) ),
			'label'  => sanitize_text_field( wp_unslash( $_POST['candidate_label'] ?? '' ) ),
			'rule'   => sanitize_text_field( wp_unslash( $_POST['candidate_rule'] ?? '' ) ),
		);
		$updates = $this->extractor->profile_rule_from_price_candidate( $candidate );
		$updates['requires_javascript'] = ! empty( $_POST['requires_javascript'] ) ? 1 : (int) ( $competitor['requires_javascript'] ?? 0 );
		$updates['notes'] = $this->merge_competitor_notes(
			(string) ( $competitor['notes'] ?? '' ),
			array(
				'last_price_candidate' => $candidate,
				'external_browser_worker_enabled' => false,
			)
		);

		if ( $this->save_competitor_profile_updates( $competitor, $updates ) ) {
			$source = (string) ( $candidate['source'] ?? '' );
			$message = in_array( $source, array( 'selector', 'custom_rule' ), true )
				? __( 'Default competitor price rule saved. The selector was saved to the competitor profile and future checks will use this monitored price field.', 'lilleprinsen-price-monitor' )
				: __( 'Default competitor price rule saved. No CSS selector was needed because the price came from structured data, a meta tag, or visible text; future checks will use this monitored price field.', 'lilleprinsen-price-monitor' );
			$this->set_notice( $message );
			return;
		}

		$this->set_notice( __( 'The competitor price rule could not be saved.', 'lilleprinsen-price-monitor' ), 'error' );
	}

	private function handle_save_competitor_search_template(): void {
		$competitor_id = absint( $_POST['template_competitor_id'] ?? 0 );
		$competitor    = $competitor_id > 0 ? $this->repository->get_competitor( $competitor_id ) : null;
		if ( ! $competitor ) {
			$this->set_notice( __( 'Choose a competitor before saving search templates.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}

		$templates = self::normalize_search_template_inputs(
			sanitize_text_field( wp_unslash( $_POST['search_url_templates'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['search_result_url'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['search_result_value'] ?? '' ) )
		);
		if ( empty( $templates ) ) {
			$this->set_notice( __( 'Paste a search-results URL with the searched SKU/EAN, or enter at least one template using {sku}, {ean}, {gtin}, or {query}.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}

		$notes = $this->merge_competitor_notes( (string) ( $competitor['notes'] ?? '' ), array( 'search_url_templates' => $templates ) );
		$this->save_competitor_profile_updates( $competitor, array( 'notes' => $notes ) );
		$this->set_notice( sprintf( __( 'Search setup saved for this competitor. Templates: %s', 'lilleprinsen-price-monitor' ), implode( ', ', $templates ) ) );

		$searched_value = sanitize_text_field( wp_unslash( $_POST['search_result_value'] ?? '' ) );
		if ( '' !== trim( $searched_value ) ) {
			$competitor['notes'] = $notes;
			$product = (object) array(
				'id'              => 0,
				'product_id'      => 0,
				'variation_id'    => 0,
				'sku'             => $searched_value,
				'normalized_sku'  => preg_replace( '/[^A-Z0-9]/', '', strtoupper( $searched_value ) ),
				'gtin'            => $searched_value,
				'normalized_gtin' => preg_replace( '/[^A-Z0-9]/', '', strtoupper( $searched_value ) ),
			);
			$this->last_search_test = $this->sku_search->discover_for_product( $competitor, $product );
		}
	}

	private function handle_test_search_template(): void {
		$competitor_id = absint( $_POST['search_test_competitor_id'] ?? 0 );
		$competitor    = $competitor_id > 0 ? $this->repository->get_competitor( $competitor_id ) : null;
		$sku           = sanitize_text_field( wp_unslash( $_POST['test_sku'] ?? '' ) );
		$gtin          = sanitize_text_field( wp_unslash( $_POST['test_gtin'] ?? '' ) );
		if ( ! $competitor || '' === $sku ) {
			$this->set_notice( __( 'Choose a competitor and enter one SKU or EAN to test.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}

		$product = (object) array(
			'id' => 0,
			'product_id' => 0,
			'variation_id' => 0,
			'sku' => $sku,
			'normalized_sku' => preg_replace( '/[^A-Z0-9]/', '', strtoupper( $sku ) ),
			'gtin' => $gtin,
			'normalized_gtin' => preg_replace( '/[^A-Z0-9]/', '', strtoupper( $gtin ) ),
		);
		$result = $this->sku_search->discover_for_product( $competitor, $product );
		$this->last_search_test = $result;
		if ( empty( $result['success'] ) || empty( $result['urls'] ) ) {
			$this->set_notice( $this->no_match_reason( $result ), 'warning' );
			return;
		}

		$this->set_notice( sprintf( __( 'Search template test found %d possible product URLs.', 'lilleprinsen-price-monitor' ), count( (array) $result['urls'] ) ) );
	}

	private function handle_retest_suggestion(): void {
		$suggestion = $this->discovery_repository->get_suggestion( absint( $_POST['suggestion_id'] ?? 0 ) );
		if ( ! $suggestion || 'pending' !== (string) $suggestion->status ) {
			$this->set_notice( __( 'Only pending suggestions can be retested.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}
		$competitor = $this->repository->get_competitor( (int) $suggestion->competitor_id );
		if ( ! $competitor ) {
			$this->set_notice( __( 'Competitor was not found.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}
		$result = $this->extractor->test_url( (string) $suggestion->competitor_url, $competitor );
		$this->last_test = $result;
		$this->last_test['competitor_id'] = (int) $suggestion->competitor_id;
		$this->last_test['suggestion_count'] = 1;
		$this->set_notice( empty( $result['success'] ) ? (string) $result['message'] : __( 'Retest complete. Review the detected values before approving.', 'lilleprinsen-price-monitor' ), empty( $result['success'] ) ? 'error' : 'success' );
	}

	private function handle_approve_suggestion(): void {
		$id         = absint( $_POST['suggestion_id'] ?? 0 );
		$suggestion = $this->discovery_repository->get_suggestion( $id );
		if ( ! $suggestion || 'pending' !== (string) $suggestion->status ) {
			$this->set_notice( __( 'Only pending suggestions can be approved.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}
		$product    = $this->discovery_repository->get_discovery_product( (int) $suggestion->discovery_product_id );
		$competitor = $this->repository->get_competitor( (int) $suggestion->competitor_id );
		$monitored  = $this->repository->get_monitored_product_by_product_id( (int) $suggestion->product_id );
		$created_monitor = $monitored ? array( 'success' => true, 'id' => (int) $monitored['id'] ) : $this->repository->add_monitored_product( (int) $suggestion->product_id, $product ? (string) $product->sku : '' );
		$monitored_id = (int) ( $created_monitor['id'] ?? 0 );
		if ( $monitored_id <= 0 ) {
			$this->set_notice( __( 'The product could not be added to monitoring.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}
		$match_type    = 'High confidence' === (string) $suggestion->confidence_label ? 'exact' : 'similar';
		$existing_link = $this->repository->get_competitor_link_by_url( $monitored_id, (string) $suggestion->competitor_url );
		$discovered    = $this->discovery_repository->get_discovered_product( (int) $suggestion->discovered_product_id );
		$approved_title = $discovered ? sanitize_text_field( (string) ( $discovered->title ?? '' ) ) : '';
		$data = array(
			'monitored_product_id' => $monitored_id,
			'competitor_id'        => (int) $suggestion->competitor_id,
			'competitor_name'      => $competitor['name'] ?? '',
			'competitor_url'       => (string) $suggestion->competitor_url,
			'match_type'           => $match_type,
			'enabled'              => 1,
			'is_primary'           => 0,
			'approved_sku'         => $discovered ? (string) ( $discovered->sku ?? '' ) : '',
			'approved_gtin'        => $discovered ? (string) ( $discovered->gtin ?? '' ) : '',
			'approved_mpn'         => $discovered ? (string) ( $discovered->mpn ?? '' ) : '',
			'approved_title'       => $approved_title,
			'approved_title_hash'  => '' !== $approved_title ? hash( 'sha256', strtolower( preg_replace( '/\s+/', ' ', $approved_title ) ?? $approved_title ) ) : '',
			'identity_guard_enabled' => 1,
		);
		$link_id = $existing_link ? (int) $existing_link['id'] : $this->repository->add_competitor_link( $data );
		if ( $existing_link ) {
			$this->repository->update_competitor_link( $link_id, $data );
		}
		$this->discovery_repository->approve_suggestion( $id, get_current_user_id(), $link_id );
		$this->set_notice( __( 'Suggestion approved. This is now an active monitored competitor link and will appear in recurring checks.', 'lilleprinsen-price-monitor' ) );
	}

	private function handle_reject_suggestion(): void {
		$suggestion = $this->discovery_repository->get_suggestion( absint( $_POST['suggestion_id'] ?? 0 ) );
		if ( ! $suggestion || 'pending' !== (string) $suggestion->status ) {
			$this->set_notice( __( 'Only pending suggestions can be rejected.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}
		$this->discovery_repository->reject_suggestion( (int) $suggestion->id, get_current_user_id() );
		$this->set_notice( __( 'Suggestion rejected. It will not keep reappearing unless the product data changes.', 'lilleprinsen-price-monitor' ) );
	}

	private function render_last_test(): void {
		if ( null === $this->last_test ) {
			return;
		}
		$fields = array( 'title' => __( 'Product title', 'lilleprinsen-price-monitor' ), 'sku' => __( 'SKU', 'lilleprinsen-price-monitor' ), 'gtin' => __( 'EAN/GTIN', 'lilleprinsen-price-monitor' ), 'mpn' => __( 'MPN', 'lilleprinsen-price-monitor' ), 'brand' => __( 'Brand', 'lilleprinsen-price-monitor' ), 'currency' => __( 'Currency', 'lilleprinsen-price-monitor' ), 'stock_status' => __( 'Stock status', 'lilleprinsen-price-monitor' ), 'image_url' => __( 'Image', 'lilleprinsen-price-monitor' ), 'canonical_url' => __( 'Canonical URL', 'lilleprinsen-price-monitor' ) );
		$sources          = is_array( $this->last_test['sources'] ?? null ) ? $this->last_test['sources'] : array();
		$candidates       = is_array( $this->last_test['price_candidates'] ?? null ) ? $this->last_test['price_candidates'] : array();
		$candidates       = array_values(
			array_filter(
				$candidates,
				static fn( $candidate ) => is_array( $candidate ) && in_array( (string) ( $candidate['field'] ?? '' ), array( 'regular_price', 'sale_price' ), true )
			)
		);
		$competitor_id    = absint( $this->last_test['competitor_id'] ?? 0 );
		$suggestion_count = absint( $this->last_test['suggestion_count'] ?? 0 );
		$success          = ! empty( $this->last_test['success'] );
		$suggestions_url  = add_query_arg( array( 'page' => 'lpm-competitor-prices', 'view' => 'suggestions' ), admin_url( 'admin.php' ) );
		$products_url     = add_query_arg( array( 'page' => 'lpm-competitor-prices', 'view' => 'products' ), admin_url( 'admin.php' ) );
		?>
		<h3><?php esc_html_e( 'Detected price candidates', 'lilleprinsen-price-monitor' ); ?></h3>
		<?php if ( ! empty( $this->last_test['requires_javascript'] ) ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'This competitor page appears to require JavaScript. The internal checker cannot render JavaScript; connect an external scraper/browser worker in the future before relying on this competitor.', 'lilleprinsen-price-monitor' ); ?></p></div><?php endif; ?>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Use', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Detected price', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Field', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Source', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Rule', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Action', 'lilleprinsen-price-monitor' ); ?></th></tr></thead><tbody>
		<?php foreach ( $candidates as $index => $candidate ) : ?>
			<tr><td><?php echo (string) ( $this->last_test['monitored_price_field'] ?? '' ) === (string) ( $candidate['field'] ?? '' ) ? esc_html__( 'Selected', 'lilleprinsen-price-monitor' ) : ''; ?></td><td><strong><?php echo esc_html( wc_format_decimal( (float) ( $candidate['price'] ?? 0 ), 2 ) ); ?></strong></td><td><?php echo esc_html( $this->price_field_label( (string) ( $candidate['field'] ?? '' ) ) ); ?></td><td><?php echo esc_html( (string) ( $candidate['label'] ?? $candidate['source'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $candidate['rule'] ?? '' ) ); ?></td><td><form method="post" style="margin:0;"><?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?><input type="hidden" name="lpm_discovery_action" value="save_test_price_rule" /><input type="hidden" name="competitor_id" value="<?php echo esc_attr( (string) $competitor_id ); ?>" /><input type="hidden" name="candidate_field" value="<?php echo esc_attr( (string) ( $candidate['field'] ?? '' ) ); ?>" /><input type="hidden" name="candidate_value" value="<?php echo esc_attr( (string) ( $candidate['value'] ?? '' ) ); ?>" /><input type="hidden" name="candidate_price" value="<?php echo esc_attr( (string) ( $candidate['price'] ?? '' ) ); ?>" /><input type="hidden" name="candidate_source" value="<?php echo esc_attr( (string) ( $candidate['source'] ?? '' ) ); ?>" /><input type="hidden" name="candidate_label" value="<?php echo esc_attr( (string) ( $candidate['label'] ?? '' ) ); ?>" /><input type="hidden" name="candidate_rule" value="<?php echo esc_attr( (string) ( $candidate['rule'] ?? '' ) ); ?>" /><input type="hidden" name="requires_javascript" value="<?php echo esc_attr( ! empty( $this->last_test['requires_javascript'] ) ? '1' : '0' ); ?>" /><button class="button <?php echo 0 === $index ? 'button-primary' : ''; ?>"><?php esc_html_e( 'Use this price', 'lilleprinsen-price-monitor' ); ?></button></form></td></tr>
		<?php endforeach; ?>
		<?php if ( empty( $candidates ) ) : ?><tr><td colspan="6"><?php esc_html_e( 'No supported regular or sale price candidates were detected. The page may block requests, hide prices behind JavaScript, or need an advanced selector.', 'lilleprinsen-price-monitor' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<h3><?php esc_html_e( 'Other detected fields', 'lilleprinsen-price-monitor' ); ?></h3><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Field', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Detected value', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Source', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Confidence', 'lilleprinsen-price-monitor' ); ?></th></tr></thead><tbody><?php foreach ( $fields as $key => $label ) : ?><tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( (string) ( $this->last_test[ $key ] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $sources[ $key ] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $this->last_test['confidence_status'] ?? '' ) ); ?></td></tr><?php endforeach; ?></tbody></table>
		<?php if ( $success ) : ?>
			<div class="notice notice-info inline" style="margin:14px 0;padding:12px 14px;">
				<p><strong><?php esc_html_e( 'Next step', 'lilleprinsen-price-monitor' ); ?></strong></p>
				<p><?php echo esc_html( 0 < $suggestion_count ? sprintf( __( 'We created %d suggested matches. Review them before anything is added to price monitoring.', 'lilleprinsen-price-monitor' ), $suggestion_count ) : __( 'We could read the page, but did not find a matching selected product yet. Check that the product is selected and that SKU or EAN/GTIN matches what the competitor page shows.', 'lilleprinsen-price-monitor' ) ); ?></p>
				<p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
					<a class="button button-primary" href="<?php echo esc_url( $suggestions_url ); ?>"><?php esc_html_e( 'View suggested matches', 'lilleprinsen-price-monitor' ); ?></a>
					<a class="button" href="#lpm-add-product-source"><?php esc_html_e( 'Add page with many products', 'lilleprinsen-price-monitor' ); ?></a>
					<a class="button" href="<?php echo esc_url( $products_url ); ?>"><?php esc_html_e( 'Check selected products', 'lilleprinsen-price-monitor' ); ?></a>
					<?php if ( $competitor_id > 0 ) : ?>
						<form method="post" style="display:inline-block;margin:0;">
							<?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?>
							<input type="hidden" name="lpm_discovery_action" value="run_small_discovery" />
							<input type="hidden" name="competitor_id" value="<?php echo esc_attr( (string) $competitor_id ); ?>" />
							<button class="button"><?php esc_html_e( 'Scan monitored SKUs', 'lilleprinsen-price-monitor' ); ?></button>
						</form>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $this->last_test['technical_details'] ) ) : ?><details style="margin-top:12px;"><summary><?php esc_html_e( 'Show details', 'lilleprinsen-price-monitor' ); ?></summary><pre><?php echo esc_html( (string) $this->last_test['technical_details'] ); ?></pre></details><?php endif; ?>
		<?php
	}

	private function render_last_search_test(): void {
		if ( null === $this->last_search_test ) {
			return;
		}
		$urls          = (array) ( $this->last_search_test['urls'] ?? array() );
		$searched_urls = (array) ( $this->last_search_test['searched_urls'] ?? array() );
		?>
		<h4><?php esc_html_e( 'Search template test result', 'lilleprinsen-price-monitor' ); ?></h4>
		<div class="notice notice-<?php echo empty( $urls ) ? 'warning' : 'success'; ?> inline"><p><?php echo esc_html( empty( $urls ) ? $this->no_match_reason( $this->last_search_test ) : (string) $this->last_search_test['message'] ); ?></p></div>
		<?php if ( ! empty( $searched_urls ) ) : ?>
			<p><strong><?php esc_html_e( 'Search pages tested', 'lilleprinsen-price-monitor' ); ?></strong></p>
			<ul style="list-style:disc;margin-left:20px;"><?php foreach ( array_slice( $searched_urls, 0, 10 ) as $url ) : ?><li><a href="<?php echo esc_url( (string) $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $url ); ?></a></li><?php endforeach; ?></ul>
		<?php endif; ?>
		<?php if ( ! empty( $urls ) ) : ?>
			<p><strong><?php esc_html_e( 'Possible product pages found', 'lilleprinsen-price-monitor' ); ?></strong></p>
			<ul style="list-style:disc;margin-left:20px;"><?php foreach ( array_slice( $urls, 0, 10 ) as $url ) : ?><li><a href="<?php echo esc_url( (string) $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $url ); ?></a></li><?php endforeach; ?></ul>
		<?php endif; ?>
		<?php if ( ! empty( $this->last_search_test['technical_details'] ) ) : ?><details><summary><?php esc_html_e( 'Why no match may have been found', 'lilleprinsen-price-monitor' ); ?></summary><pre><?php echo esc_html( (string) $this->last_search_test['technical_details'] ); ?></pre></details><?php endif; ?>
		<?php
	}

	/**
	 * Build safe search templates from easy and advanced inputs.
	 *
	 * @return array<int,string>
	 */
	public static function normalize_search_template_inputs( string $raw_templates, string $search_result_url, string $searched_value ): array {
		$templates = array();
		foreach ( preg_split( '/[\r\n,]+/', $raw_templates ) ?: array() as $template ) {
			$template = self::sanitize_search_template( (string) $template );
			if ( '' !== $template && self::template_has_search_placeholder( $template ) ) {
				$templates[] = $template;
			}
		}

		$derived = self::template_from_search_result_url( $search_result_url, $searched_value );
		if ( '' !== $derived ) {
			array_unshift( $templates, $derived );
		}

		return array_values( array_unique( array_filter( $templates ) ) );
	}

	private static function template_from_search_result_url( string $search_result_url, string $searched_value ): string {
		$url = trim( $search_result_url );
		if ( '' === $url ) {
			return '';
		}
		if ( self::template_has_search_placeholder( $url ) ) {
			return self::sanitize_search_template( $url );
		}

		$searched_value = trim( $searched_value );
		if ( '' === $searched_value ) {
			return '';
		}

		$candidates = array_values(
			array_unique(
				array_filter(
					array(
						$searched_value,
						rawurlencode( $searched_value ),
						urlencode( $searched_value ),
					)
				)
			)
		);
		foreach ( $candidates as $candidate ) {
			if ( false !== strpos( $url, $candidate ) ) {
				return self::sanitize_search_template( str_replace( $candidate, '{query}', $url ) );
			}
		}

		return '';
	}

	private static function sanitize_search_template( string $template ): string {
		$template = html_entity_decode( trim( sanitize_text_field( $template ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$template = str_ireplace(
			array( '%7Bsku%7D', '%7Bquery%7D', '%7Bean%7D', '%7Bgtin%7D' ),
			array( '{sku}', '{query}', '{ean}', '{gtin}' ),
			$template
		);
		if ( '' === $template || ! self::template_has_search_placeholder( $template ) || preg_match( '/\s/', $template ) ) {
			return '';
		}
		if ( preg_match( '#^[a-z][a-z0-9+\-.]*:#i', $template ) && ! preg_match( '#^https?://#i', $template ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $template ) ) {
			$placeholders = array(
				'{sku}'   => 'LPMPLACEHOLDERSKU',
				'{query}' => 'LPMPLACEHOLDERQUERY',
				'{ean}'   => 'LPMPLACEHOLDEREAN',
				'{gtin}'  => 'LPMPLACEHOLDERGTIN',
				'%s'      => 'LPMPLACEHOLDERSPRINTF',
			);
			$protected = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $template );
			$protected = esc_url_raw( $protected );
			$template  = str_replace( array_values( $placeholders ), array_keys( $placeholders ), $protected );
		}

		return $template;
	}

	private static function template_has_search_placeholder( string $template ): bool {
		return false !== strpos( $template, '{sku}' ) || false !== strpos( $template, '{query}' ) || false !== strpos( $template, '{ean}' ) || false !== strpos( $template, '{gtin}' ) || false !== strpos( $template, '%s' );
	}

	private function render_manual_discovery_panel(): void {
		$products    = $this->discovery_repository->get_enabled_products_for_matching( 200 );
		$competitors = array_values( array_filter( $this->repository->get_competitors( 1, 200 ), static fn( $competitor ) => ! empty( $competitor['enabled'] ) ) );
		$settings    = $this->settings->get_all();
		?>
		<div class="lpm-discovery-run-card" data-lpm-manual-discovery-panel data-selected-product-count="<?php echo esc_attr( (string) count( $products ) ); ?>" data-active-competitor-count="<?php echo esc_attr( (string) count( $competitors ) ); ?>">
			<div class="lpm-discovery-card-header">
				<div>
					<p class="lpm-drawer-kicker"><?php esc_html_e( 'Manual live discovery', 'lilleprinsen-price-monitor' ); ?></p>
					<h3><?php esc_html_e( 'Find competitor matches now', 'lilleprinsen-price-monitor' ); ?></h3>
					<p><?php esc_html_e( 'This searches only the products you selected. Matches must be approved before monitoring starts.', 'lilleprinsen-price-monitor' ); ?></p>
				</div>
				<div class="lpm-discovery-stats">
					<span><strong><?php echo esc_html( (string) count( $products ) ); ?></strong><?php esc_html_e( 'selected products', 'lilleprinsen-price-monitor' ); ?></span>
					<span><strong><?php echo esc_html( (string) count( $competitors ) ); ?></strong><?php esc_html_e( 'active competitors', 'lilleprinsen-price-monitor' ); ?></span>
				</div>
			</div>
			<div class="lpm-discovery-run-controls">
				<label><span><?php esc_html_e( 'Product scope', 'lilleprinsen-price-monitor' ); ?></span>
					<select data-lpm-manual-product>
						<option value="0"><?php esc_html_e( 'All selected products', 'lilleprinsen-price-monitor' ); ?></option>
						<?php foreach ( $products as $product ) : $title_id = (int) ( $product->variation_id ?: $product->product_id ); ?>
							<option value="<?php echo esc_attr( (string) $product->id ); ?>"><?php echo esc_html( get_the_title( $title_id ) . ( $product->sku ? ' - ' . $product->sku : '' ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><span><?php esc_html_e( 'Competitor scope', 'lilleprinsen-price-monitor' ); ?></span>
					<select data-lpm-manual-competitor>
						<option value="0"><?php esc_html_e( 'All active competitors', 'lilleprinsen-price-monitor' ); ?></option>
						<?php foreach ( $competitors as $competitor ) : ?>
							<option value="<?php echo esc_attr( (string) $competitor['id'] ); ?>"><?php echo esc_html( (string) $competitor['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="button" class="button button-primary" data-lpm-manual-start <?php disabled( empty( $products ) || empty( $competitors ) ); ?>><?php esc_html_e( 'Find competitor matches now', 'lilleprinsen-price-monitor' ); ?></button>
				<button type="button" class="button" data-lpm-manual-cancel hidden><?php esc_html_e( 'Cancel run', 'lilleprinsen-price-monitor' ); ?></button>
			</div>
			<details class="lpm-discovery-safety-note">
				<summary><?php esc_html_e( 'Safety limits', 'lilleprinsen-price-monitor' ); ?></summary>
				<?php printf( esc_html__( 'Manual runs are bounded to %1$d selected products and %2$d active competitors per run. Request delays and timeouts from competitor profiles still apply.', 'lilleprinsen-price-monitor' ), absint( $settings['discovery_manual_max_products_per_run'] ), absint( $settings['discovery_manual_max_competitors_per_run'] ) ); ?>
			</details>
			<?php if ( count( $products ) >= 25 || count( $competitors ) >= 5 ) : ?>
				<p class="lpm-discovery-warning"><strong><?php esc_html_e( 'Large run warning:', 'lilleprinsen-price-monitor' ); ?></strong> <?php esc_html_e( 'This may take a few minutes. Results will appear below as each small batch finishes.', 'lilleprinsen-price-monitor' ); ?></p>
			<?php endif; ?>
			<?php if ( empty( $products ) || empty( $competitors ) ) : ?>
				<p class="lpm-discovery-danger"><strong><?php esc_html_e( 'Cannot start yet:', 'lilleprinsen-price-monitor' ); ?></strong> <?php echo esc_html( empty( $products ) ? __( 'Add at least one selected product first.', 'lilleprinsen-price-monitor' ) : __( 'Add or enable at least one competitor first.', 'lilleprinsen-price-monitor' ) ); ?></p>
			<?php endif; ?>
			<div data-lpm-manual-progress hidden>
				<p><span data-lpm-manual-status class="lpm-status-badge"><?php esc_html_e( 'Queued', 'lilleprinsen-price-monitor' ); ?></span> <span data-lpm-manual-counts></span></p>
				<progress data-lpm-manual-progress-bar value="0" max="100"></progress>
			</div>
			<table class="widefat striped lpm-discovery-results" data-lpm-manual-results hidden>
				<thead><tr><th><?php esc_html_e( 'Our product', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Search/source', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Status', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Competitor product', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Detected', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Confidence', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Reason', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th></tr></thead>
				<tbody></tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Real admin testing readiness checklist.
	 *
	 * @param array<int,array<string,mixed>> $active_competitors Active competitor profiles.
	 * @param array<string,mixed>            $settings Combined plugin/discovery settings.
	 */
	private function render_admin_test_status_panel( int $selected_products, array $active_competitors, array $settings ): void {
		$js_warning_count = count( array_filter( $active_competitors, static fn( $competitor ) => ! empty( $competitor['requires_javascript'] ) ) );
		$real_updates_enabled = ! empty( $settings['allow_real_price_updates'] ) && empty( $settings['disable_all_price_updates'] );
		?>
		<h2><?php esc_html_e( 'Admin test readiness', 'lilleprinsen-price-monitor' ); ?></h2>
		<div style="background:#fff;border:1px solid #ccd0d4;padding:14px;margin:12px 0 18px;">
			<p style="margin-top:0;"><?php esc_html_e( 'Use this checklist before real admin testing. Discovery still searches only selected products and all matches remain approval-first.', 'lilleprinsen-price-monitor' ); ?></p>
			<div style="display:flex;gap:8px;flex-wrap:wrap;">
				<?php $this->status_badge_text( __( 'Selected discovery products', 'lilleprinsen-price-monitor' ), (string) $selected_products, $selected_products > 0 ? 'good' : 'warning' ); ?>
				<?php $this->status_badge_text( __( 'Active competitors', 'lilleprinsen-price-monitor' ), (string) count( $active_competitors ), count( $active_competitors ) > 0 ? 'good' : 'warning' ); ?>
				<?php $this->status_badge_text( __( 'Scheduled discovery', 'lilleprinsen-price-monitor' ), ! empty( $settings['discovery_enabled'] ) ? __( 'Enabled', 'lilleprinsen-price-monitor' ) : __( 'Disabled', 'lilleprinsen-price-monitor' ), ! empty( $settings['discovery_enabled'] ) ? 'warning' : 'muted' ); ?>
				<?php $this->status_badge_text( __( 'Scheduled price checks', 'lilleprinsen-price-monitor' ), ! empty( $settings['scheduled_checks_enabled'] ) ? __( 'Enabled', 'lilleprinsen-price-monitor' ) : __( 'Disabled', 'lilleprinsen-price-monitor' ), ! empty( $settings['scheduled_checks_enabled'] ) ? 'warning' : 'muted' ); ?>
				<?php $this->status_badge_text( __( 'Dry-run mode', 'lilleprinsen-price-monitor' ), ! empty( $settings['dry_run_mode'] ) ? __( 'On', 'lilleprinsen-price-monitor' ) : __( 'Off', 'lilleprinsen-price-monitor' ), ! empty( $settings['dry_run_mode'] ) ? 'good' : 'warning' ); ?>
				<?php $this->status_badge_text( __( 'Real WooCommerce price updates', 'lilleprinsen-price-monitor' ), $real_updates_enabled ? __( 'Enabled', 'lilleprinsen-price-monitor' ) : __( 'Disabled', 'lilleprinsen-price-monitor' ), $real_updates_enabled ? 'danger' : 'good' ); ?>
				<?php $this->status_badge_text( __( 'JavaScript-only competitors', 'lilleprinsen-price-monitor' ), (string) $js_warning_count, $js_warning_count > 0 ? 'warning' : 'good' ); ?>
			</div>
		</div>
		<?php
	}

	private function render_step_guidance( int $current_step ): void {
		$steps = array(
			1 => __( 'Select WooCommerce products to monitor.', 'lilleprinsen-price-monitor' ),
			2 => __( 'Add or choose a competitor.', 'lilleprinsen-price-monitor' ),
			3 => __( 'Test an example competitor product page.', 'lilleprinsen-price-monitor' ),
			4 => __( 'Confirm the detected fields and save the monitored price rule.', 'lilleprinsen-price-monitor' ),
			5 => __( 'Run discovery for selected products.', 'lilleprinsen-price-monitor' ),
			6 => __( 'Review and approve suggested matches.', 'lilleprinsen-price-monitor' ),
			7 => __( 'Monitor approved links for price changes.', 'lilleprinsen-price-monitor' ),
		);
		$current = $steps[ $current_step ] ?? '';
		$next    = $steps[ $current_step + 1 ] ?? __( 'Monitor approved competitor links.', 'lilleprinsen-price-monitor' );
		?>
		<div class="lpm-step-guidance">
			<span><?php printf( esc_html__( 'Step %1$d of 7', 'lilleprinsen-price-monitor' ), absint( $current_step ) ); ?></span>
			<strong><?php echo esc_html( $current ); ?></strong>
			<small><?php printf( esc_html__( 'Next: %s', 'lilleprinsen-price-monitor' ), esc_html( $next ) ); ?></small>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $competitor Existing competitor row.
	 * @param array<string,mixed> $updates Fields to override.
	 */
	private function save_competitor_profile_updates( array $competitor, array $updates ): bool {
		return $this->repository->update_competitor(
			(int) $competitor['id'],
			array_merge(
				$competitor,
				$updates,
				array(
					'name' => (string) $competitor['name'],
					'domain' => (string) ( $competitor['domain'] ?? '' ),
				)
			)
		);
	}

	/**
	 * Merge JSON notes while preserving unknown technical fields.
	 *
	 * @param array<string,mixed> $updates Updates.
	 */
	private function merge_competitor_notes( string $notes, array $updates ): string {
		$decoded = array();
		if ( '' !== trim( $notes ) && '{' === substr( trim( $notes ), 0, 1 ) ) {
			$maybe = json_decode( $notes, true );
			$decoded = is_array( $maybe ) ? $maybe : array();
		}

		return (string) wp_json_encode( array_merge( $decoded, $updates ) );
	}

	/**
	 * @param array<string,mixed> $result Search result.
	 */
	private function no_match_reason( array $result ): string {
		$details = strtolower( (string) ( $result['technical_details'] ?? '' ) );
		if ( str_contains( $details, 'competitor domain is empty' ) ) {
			return __( 'No match found: no search page could be built because the competitor has no website/domain.', 'lilleprinsen-price-monitor' );
		}
		if ( str_contains( $details, 'http status' ) ) {
			return __( 'No match found: the competitor returned a blocked or unavailable HTTP response.', 'lilleprinsen-price-monitor' );
		}
		if ( str_contains( $details, 'javascript' ) ) {
			return __( 'No match found: the page likely requires JavaScript, which the internal checker does not render.', 'lilleprinsen-price-monitor' );
		}
		if ( empty( $result['urls'] ) ) {
			return __( 'No match found: no product URLs were found for this SKU. Check the search template, SKU, and source pages.', 'lilleprinsen-price-monitor' );
		}

		return (string) ( $result['message'] ?? __( 'No match found.', 'lilleprinsen-price-monitor' ) );
	}

	private function price_field_label( string $field ): string {
		$labels = array(
			'regular_price' => __( 'Regular price', 'lilleprinsen-price-monitor' ),
			'sale_price' => __( 'Sale price', 'lilleprinsen-price-monitor' ),
			'sale_price_first' => __( 'Sale price first, then regular price', 'lilleprinsen-price-monitor' ),
			'detected_price' => __( 'Detected price', 'lilleprinsen-price-monitor' ),
			'lowest_price' => __( 'Lowest detected price', 'lilleprinsen-price-monitor' ),
		);
		return $labels[ $field ] ?? $field;
	}

	/**
	 * @param object|null $product Selected product row.
	 * @param object|null $discovered Discovered competitor row.
	 */
	private function suggestion_warning_text( object $suggestion, ?object $product, ?object $discovered ): string {
		if ( 'High confidence' === (string) $suggestion->confidence_label ) {
			return __( 'Still confirm pack size, color and variant before approval.', 'lilleprinsen-price-monitor' );
		}

		$warnings = array( __( 'Review color, model, bundle size and variant.', 'lilleprinsen-price-monitor' ) );
		if ( $product && $discovered && '' === (string) $discovered->normalized_gtin && '' === (string) $discovered->normalized_sku ) {
			$warnings[] = __( 'No exact SKU/EAN was found on the competitor page.', 'lilleprinsen-price-monitor' );
		}
		if ( 'title_only' === (string) $suggestion->match_type ) {
			$warnings[] = __( 'Title-only match: approve only after manual inspection.', 'lilleprinsen-price-monitor' );
		}

		return implode( ' ', $warnings );
	}

	private function competitor_select( string $name, array $competitors, bool $allow_create = true ): void {
		echo '<select name="' . esc_attr( $name ) . '">';
		if ( $allow_create ) {
			echo '<option value="0">' . esc_html__( 'Create new competitor', 'lilleprinsen-price-monitor' ) . '</option>';
		}
		foreach ( $competitors as $competitor ) {
			printf( '<option value="%s">%s</option>', esc_attr( (string) $competitor['id'] ), esc_html( (string) $competitor['name'] ) );
		}
		echo '</select>';
	}

	private function suggestion_buttons( object $suggestion ): void {
		foreach ( array( 'approve_suggestion' => __( 'Approve', 'lilleprinsen-price-monitor' ), 'reject_suggestion' => __( 'Reject', 'lilleprinsen-price-monitor' ), 'retest_suggestion' => __( 'Retest', 'lilleprinsen-price-monitor' ) ) as $action => $label ) {
			echo '<form method="post" style="display:inline-block;margin-right:6px;">';
			wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' );
			echo '<input type="hidden" name="lpm_discovery_action" value="' . esc_attr( $action ) . '" /><input type="hidden" name="suggestion_id" value="' . esc_attr( (string) $suggestion->id ) . '" /><button class="button ' . ( 'approve_suggestion' === $action ? 'button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
		}
	}

	private function metric( string $label, int $value ): void {
		echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:14px;"><strong style="font-size:24px;display:block;">' . esc_html( (string) $value ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
	}

	private function status_badge( string $label, bool $enabled ): void {
		$background = $enabled ? '#e7f7ed' : '#f6f7f7';
		$border     = $enabled ? '#00a32a' : '#8c8f94';
		$text       = $enabled ? __( 'On', 'lilleprinsen-price-monitor' ) : __( 'Off', 'lilleprinsen-price-monitor' );
		printf( '<span style="display:inline-flex;gap:6px;align-items:center;border:1px solid %1$s;background:%2$s;border-radius:999px;padding:4px 10px;"><strong>%3$s</strong> %4$s</span>', esc_attr( $border ), esc_attr( $background ), esc_html( $label ), esc_html( $text ) );
	}

	private function status_badge_text( string $label, string $value, string $tone = 'muted' ): void {
		$tones = array(
			'good'    => array( '#00a32a', '#e7f7ed' ),
			'warning' => array( '#996800', '#fff8e5' ),
			'danger'  => array( '#b32d2e', '#fcf0f1' ),
			'muted'   => array( '#8c8f94', '#f6f7f7' ),
		);
		$colors = $tones[ $tone ] ?? $tones['muted'];
		printf( '<span style="display:inline-flex;gap:6px;align-items:center;border:1px solid %1$s;background:%2$s;border-radius:999px;padding:4px 10px;"><strong>%3$s</strong> %4$s</span>', esc_attr( $colors[0] ), esc_attr( $colors[1] ), esc_html( $label ), esc_html( $value ) );
	}

	private function pagination( int $total, int $page, string $view, string $search = '' ): void {
		$pages = (int) ceil( $total / 50 );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<p class="tablenav-pages">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$url = add_query_arg( array( 'page' => 'lpm-competitor-prices', 'view' => $view, 'paged' => $i, 's' => $search ), admin_url( 'admin.php' ) );
			printf( '<a class="button %s" href="%s">%s</a> ', esc_attr( $i === $page ? 'button-primary' : '' ), esc_url( $url ), esc_html( (string) $i ) );
		}
		echo '</p>';
	}

	private function set_notice( string $message, string $type = 'success' ): void {
		$this->notice      = $message;
		$this->notice_type = $type;
	}

	private function render_notice(): void {
		if ( '' !== $this->notice ) {
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $this->notice_type ), esc_html( $this->notice ) );
		}
	}

	/** @return array<int,object> */
	private function health_by_competitor(): array {
		$map = array();
		foreach ( $this->discovery_repository->get_competitor_health_rows() as $row ) {
			$map[ (int) $row->competitor_id ] = $row;
		}
		return $map;
	}

	private function health_label( string $status ): string {
		$labels = array( 'working' => __( 'Working', 'lilleprinsen-price-monitor' ), 'needs_attention' => __( 'Needs attention', 'lilleprinsen-price-monitor' ), 'paused' => __( 'Paused', 'lilleprinsen-price-monitor' ), 'blocked_request_failed' => __( 'Blocked / request failed', 'lilleprinsen-price-monitor' ), 'extraction_changed' => __( 'Extraction changed', 'lilleprinsen-price-monitor' ), 'no_recent_success' => __( 'No recent successful checks', 'lilleprinsen-price-monitor' ) );
		return $labels[ $status ] ?? $status;
	}

	private function effective_price( object $discovered ): string {
		$price = null !== $discovered->sale_price ? $discovered->sale_price : $discovered->regular_price;
		return null === $price ? '' : wc_format_decimal( $price, 2 );
	}
}
