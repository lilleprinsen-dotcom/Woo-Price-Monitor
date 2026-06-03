<?php
/**
 * Dry-run price suggestion service.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

use Lilleprinsen\PriceMonitor\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SuggestionService {
	private Repository $repository;

	private PriceRecoveryService $recovery_service;

	private PricingRuleService $pricing_rule_service;

	public function __construct( Repository $repository, ?PriceRecoveryService $recovery_service = null, ?PricingRuleService $pricing_rule_service = null ) {
		$this->repository           = $repository;
		$this->recovery_service     = $recovery_service ?? new PriceRecoveryService();
		$this->pricing_rule_service = $pricing_rule_service ?? new PricingRuleService();
	}

	/**
	 * @param array<string, mixed> $monitored_product Monitored product row.
	 * @param array<string, mixed> $competitor_link Competitor link row.
	 * @param object $product WooCommerce product object.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 * @return array<string, mixed>
	 */
	public function create_from_competitor_link( array $monitored_product, array $competitor_link, object $product, array $settings ): array {
		$current_price    = $this->get_product_price( $product );
		$competitor_price = $this->normalize_price( $competitor_link['last_price'] ?? null );

		if ( null === $current_price || null === $competitor_price ) {
			return $this->skipped( __( 'Current product price or competitor price is missing.', 'lilleprinsen-price-monitor' ) );
		}

		$active_session = $this->repository->get_active_price_match_session_for_product( (int) $monitored_product['product_id'] );
		$base_plan      = $this->build_base_plan( $monitored_product, $current_price, $competitor_price, $settings, $active_session );

		if ( 'skipped' === (string) $base_plan['status'] ) {
			return $this->skipped( (string) $base_plan['reason'] );
		}

		$product_id = (int) $monitored_product['product_id'];
		$rule       = $this->pricing_rule_service->calculate_suggestion(
			array(
				'product_id'             => $product_id,
				'current_price'          => $current_price,
				'competitor_price'       => $competitor_price,
				'suggestion_type'        => (string) $base_plan['suggestion_type'],
				'status'                 => (string) $base_plan['status'],
				'monitored_product'      => $monitored_product,
				'active_price_match_session' => $active_session,
				'base_suggested_price'   => (float) $base_plan['suggested_price'],
				'base_reason'            => (string) $base_plan['reason'],
				'product_cost'           => $this->pricing_rule_service->get_product_cost( $product_id, $settings ),
				'currency'               => (string) ( $competitor_link['last_currency'] ?? ( $settings['default_currency'] ?? 'NOK' ) ),
				'product_is_on_sale'     => $this->product_is_on_sale( $product ),
				'product_stock_status'   => $this->get_product_stock_status( $product ),
			),
			$settings
		);

		if ( 'skipped' === (string) $rule['status'] ) {
			return $this->skipped( (string) $rule['reason'] );
		}

		if ( $this->repository->has_duplicate_pending_suggestion( (int) $monitored_product['id'], (int) $competitor_link['id'], $competitor_price ) ) {
			return $this->skipped( __( 'A pending suggestion already exists for this competitor price.', 'lilleprinsen-price-monitor' ) );
		}

		$db_status       = 'blocked' === (string) $rule['status'] ? 'blocked' : 'pending';
		$suggestion_type = 'manual_review' === (string) $rule['status'] ? 'manual_review' : (string) $base_plan['suggestion_type'];
		$reason          = $this->merge_reasons( (string) $base_plan['reason'], (string) $rule['reason'] );
		$suggested_price = (float) $rule['suggested_price'];
		$rule_details    = $rule['rule_details'];
		$group_context   = $this->get_group_context( $monitored_product, $suggested_price );

		if ( $active_session && $this->is_recovery_suggestion_type( $suggestion_type ) ) {
			$rule_details['recovery_session'] = $this->get_recovery_session_summary( $active_session );
		}

		if ( $group_context ) {
			if ( ! empty( $group_context['skip'] ) ) {
				return $this->skipped( (string) $group_context['reason'] );
			}

			$rule_details['product_group'] = $group_context['details'];
			$reason = $this->merge_reasons( $reason, (string) $group_context['reason'] );

			if ( 'manual_review_only' === (string) $group_context['pricing_mode'] ) {
				$suggestion_type = 'manual_review';
			}
		}

		$suggestion      = array(
			'monitored_product_id' => (int) $monitored_product['id'],
			'competitor_link_id'   => (int) $competitor_link['id'],
			'product_id'           => $product_id,
			'current_price'        => $current_price,
			'competitor_price'     => $competitor_price,
			'suggested_price'      => $suggested_price,
			'difference'           => $suggested_price - $current_price,
			'suggestion_type'      => $suggestion_type,
			'status'               => $db_status,
			'reason'               => $reason,
			'margin_after_change'  => $rule['margin_after_change'],
			'rule_details'         => $rule_details,
			'warnings'             => $rule['warnings'],
		);

		if ( $group_context ) {
			$suggestion['group_id']            = (int) $group_context['group_id'];
			$suggestion['applies_to_group']    = 1;
			$suggestion['group_action_status'] = 'pending';
		}

		$suggestion_id = $this->repository->create_price_suggestion( $suggestion );

		if ( $suggestion_id <= 0 ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Could not create price suggestion.', 'lilleprinsen-price-monitor' ),
			);
		}

		return array(
			'status'              => $db_status,
			'message'             => $reason,
			'suggestion_id'       => $suggestion_id,
			'suggestion_type'     => $suggestion_type,
			'suggested_price'     => $suggested_price,
			'margin_after_change' => $rule['margin_after_change'],
			'warnings'            => $rule['warnings'],
			'rule_details'        => $rule_details,
		);
	}

	/**
	 * @param array<string, mixed> $monitored_product Monitored product row.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 * @param array<string, mixed>|null $active_session Active match session.
	 * @return array{suggestion_type: string, suggested_price: float, status: string, reason: string}
	 */
	private function build_base_plan( array $monitored_product, float $current_price, float $competitor_price, array $settings, ?array $active_session ): array {
		if ( $competitor_price < $current_price ) {
			return array(
				'suggestion_type' => 'price_match_down',
				'suggested_price' => $competitor_price,
				'status'          => 'pending',
				'reason'          => __( 'Competitor price is lower than the current WooCommerce price.', 'lilleprinsen-price-monitor' ),
			);
		}

		if ( $active_session ) {
			$competitor_links = $this->repository->get_competitor_links_for_monitored_product( (int) $monitored_product['id'] );

			return $this->recovery_service->determine_recovery_suggestion(
				$current_price,
				$competitor_price,
				$active_session,
				$settings,
				$competitor_links
			);
		}

		return array(
			'suggestion_type' => 'manual_review',
			'suggested_price' => $competitor_price,
			'status'          => 'manual_review',
			'reason'          => __( 'Competitor price is higher than the current WooCommerce price and there is no active price match session.', 'lilleprinsen-price-monitor' ),
		);
	}

	private function get_product_price( object $product ): ?float {
		if ( ! method_exists( $product, 'get_price' ) ) {
			return null;
		}

		return $this->normalize_price( $product->get_price() );
	}

	private function product_is_on_sale( object $product ): bool {
		return method_exists( $product, 'is_on_sale' ) && (bool) $product->is_on_sale();
	}

	private function get_product_stock_status( object $product ): string {
		return method_exists( $product, 'get_stock_status' ) ? sanitize_key( (string) $product->get_stock_status() ) : '';
	}

	/**
	 * @param mixed $value Raw price.
	 */
	private function normalize_price( $value ): ?float {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$price = round( (float) $value, 4 );

		return $price > 0 ? $price : null;
	}

	private function merge_reasons( string $base_reason, string $rule_reason ): string {
		$base_reason = trim( $base_reason );
		$rule_reason = trim( $rule_reason );

		if ( '' === $base_reason ) {
			return $rule_reason;
		}

		if ( '' === $rule_reason || $base_reason === $rule_reason ) {
			return $base_reason;
		}

		return $base_reason . ' ' . $rule_reason;
	}

	private function is_recovery_suggestion_type( string $suggestion_type ): bool {
		return in_array(
			$suggestion_type,
			array(
				'price_match_up',
				'restore_previous_active_price',
				'restore_previous_regular_price',
				'restore_previous_sale_price',
				'manual_review',
			),
			true
		);
	}

	/**
	 * @param array<string, mixed> $session Active price match session.
	 * @return array<string, mixed>
	 */
	private function get_recovery_session_summary( array $session ): array {
		return array(
			'id'                         => isset( $session['id'] ) ? (int) $session['id'] : 0,
			'original_regular_price'     => $session['original_regular_price'] ?? null,
			'original_sale_price'        => $session['original_sale_price'] ?? null,
			'original_active_price'      => $session['original_active_price'] ?? null,
			'original_sale_start'        => $session['original_sale_start'] ?? null,
			'original_sale_end'          => $session['original_sale_end'] ?? null,
			'matched_price'              => $session['matched_price'] ?? null,
			'matched_at'                 => $session['matched_at'] ?? null,
			'recovery_strategy'          => $session['recovery_strategy'] ?? null,
			'last_lowest_competitor_price' => $session['last_lowest_competitor_price'] ?? null,
		);
	}

	/**
	 * @param array<string, mixed> $monitored_product Monitored product row.
	 * @return array<string, mixed>|null
	 */
	private function get_group_context( array $monitored_product, float $suggested_price ): ?array {
		$group = $this->repository->get_active_product_group_for_monitored_product( (int) $monitored_product['id'] );

		if ( ! $group ) {
			return null;
		}

		$members      = $this->repository->get_product_group_members( (int) $group['id'], true );
		$pricing_mode = (string) ( $group['pricing_mode'] ?? 'shared_price' );

		if ( 'primary_product_controls_group' === $pricing_mode && ! empty( $group['primary_product_id'] ) && (int) $group['primary_product_id'] !== (int) $monitored_product['product_id'] ) {
			return array(
				'skip'         => true,
				'reason'       => __( 'This product belongs to a primary-controlled group, and only the primary product may drive group suggestions.', 'lilleprinsen-price-monitor' ),
				'group_id'     => (int) $group['id'],
				'pricing_mode' => $pricing_mode,
			);
		}

		$warnings = array();

		foreach ( $members as $member ) {
			if ( $suggested_price <= 0 ) {
				$warnings[] = sprintf(
					/* translators: %d: product ID. */
					__( 'Product %d has an invalid suggested price.', 'lilleprinsen-price-monitor' ),
					(int) $member['product_id']
				);
			}

			if ( isset( $member['min_price'] ) && '' !== (string) $member['min_price'] && $suggested_price < (float) $member['min_price'] ) {
				$warnings[] = sprintf(
					/* translators: 1: product ID, 2: minimum price. */
					__( 'Product %1$d has a minimum price of %2$s.', 'lilleprinsen-price-monitor' ),
					(int) $member['product_id'],
					(string) $member['min_price']
				);
			}
		}

		return array(
			'skip'         => false,
			'group_id'     => (int) $group['id'],
			'pricing_mode' => $pricing_mode,
			'reason'       => 'manual_review_only' === $pricing_mode
				? __( 'This product belongs to a manual-review-only group. The suggestion is marked for manual review before any group action.', 'lilleprinsen-price-monitor' )
				: __( 'This product belongs to a price group. The suggestion is marked as group-aware and should be reviewed for all enabled members.', 'lilleprinsen-price-monitor' ),
			'details'      => array(
				'group_id'       => (int) $group['id'],
				'group_name'     => (string) ( $group['name'] ?? '' ),
				'pricing_mode'   => $pricing_mode,
				'member_count'   => count( $members ),
				'primary_product_id' => isset( $group['primary_product_id'] ) ? (int) $group['primary_product_id'] : 0,
				'warnings'       => $warnings,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function skipped( string $message ): array {
		return array(
			'status'  => 'skipped',
			'message' => $message,
		);
	}
}
