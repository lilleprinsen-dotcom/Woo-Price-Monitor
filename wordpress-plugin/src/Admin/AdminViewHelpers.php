<?php
/**
 * Shared admin rendering helpers.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AdminViewHelpers {
	protected function render_summary_card( string $label, int $value, string $description ): void {
		?>
		<section class="lpm-card lpm-summary-card">
			<span class="lpm-summary-label"><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong>
			<span><?php echo esc_html( $description ); ?></span>
		</section>
		<?php
	}

	protected function render_health_card( string $label, string $value, string $description, string $status ): void {
		?>
		<section class="lpm-card lpm-summary-card">
			<span class="lpm-summary-label"><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( $value ); ?></strong>
			<span><?php echo esc_html( $description ); ?></span>
			<?php $this->render_status_pill( $this->get_health_status_label( $status ), $status ); ?>
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

	protected function render_empty_state( string $message ): void {
		?>
		<p class="lpm-empty"><?php echo esc_html( $message ); ?></p>
		<?php
	}

	protected function render_context_summary( string $context ): void {
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

	protected function render_pagination( int $total, int $page, int $per_page, string $page_arg, array $extra_args ): void {
		$total_pages = (int) ceil( max( 0, $total ) / max( 1, $per_page ) );

		if ( $total_pages <= 1 ) {
			return;
		}

		$base_args = array_merge(
			array(
				'page' => AdminPage::SLUG,
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
	protected function render_checkbox_field( string $key, string $label, array $settings, string $description = '' ): void {
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
	 * @param array<string, string> $options Field options.
	 */
	protected function render_checkbox_group_field( string $key, string $label, array $settings, array $options ): void {
		$values = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? $settings[ $key ] : array();
		?>
		<div class="lpm-field">
			<strong><?php echo esc_html( $label ); ?></strong>
			<input type="hidden" name="lpm_settings[<?php echo esc_attr( $key ); ?>][]" value="" />
			<?php foreach ( $options as $value => $option_label ) : ?>
				<label class="lpm-field-checkbox lpm-field-checkbox-inline">
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
	protected function render_text_field( string $key, string $label, array $settings, string $placeholder = '' ): void {
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
	protected function render_number_field( string $key, string $label, array $settings, int $min ): void {
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
	protected function render_decimal_field( string $key, string $label, array $settings, string $description = '' ): void {
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
	protected function render_select_field( string $key, string $label, array $settings, array $options ): void {
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

	protected function get_health_status_label( string $status ): string {
		$labels = array(
			'ok'      => __( 'OK', 'lilleprinsen-price-monitor' ),
			'warning' => __( 'Warning', 'lilleprinsen-price-monitor' ),
			'danger'  => __( 'Review', 'lilleprinsen-price-monitor' ),
			'muted'   => __( 'Idle', 'lilleprinsen-price-monitor' ),
		);

		return $labels[ $status ] ?? __( 'Status', 'lilleprinsen-price-monitor' );
	}

	protected function get_positive_query_arg( string $key, int $default ): int {
		if ( ! isset( $_GET[ $key ] ) ) {
			return $default;
		}

		return max( 1, absint( wp_unslash( $_GET[ $key ] ) ) );
	}

	protected function format_datetime( $value ): string {
		if ( empty( $value ) ) {
			return '—';
		}

		$timestamp = strtotime( (string) $value );

		if ( false === $timestamp ) {
			return '—';
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	protected function format_nullable_value( $value ): string {
		if ( null === $value || '' === $value ) {
			return '—';
		}

		return (string) $value;
	}

	protected function shorten_text( string $text, int $length ): string {
		$text = trim( $text );

		if ( '' === $text ) {
			return '—';
		}

		if ( strlen( $text ) <= $length ) {
			return $text;
		}

		return substr( $text, 0, max( 0, $length - 3 ) ) . '...';
	}

	/**
	 * @param array<string, mixed> $settings Current settings.
	 */
	protected function real_updates_enabled( array $settings ): bool {
		return empty( $settings['dry_run_mode'] )
			&& empty( $settings['disable_all_price_updates'] )
			&& ! empty( $settings['allow_real_price_updates'] )
			&& ! empty( $settings['require_manual_approval'] )
			&& ! empty( $settings['require_confirmation_for_real_updates'] );
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_pricing_strategy_options(): array {
		return array(
			'notify_only'                 => __( 'Notify only', 'lilleprinsen-price-monitor' ),
			'match_competitor'            => __( 'Match competitor', 'lilleprinsen-price-monitor' ),
			'beat_competitor_by_amount'   => __( 'Beat competitor by amount', 'lilleprinsen-price-monitor' ),
			'stay_above_competitor_by_amount' => __( 'Stay above competitor by amount', 'lilleprinsen-price-monitor' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_rounding_mode_options(): array {
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

	/**
	 * @return array<string, string>
	 */
	protected function get_real_update_type_options(): array {
		return array(
			'price_match_down'               => __( 'Price match down', 'lilleprinsen-price-monitor' ),
			'price_match_up'                 => __( 'Price match up', 'lilleprinsen-price-monitor' ),
			'restore_previous_active_price'  => __( 'Restore previous active price', 'lilleprinsen-price-monitor' ),
			'restore_previous_regular_price' => __( 'Restore previous regular price', 'lilleprinsen-price-monitor' ),
			'restore_previous_sale_price'    => __( 'Restore previous sale price', 'lilleprinsen-price-monitor' ),
		);
	}
}
