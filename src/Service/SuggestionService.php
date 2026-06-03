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
			'rule_details'         => $rule['rule_details'],
			'warnings'             => $rule['warnings'],
		);

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
			'rule_details'        => $rule['rule_details'],
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
