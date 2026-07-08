<?php
/**
 * Admin app shell template.
 *
 * @package LilleprinsenPriceMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="wrap lpm-wrap">
	<header class="lpm-header">
		<div>
			<h1><?php esc_html_e( 'Lilleprinsen Price Monitor', 'lilleprinsen-price-monitor' ); ?></h1>
			<p><?php esc_html_e( 'Admin-only competitor price monitoring foundation for WooCommerce.', 'lilleprinsen-price-monitor' ); ?></p>
		</div>
		<?php $this->render_status_pill( ! empty( $settings['dry_run_mode'] ) ? __( 'Dry-run mode', 'lilleprinsen-price-monitor' ) : __( 'Dry-run disabled', 'lilleprinsen-price-monitor' ), ! empty( $settings['dry_run_mode'] ) ? 'ok' : 'danger' ); ?>
	</header>

	<?php if ( isset( $_GET['lpm_settings_saved'] ) && '1' === sanitize_key( wp_unslash( $_GET['lpm_settings_saved'] ) ) ) : ?>
		<div class="lpm-notice">
			<?php esc_html_e( 'Settings saved.', 'lilleprinsen-price-monitor' ); ?>
		</div>
	<?php endif; ?>
	<?php $this->render_admin_notices(); ?>

	<nav class="lpm-tabs" aria-label="<?php esc_attr_e( 'Price Monitor sections', 'lilleprinsen-price-monitor' ); ?>">
		<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
			<a
				class="lpm-tab <?php echo esc_attr( $active_tab === $tab_key ? 'is-active' : '' ); ?>"
				href="<?php echo esc_url( add_query_arg( array( 'page' => \Lilleprinsen\PriceMonitor\Admin\AdminPage::SLUG, 'tab' => $tab_key ), admin_url( 'admin.php' ) ) ); ?>"
				data-lpm-tab-target="<?php echo esc_attr( $tab_key ); ?>"
				aria-selected="<?php echo esc_attr( $active_tab === $tab_key ? 'true' : 'false' ); ?>"
			>
				<?php echo esc_html( $tab_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="lpm-panels">
		<section class="lpm-panel <?php echo esc_attr( 'dashboard' === $active_tab ? 'is-active' : '' ); ?>" data-lpm-tab-panel="dashboard">
			<?php
			if ( 'dashboard' === $active_tab ) {
				$this->render_dashboard( $counts, $settings, $table_status, $woocommerce_active, $competitor_strategy ?? array() );
			}
			?>
		</section>

		<section class="lpm-panel <?php echo esc_attr( 'products' === $active_tab ? 'is-active' : '' ); ?>" data-lpm-tab-panel="products">
			<?php
			if ( 'products' === $active_tab ) {
				$this->render_products();
			}
			?>
		</section>

		<section class="lpm-panel <?php echo esc_attr( 'approvals' === $active_tab ? 'is-active' : '' ); ?>" data-lpm-tab-panel="approvals">
			<?php
			if ( 'approvals' === $active_tab ) {
				$this->render_approvals();
			}
			?>
		</section>

		<section class="lpm-panel <?php echo esc_attr( 'competitors' === $active_tab ? 'is-active' : '' ); ?>" data-lpm-tab-panel="competitors">
			<?php
			if ( 'competitors' === $active_tab ) {
				$this->render_competitors();
			}
			?>
		</section>

		<section class="lpm-panel <?php echo esc_attr( 'settings_logs' === $active_tab ? 'is-active' : '' ); ?>" data-lpm-tab-panel="settings_logs">
			<?php
			if ( 'settings_logs' === $active_tab ) {
				$this->render_settings_logs( $settings, $table_status );
			}
			?>
		</section>
	</div>

	<div class="lpm-drawer-backdrop" data-lpm-drawer-close hidden></div>
	<aside class="lpm-drawer" aria-hidden="true" aria-label="<?php esc_attr_e( 'Product details', 'lilleprinsen-price-monitor' ); ?>">
		<header class="lpm-drawer-header">
			<div>
				<p class="lpm-drawer-kicker"><?php esc_html_e( 'Monitored product', 'lilleprinsen-price-monitor' ); ?></p>
				<h2 data-lpm-drawer-title><?php esc_html_e( 'Product details', 'lilleprinsen-price-monitor' ); ?></h2>
			</div>
			<button type="button" class="button-link lpm-drawer-close" data-lpm-drawer-close aria-label="<?php esc_attr_e( 'Close product details', 'lilleprinsen-price-monitor' ); ?>">×</button>
		</header>
		<nav class="lpm-drawer-tabs" aria-label="<?php esc_attr_e( 'Product detail sections', 'lilleprinsen-price-monitor' ); ?>">
			<button type="button" class="is-active" data-lpm-drawer-tab="summary"><?php esc_html_e( 'Summary', 'lilleprinsen-price-monitor' ); ?></button>
			<button type="button" data-lpm-drawer-tab="competitors"><?php esc_html_e( 'Competitors', 'lilleprinsen-price-monitor' ); ?></button>
			<button type="button" data-lpm-drawer-tab="rules"><?php esc_html_e( 'Rules', 'lilleprinsen-price-monitor' ); ?></button>
			<button type="button" data-lpm-drawer-tab="history"><?php esc_html_e( 'History', 'lilleprinsen-price-monitor' ); ?></button>
			<button type="button" data-lpm-drawer-tab="suggestions"><?php esc_html_e( 'Suggestions', 'lilleprinsen-price-monitor' ); ?></button>
			<button type="button" data-lpm-drawer-tab="logs"><?php esc_html_e( 'Logs', 'lilleprinsen-price-monitor' ); ?></button>
		</nav>
		<div class="lpm-drawer-body" data-lpm-drawer-body>
			<p class="lpm-empty"><?php esc_html_e( 'Select a monitored product to load details.', 'lilleprinsen-price-monitor' ); ?></p>
		</div>
	</aside>
	<div class="lpm-toast-region" aria-live="polite" aria-atomic="true"></div>
</div>
