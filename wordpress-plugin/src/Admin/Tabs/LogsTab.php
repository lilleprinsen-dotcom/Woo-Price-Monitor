<?php
/**
 * Logs tab renderer.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin\Tabs;

use Lilleprinsen\PriceMonitor\Admin\AdminViewHelpers;
use Lilleprinsen\PriceMonitor\Admin\AdminPage;
use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Database\Schema;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LogsTab extends AdminViewHelpers {
	private Repository $repository;

	private Settings $settings;

	public function __construct( Repository $repository, Settings $settings ) {
		$this->repository = $repository;
		$this->settings   = $settings;
	}

	/**
	 * @param array<string, mixed> $table_status Schema status.
	 */
	public function render( array $table_status ): void {
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

	/**
	 * @return array<string, string|int>
	 */
	private function get_log_filters(): array {
		return array(
			'level'      => isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '',
			'event'      => isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : '',
			'product_id' => isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0,
		);
	}

	/**
	 * @param array<string, string|int> $filters Current filters.
	 */
	private function render_log_filters( array $filters ): void {
		?>
		<form method="get" class="lpm-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( AdminPage::SLUG ); ?>" />
			<input type="hidden" name="tab" value="logs" />
			<label>
				<span><?php esc_html_e( 'Level', 'lilleprinsen-price-monitor' ); ?></span>
				<select name="level">
					<option value=""><?php esc_html_e( 'All', 'lilleprinsen-price-monitor' ); ?></option>
					<?php foreach ( array( 'info', 'warning', 'error', 'debug' ) as $level ) : ?>
						<option value="<?php echo esc_attr( $level ); ?>" <?php selected( (string) $filters['level'], $level ); ?>><?php echo esc_html( ucfirst( $level ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Event', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="text" name="event" value="<?php echo esc_attr( (string) $filters['event'] ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'Product ID', 'lilleprinsen-price-monitor' ); ?></span>
				<input type="number" min="1" name="product_id" value="<?php echo esc_attr( $filters['product_id'] ? (string) $filters['product_id'] : '' ); ?>" />
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'lilleprinsen-price-monitor' ); ?></button>
		</form>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $logs Log rows.
	 */
	private function render_logs_table( array $logs ): void {
		if ( empty( $logs ) ) {
			$this->render_empty_state( __( 'No logs found for the current filters.', 'lilleprinsen-price-monitor' ) );
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
					<th scope="col"><?php esc_html_e( 'Context', 'lilleprinsen-price-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $this->format_datetime( $log['created_at'] ?? null ) ); ?></td>
						<td><?php $this->render_status_pill( (string) $log['level'], 'error' === (string) $log['level'] ? 'danger' : ( 'warning' === (string) $log['level'] ? 'warning' : 'muted' ) ); ?></td>
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

	/**
	 * @param array<string, mixed> $table_status Schema status.
	 */
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
}
