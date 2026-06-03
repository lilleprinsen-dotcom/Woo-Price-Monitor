<?php
/**
 * Admin page renderer and action controller.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Database\Schema;
use Lilleprinsen\PriceMonitor\Plugin;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminPage {
	public const SLUG = 'lilleprinsen-price-monitor';

	private Repository $repository;

	private Settings $settings;

	public function __construct( Repository $repository, Settings $settings ) {
		$this->repository = $repository;
		$this->settings   = $settings;
	}

	public function handle_actions(): void {
		if ( empty( $_POST['lpm_action'] ) ) {
			return;
		}

		if ( ! Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage Lilleprinsen Price Monitor.', 'lilleprinsen-price-monitor' ) );
		}

		check_admin_referer( 'lpm_admin_action', 'lpm_nonce' );

		$action = sanitize_key( wp_unslash( $_POST['lpm_action'] ) );

		switch ( $action ) {
			case 'add_monitored_product':
				$this->handle_add_monitored_product();
				break;
			case 'enable_monitored':
			case 'disable_monitored':
			case 'remove_monitored':
				$this->handle_monitored_status_action( $action );
				break;
			case 'add_competitor_link':
			case 'update_competitor_link':
				$this->handle_save_competitor_link( $action );
				break;
			case 'enable_competitor_link':
			case 'disable_competitor_link':
			case 'delete_competitor_link':
				$this->handle_competitor_link_action( $action );
				break;
			default:
				$this->redirect_to_tab( 'dashboard', 'unknown_action' );
		}
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
		$woocommerce_active = Plugin::is_woocommerce_active();

		if ( 'dashboard' === $active_tab ) {
			$counts       = $this->repository->get_dashboard_counts();
			$table_status = $this->repository->get_table_status();
		} elseif ( 'logs' === $active_tab ) {
			$table_status = $this->repository->get_table_status();
		}

		include LPM_PLUGIN_DIR . 'templates/admin/app-shell.php';
	}

	public function render_admin_notices(): void {
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
			'dashboard'   => __( 'Dashboard', 'lilleprinsen-price-monitor' ),
			'products'    => __( 'Products', 'lilleprinsen-price-monitor' ),
			'approvals'   => __( 'Approvals', 'lilleprinsen-price-monitor' ),
			'competitors' => __( 'Competitors', 'lilleprinsen-price-monitor' ),
			'settings'    => __( 'Settings', 'lilleprinsen-price-monitor' ),
			'logs'        => __( 'Logs', 'lilleprinsen-price-monitor' ),
		);
	}

	/**
	 * @param array<string, string> $tabs Registered tabs.
	 */
	private function get_active_tab( array $tabs ): string {
		$requested_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		return array_key_exists( $requested_tab, $tabs ) ? $requested_tab : 'dashboard';
	}

	/**
	 * @return array<string, int>
	 */
	private function get_empty_dashboard_counts(): array {
		return array(
			'monitored_products'          => 0,
			'active_competitor_links'     => 0,
			'pending_suggestions'         => 0,
			'failed_logs'                 => 0,
			'active_price_match_sessions' => 0,
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
	 * @param array<string, int>   $counts Dashboard counts.
	 * @param array<string, mixed> $settings Current settings.
	 * @param array<string, mixed> $table_status Schema status.
	 */
	public function render_dashboard( array $counts, array $settings, array $table_status, bool $woocommerce_active ): void {
		?>
		<div class="lpm-grid lpm-grid-summary">
			<?php
			$this->render_summary_card( __( 'Monitored products', 'lilleprinsen-price-monitor' ), $counts['monitored_products'], __( 'Selected products only', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Active competitor links', 'lilleprinsen-price-monitor' ), $counts['active_competitor_links'], __( 'Stored direct URLs', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Pending suggestions', 'lilleprinsen-price-monitor' ), $counts['pending_suggestions'], __( 'Awaiting review', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Failed logs/checks', 'lilleprinsen-price-monitor' ), $counts['failed_logs'], __( 'Error-level audit entries', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Active price match sessions', 'lilleprinsen-price-monitor' ), $counts['active_price_match_sessions'], __( 'Dry-run recovery state', 'lilleprinsen-price-monitor' ) );
			?>
		</div>

		<div class="lpm-grid lpm-grid-two">
			<section class="lpm-card">
				<div class="lpm-card-header">
					<h2><?php esc_html_e( 'System status', 'lilleprinsen-price-monitor' ); ?></h2>
				</div>
				<table class="lpm-status-table">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'WooCommerce', 'lilleprinsen-price-monitor' ); ?></th>
							<td><?php $this->render_status_pill( $woocommerce_active ? __( 'Active', 'lilleprinsen-price-monitor' ) : __( 'Inactive', 'lilleprinsen-price-monitor' ), $woocommerce_active ? 'ok' : 'warning' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Plugin version', 'lilleprinsen-price-monitor' ); ?></th>
							<td><?php echo esc_html( LPM_VERSION ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'DB schema version', 'lilleprinsen-price-monitor' ); ?></th>
							<td><?php echo esc_html( (string) $table_status['schema_version'] ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Dry-run mode', 'lilleprinsen-price-monitor' ); ?></th>
							<td><?php $this->render_status_pill( ! empty( $settings['dry_run_mode'] ) ? __( 'Enabled', 'lilleprinsen-price-monitor' ) : __( 'Disabled', 'lilleprinsen-price-monitor' ), ! empty( $settings['dry_run_mode'] ) ? 'ok' : 'danger' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Active price match sessions', 'lilleprinsen-price-monitor' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( (int) $counts['active_price_match_sessions'] ) ); ?></td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="lpm-card">
				<div class="lpm-card-header">
					<h2><?php esc_html_e( 'Needs attention', 'lilleprinsen-price-monitor' ); ?></h2>
					<span class="lpm-pill lpm-pill-muted"><?php esc_html_e( 'Dry-run', 'lilleprinsen-price-monitor' ); ?></span>
				</div>
				<p><?php esc_html_e( 'This panel will highlight pending suggestions, failed checks, stale monitored products, and recovery sessions once those workflows are added.', 'lilleprinsen-price-monitor' ); ?></p>
				<ul class="lpm-check-list">
					<li><?php esc_html_e( 'No competitor checks run in this version.', 'lilleprinsen-price-monitor' ); ?></li>
					<li><?php esc_html_e( 'No WooCommerce prices are updated.', 'lilleprinsen-price-monitor' ); ?></li>
					<li><?php esc_html_e( 'Price recovery settings only affect future dry-run suggestions.', 'lilleprinsen-price-monitor' ); ?></li>
				</ul>
			</section>
		</div>
		<?php
	}

	public function render_products(): void {
		$search_query   = $this->get_search_query();
		$search_results = '' !== $search_query ? $this->search_products( $search_query ) : array();
		$page           = $this->get_positive_query_arg( 'lpm_products_page', 1 );
		$per_page       = (int) $this->settings->get( 'rows_per_page', 25 );
		$rows           = $this->repository->get_monitored_products( $page, $per_page );
		$total          = $this->repository->count_monitored_products();
		$link_counts    = $this->repository->count_competitor_links_for_monitored_products( wp_list_pluck( $rows, 'id' ) );
		?>
		<div class="lpm-grid lpm-grid-two lpm-products-layout">
			<section class="lpm-card">
				<div class="lpm-card-header">
					<h2><?php esc_html_e( 'Find product', 'lilleprinsen-price-monitor' ); ?></h2>
					<span class="lpm-pill lpm-pill-muted"><?php esc_html_e( 'Limit 20', 'lilleprinsen-price-monitor' ); ?></span>
				</div>

				<p><?php esc_html_e( 'Search for a product by name, SKU or ID.', 'lilleprinsen-price-monitor' ); ?></p>

				<form method="get" class="lpm-inline-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
					<input type="hidden" name="tab" value="products" />
					<label class="screen-reader-text" for="lpm-product-search"><?php esc_html_e( 'Search products', 'lilleprinsen-price-monitor' ); ?></label>
					<input id="lpm-product-search" type="search" name="lpm_product_search" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Product name, SKU or ID', 'lilleprinsen-price-monitor' ); ?>" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Search', 'lilleprinsen-price-monitor' ); ?></button>
				</form>

				<?php if ( '' !== $search_query ) : ?>
					<?php $this->render_product_search_results( $search_results, $search_query ); ?>
				<?php endif; ?>
			</section>

			<section class="lpm-card">
				<div class="lpm-card-header">
					<h2><?php esc_html_e( 'Workflow guardrails', 'lilleprinsen-price-monitor' ); ?></h2>
					<?php $this->render_status_pill( __( 'Admin only', 'lilleprinsen-price-monitor' ), 'ok' ); ?>
				</div>
				<ul class="lpm-check-list">
					<li><?php esc_html_e( 'Search only runs after an admin submits a query.', 'lilleprinsen-price-monitor' ); ?></li>
					<li><?php esc_html_e( 'The full catalog is never loaded into a dropdown.', 'lilleprinsen-price-monitor' ); ?></li>
					<li><?php esc_html_e( 'Adding a product stores only selected monitoring rows in custom tables.', 'lilleprinsen-price-monitor' ); ?></li>
					<li><?php esc_html_e( 'No scraping, scheduling, or price updates happen here.', 'lilleprinsen-price-monitor' ); ?></li>
				</ul>
			</section>
		</div>

		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<h2><?php esc_html_e( 'Existing monitored products', 'lilleprinsen-price-monitor' ); ?></h2>
				<span class="lpm-pill lpm-pill-muted"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</div>
			<?php $this->render_monitored_products_table( $rows, $link_counts ); ?>
			<?php $this->render_pagination( $total, $page, $per_page, 'lpm_products_page', array( 'tab' => 'products' ) ); ?>
		</section>
		<?php
	}

	public function render_competitors(): void {
		$monitored_product_id = $this->get_positive_query_arg( 'monitored_product_id', 0 );

		if ( 0 >= $monitored_product_id ) {
			$this->render_competitor_picker();
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
				<?php $this->render_competitor_form( $monitored_product_id, $editing_link ); ?>
			</section>
		</div>

		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<h2><?php esc_html_e( 'Competitor links', 'lilleprinsen-price-monitor' ); ?></h2>
				<span class="lpm-pill lpm-pill-muted"><?php echo esc_html( number_format_i18n( count( $links ) ) ); ?></span>
			</div>
			<?php $this->render_competitor_links_table( $links, $monitored_product_id ); ?>
		</section>
		<?php
	}

	public function render_placeholder_panel( string $title, string $body ): void {
		?>
		<section class="lpm-card">
			<div class="lpm-card-header">
				<h2><?php echo esc_html( $title ); ?></h2>
				<span class="lpm-pill lpm-pill-muted"><?php esc_html_e( 'Planned', 'lilleprinsen-price-monitor' ); ?></span>
			</div>
			<p><?php echo esc_html( $body ); ?></p>
			<table class="lpm-compact-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Area', 'lilleprinsen-price-monitor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'lilleprinsen-price-monitor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Safety note', 'lilleprinsen-price-monitor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php echo esc_html( $title ); ?></td>
						<td><?php $this->render_status_pill( __( 'Not implemented', 'lilleprinsen-price-monitor' ), 'muted' ); ?></td>
						<td><?php esc_html_e( 'Reserved for a later focused pull request.', 'lilleprinsen-price-monitor' ); ?></td>
					</tr>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Current settings.
	 */
	public function render_settings( array $settings ): void {
		?>
		<form method="post" class="lpm-settings-form">
			<?php wp_nonce_field( 'lpm_save_settings', 'lpm_settings_nonce' ); ?>
			<input type="hidden" name="lpm_settings_action" value="save" />

			<div class="lpm-grid lpm-grid-two">
				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'General', 'lilleprinsen-price-monitor' ); ?></h2>
					</div>
					<?php
					$this->render_checkbox_field( 'plugin_enabled', __( 'Plugin enabled', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'dry_run_mode', __( 'Dry-run mode', 'lilleprinsen-price-monitor' ), $settings, __( 'Keep this enabled while the plugin records data and suggestions only. Dry-run mode does not update WooCommerce prices.', 'lilleprinsen-price-monitor' ) );
					$this->render_text_field( 'default_currency', __( 'Default currency', 'lilleprinsen-price-monitor' ), $settings, 'NOK' );
					?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'Monitoring', 'lilleprinsen-price-monitor' ); ?></h2>
					</div>
					<?php
					$this->render_number_field( 'default_check_frequency_hours', __( 'Default check frequency (hours)', 'lilleprinsen-price-monitor' ), $settings, 1 );
					$this->render_number_field( 'max_urls_per_batch', __( 'Max URLs per batch', 'lilleprinsen-price-monitor' ), $settings, 1 );
					$this->render_number_field( 'request_timeout_seconds', __( 'Request timeout (seconds)', 'lilleprinsen-price-monitor' ), $settings, 1 );
					$this->render_checkbox_field( 'retry_failed_checks', __( 'Retry failed checks', 'lilleprinsen-price-monitor' ), $settings );
					?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'Pricing safety', 'lilleprinsen-price-monitor' ); ?></h2>
					</div>
					<?php
					$this->render_decimal_field( 'default_min_margin_percent', __( 'Default minimum margin percent', 'lilleprinsen-price-monitor' ), $settings, __( 'Leave empty until margin rules are confirmed.', 'lilleprinsen-price-monitor' ) );
					$this->render_decimal_field( 'min_price_difference_to_suggest', __( 'Minimum price difference to suggest', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_decimal_field( 'max_allowed_price_drop_percent', __( 'Max allowed price drop percent', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'require_manual_approval', __( 'Require manual approval', 'lilleprinsen-price-monitor' ), $settings, __( 'Approval is stored as workflow state only. This version does not change product prices.', 'lilleprinsen-price-monitor' ) );
					?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'Price recovery', 'lilleprinsen-price-monitor' ); ?></h2>
						<?php $this->render_status_pill( __( 'Dry-run only', 'lilleprinsen-price-monitor' ), 'ok' ); ?>
					</div>
					<p class="lpm-field-description"><?php esc_html_e( 'These settings decide what the plugin should suggest when competitor prices go up again after a price match. For now this only affects future dry-run suggestions. No WooCommerce prices are updated in this version.', 'lilleprinsen-price-monitor' ); ?></p>
					<?php
					$this->render_select_field(
						'recovery_when_competitor_increases',
						__( 'When competitor price increases', 'lilleprinsen-price-monitor' ),
						$settings,
						array(
							'do_nothing'                           => __( 'Do nothing', 'lilleprinsen-price-monitor' ),
							'suggest_only'                          => __( 'Suggest only', 'lilleprinsen-price-monitor' ),
							'suggest_match_competitor'              => __( 'Suggest matching competitor', 'lilleprinsen-price-monitor' ),
							'suggest_restore_previous_active_price' => __( 'Suggest restore previous active price', 'lilleprinsen-price-monitor' ),
							'suggest_restore_previous_regular_price' => __( 'Suggest restore previous regular price', 'lilleprinsen-price-monitor' ),
							'suggest_restore_previous_sale_price'   => __( 'Suggest restore previous sale price', 'lilleprinsen-price-monitor' ),
						)
					);
					$this->render_select_field(
						'recovery_if_competitor_still_below_previous_sale_price',
						__( 'If competitor stays below previous sale price', 'lilleprinsen-price-monitor' ),
						$settings,
						array(
							'keep_current_price'             => __( 'Keep current price', 'lilleprinsen-price-monitor' ),
							'suggest_match_competitor'       => __( 'Suggest matching competitor', 'lilleprinsen-price-monitor' ),
							'suggest_restore_previous_sale_price' => __( 'Suggest restore previous sale price', 'lilleprinsen-price-monitor' ),
							'suggest_only'                   => __( 'Suggest only', 'lilleprinsen-price-monitor' ),
						)
					);
					$this->render_select_field(
						'recovery_if_competitor_above_previous_regular_price',
						__( 'If competitor rises above previous regular price', 'lilleprinsen-price-monitor' ),
						$settings,
						array(
							'keep_current_price'                => __( 'Keep current price', 'lilleprinsen-price-monitor' ),
							'suggest_restore_previous_regular_price' => __( 'Suggest restore previous regular price', 'lilleprinsen-price-monitor' ),
							'suggest_match_competitor'          => __( 'Suggest matching competitor', 'lilleprinsen-price-monitor' ),
							'suggest_only'                      => __( 'Suggest only', 'lilleprinsen-price-monitor' ),
						)
					);
					$this->render_select_field(
						'multiple_competitor_recovery_basis',
						__( 'Multiple competitor recovery basis', 'lilleprinsen-price-monitor' ),
						$settings,
						array(
							'lowest_valid_competitor'      => __( 'Lowest valid competitor', 'lilleprinsen-price-monitor' ),
							'primary_competitor'           => __( 'Primary competitor', 'lilleprinsen-price-monitor' ),
							'all_competitors_must_increase' => __( 'All competitors must increase', 'lilleprinsen-price-monitor' ),
						)
					);
					$this->render_select_field(
						'price_match_write_mode',
						__( 'Future price match write mode', 'lilleprinsen-price-monitor' ),
						$settings,
						array(
							'regular_price'        => __( 'Regular price', 'lilleprinsen-price-monitor' ),
							'sale_price'           => __( 'Sale price', 'lilleprinsen-price-monitor' ),
							'temporary_sale_price' => __( 'Temporary sale price', 'lilleprinsen-price-monitor' ),
						)
					);
					?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'UI', 'lilleprinsen-price-monitor' ); ?></h2>
					</div>
					<?php $this->render_number_field( 'rows_per_page', __( 'Rows per page', 'lilleprinsen-price-monitor' ), $settings, 1 ); ?>
				</section>
			</div>

			<div class="lpm-form-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'lilleprinsen-price-monitor' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * @param array<string, mixed> $table_status Schema status.
	 */
	public function render_logs( array $table_status ): void {
		$filters  = $this->get_log_filters();
		$page     = $this->get_positive_query_arg( 'lpm_logs_page', 1 );
		$per_page = (int) $this->settings->get( 'rows_per_page', 25 );
		$logs     = $this->repository->get_logs( $filters, $page, $per_page );
		$total    = $this->repository->count_logs( $filters );
		?>
		<section class="lpm-card">
			<div class="lpm-card-header">
				<h2><?php esc_html_e( 'Logs', 'lilleprinsen-price-monitor' ); ?></h2>
				<span class="lpm-pill lpm-pill-muted"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</div>
			<?php $this->render_log_filters( $filters ); ?>
			<?php $this->render_logs_table( $logs ); ?>
			<?php $this->render_pagination( $total, $page, $per_page, 'lpm_logs_page', array_merge( array( 'tab' => 'logs' ), $filters ) ); ?>
		</section>

		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<h2><?php esc_html_e( 'Schema status', 'lilleprinsen-price-monitor' ); ?></h2>
				<span class="lpm-pill lpm-pill-muted"><?php echo esc_html( Schema::VERSION ); ?></span>
			</div>
			<?php $this->render_schema_status_table( $table_status ); ?>
		</section>
		<?php
	}

	private function handle_add_monitored_product(): void {
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$product    = $this->get_product( $product_id );

		if ( ! $product ) {
			$this->redirect_to_tab( 'products', 'product_not_found', array( 'lpm_notice_type' => 'error' ) );
		}

		$result = $this->repository->add_monitored_product( $product_id, $product->get_sku() );

		if ( ! empty( $result['success'] ) ) {
			$this->repository->write_log( 'info', 'monitored_product_added', __( 'Product added to monitoring.', 'lilleprinsen-price-monitor' ), array( 'monitored_product_id' => (int) $result['id'] ), $product_id );
			$this->redirect_to_tab( 'products', 'monitoring_added' );
		}

		if ( 'already_monitored' === ( $result['code'] ?? '' ) || 'monitoring_reenabled' === ( $result['code'] ?? '' ) ) {
			$this->repository->write_log( 'info', 'monitored_product_reenabled', __( 'Product monitoring was already present or re-enabled.', 'lilleprinsen-price-monitor' ), array( 'monitored_product_id' => (int) ( $result['id'] ?? 0 ) ), $product_id );
			$this->redirect_to_tab( 'products', (string) $result['code'] );
		}

		$this->repository->write_log( 'error', 'monitored_product_add_failed', __( 'Could not add product to monitoring.', 'lilleprinsen-price-monitor' ), array( 'product_id' => $product_id ), $product_id );
		$this->redirect_to_tab( 'products', 'monitoring_add_failed', array( 'lpm_notice_type' => 'error' ) );
	}

	private function handle_monitored_status_action( string $action ): void {
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

	private function handle_save_competitor_link( string $action ): void {
		$monitored_product_id = isset( $_POST['monitored_product_id'] ) ? absint( wp_unslash( $_POST['monitored_product_id'] ) ) : 0;
		$monitored_product    = $this->repository->get_monitored_product( $monitored_product_id );

		if ( ! $monitored_product ) {
			$this->redirect_to_tab( 'competitors', 'monitored_not_found', array( 'lpm_notice_type' => 'error' ) );
		}

		$name       = isset( $_POST['competitor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['competitor_name'] ) ) : '';
		$url        = isset( $_POST['competitor_url'] ) ? esc_url_raw( wp_unslash( $_POST['competitor_url'] ) ) : '';
		$match_type = isset( $_POST['match_type'] ) ? sanitize_key( wp_unslash( $_POST['match_type'] ) ) : 'unknown';
		$enabled    = ! empty( $_POST['enabled'] );

		if ( '' === $name ) {
			$this->redirect_to_competitors( $monitored_product_id, 'competitor_name_required', 'error' );
		}

		if ( ! $this->is_valid_http_url( $url ) ) {
			$this->redirect_to_competitors( $monitored_product_id, 'competitor_url_invalid', 'error' );
		}

		$data = array(
			'monitored_product_id' => $monitored_product_id,
			'competitor_name'      => $name,
			'competitor_url'       => $url,
			'match_type'           => $match_type,
			'enabled'              => $enabled ? 1 : 0,
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

	private function handle_competitor_link_action( string $action ): void {
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

	/**
	 * @return array<int, object>
	 */
	private function search_products( string $query ): array {
		if ( ! Plugin::is_woocommerce_active() || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$query    = trim( sanitize_text_field( $query ) );
		$products = array();

		if ( '' === $query ) {
			return array();
		}

		if ( is_numeric( $query ) ) {
			$this->add_product_to_search_results( absint( $query ), $products );
		}

		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$sku_product_id = wc_get_product_id_by_sku( $query );

			if ( $sku_product_id ) {
				$this->add_product_to_search_results( (int) $sku_product_id, $products );
			}
		}

		if ( function_exists( 'wc_get_products' ) && count( $products ) < 20 ) {
			$remaining = 20 - count( $products );
			$matches   = array();

			try {
				$matches = wc_get_products(
					array(
						'limit'   => $remaining,
						'status'  => array( 'publish', 'private', 'draft' ),
						'orderby' => 'title',
						'order'   => 'ASC',
						's'       => $query,
					)
				);
			} catch ( \Throwable $throwable ) {
				$this->repository->write_log(
					'error',
					'product_search_failed',
					__( 'WooCommerce product search failed.', 'lilleprinsen-price-monitor' ),
					array( 'error' => $throwable->getMessage() )
				);
			}

			if ( is_array( $matches ) ) {
				foreach ( $matches as $product ) {
					if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
						$this->add_product_object_to_search_results( $product, $products );
					}
				}
			}
		}

		return array_slice( array_values( $products ), 0, 20 );
	}

	/**
	 * @param array<int, object> $products Products keyed by product ID.
	 */
	private function add_product_to_search_results( int $product_id, array &$products ): void {
		$product = $this->get_product( $product_id );

		if ( $product ) {
			$this->add_product_object_to_search_results( $product, $products );
		}
	}

	/**
	 * @param array<int, object> $products Products keyed by product ID.
	 */
	private function add_product_object_to_search_results( object $product, array &$products ): void {
		if ( ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$product_id = (int) $product->get_id();

		if ( $product_id > 0 && ! isset( $products[ $product_id ] ) ) {
			$products[ $product_id ] = $product;
		}
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
								<td><?php echo wp_kses_post( $this->get_product_thumbnail( $product ) ); ?></td>
								<td><?php echo esc_html( $this->get_product_name( $product ) ); ?></td>
								<td><?php echo esc_html( (string) $product->get_id() ); ?></td>
								<td><?php echo esc_html( $this->get_product_sku( $product ) ); ?></td>
								<td><?php echo wp_kses_post( $this->get_product_price_html( $product ) ); ?></td>
								<td><?php echo esc_html( $this->get_product_stock_status( $product ) ); ?></td>
								<td><?php $this->render_add_monitoring_form( (int) $product->get_id() ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_monitored_products_table( array $rows, array $link_counts ): void {
		if ( empty( $rows ) ) {
			$this->render_empty_state( __( 'No products are monitored yet.', 'lilleprinsen-price-monitor' ) );
			return;
		}
		?>
		<table class="lpm-compact-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Product', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Product ID', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'SKU', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Enabled', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Priority', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Strategy', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last checked', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Competitor links', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $product = $this->get_product( (int) $row['product_id'] ); ?>
					<tr>
						<td>
							<div class="lpm-product-cell">
								<?php echo wp_kses_post( $product ? $this->get_product_thumbnail( $product ) : '' ); ?>
								<span><?php echo esc_html( $product ? $this->get_product_name( $product ) : sprintf( __( 'Product #%d', 'lilleprinsen-price-monitor' ), (int) $row['product_id'] ) ); ?></span>
							</div>
						</td>
						<td><?php echo esc_html( (string) $row['product_id'] ); ?></td>
						<td><?php echo esc_html( (string) ( $row['sku'] ?? '' ) ); ?></td>
						<td><?php $this->render_status_pill( ! empty( $row['enabled'] ) ? __( 'Yes', 'lilleprinsen-price-monitor' ) : __( 'No', 'lilleprinsen-price-monitor' ), ! empty( $row['enabled'] ) ? 'ok' : 'muted' ); ?></td>
						<td><?php echo esc_html( (string) $row['priority'] ); ?></td>
						<td><?php echo esc_html( (string) $row['strategy'] ); ?></td>
						<td><?php echo esc_html( $this->format_datetime( $row['last_checked_at'] ?? null ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $link_counts[ (int) $row['id'] ] ?? 0 ) ) ); ?></td>
						<td>
							<div class="lpm-actions">
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'competitors', 'monitored_product_id' => (int) $row['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Manage competitors', 'lilleprinsen-price-monitor' ); ?></a>
								<?php $this->render_monitored_action_form( (int) $row['id'], ! empty( $row['enabled'] ) ? 'disable_monitored' : 'enable_monitored', ! empty( $row['enabled'] ) ? __( 'Disable', 'lilleprinsen-price-monitor' ) : __( 'Enable', 'lilleprinsen-price-monitor' ) ); ?>
								<?php $this->render_monitored_action_form( (int) $row['id'], 'remove_monitored', __( 'Remove', 'lilleprinsen-price-monitor' ), 'link-delete' ); ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
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

	private function render_competitor_form( int $monitored_product_id, ?array $editing_link ): void {
		$is_edit = is_array( $editing_link );
		?>
		<form method="post" class="lpm-stacked-form">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="<?php echo esc_attr( $is_edit ? 'update_competitor_link' : 'add_competitor_link' ); ?>" />
			<input type="hidden" name="monitored_product_id" value="<?php echo esc_attr( (string) $monitored_product_id ); ?>" />
			<?php if ( $is_edit ) : ?>
				<input type="hidden" name="competitor_link_id" value="<?php echo esc_attr( (string) $editing_link['id'] ); ?>" />
			<?php endif; ?>

			<label class="lpm-field">
				<span><?php esc_html_e( 'Competitor name', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="competitor_name" required maxlength="191" value="<?php echo esc_attr( $is_edit ? (string) $editing_link['competitor_name'] : '' ); ?>" />
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
					<th scope="col"><?php esc_html_e( 'Competitor name', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'URL', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Match type', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Enabled', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last price', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last currency', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last stock status', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last checked', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last error', 'lilleprinsen-price-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $links as $link ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $link['competitor_name'] ); ?></td>
						<td><a href="<?php echo esc_url( (string) $link['competitor_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $this->shorten_text( (string) $link['competitor_url'], 48 ) ); ?></a></td>
						<td><?php echo esc_html( (string) $link['match_type'] ); ?></td>
						<td><?php $this->render_status_pill( ! empty( $link['enabled'] ) ? __( 'Yes', 'lilleprinsen-price-monitor' ) : __( 'No', 'lilleprinsen-price-monitor' ), ! empty( $link['enabled'] ) ? 'ok' : 'muted' ); ?></td>
						<td><?php echo esc_html( $this->format_nullable_value( $link['last_price'] ?? null ) ); ?></td>
						<td><?php echo esc_html( $this->format_nullable_value( $link['last_currency'] ?? null ) ); ?></td>
						<td><?php echo esc_html( $this->format_nullable_value( $link['last_stock_status'] ?? null ) ); ?></td>
						<td><?php echo esc_html( $this->format_datetime( $link['last_checked_at'] ?? null ) ); ?></td>
						<td><?php echo esc_html( $this->shorten_text( (string) ( $link['last_error'] ?? '' ), 42 ) ); ?></td>
						<td>
							<div class="lpm-actions">
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'competitors', 'monitored_product_id' => $monitored_product_id, 'competitor_link_id' => (int) $link['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'lilleprinsen-price-monitor' ); ?></a>
								<?php $this->render_competitor_action_form( (int) $link['id'], ! empty( $link['enabled'] ) ? 'disable_competitor_link' : 'enable_competitor_link', ! empty( $link['enabled'] ) ? __( 'Disable', 'lilleprinsen-price-monitor' ) : __( 'Enable', 'lilleprinsen-price-monitor' ) ); ?>
								<?php $this->render_competitor_action_form( (int) $link['id'], 'delete_competitor_link', __( 'Delete', 'lilleprinsen-price-monitor' ), 'link-delete' ); ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
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
		<form method="post" class="lpm-inline-action">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="add_monitored_product" />
			<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product_id ); ?>" />
			<button type="submit" class="button button-small"><?php esc_html_e( 'Add to monitoring', 'lilleprinsen-price-monitor' ); ?></button>
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

	private function render_summary_card( string $label, int $value, string $description ): void {
		?>
		<section class="lpm-card lpm-summary-card">
			<span class="lpm-summary-label"><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong>
			<span><?php echo esc_html( $description ); ?></span>
		</section>
		<?php
	}

	public function render_status_pill( string $label, string $status ): void {
		printf(
			'<span class="lpm-pill lpm-pill-%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( $label )
		);
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

	private function get_positive_query_arg( string $key, int $default ): int {
		if ( ! isset( $_GET[ $key ] ) ) {
			return $default;
		}

		return max( 0, absint( wp_unslash( $_GET[ $key ] ) ) );
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

	private function redirect_to_tab( string $tab, string $notice, array $extra_args = array() ): void {
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

	private function get_notice_message( string $notice ): string {
		$messages = array(
			'unknown_action'                  => __( 'Unknown action.', 'lilleprinsen-price-monitor' ),
			'product_not_found'               => __( 'Product was not found or WooCommerce is inactive.', 'lilleprinsen-price-monitor' ),
			'monitoring_added'                => __( 'Product added to monitoring.', 'lilleprinsen-price-monitor' ),
			'already_monitored'               => __( 'Product is already monitored.', 'lilleprinsen-price-monitor' ),
			'monitoring_reenabled'            => __( 'Product monitoring was re-enabled.', 'lilleprinsen-price-monitor' ),
			'monitoring_add_failed'           => __( 'Could not add product to monitoring.', 'lilleprinsen-price-monitor' ),
			'monitored_not_found'             => __( 'Monitored product was not found.', 'lilleprinsen-price-monitor' ),
			'monitoring_status_updated'       => __( 'Monitoring status updated.', 'lilleprinsen-price-monitor' ),
			'monitoring_status_failed'        => __( 'Could not update monitoring status.', 'lilleprinsen-price-monitor' ),
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
