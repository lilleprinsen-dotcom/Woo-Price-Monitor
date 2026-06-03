<?php
/**
 * Plugin settings storage and sanitization.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Settings;

use Lilleprinsen\PriceMonitor\Admin\AdminPage;
use Lilleprinsen\PriceMonitor\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
	public const OPTION_NAME = 'lpm_settings';

	/**
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'plugin_enabled'                  => 1,
			'dry_run_mode'                    => 1,
			'default_currency'                => 'NOK',
			'default_check_frequency_hours'   => 24,
			'scheduled_checks_enabled'        => 0,
			'max_urls_per_batch'              => 10,
			'create_suggestions_from_scheduled_checks' => 0,
			'request_timeout_seconds'         => 8,
			'retry_failed_checks'             => 1,
			'observation_retention_days'      => 90,
			'failed_observation_retention_days' => 30,
			'default_pricing_strategy'        => 'match_competitor',
			'beat_competitor_amount'          => 1,
			'stay_above_competitor_amount'    => 1,
			'rounding_mode'                   => 'none',
			'cost_source'                     => 'none',
			'cost_meta_key'                   => '',
			'block_if_cost_missing'           => 0,
			'minimum_profit_amount'           => '',
			'price_comparison_vat_mode'       => 'consumer_prices_include_vat',
			'vat_rate_percent'                => 25,
			'default_min_margin_percent'      => '',
			'min_price_difference_to_suggest' => 10,
			'max_allowed_price_drop_percent'  => 25,
			'max_allowed_price_increase_percent' => 50,
			'block_suggestions_for_sale_products' => 0,
			'block_suggestions_for_out_of_stock_products' => 0,
			'require_manual_approval'         => 1,
			'disable_all_price_updates'       => 1,
			'allow_real_price_updates'        => 0,
			'require_confirmation_for_real_updates' => 1,
			'real_update_allowed_suggestion_types' => array(
				'price_match_down',
				'price_match_up',
				'restore_previous_active_price',
				'restore_previous_regular_price',
				'restore_previous_sale_price',
			),
			'recovery_when_competitor_increases' => 'suggest_only',
			'recovery_if_competitor_still_below_previous_sale_price' => 'suggest_match_competitor',
			'recovery_if_competitor_above_previous_regular_price' => 'suggest_restore_previous_regular_price',
			'multiple_competitor_recovery_basis' => 'lowest_valid_competitor',
			'price_match_write_mode'          => 'sale_price',
			'notifications_enabled'           => 0,
			'notify_on_new_suggestion'        => 1,
			'notify_on_blocked_suggestion'    => 1,
			'notify_on_failed_check'          => 0,
			'notification_phone_number'       => '',
			'whatsapp_provider'               => 'none',
			'rows_per_page'                   => 25,
		);
	}

	public static function ensure_defaults(): void {
		if ( false !== get_option( self::OPTION_NAME, false ) ) {
			return;
		}

		$settings = new self();
		add_option( self::OPTION_NAME, $settings->defaults(), '', false );
	}

	/**
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$settings = $this->get_all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_all(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return $this->sanitize( wp_parse_args( $stored, $this->defaults() ) );
	}

	/**
	 * @param array<string, mixed> $settings New settings.
	 */
	public function update( array $settings ): bool {
		$sanitized = $this->sanitize( wp_parse_args( $settings, $this->get_all() ) );

		return update_option( self::OPTION_NAME, $sanitized, false );
	}

	/**
	 * @param array<string, mixed> $settings Raw settings.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $settings ): array {
		$defaults = $this->defaults();

		return array(
			'plugin_enabled'                  => $this->sanitize_bool( $settings['plugin_enabled'] ?? $defaults['plugin_enabled'] ),
			'dry_run_mode'                    => $this->sanitize_bool( $settings['dry_run_mode'] ?? $defaults['dry_run_mode'] ),
			'default_currency'                => $this->sanitize_currency( $settings['default_currency'] ?? $defaults['default_currency'] ),
			'default_check_frequency_hours'   => $this->sanitize_int( $settings['default_check_frequency_hours'] ?? $defaults['default_check_frequency_hours'], 1, 720, (int) $defaults['default_check_frequency_hours'] ),
			'scheduled_checks_enabled'        => $this->sanitize_bool( $settings['scheduled_checks_enabled'] ?? $defaults['scheduled_checks_enabled'] ),
			'max_urls_per_batch'              => $this->sanitize_int( $settings['max_urls_per_batch'] ?? $defaults['max_urls_per_batch'], 1, 100, (int) $defaults['max_urls_per_batch'] ),
			'create_suggestions_from_scheduled_checks' => $this->sanitize_bool( $settings['create_suggestions_from_scheduled_checks'] ?? $defaults['create_suggestions_from_scheduled_checks'] ),
			'request_timeout_seconds'         => $this->sanitize_int( $settings['request_timeout_seconds'] ?? $defaults['request_timeout_seconds'], 1, 60, (int) $defaults['request_timeout_seconds'] ),
			'retry_failed_checks'             => $this->sanitize_bool( $settings['retry_failed_checks'] ?? $defaults['retry_failed_checks'] ),
			'observation_retention_days'      => $this->sanitize_int( $settings['observation_retention_days'] ?? $defaults['observation_retention_days'], 1, 3650, (int) $defaults['observation_retention_days'] ),
			'failed_observation_retention_days' => $this->sanitize_int( $settings['failed_observation_retention_days'] ?? $defaults['failed_observation_retention_days'], 1, 3650, (int) $defaults['failed_observation_retention_days'] ),
			'default_pricing_strategy'        => $this->sanitize_choice(
				$settings['default_pricing_strategy'] ?? $defaults['default_pricing_strategy'],
				array( 'notify_only', 'match_competitor', 'beat_competitor_by_amount', 'stay_above_competitor_by_amount' ),
				(string) $defaults['default_pricing_strategy']
			),
			'beat_competitor_amount'          => $this->sanitize_decimal( $settings['beat_competitor_amount'] ?? $defaults['beat_competitor_amount'], (float) $defaults['beat_competitor_amount'] ),
			'stay_above_competitor_amount'    => $this->sanitize_decimal( $settings['stay_above_competitor_amount'] ?? $defaults['stay_above_competitor_amount'], (float) $defaults['stay_above_competitor_amount'] ),
			'rounding_mode'                   => $this->sanitize_choice(
				$settings['rounding_mode'] ?? $defaults['rounding_mode'],
				array( 'none', 'nearest_1', 'nearest_5', 'nearest_10', 'nearest_50', 'nearest_100', 'end_9', 'end_99', 'end_95' ),
				(string) $defaults['rounding_mode']
			),
			'cost_source'                     => $this->sanitize_choice(
				$settings['cost_source'] ?? $defaults['cost_source'],
				array( 'none', 'custom_meta_key' ),
				(string) $defaults['cost_source']
			),
			'cost_meta_key'                   => $this->sanitize_meta_key( $settings['cost_meta_key'] ?? $defaults['cost_meta_key'] ),
			'block_if_cost_missing'           => $this->sanitize_bool( $settings['block_if_cost_missing'] ?? $defaults['block_if_cost_missing'] ),
			'minimum_profit_amount'           => $this->sanitize_decimal_or_empty( $settings['minimum_profit_amount'] ?? $defaults['minimum_profit_amount'], '' ),
			'price_comparison_vat_mode'       => $this->sanitize_choice(
				$settings['price_comparison_vat_mode'] ?? $defaults['price_comparison_vat_mode'],
				array( 'consumer_prices_include_vat', 'prices_exclude_vat' ),
				(string) $defaults['price_comparison_vat_mode']
			),
			'vat_rate_percent'                => $this->sanitize_decimal_between( $settings['vat_rate_percent'] ?? $defaults['vat_rate_percent'], 0, 100, (float) $defaults['vat_rate_percent'] ),
			'default_min_margin_percent'      => $this->sanitize_decimal_or_empty( $settings['default_min_margin_percent'] ?? $defaults['default_min_margin_percent'], '' ),
			'min_price_difference_to_suggest' => $this->sanitize_decimal( $settings['min_price_difference_to_suggest'] ?? $defaults['min_price_difference_to_suggest'], (float) $defaults['min_price_difference_to_suggest'] ),
			'max_allowed_price_drop_percent'  => $this->sanitize_decimal_between( $settings['max_allowed_price_drop_percent'] ?? $defaults['max_allowed_price_drop_percent'], 0, 100, (float) $defaults['max_allowed_price_drop_percent'] ),
			'max_allowed_price_increase_percent' => $this->sanitize_decimal_between( $settings['max_allowed_price_increase_percent'] ?? $defaults['max_allowed_price_increase_percent'], 0, 1000, (float) $defaults['max_allowed_price_increase_percent'] ),
			'block_suggestions_for_sale_products' => $this->sanitize_bool( $settings['block_suggestions_for_sale_products'] ?? $defaults['block_suggestions_for_sale_products'] ),
			'block_suggestions_for_out_of_stock_products' => $this->sanitize_bool( $settings['block_suggestions_for_out_of_stock_products'] ?? $defaults['block_suggestions_for_out_of_stock_products'] ),
			'require_manual_approval'         => $this->sanitize_bool( $settings['require_manual_approval'] ?? $defaults['require_manual_approval'] ),
			'disable_all_price_updates'       => $this->sanitize_bool( $settings['disable_all_price_updates'] ?? $defaults['disable_all_price_updates'] ),
			'allow_real_price_updates'        => $this->sanitize_bool( $settings['allow_real_price_updates'] ?? $defaults['allow_real_price_updates'] ),
			'require_confirmation_for_real_updates' => $this->sanitize_bool( $settings['require_confirmation_for_real_updates'] ?? $defaults['require_confirmation_for_real_updates'] ),
			'real_update_allowed_suggestion_types' => $this->sanitize_choice_list(
				$settings['real_update_allowed_suggestion_types'] ?? $defaults['real_update_allowed_suggestion_types'],
				array(
					'price_match_down',
					'price_match_up',
					'restore_previous_active_price',
					'restore_previous_regular_price',
					'restore_previous_sale_price',
				),
				(array) $defaults['real_update_allowed_suggestion_types']
			),
			'recovery_when_competitor_increases' => $this->sanitize_choice(
				$settings['recovery_when_competitor_increases'] ?? $defaults['recovery_when_competitor_increases'],
				array(
					'do_nothing',
					'suggest_only',
					'suggest_match_competitor',
					'suggest_restore_previous_active_price',
					'suggest_restore_previous_regular_price',
					'suggest_restore_previous_sale_price',
				),
				(string) $defaults['recovery_when_competitor_increases']
			),
			'recovery_if_competitor_still_below_previous_sale_price' => $this->sanitize_choice(
				$settings['recovery_if_competitor_still_below_previous_sale_price'] ?? $defaults['recovery_if_competitor_still_below_previous_sale_price'],
				array(
					'keep_current_price',
					'suggest_match_competitor',
					'suggest_restore_previous_sale_price',
					'suggest_only',
				),
				(string) $defaults['recovery_if_competitor_still_below_previous_sale_price']
			),
			'recovery_if_competitor_above_previous_regular_price' => $this->sanitize_choice(
				$settings['recovery_if_competitor_above_previous_regular_price'] ?? $defaults['recovery_if_competitor_above_previous_regular_price'],
				array(
					'keep_current_price',
					'suggest_restore_previous_regular_price',
					'suggest_match_competitor',
					'suggest_only',
				),
				(string) $defaults['recovery_if_competitor_above_previous_regular_price']
			),
			'multiple_competitor_recovery_basis' => $this->sanitize_choice(
				$settings['multiple_competitor_recovery_basis'] ?? $defaults['multiple_competitor_recovery_basis'],
				array(
					'lowest_valid_competitor',
					'primary_competitor',
					'all_competitors_must_increase',
				),
				(string) $defaults['multiple_competitor_recovery_basis']
			),
			'price_match_write_mode'          => $this->sanitize_choice(
				$settings['price_match_write_mode'] ?? $defaults['price_match_write_mode'],
				array(
					'regular_price',
					'sale_price',
					'temporary_sale_price',
				),
				(string) $defaults['price_match_write_mode']
			),
			'notifications_enabled'           => $this->sanitize_bool( $settings['notifications_enabled'] ?? $defaults['notifications_enabled'] ),
			'notify_on_new_suggestion'        => $this->sanitize_bool( $settings['notify_on_new_suggestion'] ?? $defaults['notify_on_new_suggestion'] ),
			'notify_on_blocked_suggestion'    => $this->sanitize_bool( $settings['notify_on_blocked_suggestion'] ?? $defaults['notify_on_blocked_suggestion'] ),
			'notify_on_failed_check'          => $this->sanitize_bool( $settings['notify_on_failed_check'] ?? $defaults['notify_on_failed_check'] ),
			'notification_phone_number'       => $this->sanitize_phone_placeholder( $settings['notification_phone_number'] ?? $defaults['notification_phone_number'] ),
			'whatsapp_provider'               => $this->sanitize_choice(
				$settings['whatsapp_provider'] ?? $defaults['whatsapp_provider'],
				array( 'none', 'meta_cloud_api', 'twilio', 'make_webhook', 'zapier_webhook' ),
				(string) $defaults['whatsapp_provider']
			),
			'rows_per_page'                   => $this->sanitize_int( $settings['rows_per_page'] ?? $defaults['rows_per_page'], 1, 200, (int) $defaults['rows_per_page'] ),
		);
	}

	public function handle_settings_save(): void {
		if ( empty( $_POST['lpm_settings_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['lpm_settings_action'] ) );

		if ( 'save' !== $action ) {
			return;
		}

		if ( ! Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to update Lilleprinsen Price Monitor settings.', 'lilleprinsen-price-monitor' ) );
		}

		check_admin_referer( 'lpm_save_settings', 'lpm_settings_nonce' );

		$raw_settings = isset( $_POST['lpm_settings'] ) && is_array( $_POST['lpm_settings'] )
			? wp_unslash( $_POST['lpm_settings'] )
			: array();

		$this->update( $raw_settings );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => AdminPage::SLUG,
					'tab'                => 'settings',
					'lpm_settings_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private function sanitize_bool( $value ): int {
		return in_array( $value, array( true, 1, '1', 'yes', 'on' ), true ) ? 1 : 0;
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private function sanitize_currency( $value ): string {
		$currency = strtoupper( sanitize_text_field( (string) $value ) );
		$currency = preg_replace( '/[^A-Z]/', '', $currency );

		if ( ! is_string( $currency ) || '' === $currency ) {
			return 'NOK';
		}

		return substr( $currency, 0, 10 );
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private function sanitize_phone_placeholder( $value ): string {
		$phone = sanitize_text_field( (string) $value );
		$phone = preg_replace( '/[^0-9+() .-]/', '', $phone );

		return is_string( $phone ) ? substr( trim( $phone ), 0, 50 ) : '';
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private function sanitize_meta_key( $value ): string {
		$meta_key = sanitize_text_field( (string) $value );
		$meta_key = preg_replace( '/[^A-Za-z0-9_.:-]/', '', $meta_key );

		return is_string( $meta_key ) ? substr( $meta_key, 0, 191 ) : '';
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private function sanitize_int( $value, int $min, int $max, int $fallback ): int {
		$int = absint( $value );

		if ( $int < $min ) {
			return $fallback;
		}

		return min( $int, $max );
	}

	/**
	 * @param mixed $value Raw value.
	 * @return float|string
	 */
	private function sanitize_decimal_or_empty( $value, string $fallback ) {
		if ( '' === $value || null === $value ) {
			return '';
		}

		$decimal = str_replace( ',', '.', sanitize_text_field( (string) $value ) );

		if ( ! is_numeric( $decimal ) ) {
			return $fallback;
		}

		return max( 0, round( (float) $decimal, 2 ) );
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private function sanitize_decimal( $value, float $fallback ): float {
		$decimal = str_replace( ',', '.', sanitize_text_field( (string) $value ) );

		if ( ! is_numeric( $decimal ) ) {
			return $fallback;
		}

		return max( 0, round( (float) $decimal, 2 ) );
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private function sanitize_decimal_between( $value, float $min, float $max, float $fallback ): float {
		$decimal = $this->sanitize_decimal( $value, $fallback );

		if ( $decimal < $min ) {
			return $fallback;
		}

		return min( $decimal, $max );
	}

	/**
	 * @param mixed $value Raw value.
	 * @param array<int, string> $allowed Allowed values.
	 */
	private function sanitize_choice( $value, array $allowed, string $fallback ): string {
		$value = sanitize_key( (string) $value );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * @param mixed $value Raw value.
	 * @param array<int, string> $allowed Allowed values.
	 * @param array<int, string> $fallback Fallback values.
	 * @return array<int, string>
	 */
	private function sanitize_choice_list( $value, array $allowed, array $fallback ): array {
		if ( ! is_array( $value ) ) {
			return $fallback;
		}

		$sanitized = array();

		foreach ( $value as $item ) {
			$item = sanitize_key( (string) $item );

			if ( in_array( $item, $allowed, true ) ) {
				$sanitized[] = $item;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}
}
