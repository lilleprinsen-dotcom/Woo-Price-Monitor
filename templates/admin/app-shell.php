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
				$this->render_dashboard( $counts, $settings, $table_status, $woocommerce_active );
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

		<section class="lpm-panel <?php echo esc_attr( 'history' === $active_tab ? 'is-active' : '' ); ?>" data-lpm-tab-panel="history">
			<?php
			if ( 'history' === $active_tab ) {
				$this->render_history();
			}
			?>
		</section>

		<section class="lpm-panel <?php echo esc_attr( 'import_export' === $active_tab ? 'is-active' : '' ); ?>" data-lpm-tab-panel="import_export">
			<?php
			if ( 'import_export' === $active_tab ) {
				$this->render_import_export();
			}
			?>
		</section>

		<section class="lpm-panel <?php echo esc_attr( 'settings' === $active_tab ? 'is-active' : '' ); ?>" data-lpm-tab-panel="settings">
			<?php
			if ( 'settings' === $active_tab ) {
				$this->render_settings( $settings );
			}
			?>
		</section>

		<section class="lpm-panel <?php echo esc_attr( 'logs' === $active_tab ? 'is-active' : '' ); ?>" data-lpm-tab-panel="logs">
			<?php
			if ( 'logs' === $active_tab ) {
				$this->render_logs( $table_status );
			}
			?>
		</section>
	</div>
</div>
