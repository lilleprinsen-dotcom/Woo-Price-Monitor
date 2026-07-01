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

	private GroupSuggestionService $group_suggestion_service;

	public function __construct( Repository $repository, ?PriceRecoveryService $recovery_service = null, ?PricingRuleService $pricing_rule_service = null, ?GroupSuggestionService $group_suggestion_service = null ) {
		$this->repository           = $repository;
		$this->recovery_service     = $recovery_service ?? new PriceRecoveryService();
		$this->pricing_rule_service = $pricing_rule_service ?? new PricingRuleService();
		$this->group_suggestion_service = $group_suggestion_service ?? new GroupSuggestionService( $repository, $this->pricing_rule_service );
	}

	/**
	 * @param array<string, mixed> $monitored_product Monitored product row.
	 * @param array<string, mixed> $competitor_link Competitor link row.
	 * @param object $product WooCommerce product object.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 * @return array<string, mixed>
	 */
	public function create_from_competitor_link( array $monitored_product, array $competitor_link, object $product, array $settings ): array {
		$current_price = $this->get_product_price( $product );

		if ( null === $current_price ) {
			return $this->skipped( __( 'Current product price is missing.', 'lilleprinsen-price-monitor' ) );
		}

		$competitor_link  = $this->select_market_competitor_link( $monitored_product, $competitor_link, $current_price );
		$competitor_price = $this->normalize_price( $competitor_link['last_price'] ?? null );

		if ( null === $competitor_price ) {
			return $this->skipped( __( 'Competitor price is missing.', 'lilleprinsen-price-monitor' ) );
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

		$db_status       = 'blocked' === (string) $rule['status'] ? 'blocked' : 'pending';
		$suggestion_type = 'manual_review' === (string) $rule['status'] ? 'manual_review' : (string) $base_plan['suggestion_type'];
		$reason          = $this->merge_reasons( (string) $base_plan['reason'], (string) $rule['reason'] );
		$suggested_price = (float) $rule['suggested_price'];
		$rule_details    = $rule['rule_details'];
		$group_context   = $this->group_suggestion_service->get_group_context( $monitored_product, $suggested_price, $settings );

		if ( $active_session && $this->is_recovery_suggestion_type( $suggestion_type ) ) {
			$rule_details['recovery_session'] = $this->get_recovery_session_summary( $active_session );
		}

		if ( $group_context ) {
			if ( ! empty( $group_context['skip'] ) ) {
				return $this->skipped( (string) $group_context['reason'] );
			}

			$rule_details['product_group'] = $group_context['details'];
			$reason = $this->merge_reasons( $reason, (string) $group_context['reason'] );

			if ( ! empty( $group_context['blocked'] ) ) {
				$db_status = 'blocked';
			}

			if ( ! empty( $group_context['force_manual_review'] ) ) {
				$suggestion_type = 'manual_review';
			}

			if ( ! empty( $active_session ) && $this->is_recovery_suggestion_type( $suggestion_type ) && ! empty( $group_context['affected_products'] ) && method_exists( $this->repository, 'get_active_price_match_sessions_for_products' ) ) {
				$recovery_report = $this->group_suggestion_service->detect_mixed_original_price_states(
					$this->repository->get_active_price_match_sessions_for_products( (array) $group_context['affected_products'] )
				);

				if ( ! empty( $recovery_report['mixed'] ) ) {
					$suggestion_type = 'manual_review';
					$reason          = $this->merge_reasons( $reason, (string) $recovery_report['reason'] );
					$rule_details['product_group']['recovery_warnings'] = $recovery_report['warnings'];
					$rule['warnings'] = array_values( array_unique( array_merge( (array) $rule['warnings'], (array) $recovery_report['warnings'] ) ) );
				}
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
			$suggestion['group_action_status'] = ! empty( $group_context['force_manual_review'] ) ? 'manual_review_only' : 'pending';
		}

		$open_suggestion = $this->repository->get_open_market_suggestion_for_monitored_product( (int) $monitored_product['id'] );

		if ( $open_suggestion ) {
			if ( $this->is_same_market_suggestion( $open_suggestion, $suggestion ) ) {
				return $this->skipped( __( 'A market suggestion already exists for this product and competitor price.', 'lilleprinsen-price-monitor' ) );
			}

			$updated = $this->repository->update_market_price_suggestion( (int) $open_suggestion['id'], $suggestion );

			if ( ! $updated ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Could not update the existing market price suggestion.', 'lilleprinsen-price-monitor' ),
				);
			}

			return array(
				'status'              => $db_status,
				'message'             => $this->merge_reasons( __( 'Updated the existing market suggestion for this product.', 'lilleprinsen-price-monitor' ), $reason ),
				'suggestion_id'       => (int) $open_suggestion['id'],
				'suggestion_type'     => $suggestion_type,
				'suggested_price'     => $suggested_price,
				'margin_after_change' => $rule['margin_after_change'],
				'warnings'            => $rule['warnings'],
				'rule_details'        => $rule_details,
			);
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

		if ( abs( $competitor_price - $current_price ) < 0.0001 ) {
			return array(
				'suggestion_type' => 'manual_review',
				'suggested_price' => $current_price,
				'status'          => 'skipped',
				'reason'          => __( 'Competitor price matches the current WooCommerce price, so no price suggestion is needed.', 'lilleprinsen-price-monitor' ),
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
			'suggested_price' => $current_price,
			'status'          => 'skipped',
			'reason'          => __( 'Competitor price is higher than the current WooCommerce price. No price-up suggestion is created unless there is an active market recovery session.', 'lilleprinsen-price-monitor' ),
		);
	}

	private function get_product_price( object $product ): ?float {
		if ( ! method_exists( $product, 'get_price' ) ) {
			return null;
		}

		return $this->normalize_price( $product->get_price() );
	}

	/**
	 * @param array<string, mixed> $monitored_product Monitored product row.
	 * @param array<string, mixed> $clicked_link Clicked competitor link row.
	 * @return array<string, mixed>
	 */
	private function select_market_competitor_link( array $monitored_product, array $clicked_link, float $current_price ): array {
		$market_link   = $clicked_link;
		$market_price  = $this->normalize_price( $clicked_link['last_price'] ?? null );
		$market_price  = null === $market_price ? PHP_FLOAT_MAX : $market_price;
		$competitor_links = $this->repository->get_competitor_links_for_monitored_product( (int) $monitored_product['id'] );

		foreach ( $competitor_links as $link ) {
			if ( empty( $link['enabled'] ) || 'not_comparable' === (string) ( $link['match_type'] ?? '' ) ) {
				continue;
			}

			$price = $this->normalize_price( $link['last_price'] ?? null );

			if ( null === $price ) {
				continue;
			}

			if ( $price < $current_price && $price < $market_price ) {
				$market_link  = $link;
				$market_price = $price;
			}
		}

		return $market_link;
	}

	/**
	 * @param array<string, mixed> $existing Existing open suggestion.
	 * @param array<string, mixed> $next Next suggestion payload.
	 */
	private function is_same_market_suggestion( array $existing, array $next ): bool {
		return (int) ( $existing['competitor_link_id'] ?? 0 ) === (int) ( $next['competitor_link_id'] ?? 0 )
			&& (string) ( $existing['status'] ?? '' ) === (string) ( $next['status'] ?? '' )
			&& abs( (float) ( $existing['competitor_price'] ?? 0 ) - (float) ( $next['competitor_price'] ?? 0 ) ) < 0.0001
			&& abs( (float) ( $existing['suggested_price'] ?? 0 ) - (float) ( $next['suggested_price'] ?? 0 ) ) < 0.0001
			&& (string) ( $existing['suggestion_type'] ?? '' ) === (string) ( $next['suggestion_type'] ?? '' );
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
	 * @return array<string, mixed>
	 */
	private function skipped( string $message ): array {
		return array(
			'status'  => 'skipped',
			'message' => $message,
		);
	}
}
