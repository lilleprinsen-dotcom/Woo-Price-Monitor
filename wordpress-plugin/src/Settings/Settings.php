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
			'check_batch_lock_minutes'        => 10,
			'create_suggestions_from_scheduled_checks' => 0,
			'request_timeout_seconds'         => 8,
			'retry_failed_checks'             => 1,
			'observation_retention_days'      => 90,
			'failed_observation_retention_days' => 30,
			'log_retention_days'              => 90,
			'debug_log_retention_days'        => 14,
			'keep_audit_logs_forever'         => 1,
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
			'allow_partial_group_price_updates' => 0,
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
			'recovery_max_competitor_price_age_hours' => 48,
			'price_match_write_mode'          => 'sale_price',
			'notifications_enabled'           => 0,
			'notify_on_new_suggestion'        => 1,
			'notify_on_blocked_suggestion'    => 1,
			'notify_on_failed_check'          => 0,
			'notification_phone_number'       => '',
			'whatsapp_provider'               => 'none',
			'webhook_notifications_enabled'   => 0,
			'webhook_url'                     => '',
			'webhook_secret'                  => '',
			'webhook_send_on_new_suggestion'  => 1,
			'webhook_send_on_blocked_suggestion' => 1,
			'webhook_send_on_failed_check'    => 0,
			'webhook_send_on_recovery_suggestion' => 1,
			'allow_token_dry_run_approval_links' => 0,
			'token_link_expiry_hours'         => 24,
			'token_retention_days'            => 30,
			'whatsapp_action_links_enabled'   => 0,
			'whatsapp_action_link_expiry_hours' => 24,
			'allow_token_match_price_dry_run' => 1,
			'allow_token_match_price_minus_1_dry_run' => 1,
			'allow_token_reject'              => 1,
			'allow_unauthenticated_real_price_update_from_token' => 0,
			'price_match_box_enabled'         => 0,
			'price_match_box_show_on_product_page' => 1,
			'price_match_box_show_on_loop'    => 0,
			'price_match_box_position'        => 'below_price',
			'price_match_box_text'            => '⭐ Prismatch: Denne varen er matchet mot våre nærmeste konkurrenter.',
			'price_match_box_subtext'         => 'Rabattkoder kan ikke brukes på prismatch.',
			'price_match_box_emoji'           => '⭐',
			'price_match_box_use_theme_color' => 1,
			'price_match_box_background_color' => '',
			'price_match_box_text_color'      => '',
			'price_match_box_border_color'    => '',
			'price_match_box_border_radius'   => 10,
			'price_match_box_hide_if_no_active_match' => 1,
			'price_match_box_show_for_group_matches' => 1,
			'disable_coupons_for_price_matched_products' => 1,
			'rows_per_page'                   => 25,
			'external_browser_worker_enabled' => 0,
			'external_browser_worker_endpoint' => '',
			'external_browser_worker_secret'  => '',
			'external_browser_worker_timeout_seconds' => 20,
			'external_browser_worker_max_candidates' => 8,
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
			'check_batch_lock_minutes'        => $this->sanitize_int( $settings['check_batch_lock_minutes'] ?? $defaults['check_batch_lock_minutes'], 1, 60, (int) $defaults['check_batch_lock_minutes'] ),
			'create_suggestions_from_scheduled_checks' => $this->sanitize_bool( $settings['create_suggestions_from_scheduled_checks'] ?? $defaults['create_suggestions_from_scheduled_checks'] ),
			'request_timeout_seconds'         => $this->sanitize_int( $settings['request_timeout_seconds'] ?? $defaults['request_timeout_seconds'], 1, 60, (int) $defaults['request_timeout_seconds'] ),
			'retry_failed_checks'             => $this->sanitize_bool( $settings['retry_failed_checks'] ?? $defaults['retry_failed_checks'] ),
			'observation_retention_days'      => $this->sanitize_int( $settings['observation_retention_days'] ?? $defaults['observation_retention_days'], 1, 3650, (int) $defaults['observation_retention_days'] ),
			'failed_observation_retention_days' => $this->sanitize_int( $settings['failed_observation_retention_days'] ?? $defaults['failed_observation_retention_days'], 1, 3650, (int) $defaults['failed_observation_retention_days'] ),
			'log_retention_days'              => $this->sanitize_int( $settings['log_retention_days'] ?? $defaults['log_retention_days'], 1, 3650, (int) $defaults['log_retention_days'] ),
			'debug_log_retention_days'        => $this->sanitize_int( $settings['debug_log_retention_days'] ?? $defaults['debug_log_retention_days'], 1, 3650, (int) $defaults['debug_log_retention_days'] ),
			'keep_audit_logs_forever'         => $this->sanitize_bool( $settings['keep_audit_logs_forever'] ?? $defaults['keep_audit_logs_forever'] ),
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
			'allow_partial_group_price_updates' => $this->sanitize_bool( $settings['allow_partial_group_price_updates'] ?? $defaults['allow_partial_group_price_updates'] ),
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
			'recovery_max_competitor_price_age_hours' => $this->sanitize_int( $settings['recovery_max_competitor_price_age_hours'] ?? $defaults['recovery_max_competitor_price_age_hours'], 1, 720, (int) $defaults['recovery_max_competitor_price_age_hours'] ),
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
			'webhook_notifications_enabled'   => $this->sanitize_bool( $settings['webhook_notifications_enabled'] ?? $defaults['webhook_notifications_enabled'] ),
			'webhook_url'                     => $this->sanitize_webhook_url( $settings['webhook_url'] ?? $defaults['webhook_url'] ),
			'webhook_secret'                  => $this->sanitize_secret( $settings['webhook_secret'] ?? $defaults['webhook_secret'] ),
			'webhook_send_on_new_suggestion'  => $this->sanitize_bool( $settings['webhook_send_on_new_suggestion'] ?? $defaults['webhook_send_on_new_suggestion'] ),
			'webhook_send_on_blocked_suggestion' => $this->sanitize_bool( $settings['webhook_send_on_blocked_suggestion'] ?? $defaults['webhook_send_on_blocked_suggestion'] ),
			'webhook_send_on_failed_check'    => $this->sanitize_bool( $settings['webhook_send_on_failed_check'] ?? $defaults['webhook_send_on_failed_check'] ),
			'webhook_send_on_recovery_suggestion' => $this->sanitize_bool( $settings['webhook_send_on_recovery_suggestion'] ?? $defaults['webhook_send_on_recovery_suggestion'] ),
			'allow_token_dry_run_approval_links' => $this->sanitize_bool( $settings['allow_token_dry_run_approval_links'] ?? $defaults['allow_token_dry_run_approval_links'] ),
			'token_link_expiry_hours'         => $this->sanitize_int( $settings['token_link_expiry_hours'] ?? $defaults['token_link_expiry_hours'], 1, 168, (int) $defaults['token_link_expiry_hours'] ),
			'token_retention_days'            => $this->sanitize_int( $settings['token_retention_days'] ?? $defaults['token_retention_days'], 1, 3650, (int) $defaults['token_retention_days'] ),
			'whatsapp_action_links_enabled'   => $this->sanitize_bool( $settings['whatsapp_action_links_enabled'] ?? $defaults['whatsapp_action_links_enabled'] ),
			'whatsapp_action_link_expiry_hours' => $this->sanitize_int( $settings['whatsapp_action_link_expiry_hours'] ?? $defaults['whatsapp_action_link_expiry_hours'], 1, 168, (int) $defaults['whatsapp_action_link_expiry_hours'] ),
			'allow_token_match_price_dry_run' => $this->sanitize_bool( $settings['allow_token_match_price_dry_run'] ?? $defaults['allow_token_match_price_dry_run'] ),
			'allow_token_match_price_minus_1_dry_run' => $this->sanitize_bool( $settings['allow_token_match_price_minus_1_dry_run'] ?? $defaults['allow_token_match_price_minus_1_dry_run'] ),
			'allow_token_reject'              => $this->sanitize_bool( $settings['allow_token_reject'] ?? $defaults['allow_token_reject'] ),
			'allow_unauthenticated_real_price_update_from_token' => $this->sanitize_bool( $settings['allow_unauthenticated_real_price_update_from_token'] ?? $defaults['allow_unauthenticated_real_price_update_from_token'] ),
			'price_match_box_enabled'         => $this->sanitize_bool( $settings['price_match_box_enabled'] ?? $defaults['price_match_box_enabled'] ),
			'price_match_box_show_on_product_page' => $this->sanitize_bool( $settings['price_match_box_show_on_product_page'] ?? $defaults['price_match_box_show_on_product_page'] ),
			'price_match_box_show_on_loop'    => $this->sanitize_bool( $settings['price_match_box_show_on_loop'] ?? $defaults['price_match_box_show_on_loop'] ),
			'price_match_box_position'        => $this->sanitize_choice(
				$settings['price_match_box_position'] ?? $defaults['price_match_box_position'],
				array( 'below_price', 'below_add_to_cart', 'product_summary_bottom' ),
				(string) $defaults['price_match_box_position']
			),
			'price_match_box_text'            => $this->sanitize_limited_text_setting( $settings['price_match_box_text'] ?? $defaults['price_match_box_text'], 220, (string) $defaults['price_match_box_text'] ),
			'price_match_box_subtext'         => $this->sanitize_limited_text_setting( $settings['price_match_box_subtext'] ?? $defaults['price_match_box_subtext'], 220, (string) $defaults['price_match_box_subtext'] ),
			'price_match_box_emoji'           => $this->sanitize_limited_text_setting( $settings['price_match_box_emoji'] ?? $defaults['price_match_box_emoji'], 12, (string) $defaults['price_match_box_emoji'] ),
			'price_match_box_use_theme_color' => $this->sanitize_bool( $settings['price_match_box_use_theme_color'] ?? $defaults['price_match_box_use_theme_color'] ),
			'price_match_box_background_color' => $this->sanitize_color_or_empty( $settings['price_match_box_background_color'] ?? $defaults['price_match_box_background_color'] ),
			'price_match_box_text_color'      => $this->sanitize_color_or_empty( $settings['price_match_box_text_color'] ?? $defaults['price_match_box_text_color'] ),
			'price_match_box_border_color'    => $this->sanitize_color_or_empty( $settings['price_match_box_border_color'] ?? $defaults['price_match_box_border_color'] ),
			'price_match_box_border_radius'   => $this->sanitize_int( $settings['price_match_box_border_radius'] ?? $defaults['price_match_box_border_radius'], 0, 40, (int) $defaults['price_match_box_border_radius'] ),
			'price_match_box_hide_if_no_active_match' => $this->sanitize_bool( $settings['price_match_box_hide_if_no_active_match'] ?? $defaults['price_match_box_hide_if_no_active_match'] ),
			'price_match_box_show_for_group_matches' => $this->sanitize_bool( $settings['price_match_box_show_for_group_matches'] ?? $defaults['price_match_box_show_for_group_matches'] ),
			'disable_coupons_for_price_matched_products' => $this->sanitize_bool( $settings['disable_coupons_for_price_matched_products'] ?? $defaults['disable_coupons_for_price_matched_products'] ),
			'rows_per_page'                   => $this->sanitize_int( $settings['rows_per_page'] ?? $defaults['rows_per_page'], 1, 200, (int) $defaults['rows_per_page'] ),
			'external_browser_worker_enabled' => $this->sanitize_bool( $settings['external_browser_worker_enabled'] ?? $defaults['external_browser_worker_enabled'] ),
			'external_browser_worker_endpoint' => $this->sanitize_webhook_url( $settings['external_browser_worker_endpoint'] ?? $defaults['external_browser_worker_endpoint'] ),
			'external_browser_worker_secret'  => $this->sanitize_secret( $settings['external_browser_worker_secret'] ?? $defaults['external_browser_worker_secret'] ),
			'external_browser_worker_timeout_seconds' => $this->sanitize_int( $settings['external_browser_worker_timeout_seconds'] ?? $defaults['external_browser_worker_timeout_seconds'], 5, 60, (int) $defaults['external_browser_worker_timeout_seconds'] ),
			'external_browser_worker_max_candidates' => $this->sanitize_int( $settings['external_browser_worker_max_candidates'] ?? $defaults['external_browser_worker_max_candidates'], 1, 25, (int) $defaults['external_browser_worker_max_candidates'] ),
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
	private function sanitize_webhook_url( $value ): string {
		$url = esc_url_raw( (string) $value );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		return substr( $url, 0, 500 );
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private function sanitize_secret( $value ): string {
		$secret = sanitize_text_field( (string) $value );

		return substr( trim( $secret ), 0, 255 );
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private function sanitize_limited_text_setting( $value, int $max_length, string $fallback ): string {
		$text = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $text ) {
			return $fallback;
		}

		return substr( $text, 0, $max_length );
	}

	/**
	 * @param mixed $value Raw color.
	 */
	private function sanitize_color_or_empty( $value ): string {
		$color = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $color ) {
			return '';
		}

		if ( function_exists( 'sanitize_hex_color' ) ) {
			$hex = sanitize_hex_color( $color );

			return is_string( $hex ) ? $hex : '';
		}

		return preg_match( '/^#[0-9A-Fa-f]{3,6}$/', $color ) ? $color : '';
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
