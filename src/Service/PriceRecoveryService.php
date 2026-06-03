<?php
/**
 * Price recovery planning service.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PriceRecoveryService {
	/**
	 * Determine what dry-run recovery suggestion should be created after a competitor price increases.
	 *
	 * TODO: Compare the active price match session, current competitor observations, and recovery settings.
	 * TODO: Return a structured suggestion type such as price_match_up, restore_previous_active_price,
	 * restore_previous_regular_price, restore_previous_sale_price, manual_review, or blocked.
	 *
	 * @param array<string, mixed> $session Active price match session state.
	 * @param array<int, array<string, mixed>> $competitor_observations Recent competitor observations.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 * @return array<string, mixed>
	 */
	public function determine_recovery_suggestion( array $session, array $competitor_observations, array $settings ): array {
		unset( $session, $competitor_observations, $settings );

		return array(
			'suggestion_type' => 'manual_review',
			'reason'          => __( 'Price recovery logic is not implemented yet. This version only stores recovery settings and session state.', 'lilleprinsen-price-monitor' ),
		);
	}

	/**
	 * Capture the original WooCommerce price state before a future price match update.
	 *
	 * TODO: Read regular price, sale price, active price, and sale schedule through WooCommerce CRUD APIs.
	 * TODO: This must not update product prices or write post meta directly.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return array<string, mixed>
	 */
	public function get_original_price_state( int $product_id ): array {
		unset( $product_id );

		return array();
	}

	/**
	 * Resolve the configured dry-run recovery strategy for a matched product.
	 *
	 * TODO: Account for competitor price relative to previous sale and regular prices.
	 * TODO: Account for multiple competitor rules such as lowest_valid_competitor.
	 *
	 * @param array<string, mixed> $session Active price match session state.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 * @return string
	 */
	public function get_recovery_strategy( array $session, array $settings ): string {
		unset( $session );

		return isset( $settings['recovery_when_competitor_increases'] )
			? sanitize_key( (string) $settings['recovery_when_competitor_increases'] )
			: 'suggest_only';
	}

	/**
	 * Build a human-readable reason for a future recovery suggestion.
	 *
	 * TODO: Explain the original price, matched price, competitor price movement, and selected strategy.
	 *
	 * @param string $strategy Selected recovery strategy.
	 * @param array<string, mixed> $session Active price match session state.
	 * @param array<string, mixed> $context Additional comparison context.
	 */
	public function explain_recovery_reason( string $strategy, array $session, array $context = array() ): string {
		unset( $session, $context );

		return sprintf(
			/* translators: %s: recovery strategy key. */
			__( 'Recovery strategy "%s" is reserved for future dry-run suggestions. No WooCommerce price update was performed.', 'lilleprinsen-price-monitor' ),
			$strategy
		);
	}
}
