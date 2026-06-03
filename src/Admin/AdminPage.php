<?php
/**
 * Admin page renderer.
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

	public function render(): void {
		if ( ! Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access Lilleprinsen Price Monitor.', 'lilleprinsen-price-monitor' ) );
		}

		$tabs               = $this->get_tabs();
		$active_tab         = $this->get_active_tab( $tabs );
		$settings           = $this->settings->get_all();
		$counts             = $this->repository->get_dashboard_counts();
		$table_status       = $this->repository->get_table_status();
		$woocommerce_active = Plugin::is_woocommerce_active();

		include LPM_PLUGIN_DIR . 'templates/admin/app-shell.php';
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
	 * @param array<string, int>          $counts Dashboard counts.
	 * @param array<string, mixed>        $settings Current settings.
	 * @param array<string, mixed>        $table_status Schema status.
	 */
	public function render_dashboard( array $counts, array $settings, array $table_status, bool $woocommerce_active ): void {
		?>
		<div class="lpm-grid lpm-grid-summary">
			<?php
			$this->render_summary_card( __( 'Monitored products', 'lilleprinsen-price-monitor' ), $counts['monitored_products'], __( 'Selected products only', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Active competitor links', 'lilleprinsen-price-monitor' ), $counts['active_competitor_links'], __( 'Stored direct URLs', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Pending suggestions', 'lilleprinsen-price-monitor' ), $counts['pending_suggestions'], __( 'Awaiting review', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Failed logs/checks', 'lilleprinsen-price-monitor' ), $counts['failed_logs'], __( 'Error-level audit entries', 'lilleprinsen-price-monitor' ) );
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
					</tbody>
				</table>
			</section>

			<section class="lpm-card">
				<div class="lpm-card-header">
					<h2><?php esc_html_e( 'Needs attention', 'lilleprinsen-price-monitor' ); ?></h2>
					<span class="lpm-pill lpm-pill-muted"><?php esc_html_e( 'Placeholder', 'lilleprinsen-price-monitor' ); ?></span>
				</div>
				<p><?php esc_html_e( 'This panel will highlight pending suggestions, failed checks, and stale monitored products once those workflows are added.', 'lilleprinsen-price-monitor' ); ?></p>
				<ul class="lpm-check-list">
					<li><?php esc_html_e( 'No competitor checks run in this foundation version.', 'lilleprinsen-price-monitor' ); ?></li>
					<li><?php esc_html_e( 'No WooCommerce prices are updated.', 'lilleprinsen-price-monitor' ); ?></li>
					<li><?php esc_html_e( 'All monitoring data is reserved for custom tables.', 'lilleprinsen-price-monitor' ); ?></li>
				</ul>
			</section>
		</div>
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
		$tables = isset( $table_status['tables'] ) && is_array( $table_status['tables'] ) ? $table_status['tables'] : array();
		?>
		<section class="lpm-card">
			<div class="lpm-card-header">
				<h2><?php esc_html_e( 'Schema and log status', 'lilleprinsen-price-monitor' ); ?></h2>
				<span class="lpm-pill lpm-pill-muted"><?php echo esc_html( Schema::VERSION ); ?></span>
			</div>
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
		</section>
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
}
