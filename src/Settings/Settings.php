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
			'max_urls_per_batch'              => 10,
			'request_timeout_seconds'         => 8,
			'retry_failed_checks'             => 1,
			'default_min_margin_percent'      => '',
			'min_price_difference_to_suggest' => 10,
			'max_allowed_price_drop_percent'  => 25,
			'require_manual_approval'         => 1,
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
			'max_urls_per_batch'              => $this->sanitize_int( $settings['max_urls_per_batch'] ?? $defaults['max_urls_per_batch'], 1, 100, (int) $defaults['max_urls_per_batch'] ),
			'request_timeout_seconds'         => $this->sanitize_int( $settings['request_timeout_seconds'] ?? $defaults['request_timeout_seconds'], 1, 60, (int) $defaults['request_timeout_seconds'] ),
			'retry_failed_checks'             => $this->sanitize_bool( $settings['retry_failed_checks'] ?? $defaults['retry_failed_checks'] ),
			'default_min_margin_percent'      => $this->sanitize_decimal_or_empty( $settings['default_min_margin_percent'] ?? $defaults['default_min_margin_percent'], '' ),
			'min_price_difference_to_suggest' => $this->sanitize_decimal( $settings['min_price_difference_to_suggest'] ?? $defaults['min_price_difference_to_suggest'], (float) $defaults['min_price_difference_to_suggest'] ),
			'max_allowed_price_drop_percent'  => $this->sanitize_decimal_between( $settings['max_allowed_price_drop_percent'] ?? $defaults['max_allowed_price_drop_percent'], 0, 100, (float) $defaults['max_allowed_price_drop_percent'] ),
			'require_manual_approval'         => $this->sanitize_bool( $settings['require_manual_approval'] ?? $defaults['require_manual_approval'] ),
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
}
