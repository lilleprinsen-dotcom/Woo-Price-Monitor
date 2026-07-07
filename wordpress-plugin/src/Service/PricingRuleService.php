<?php
/**
 * Explainable pricing rule calculations for dry-run suggestions.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PricingRuleService {
	/**
	 * @param array<string, mixed> $context Suggestion context.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 * @return array{suggested_price: float, status: string, reason: string, rule_details: array<string, mixed>, margin_after_change: float|null, warnings: array<int, string>}
	 */
	public function calculate_suggestion( array $context, array $settings ): array {
		$current_price    = $this->normalize_price( $context['current_price'] ?? null );
		$competitor_price = $this->normalize_price( $context['competitor_price'] ?? null );
		$suggestion_type  = sanitize_key( (string) ( $context['suggestion_type'] ?? 'manual_review' ) );
		$monitored        = isset( $context['monitored_product'] ) && is_array( $context['monitored_product'] ) ? $context['monitored_product'] : array();
		$warnings         = array();
		$reason_parts     = array();
		$rule_details     = array(
			'suggestion_type' => $suggestion_type,
			'currency'        => $this->sanitize_currency( (string) ( $context['currency'] ?? ( $settings['default_currency'] ?? 'NOK' ) ) ),
			'vat_mode'        => $this->sanitize_choice(
				$settings['price_comparison_vat_mode'] ?? 'consumer_prices_include_vat',
				array( 'consumer_prices_include_vat', 'prices_exclude_vat' ),
				'consumer_prices_include_vat'
			),
			'vat_rate_percent' => $this->setting_float( $settings, 'vat_rate_percent', 25.0 ),
		);

		if ( null === $current_price || null === $competitor_price ) {
			return $this->result(
				0.0,
				'skipped',
				__( 'Current product price or competitor price is missing.', 'lilleprinsen-price-monitor' ),
				$rule_details,
				null,
				$warnings
			);
		}

		$strategy                  = $this->get_strategy( $monitored, $settings );
		$rule_details['strategy'] = $strategy;

		if ( 'notify_only' === $strategy ) {
			$rule_details['strategy_action'] = 'no_price_change';

			return $this->result(
				$current_price,
				'skipped',
				__( 'Strategy is notify only, so no dry-run price change suggestion was created.', 'lilleprinsen-price-monitor' ),
				$rule_details,
				null,
				$warnings
			);
		}

		$suggested_price = $this->get_initial_suggested_price( $current_price, $competitor_price, $suggestion_type, $strategy, $context, $settings, $rule_details, $reason_parts );

		if ( $suggested_price <= 0 ) {
			return $this->result(
				$suggested_price,
				'blocked',
				__( 'The pricing rule produced a non-positive suggested price, so it was blocked.', 'lilleprinsen-price-monitor' ),
				$rule_details,
				null,
				$warnings
			);
		}

		$rounded_price = $this->apply_rounding( $suggested_price, (string) ( $settings['rounding_mode'] ?? 'none' ), $rule_details );

		if ( abs( $rounded_price - $suggested_price ) >= 0.0001 ) {
			$reason_parts[] = sprintf(
				/* translators: 1: original price, 2: rounded price. */
				__( 'Rounding changed the rule price from %.2f to %.2f.', 'lilleprinsen-price-monitor' ),
				$suggested_price,
				$rounded_price
			);
		}

		$suggested_price = $rounded_price;
		$status          = 'pending';
		$margin_after    = null;

		if ( 'manual_review' === $suggestion_type || 'manual_review' === (string) ( $context['status'] ?? '' ) ) {
			$status = 'manual_review';
			$reason_parts[] = __( 'This situation needs manual review before any future pricing action.', 'lilleprinsen-price-monitor' );
		}

		$product_is_on_sale = ! empty( $context['product_is_on_sale'] );
		$stock_status       = sanitize_key( (string) ( $context['product_stock_status'] ?? '' ) );

		if ( ! empty( $settings['block_suggestions_for_sale_products'] ) && $product_is_on_sale ) {
			$status         = 'blocked';
			$reason_parts[] = __( 'The WooCommerce product is currently on sale and sale-product suggestions are blocked.', 'lilleprinsen-price-monitor' );
		}

		if ( ! empty( $settings['block_suggestions_for_out_of_stock_products'] ) && 'outofstock' === $stock_status ) {
			$status         = 'blocked';
			$reason_parts[] = __( 'The WooCommerce product is out of stock and out-of-stock suggestions are blocked.', 'lilleprinsen-price-monitor' );
		}

		$min_difference = $this->setting_float( $settings, 'min_price_difference_to_suggest', 10.0 );
		$difference     = abs( $suggested_price - $current_price );

		if ( $difference < $min_difference && 'blocked' !== $status && 'manual_review' !== $status ) {
			return $this->result(
				$suggested_price,
				'skipped',
				sprintf(
					/* translators: 1: price difference, 2: configured minimum. */
					__( 'Suggested price difference %.2f is below the configured minimum %.2f.', 'lilleprinsen-price-monitor' ),
					$difference,
					$min_difference
				),
				$rule_details,
				null,
				$warnings
			);
		}

		$min_price = $this->monitored_float_or_null( $monitored, 'min_price' );

		if ( null !== $min_price && $suggested_price < $min_price ) {
			$status         = 'blocked';
			$reason_parts[] = sprintf(
				/* translators: 1: suggested price, 2: configured minimum price. */
				__( 'Suggested price %.2f is below the product minimum price %.2f.', 'lilleprinsen-price-monitor' ),
				$suggested_price,
				$min_price
			);
		}

		$cost = array_key_exists( 'product_cost', $context ) ? $this->normalize_price_or_zero( $context['product_cost'] ) : null;

		if ( null === $cost && 'custom_meta_key' === (string) ( $settings['cost_source'] ?? 'none' ) ) {
			if ( ! empty( $settings['block_if_cost_missing'] ) ) {
				$status         = 'blocked';
				$reason_parts[] = __( 'Product cost is required for margin checks, but no valid cost was found.', 'lilleprinsen-price-monitor' );
			} else {
				$warnings[] = __( 'No product cost was found, so margin checks were skipped.', 'lilleprinsen-price-monitor' );
			}
		}

		if ( null !== $cost ) {
			$profit_after = $suggested_price - $cost;
			$margin_after = $suggested_price > 0 ? round( ( $profit_after / $suggested_price ) * 100, 2 ) : null;

			$rule_details['cost']                = $cost;
			$rule_details['profit_after_change'] = round( $profit_after, 4 );

			$minimum_profit = $this->setting_float_or_null( $settings, 'minimum_profit_amount' );

			if ( null !== $minimum_profit && $profit_after < $minimum_profit ) {
				$status         = 'blocked';
				$reason_parts[] = sprintf(
					/* translators: 1: profit after price change, 2: configured minimum profit. */
					__( 'Profit after the change would be %.2f, below the minimum profit %.2f.', 'lilleprinsen-price-monitor' ),
					$profit_after,
					$minimum_profit
				);
			}

			$minimum_margin = $this->get_min_margin_percent( $monitored, $settings );

			if ( null !== $minimum_margin && null !== $margin_after && $margin_after < $minimum_margin ) {
				$status         = 'blocked';
				$reason_parts[] = sprintf(
					/* translators: 1: margin after price change, 2: configured minimum margin. */
					__( 'Margin after the change would be %.2f%%, below the minimum margin %.2f%%.', 'lilleprinsen-price-monitor' ),
					$margin_after,
					$minimum_margin
				);
			}
		}

		if ( $suggested_price < $current_price && $current_price > 0 ) {
			$drop_percent = ( ( $current_price - $suggested_price ) / $current_price ) * 100;
			$max_drop     = $this->setting_float( $settings, 'max_allowed_price_drop_percent', 25.0 );

			$rule_details['price_drop_percent'] = round( $drop_percent, 2 );

			if ( $drop_percent > $max_drop ) {
				$status         = 'blocked';
				$reason_parts[] = sprintf(
					/* translators: 1: drop percent, 2: configured maximum. */
					__( 'Price drop of %.2f%% exceeds the configured maximum %.2f%%.', 'lilleprinsen-price-monitor' ),
					$drop_percent,
					$max_drop
				);
			}
		}

		if ( $suggested_price > $current_price && $current_price > 0 ) {
			$increase_percent = ( ( $suggested_price - $current_price ) / $current_price ) * 100;
			$max_increase     = $this->setting_float( $settings, 'max_allowed_price_increase_percent', 50.0 );

			$rule_details['price_increase_percent'] = round( $increase_percent, 2 );

			if ( $increase_percent > $max_increase ) {
				$status         = 'blocked';
				$reason_parts[] = sprintf(
					/* translators: 1: increase percent, 2: configured maximum. */
					__( 'Price increase of %.2f%% exceeds the configured maximum %.2f%%.', 'lilleprinsen-price-monitor' ),
					$increase_percent,
					$max_increase
				);
			}
		}

		if ( empty( $reason_parts ) ) {
			$reason_parts[] = __( 'Pricing rules created a dry-run suggestion for manual approval.', 'lilleprinsen-price-monitor' );
		}

		$rule_details['final_status']    = $status;
		$rule_details['suggested_price'] = round( $suggested_price, 4 );

		return $this->result(
			$suggested_price,
			$status,
			implode( ' ', array_unique( $reason_parts ) ),
			$rule_details,
			$margin_after,
			array_values( array_unique( $warnings ) )
		);
	}

	/**
	 * Looks up one product cost only when a single suggestion is being created.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	public function get_product_cost( int $product_id, array $settings ): ?float {
		if ( $product_id <= 0 || 'custom_meta_key' !== (string) ( $settings['cost_source'] ?? 'none' ) ) {
			return null;
		}

		$meta_key = $this->sanitize_meta_key( (string) ( $settings['cost_meta_key'] ?? '' ) );

		if ( '' === $meta_key || ! function_exists( 'get_post_meta' ) ) {
			return null;
		}

		$value = get_post_meta( $product_id, $meta_key, true );

		return $this->normalize_price_or_zero( $value );
	}

	/**
	 * @param array<string, mixed> $rule_details Rule details.
	 * @param array<int, string>   $warnings Warning messages.
	 * @return array{suggested_price: float, status: string, reason: string, rule_details: array<string, mixed>, margin_after_change: float|null, warnings: array<int, string>}
	 */
	private function result( float $suggested_price, string $status, string $reason, array $rule_details, ?float $margin_after_change, array $warnings ): array {
		return array(
			'suggested_price'      => round( max( 0, $suggested_price ), 4 ),
			'status'               => $this->sanitize_result_status( $status ),
			'reason'               => $reason,
			'rule_details'         => $rule_details,
			'margin_after_change'  => null === $margin_after_change ? null : round( $margin_after_change, 2 ),
			'warnings'             => array_values( $warnings ),
		);
	}

	/**
	 * @param array<string, mixed> $monitored Monitored product row.
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	private function get_strategy( array $monitored, array $settings ): string {
		$allowed = array( 'notify_only', 'match_competitor', 'beat_competitor_by_amount', 'stay_above_competitor_by_amount' );
		$strategy = isset( $monitored['strategy'] ) ? sanitize_key( (string) $monitored['strategy'] ) : '';

		if ( in_array( $strategy, $allowed, true ) ) {
			return $strategy;
		}

		return $this->sanitize_choice( $settings['default_pricing_strategy'] ?? 'match_competitor', $allowed, 'match_competitor' );
	}

	/**
	 * @param array<string, mixed> $context Rule context.
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @param array<string, mixed> $rule_details Rule details.
	 * @param array<int, string>   $reason_parts Reason parts.
	 */
	private function get_initial_suggested_price( float $current_price, float $competitor_price, string $suggestion_type, string $strategy, array $context, array $settings, array &$rule_details, array &$reason_parts ): float {
		$base_price = $this->normalize_price( $context['base_suggested_price'] ?? null );
		$base_reason = isset( $context['base_reason'] ) ? trim( (string) $context['base_reason'] ) : '';

		if ( null !== $base_price && 'price_match_down' !== $suggestion_type ) {
			$rule_details['base_price_source'] = 'recovery_service';
			$reason_parts[] = '' !== $base_reason ? $base_reason : __( 'Recovery rules supplied the initial suggested price.', 'lilleprinsen-price-monitor' );
			return $base_price;
		}

		if ( 'manual_review' === $suggestion_type ) {
			$rule_details['base_price_source'] = 'manual_review_competitor_price';
			$reason_parts[] = '' !== $base_reason ? $base_reason : __( 'Competitor price is above the current WooCommerce price without an active price match session.', 'lilleprinsen-price-monitor' );
			return $competitor_price;
		}

		switch ( $strategy ) {
			case 'beat_competitor_by_amount':
				$amount = $this->setting_float( $settings, 'beat_competitor_amount', 1.0 );
				$rule_details['strategy_amount'] = $amount;
				$reason_parts[] = sprintf(
					/* translators: %s: configured strategy amount. */
					__( 'Strategy beat competitor by amount was used with %.2f.', 'lilleprinsen-price-monitor' ),
					$amount
				);
				return $competitor_price - $amount;
			case 'stay_above_competitor_by_amount':
				$amount = $this->setting_float( $settings, 'stay_above_competitor_amount', 1.0 );
				$rule_details['strategy_amount'] = $amount;
				$reason_parts[] = sprintf(
					/* translators: %s: configured strategy amount. */
					__( 'Strategy stay above competitor by amount was used with %.2f.', 'lilleprinsen-price-monitor' ),
					$amount
				);
				return $competitor_price + $amount;
			case 'match_competitor':
			default:
				$reason_parts[] = __( 'Strategy match competitor was used.', 'lilleprinsen-price-monitor' );
				return $competitor_price;
		}
	}

	/**
	 * @param array<string, mixed> $rule_details Rule details.
	 */
	private function apply_rounding( float $price, string $rounding_mode, array &$rule_details ): float {
		$rounding_mode = $this->sanitize_choice(
			$rounding_mode,
			array( 'none', 'nearest_1', 'nearest_5', 'nearest_10', 'nearest_50', 'nearest_100', 'end_9', 'end_99', 'end_95' ),
			'none'
		);
		$rule_details['rounding_mode'] = $rounding_mode;

		switch ( $rounding_mode ) {
			case 'nearest_1':
				return round( $price, 0 );
			case 'nearest_5':
				return round( $price / 5 ) * 5;
			case 'nearest_10':
				return round( $price / 10 ) * 10;
			case 'nearest_50':
				return round( $price / 50 ) * 50;
			case 'nearest_100':
				return round( $price / 100 ) * 100;
			case 'end_9':
				return $this->round_to_ending( $price, 9, 10 );
			case 'end_99':
				return $this->round_to_ending( $price, 99, 100 );
			case 'end_95':
				return $this->round_to_ending( $price, 95, 100 );
			case 'none':
			default:
				return round( $price, 4 );
		}
	}

	private function round_to_ending( float $price, int $ending, int $base ): float {
		$floor = floor( $price / $base ) * $base + $ending;
		$ceil  = $floor < $price ? $floor + $base : $floor;

		if ( $floor <= 0 ) {
			return max( 0.01, round( $ceil, 2 ) );
		}

		return abs( $price - $floor ) <= abs( $ceil - $price ) ? round( $floor, 2 ) : round( $ceil, 2 );
	}

	/**
	 * @param array<string, mixed> $monitored Monitored product row.
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	private function get_min_margin_percent( array $monitored, array $settings ): ?float {
		$product_margin = $this->monitored_float_or_null( $monitored, 'min_margin_percent' );

		if ( null !== $product_margin ) {
			return $product_margin;
		}

		return $this->setting_float_or_null( $settings, 'default_min_margin_percent' );
	}

	/**
	 * @param array<string, mixed> $monitored Monitored product row.
	 */
	private function monitored_float_or_null( array $monitored, string $key ): ?float {
		if ( ! array_key_exists( $key, $monitored ) || '' === $monitored[ $key ] || null === $monitored[ $key ] || ! is_numeric( $monitored[ $key ] ) ) {
			return null;
		}

		return max( 0, (float) $monitored[ $key ] );
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	private function setting_float( array $settings, string $key, float $fallback ): float {
		if ( ! isset( $settings[ $key ] ) || '' === $settings[ $key ] || ! is_numeric( $settings[ $key ] ) ) {
			return $fallback;
		}

		return max( 0, (float) $settings[ $key ] );
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	private function setting_float_or_null( array $settings, string $key ): ?float {
		if ( ! isset( $settings[ $key ] ) || '' === $settings[ $key ] || ! is_numeric( $settings[ $key ] ) ) {
			return null;
		}

		return max( 0, (float) $settings[ $key ] );
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

	/**
	 * @param mixed $value Raw price.
	 */
	private function normalize_price_or_zero( $value ): ?float {
		if ( null === $value || '' === $value || ! is_numeric( str_replace( ',', '.', (string) $value ) ) ) {
			return null;
		}

		$price = round( (float) str_replace( ',', '.', (string) $value ), 4 );

		return $price >= 0 ? $price : null;
	}

	private function sanitize_result_status( string $status ): string {
		return $this->sanitize_choice( $status, array( 'pending', 'blocked', 'skipped', 'manual_review' ), 'pending' );
	}

	/**
	 * @param mixed $value Raw value.
	 * @param array<int, string> $allowed Allowed values.
	 */
	private function sanitize_choice( $value, array $allowed, string $fallback ): string {
		$value = sanitize_key( (string) $value );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private function sanitize_currency( string $currency ): string {
		$currency = strtoupper( sanitize_text_field( $currency ) );
		$currency = preg_replace( '/[^A-Z]/', '', $currency );

		return is_string( $currency ) && '' !== $currency ? substr( $currency, 0, 10 ) : 'NOK';
	}

	private function sanitize_meta_key( string $meta_key ): string {
		$meta_key = sanitize_text_field( $meta_key );
		$meta_key = preg_replace( '/[^A-Za-z0-9_.:-]/', '', $meta_key );

		return is_string( $meta_key ) ? substr( $meta_key, 0, 191 ) : '';
	}
}
