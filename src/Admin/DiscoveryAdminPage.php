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
use Lilleprinsen\PriceMonitor\Service\DiscoveryUrlService;
use Lilleprinsen\PriceMonitor\Service\MatchSuggestionService;
use Lilleprinsen\PriceMonitor\Service\ProductIdentifierService;
use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;

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
	private string $notice = '';
	private string $notice_type = 'success';
	/** @var array<string,mixed>|null */
	private ?array $last_test = null;

	/** Constructor. */
	public function __construct(
		Repository $repository,
		DiscoveryRepository $discovery_repository,
		DiscoverySettings $settings,
		ProductIdentifierService $identifiers,
		CompetitorProductExtractor $extractor,
		MatchSuggestionService $matcher,
		CompetitorDiscoveryJob $job,
		DiscoveryUrlService $url_service
	) {
		$this->repository           = $repository;
		$this->discovery_repository = $discovery_repository;
		$this->settings             = $settings;
		$this->identifiers          = $identifiers;
		$this->extractor            = $extractor;
		$this->matcher              = $matcher;
		$this->job                  = $job;
		$this->url_service          = $url_service;
	}

	/** Register submenu. */
	public function register_menu(): void {
		add_submenu_page( 'woocommerce', __( 'Competitor Price Assistant', 'lilleprinsen-price-monitor' ), __( 'Competitor Prices', 'lilleprinsen-price-monitor' ), 'manage_woocommerce', 'lpm-competitor-prices', array( $this, 'render' ) );
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
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Competitor Price Assistant', 'lilleprinsen-price-monitor' ); ?></h1>
			<p><?php esc_html_e( 'Find competitor product matches for the products you choose. Nothing is approved until you say so.', 'lilleprinsen-price-monitor' ); ?></p>
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
		?>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:16px;">
			<?php $this->metric( __( 'Products selected', 'lilleprinsen-price-monitor' ), $quality['selected'] ); ?>
			<?php $this->metric( __( 'With SKU', 'lilleprinsen-price-monitor' ), $quality['with_sku'] ); ?>
			<?php $this->metric( __( 'With EAN/GTIN', 'lilleprinsen-price-monitor' ), $quality['with_gtin'] ); ?>
			<?php $this->metric( __( 'Pending suggested matches', 'lilleprinsen-price-monitor' ), $pending ); ?>
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
		<p><?php esc_html_e( 'Only these products are used when finding competitor matches. This keeps discovery fast even on large stores.', 'lilleprinsen-price-monitor' ); ?></p>
		<form method="post" style="margin:16px 0;">
			<?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?>
			<input type="hidden" name="lpm_discovery_action" value="add_products_by_sku" />
			<label for="lpm_sku_list"><strong><?php esc_html_e( 'Add products by SKU, product ID, or variation ID', 'lilleprinsen-price-monitor' ); ?></strong></label>
			<textarea id="lpm_sku_list" name="sku_list" class="large-text" rows="3" placeholder="SKU-1&#10;12345&#10;VAR-SKU"></textarea>
			<p><button class="button button-primary"><?php esc_html_e( 'Add products', 'lilleprinsen-price-monitor' ); ?></button></p>
		</form>
		<form method="post" style="margin:12px 0;">
			<?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?>
			<input type="hidden" name="lpm_discovery_action" value="test_gtin_source" />
			<button class="button"><?php esc_html_e( 'Test EAN/GTIN source', 'lilleprinsen-price-monitor' ); ?></button>
		</form>
		<form method="get" style="margin:12px 0;"><input type="hidden" name="page" value="lpm-competitor-prices" /><input type="hidden" name="view" value="products" /><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search selected products', 'lilleprinsen-price-monitor' ); ?>" /> <button class="button"><?php esc_html_e( 'Search', 'lilleprinsen-price-monitor' ); ?></button></form>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Product', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'SKU', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'EAN/GTIN', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'EAN source', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Brand', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Pending suggestions', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Last discovery run', 'lilleprinsen-price-monitor' ); ?></th><th></th></tr></thead><tbody>
		<?php foreach ( $products as $product ) : $edit_id = (int) ( $product->variation_id ?: $product->product_id ); ?>
			<tr><td><a href="<?php echo esc_url( get_edit_post_link( $edit_id ) ); ?>"><?php echo esc_html( get_the_title( $edit_id ) ); ?></a></td><td><?php echo esc_html( (string) $product->sku ); ?></td><td><?php echo esc_html( (string) $product->gtin ); ?></td><td><?php echo esc_html( (string) $product->gtin_source ); ?></td><td><?php echo esc_html( (string) $product->brand ); ?></td><td><?php echo esc_html( (string) ( $counts[ (int) $product->id ] ?? 0 ) ); ?></td><td><?php echo esc_html( (string) ( $product->last_discovery_at ?: __( 'Not run yet', 'lilleprinsen-price-monitor' ) ) ); ?></td><td><form method="post"><?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?><input type="hidden" name="lpm_discovery_action" value="remove_product" /><input type="hidden" name="discovery_product_id" value="<?php echo esc_attr( (string) $product->id ); ?>" /><button class="button-link-delete"><?php esc_html_e( 'Remove', 'lilleprinsen-price-monitor' ); ?></button></form></td></tr>
		<?php endforeach; ?>
		<?php if ( empty( $products ) ) : ?><tr><td colspan="8"><?php esc_html_e( 'No products selected yet.', 'lilleprinsen-price-monitor' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<?php $this->pagination( $total, $page, 'products', $search ); ?>
		<?php
	}

	/** Competitors/test tab. */
	private function render_competitors(): void {
		$competitors = $this->repository->get_competitors( 1, 200 );
		?>
		<h2><?php esc_html_e( 'Find Matches', 'lilleprinsen-price-monitor' ); ?></h2>
		<form method="post" style="max-width:900px;">
			<?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?><input type="hidden" name="lpm_discovery_action" value="test_product_page" />
			<table class="form-table" role="presentation"><tr><th><label for="competitor_id"><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></label></th><td><?php $this->competitor_select( 'competitor_id', $competitors ); ?></td></tr><tr><th><label for="competitor_name"><?php esc_html_e( 'Competitor name', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="competitor_name" name="competitor_name" type="text" class="regular-text" /></td></tr><tr><th><label for="competitor_domain"><?php esc_html_e( 'Website/domain', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="competitor_domain" name="competitor_domain" type="text" class="regular-text" placeholder="example.no" /></td></tr><tr><th><label for="product_url"><?php esc_html_e( 'Add example product URL', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="product_url" name="product_url" type="url" class="large-text" required /></td></tr></table>
			<p><button class="button button-primary"><?php esc_html_e( 'Test Product Page', 'lilleprinsen-price-monitor' ); ?></button></p>
		</form>
		<?php $this->render_last_test(); ?>
		<h3 id="lpm-add-product-source"><?php esc_html_e( 'Add page with many products', 'lilleprinsen-price-monitor' ); ?></h3>
		<form method="post" style="max-width:900px;">
			<?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?><input type="hidden" name="lpm_discovery_action" value="add_seed_url" />
			<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></th><td><?php $this->competitor_select( 'seed_competitor_id', $competitors, false ); ?></td></tr><tr><th><?php esc_html_e( 'Source type', 'lilleprinsen-price-monitor' ); ?></th><td><select name="source_type"><option value="listing"><?php esc_html_e( 'Page with many products', 'lilleprinsen-price-monitor' ); ?></option><option value="product"><?php esc_html_e( 'Example product URL', 'lilleprinsen-price-monitor' ); ?></option><option value="sitemap"><?php esc_html_e( 'Sitemap URL', 'lilleprinsen-price-monitor' ); ?></option></select></td></tr><tr><th><?php esc_html_e( 'URL', 'lilleprinsen-price-monitor' ); ?></th><td><input name="seed_url" type="url" class="large-text" required /></td></tr></table>
			<details><summary><?php esc_html_e( 'Advanced Settings', 'lilleprinsen-price-monitor' ); ?></summary><p><label><?php esc_html_e( 'Include URL patterns', 'lilleprinsen-price-monitor' ); ?><input name="include_patterns" class="large-text" /></label></p><p><label><?php esc_html_e( 'Exclude URL patterns', 'lilleprinsen-price-monitor' ); ?><input name="exclude_patterns" class="large-text" /></label></p><p><label><?php esc_html_e( 'Product URL patterns', 'lilleprinsen-price-monitor' ); ?><input name="product_url_patterns" class="large-text" /></label></p></details>
			<p><button class="button button-primary"><?php esc_html_e( 'Add source', 'lilleprinsen-price-monitor' ); ?></button></p>
		</form>
		<h3><?php esc_html_e( 'Competitors', 'lilleprinsen-price-monitor' ); ?></h3>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Name', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Domain', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Seed URLs', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th></tr></thead><tbody>
		<?php foreach ( $competitors as $competitor ) : $seeds = $this->discovery_repository->get_seed_urls_for_competitor( (int) $competitor['id'] ); ?>
			<tr><td><?php echo esc_html( $competitor['name'] ); ?></td><td><?php echo esc_html( (string) $competitor['domain'] ); ?></td><td><?php echo esc_html( (string) count( $seeds ) ); ?></td><td><form method="post" style="display:inline-block;"><?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?><input type="hidden" name="lpm_discovery_action" value="run_small_discovery" /><input type="hidden" name="competitor_id" value="<?php echo esc_attr( (string) $competitor['id'] ); ?>" /><button class="button"><?php esc_html_e( 'Scan monitored SKUs', 'lilleprinsen-price-monitor' ); ?></button></form></td></tr>
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
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Our product', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Our identifiers', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Competitor product', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Price / stock', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Why this match was suggested', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Confidence', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th></tr></thead><tbody>
		<?php foreach ( $suggestions as $suggestion ) : $product = $this->discovery_repository->get_discovery_product( (int) $suggestion->discovery_product_id ); $discovered = $this->discovery_repository->get_discovered_product( (int) $suggestion->discovered_product_id ); ?>
			<tr><td><a href="<?php echo esc_url( get_edit_post_link( (int) $suggestion->product_id ) ); ?>"><?php echo esc_html( get_the_title( (int) $suggestion->product_id ) ); ?></a></td><td><?php echo esc_html( $product ? 'SKU: ' . $product->sku . ' EAN: ' . $product->gtin . ' MPN: ' . $product->mpn : '' ); ?></td><td><?php echo esc_html( $discovered ? (string) $discovered->title : '' ); ?><br><a href="<?php echo esc_url( $suggestion->competitor_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open competitor page', 'lilleprinsen-price-monitor' ); ?></a></td><td><?php echo esc_html( $discovered ? $this->effective_price( $discovered ) . ' ' . $discovered->currency . ' / ' . $discovered->stock_status : '' ); ?></td><td><?php echo esc_html( (string) $suggestion->explanation ); ?></td><td><strong><?php echo esc_html( (string) $suggestion->confidence_label ); ?></strong></td><td><?php $this->suggestion_buttons( $suggestion ); ?></td></tr>
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
			<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Where is EAN/GTIN stored?', 'lilleprinsen-price-monitor' ); ?></th><td><select name="discovery_gtin_source"><?php foreach ( $this->settings->gtin_source_options() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['discovery_gtin_source'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'This is used for selected products, data quality, matching and suggestions.', 'lilleprinsen-price-monitor' ); ?></p></td></tr><tr><th><label for="gtin_meta"><?php esc_html_e( 'EAN/GTIN meta key', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="gtin_meta" type="text" name="discovery_gtin_meta_key" value="<?php echo esc_attr( (string) $settings['discovery_gtin_meta_key'] ); ?>" /><p class="description"><?php esc_html_e( 'Examples: _alg_ean, _wpm_gtin_code, _global_unique_id, ean, gtin, barcode', 'lilleprinsen-price-monitor' ); ?></p></td></tr><tr><th><?php esc_html_e( 'Discovery schedule', 'lilleprinsen-price-monitor' ); ?></th><td><label><input type="checkbox" name="discovery_enabled" value="1" <?php checked( ! empty( $settings['discovery_enabled'] ) ); ?> /> <?php esc_html_e( 'Allow weekly discovery jobs', 'lilleprinsen-price-monitor' ); ?></label></td></tr><tr><th><?php esc_html_e( 'Scan selected SKUs', 'lilleprinsen-price-monitor' ); ?></th><td><label><input type="checkbox" name="discovery_sku_scan_enabled" value="1" <?php checked( ! empty( $settings['discovery_sku_scan_enabled'] ) ); ?> /> <?php esc_html_e( 'Search competitor websites for the SKUs in Products to Monitor.', 'lilleprinsen-price-monitor' ); ?></label><p class="description"><?php esc_html_e( 'This only uses products you selected, never the full catalog.', 'lilleprinsen-price-monitor' ); ?></p></td></tr><tr><th><?php esc_html_e( 'Crawl competitor pages', 'lilleprinsen-price-monitor' ); ?></th><td><label><input type="checkbox" name="discovery_sku_crawl_enabled" value="1" <?php checked( ! empty( $settings['discovery_sku_crawl_enabled'] ) ); ?> /> <?php esc_html_e( 'Start from the competitor website and added source pages, then look for selected SKUs.', 'lilleprinsen-price-monitor' ); ?></label><p class="description"><?php esc_html_e( 'Small and safe by default. It follows same-domain links only and queues possible product pages for review.', 'lilleprinsen-price-monitor' ); ?></p></td></tr><tr><th><?php esc_html_e( 'Limits', 'lilleprinsen-price-monitor' ); ?></th><td><label><?php esc_html_e( 'Crawl pages', 'lilleprinsen-price-monitor' ); ?> <input type="number" name="discovery_max_crawl_pages_per_run" min="1" max="50" value="<?php echo esc_attr( (string) $settings['discovery_max_crawl_pages_per_run'] ); ?>" /></label> <label><?php esc_html_e( 'Selected SKUs per scan', 'lilleprinsen-price-monitor' ); ?> <input type="number" name="discovery_max_sku_searches_per_run" min="1" max="200" value="<?php echo esc_attr( (string) $settings['discovery_max_sku_searches_per_run'] ); ?>" /></label> <label><?php esc_html_e( 'Search attempts per SKU', 'lilleprinsen-price-monitor' ); ?> <input type="number" name="discovery_search_urls_per_sku" min="1" max="10" value="<?php echo esc_attr( (string) $settings['discovery_search_urls_per_sku'] ); ?>" /></label> <label><?php esc_html_e( 'Product pages', 'lilleprinsen-price-monitor' ); ?> <input type="number" name="discovery_max_product_pages_per_run" min="1" max="500" value="<?php echo esc_attr( (string) $settings['discovery_max_product_pages_per_run'] ); ?>" /></label> <label><?php esc_html_e( 'Requests per batch', 'lilleprinsen-price-monitor' ); ?> <input type="number" name="discovery_max_requests_per_batch" min="1" max="100" value="<?php echo esc_attr( (string) $settings['discovery_max_requests_per_batch'] ); ?>" /></label> <label><?php esc_html_e( 'Delay seconds', 'lilleprinsen-price-monitor' ); ?> <input type="number" name="discovery_request_delay_seconds" min="0" max="30" value="<?php echo esc_attr( (string) $settings['discovery_request_delay_seconds'] ); ?>" /></label></td></tr><tr><th><?php esc_html_e( 'Safety', 'lilleprinsen-price-monitor' ); ?></th><td><label><input type="checkbox" name="discovery_same_domain_only" value="1" <?php checked( ! empty( $settings['discovery_same_domain_only'] ) ); ?> /> <?php esc_html_e( 'Only test URLs on the competitor website by default', 'lilleprinsen-price-monitor' ); ?></label></td></tr></table>
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
			$competitor_id = $this->repository->add_competitor( array( 'name' => $name, 'domain' => $domain, 'enabled' => 1 ) );
			$competitor    = $competitor_id > 0 ? $this->repository->get_competitor( $competitor_id ) : null;
		}
		if ( ! $competitor ) {
			$this->set_notice( __( 'The competitor profile could not be saved.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}
		$result          = $this->extractor->test_url( $url, $competitor );
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
		$monitored_id = $monitored ? (int) $monitored['id'] : $this->repository->add_monitored_product( (int) $suggestion->product_id, $product ? (string) $product->sku : '' );
		if ( $monitored_id <= 0 ) {
			$this->set_notice( __( 'The product could not be added to monitoring.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}
		$match_type    = 'High confidence' === (string) $suggestion->confidence_label ? 'exact' : 'similar';
		$existing_link = $this->repository->get_competitor_link_by_url( $monitored_id, (string) $suggestion->competitor_url );
		$data = array( 'monitored_product_id' => $monitored_id, 'competitor_id' => (int) $suggestion->competitor_id, 'competitor_name' => $competitor['name'] ?? '', 'competitor_url' => (string) $suggestion->competitor_url, 'match_type' => $match_type, 'enabled' => 1, 'is_primary' => 0 );
		$link_id = $existing_link ? (int) $existing_link['id'] : $this->repository->add_competitor_link( $data );
		if ( $existing_link ) {
			$this->repository->update_competitor_link( $link_id, $data );
		}
		$this->discovery_repository->approve_suggestion( $id, get_current_user_id(), $link_id );
		$this->set_notice( __( 'Suggestion approved. The competitor URL is now part of regular price monitoring.', 'lilleprinsen-price-monitor' ) );
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
		$fields = array( 'title' => __( 'Product title', 'lilleprinsen-price-monitor' ), 'sku' => __( 'SKU', 'lilleprinsen-price-monitor' ), 'gtin' => __( 'EAN/GTIN', 'lilleprinsen-price-monitor' ), 'mpn' => __( 'MPN', 'lilleprinsen-price-monitor' ), 'brand' => __( 'Brand', 'lilleprinsen-price-monitor' ), 'regular_price' => __( 'Regular price', 'lilleprinsen-price-monitor' ), 'sale_price' => __( 'Sale price', 'lilleprinsen-price-monitor' ), 'currency' => __( 'Currency', 'lilleprinsen-price-monitor' ), 'stock_status' => __( 'Stock status', 'lilleprinsen-price-monitor' ), 'image_url' => __( 'Image', 'lilleprinsen-price-monitor' ), 'canonical_url' => __( 'Canonical URL', 'lilleprinsen-price-monitor' ) );
		$sources          = is_array( $this->last_test['sources'] ?? null ) ? $this->last_test['sources'] : array();
		$competitor_id    = absint( $this->last_test['competitor_id'] ?? 0 );
		$suggestion_count = absint( $this->last_test['suggestion_count'] ?? 0 );
		$success          = ! empty( $this->last_test['success'] );
		$suggestions_url  = add_query_arg( array( 'page' => 'lpm-competitor-prices', 'view' => 'suggestions' ), admin_url( 'admin.php' ) );
		$products_url     = add_query_arg( array( 'page' => 'lpm-competitor-prices', 'view' => 'products' ), admin_url( 'admin.php' ) );
		?>
		<h3><?php esc_html_e( 'Detected values', 'lilleprinsen-price-monitor' ); ?></h3><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Field', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Detected value', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Source', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Confidence', 'lilleprinsen-price-monitor' ); ?></th></tr></thead><tbody><?php foreach ( $fields as $key => $label ) : ?><tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( (string) ( $this->last_test[ $key ] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $sources[ $key ] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $this->last_test['confidence_status'] ?? '' ) ); ?></td></tr><?php endforeach; ?></tbody></table>
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
