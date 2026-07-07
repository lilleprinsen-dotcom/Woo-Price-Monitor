<?php
/**
 * Dashboard tab renderer.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin\Tabs;

use Lilleprinsen\PriceMonitor\Admin\AdminViewHelpers;
use Lilleprinsen\PriceMonitor\Jobs\CheckCompetitorLinkJob;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DashboardTab extends AdminViewHelpers {
	/**
	 * @param array<string, mixed> $counts Dashboard counts.
	 * @param array<string, mixed> $settings Current settings.
	 * @param array<string, mixed> $table_status Schema status.
	 */
	public function render( array $counts, array $settings, array $table_status, bool $woocommerce_active, array $competitor_strategy = array() ): void {
		$lock_status     = CheckCompetitorLinkJob::get_lock_status();
		$health_warnings = $this->get_health_warnings( $counts, $settings, $woocommerce_active, $lock_status );
		?>
		<div class="lpm-grid lpm-grid-summary">
			<?php
			$this->render_summary_card( __( 'Monitored products', 'lilleprinsen-price-monitor' ), $counts['monitored_products'], __( 'Selected products only', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Active competitor links', 'lilleprinsen-price-monitor' ), $counts['active_competitor_links'], __( 'Stored direct URLs', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Pending suggestions', 'lilleprinsen-price-monitor' ), $counts['pending_suggestions'], __( 'Awaiting review', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Blocked suggestions', 'lilleprinsen-price-monitor' ), $counts['blocked_suggestions'], __( 'Need manual attention', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Recovery suggestions', 'lilleprinsen-price-monitor' ), $counts['recovery_suggestions'], __( 'Price-up or restore plans', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Checks last 24h', 'lilleprinsen-price-monitor' ), (int) $counts['checks_last_24h'], __( 'Observation rows', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Failed checks last 24h', 'lilleprinsen-price-monitor' ), (int) $counts['failed_checks_last_24h'], __( 'Observation failures', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Recent failed checks', 'lilleprinsen-price-monitor' ), $counts['recent_failed_checks'], __( 'Last 7 days', 'lilleprinsen-price-monitor' ) );
			$this->render_summary_card( __( 'Active price match sessions', 'lilleprinsen-price-monitor' ), $counts['active_price_match_sessions'], __( 'Dry-run recovery state', 'lilleprinsen-price-monitor' ) );
			?>
		</div>

		<div class="lpm-grid lpm-grid-summary">
			<?php
			$this->render_health_card( __( 'Last check time', 'lilleprinsen-price-monitor' ), $this->format_datetime( $counts['last_successful_check_time'] ?? null ), __( 'Latest successful observation', 'lilleprinsen-price-monitor' ), '' !== (string) ( $counts['last_successful_check_time'] ?? '' ) ? 'ok' : 'muted' );
			$this->render_health_card( __( 'Batch lock', 'lilleprinsen-price-monitor' ), ! empty( $lock_status['locked'] ) ? __( 'Locked', 'lilleprinsen-price-monitor' ) : __( 'Free', 'lilleprinsen-price-monitor' ), ! empty( $lock_status['source'] ) ? sprintf( __( 'Source: %s', 'lilleprinsen-price-monitor' ), (string) $lock_status['source'] ) : __( 'Transient lock status', 'lilleprinsen-price-monitor' ), ! empty( $lock_status['locked'] ) ? 'warning' : 'ok' );
			$this->render_health_card( __( 'Scheduled checks', 'lilleprinsen-price-monitor' ), ! empty( $settings['scheduled_checks_enabled'] ) ? __( 'Enabled', 'lilleprinsen-price-monitor' ) : __( 'Disabled', 'lilleprinsen-price-monitor' ), __( 'Opt-in background work', 'lilleprinsen-price-monitor' ), ! empty( $settings['scheduled_checks_enabled'] ) ? 'warning' : 'muted' );
			$this->render_health_card( __( 'Real updates', 'lilleprinsen-price-monitor' ), $this->real_updates_enabled( $settings ) ? __( 'Possible', 'lilleprinsen-price-monitor' ) : __( 'Impossible', 'lilleprinsen-price-monitor' ), __( 'Requires all guardrails', 'lilleprinsen-price-monitor' ), $this->real_updates_enabled( $settings ) ? 'danger' : 'ok' );
			$this->render_health_card( __( 'Webhook notifications', 'lilleprinsen-price-monitor' ), ! empty( $settings['webhook_notifications_enabled'] ) ? __( 'Enabled', 'lilleprinsen-price-monitor' ) : __( 'Disabled', 'lilleprinsen-price-monitor' ), __( 'Make/Zapier channel', 'lilleprinsen-price-monitor' ), ! empty( $settings['webhook_notifications_enabled'] ) ? 'warning' : 'muted' );
			$this->render_health_card( __( 'Rate limiting', 'lilleprinsen-price-monitor' ), __( 'Profile delay', 'lilleprinsen-price-monitor' ), __( 'Respected during batch selection', 'lilleprinsen-price-monitor' ), 'ok' );
			$this->render_health_card( __( 'Pending suggestions', 'lilleprinsen-price-monitor' ), number_format_i18n( (int) $counts['pending_suggestions'] ), __( 'Pricing inbox', 'lilleprinsen-price-monitor' ), (int) $counts['pending_suggestions'] > 0 ? 'warning' : 'ok' );
			$this->render_health_card( __( 'Blocked suggestions', 'lilleprinsen-price-monitor' ), number_format_i18n( (int) $counts['blocked_suggestions'] ), __( 'Safety review', 'lilleprinsen-price-monitor' ), (int) $counts['blocked_suggestions'] > 0 ? 'danger' : 'ok' );
			?>
		</div>

		<?php $this->render_competitor_health_panel( is_array( $counts['competitor_health'] ?? null ) ? $counts['competitor_health'] : array() ); ?>
		<?php $this->render_competitor_strategy_panel( $competitor_strategy ); ?>

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
							<th scope="row"><?php esc_html_e( 'Scheduled checks', 'lilleprinsen-price-monitor' ); ?></th>
							<td><?php $this->render_status_pill( ! empty( $settings['scheduled_checks_enabled'] ) ? __( 'Enabled', 'lilleprinsen-price-monitor' ) : __( 'Disabled', 'lilleprinsen-price-monitor' ), ! empty( $settings['scheduled_checks_enabled'] ) ? 'warning' : 'muted' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Emergency update disable', 'lilleprinsen-price-monitor' ); ?></th>
							<td><?php $this->render_status_pill( ! empty( $settings['disable_all_price_updates'] ) ? __( 'Enabled', 'lilleprinsen-price-monitor' ) : __( 'Disabled', 'lilleprinsen-price-monitor' ), ! empty( $settings['disable_all_price_updates'] ) ? 'ok' : 'danger' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Active price match sessions', 'lilleprinsen-price-monitor' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( (int) $counts['active_price_match_sessions'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last successful check', 'lilleprinsen-price-monitor' ); ?></th>
							<td><?php echo esc_html( $this->format_datetime( $counts['last_successful_check_time'] ?? null ) ); ?></td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="lpm-card">
				<div class="lpm-card-header">
					<h2><?php esc_html_e( 'Needs attention', 'lilleprinsen-price-monitor' ); ?></h2>
					<span class="lpm-pill lpm-pill-muted"><?php esc_html_e( 'Dry-run', 'lilleprinsen-price-monitor' ); ?></span>
				</div>
				<p><?php esc_html_e( 'Manual checks and dry-run suggestions surface here before any real price update workflow exists.', 'lilleprinsen-price-monitor' ); ?></p>
				<ul class="lpm-check-list">
					<?php if ( empty( $health_warnings ) ) : ?>
						<li><?php esc_html_e( 'No operational warnings detected from the current dashboard counts.', 'lilleprinsen-price-monitor' ); ?></li>
					<?php else : ?>
						<?php foreach ( $health_warnings as $warning ) : ?>
							<li><?php echo esc_html( $warning ); ?></li>
						<?php endforeach; ?>
					<?php endif; ?>
				</ul>
				<form method="post" class="lpm-form-actions">
					<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
					<input type="hidden" name="lpm_action" value="run_small_check_batch_now" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Run one small check batch now', 'lilleprinsen-price-monitor' ); ?></button>
				</form>
			</section>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $strategy Competitor strategy analytics.
	 */
	private function render_competitor_strategy_panel( array $strategy ): void {
		$rows = isset( $strategy['competitors'] ) && is_array( $strategy['competitors'] ) ? $strategy['competitors'] : array();
		?>
		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<h2><?php esc_html_e( 'Competitor strategy detection', 'lilleprinsen-price-monitor' ); ?></h2>
				<span class="lpm-pill lpm-pill-muted">
					<?php
					printf(
						esc_html__( '%d drop events', 'lilleprinsen-price-monitor' ),
						absint( $strategy['events_analyzed'] ?? 0 )
					);
					?>
				</span>
			</div>
			<p><?php esc_html_e( 'Detects whether competitors usually lead price drops, follow another store within the observation window, or run short-lived campaign dips. This is advisory only and never changes prices automatically.', 'lilleprinsen-price-monitor' ); ?></p>
			<div class="lpm-grid lpm-grid-summary">
				<?php
				$this->render_health_card( __( 'Price-drop leaders', 'lilleprinsen-price-monitor' ), number_format_i18n( absint( $strategy['leaders'] ?? 0 ) ), __( 'Often move before others', 'lilleprinsen-price-monitor' ), absint( $strategy['leaders'] ?? 0 ) > 0 ? 'warning' : 'muted' );
				$this->render_health_card( __( 'Market followers', 'lilleprinsen-price-monitor' ), number_format_i18n( absint( $strategy['followers'] ?? 0 ) ), __( 'Often move after others', 'lilleprinsen-price-monitor' ), absint( $strategy['followers'] ?? 0 ) > 0 ? 'ok' : 'muted' );
				$this->render_health_card( __( 'Campaign runners', 'lilleprinsen-price-monitor' ), number_format_i18n( absint( $strategy['campaign_runners'] ?? 0 ) ), __( 'Drops often recover quickly', 'lilleprinsen-price-monitor' ), absint( $strategy['campaign_runners'] ?? 0 ) > 0 ? 'warning' : 'muted' );
				$this->render_health_card( __( 'Rows analyzed', 'lilleprinsen-price-monitor' ), number_format_i18n( absint( $strategy['rows_used'] ?? 0 ) ), __( 'Recent successful observations', 'lilleprinsen-price-monitor' ), absint( $strategy['rows_used'] ?? 0 ) > 0 ? 'ok' : 'muted' );
				?>
			</div>

			<?php if ( empty( $rows ) ) : ?>
				<?php $this->render_empty_state( __( 'No competitor strategy can be detected yet. Run scheduled checks for approved links until each competitor has repeated price observations.', 'lilleprinsen-price-monitor' ) ); ?>
			<?php else : ?>
				<table class="widefat striped lpm-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Detected strategy', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Evidence', 'lilleprinsen-price-monitor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Latest drop', 'lilleprinsen-price-monitor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $rows, 0, 8 ) as $row ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( (string) ( $row['competitor_name'] ?? __( 'Unknown competitor', 'lilleprinsen-price-monitor' ) ) ); ?></strong>
								</td>
								<td><?php $this->render_status_pill( (string) ( $row['strategy_label'] ?? __( 'Unknown strategy', 'lilleprinsen-price-monitor' ) ), $this->strategy_status( (string) ( $row['strategy'] ?? '' ) ) ); ?></td>
								<td>
									<?php
									printf(
										/* translators: 1: drop count, 2: leader ratio, 3: follower ratio, 4: campaign ratio. */
										esc_html__( '%1$d drops. Leader %2$s%%, follower %3$s%%, campaign %4$s%%.', 'lilleprinsen-price-monitor' ),
										absint( $row['price_drop_events'] ?? 0 ),
										esc_html( (string) ( $row['leader_ratio'] ?? '0' ) ),
										esc_html( (string) ( $row['follower_ratio'] ?? '0' ) ),
										esc_html( (string) ( $row['campaign_ratio'] ?? '0' ) )
									);
									?>
									<details class="lpm-context">
										<summary><?php esc_html_e( 'Details', 'lilleprinsen-price-monitor' ); ?></summary>
										<p><?php echo esc_html( (string) ( $row['explanation'] ?? '' ) ); ?></p>
									</details>
								</td>
								<td><?php echo esc_html( $this->format_datetime( $row['latest_event_at'] ?? null ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<details class="lpm-context">
				<summary><?php esc_html_e( 'How strategy is detected', 'lilleprinsen-price-monitor' ); ?></summary>
				<p>
					<?php
					printf(
						esc_html__( 'A meaningful drop is at least 2%% and at least 1 currency unit. A leader drops before another competitor on the same product within %1$d hours. A follower drops after another competitor within that window. A temporary campaign is a drop that recovers near its previous price within %2$d days.', 'lilleprinsen-price-monitor' ),
						absint( $strategy['leader_window_hours'] ?? 48 ),
						absint( $strategy['campaign_days'] ?? 7 )
					);
					?>
				</p>
			</details>
		</section>
		<?php
	}

	private function strategy_status( string $strategy ): string {
		if ( 'price_drop_leader' === $strategy || 'campaign_runner' === $strategy ) {
			return 'warning';
		}

		if ( 'market_follower' === $strategy ) {
			return 'ok';
		}

		return 'muted';
	}

	/**
	 * @param array<string, mixed> $health Competitor health metrics.
	 */
	private function render_competitor_health_panel( array $health ): void {
		$window_days = max( 1, absint( $health['window_days'] ?? 30 ) );
		?>
		<section class="lpm-card lpm-card-spaced">
			<div class="lpm-card-header">
				<h2><?php esc_html_e( 'Competitor health', 'lilleprinsen-price-monitor' ); ?></h2>
				<span class="lpm-pill lpm-pill-muted">
					<?php
					printf(
						esc_html__( 'Last %d days', 'lilleprinsen-price-monitor' ),
						$window_days
					);
					?>
				</span>
			</div>
			<p><?php esc_html_e( 'Use this to spot competitors that are difficult to search, slow to extract, or producing suggestions that rarely get approved.', 'lilleprinsen-price-monitor' ); ?></p>
			<div class="lpm-grid lpm-grid-summary">
				<?php
				$this->render_health_card(
					__( 'Search success rate', 'lilleprinsen-price-monitor' ),
					$this->format_health_rate( $health['search_success_rate'] ?? null ),
					$this->format_metric_count(
						__( 'matches', 'lilleprinsen-price-monitor' ),
						absint( $health['search_successes'] ?? 0 ),
						absint( $health['search_attempts'] ?? 0 )
					),
					$this->rate_status( $health['search_success_rate'] ?? null, 'high_good' )
				);
				$this->render_health_card(
					__( 'Extraction success rate', 'lilleprinsen-price-monitor' ),
					$this->format_health_rate( $health['extraction_success_rate'] ?? null ),
					$this->format_metric_count(
						__( 'successful extractions', 'lilleprinsen-price-monitor' ),
						absint( $health['extraction_successes'] ?? 0 ),
						absint( $health['extraction_attempts'] ?? 0 )
					),
					$this->rate_status( $health['extraction_success_rate'] ?? null, 'high_good' )
				);
				$this->render_health_card(
					__( 'Timeout rate', 'lilleprinsen-price-monitor' ),
					$this->format_health_rate( $health['timeout_rate'] ?? null ),
					$this->format_metric_count(
						__( 'timeouts', 'lilleprinsen-price-monitor' ),
						absint( $health['timeout_failures'] ?? 0 ),
						absint( $health['extraction_attempts'] ?? 0 )
					),
					$this->rate_status( $health['timeout_rate'] ?? null, 'low_good' )
				);
				$this->render_health_card(
					__( 'Approval rate', 'lilleprinsen-price-monitor' ),
					$this->format_health_rate( $health['approval_rate'] ?? null ),
					$this->format_metric_count(
						__( 'approved suggestions', 'lilleprinsen-price-monitor' ),
						absint( $health['approval_approved'] ?? 0 ),
						absint( $health['approval_total'] ?? 0 )
					),
					$this->rate_status( $health['approval_rate'] ?? null, 'approval' )
				);
				?>
			</div>
			<details class="lpm-context">
				<summary><?php esc_html_e( 'How these numbers are calculated', 'lilleprinsen-price-monitor' ); ?></summary>
				<p><?php esc_html_e( 'Search success compares selected-product competitor searches with runs that produced a match suggestion. Extraction success uses competitor link observations and discovered product pages with usable prices. Timeout rate counts extraction failures and logs that explicitly mention request timeouts. Approval rate compares approved suggestions with all suggestions created in the same window.', 'lilleprinsen-price-monitor' ); ?></p>
			</details>
		</section>
		<?php
	}

	private function format_health_rate( $rate ): string {
		if ( null === $rate || '' === $rate ) {
			return '—';
		}

		return number_format_i18n( (float) $rate, 1 ) . '%';
	}

	private function format_metric_count( string $label, int $part, int $total ): string {
		if ( $total <= 0 ) {
			return __( 'No recent data', 'lilleprinsen-price-monitor' );
		}

		return sprintf(
			/* translators: 1: part count, 2: total count, 3: metric label. */
			__( '%1$s of %2$s %3$s', 'lilleprinsen-price-monitor' ),
			number_format_i18n( $part ),
			number_format_i18n( $total ),
			$label
		);
	}

	private function rate_status( $rate, string $mode ): string {
		if ( null === $rate || '' === $rate ) {
			return 'muted';
		}

		$rate = (float) $rate;

		if ( 'low_good' === $mode ) {
			if ( $rate <= 5.0 ) {
				return 'ok';
			}

			return $rate <= 20.0 ? 'warning' : 'danger';
		}

		if ( 'approval' === $mode ) {
			if ( $rate >= 30.0 ) {
				return 'ok';
			}

			return $rate >= 10.0 ? 'warning' : 'danger';
		}

		if ( $rate >= 70.0 ) {
			return 'ok';
		}

		return $rate >= 40.0 ? 'warning' : 'danger';
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
}
