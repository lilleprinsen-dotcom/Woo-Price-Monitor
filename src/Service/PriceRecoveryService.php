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
	 * This service never updates WooCommerce prices. It only returns a dry-run suggestion plan.
	 *
	 * @param float $current_price Current WooCommerce active price.
	 * @param float $new_competitor_price Newly observed competitor price.
	 * @param array<string, mixed> $session Active price match session state.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 * @param array<int, array<string, mixed>> $competitor_links Known competitor links for the monitored product.
	 * @return array{suggestion_type: string, suggested_price: float, status: string, reason: string}
	 */
	public function determine_recovery_suggestion( float $current_price, float $new_competitor_price, array $session, array $settings, array $competitor_links = array() ): array {
		$original_sale_price    = $this->nullable_float( $session['original_sale_price'] ?? null );
		$original_regular_price = $this->nullable_float( $session['original_regular_price'] ?? null );
		$original_active_price  = $this->nullable_float( $session['original_active_price'] ?? null );
		$matched_price          = $this->nullable_float( $session['matched_price'] ?? null );
		$plan                   = $this->manual_review(
			$current_price,
			__( 'Competitor price increased during an active price match session. Manual dry-run review is required.', 'lilleprinsen-price-monitor' )
		);

		if ( empty( $session ) ) {
			return $plan;
		}

		if ( null === $original_active_price && null === $original_regular_price && null === $original_sale_price ) {
			return $this->manual_review( $current_price, __( 'Original price state is missing from the price match session, so recovery needs manual review.', 'lilleprinsen-price-monitor' ) );
		}

		if ( null !== $matched_price && 'active_dry_run' !== (string) ( $session['status'] ?? '' ) && abs( $current_price - $matched_price ) > 0.0001 ) {
			return $this->manual_review( $current_price, __( 'WooCommerce product price changed since the price match session was created. Recovery needs manual review before any future update.', 'lilleprinsen-price-monitor' ) );
		}

		if ( null !== $original_sale_price && $new_competitor_price < $original_sale_price ) {
			$plan = $this->plan_for_below_previous_sale_price( $current_price, $new_competitor_price, $settings );
		} elseif ( null !== $original_regular_price && $new_competitor_price > $original_regular_price ) {
			$plan = $this->plan_for_above_previous_regular_price( $current_price, $new_competitor_price, $original_regular_price, $original_active_price, $settings );
		} elseif ( null !== $original_sale_price && null !== $original_regular_price && $new_competitor_price > $original_sale_price && $new_competitor_price < $original_regular_price ) {
			$plan = $this->plan_for_between_sale_and_regular_price( $current_price, $new_competitor_price, $original_sale_price, $original_regular_price, $original_active_price, $settings );
		}

		$basis_blocker = $this->get_recovery_basis_blocker( $competitor_links, $new_competitor_price, (float) $plan['suggested_price'], $current_price, $settings );

		if ( null !== $basis_blocker ) {
			return $basis_blocker;
		}

		if ( $this->exceeds_max_increase( $current_price, (float) $plan['suggested_price'], $settings ) ) {
			return $this->skipped( $current_price, __( 'Recovery suggestion skipped because the suggested price increase exceeds the configured maximum increase percent.', 'lilleprinsen-price-monitor' ) );
		}

		return $plan;
	}

	/**
	 * Capture the original WooCommerce price state before a future dry-run price match session.
	 *
	 * This reads through WooCommerce CRUD APIs and does not update product prices or post meta.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return array<string, mixed>
	 */
	public function get_original_price_state( int $product_id ): array {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = wc_get_product( $product_id );

		if ( ! is_object( $product ) ) {
			return array();
		}

		return array(
			'original_regular_price' => method_exists( $product, 'get_regular_price' ) ? $product->get_regular_price() : null,
			'original_sale_price'    => method_exists( $product, 'get_sale_price' ) ? $product->get_sale_price() : null,
			'original_active_price'  => method_exists( $product, 'get_price' ) ? $product->get_price() : null,
			'original_sale_start'    => $this->format_wc_datetime( method_exists( $product, 'get_date_on_sale_from' ) ? $product->get_date_on_sale_from() : null ),
			'original_sale_end'      => $this->format_wc_datetime( method_exists( $product, 'get_date_on_sale_to' ) ? $product->get_date_on_sale_to() : null ),
		);
	}

	/**
	 * Resolve the configured dry-run recovery strategy for a matched product.
	 *
	 * @param array<string, mixed> $session Active price match session state.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
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
	 * @param string $strategy Selected recovery strategy.
	 * @param array<string, mixed> $session Active price match session state.
	 * @param array<string, mixed> $context Additional comparison context.
	 */
	public function explain_recovery_reason( string $strategy, array $session, array $context = array() ): string {
		unset( $session, $context );

		return sprintf(
			/* translators: %s: recovery strategy key. */
			__( 'Recovery strategy "%s" created a dry-run suggestion only. No WooCommerce price update was performed.', 'lilleprinsen-price-monitor' ),
			$strategy
		);
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return array{suggestion_type: string, suggested_price: float, status: string, reason: string}
	 */
	private function plan_for_below_previous_sale_price( float $current_price, float $competitor_price, array $settings ): array {
		$strategy = $this->setting_choice( $settings, 'recovery_if_competitor_still_below_previous_sale_price', 'suggest_match_competitor' );

		if ( 'suggest_match_competitor' === $strategy || 'suggest_only' === $strategy ) {
			return array(
				'suggestion_type' => 'price_match_up',
				'suggested_price' => $competitor_price,
				'status'          => 'pending',
				'reason'          => __( 'Competitor price increased, but is still below previous sale price. Suggesting match to recover margin while staying competitive.', 'lilleprinsen-price-monitor' ),
			);
		}

		if ( 'keep_current_price' === $strategy ) {
			return $this->manual_review( $current_price, __( 'Competitor is still below the previous sale price, and settings prefer keeping the current price.', 'lilleprinsen-price-monitor' ) );
		}

		return $this->manual_review( $current_price, __( 'Competitor remains below the previous sale price. Manual review is safer than restoring a higher price.', 'lilleprinsen-price-monitor' ) );
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return array{suggestion_type: string, suggested_price: float, status: string, reason: string}
	 */
	private function plan_for_between_sale_and_regular_price( float $current_price, float $competitor_price, float $original_sale_price, float $original_regular_price, ?float $original_active_price, array $settings ): array {
		$strategy = $this->setting_choice( $settings, 'recovery_when_competitor_increases', 'suggest_only' );

		if ( 'suggest_restore_previous_active_price' === $strategy ) {
			if ( null === $original_active_price ) {
				return $this->manual_review( $current_price, __( 'Previous active price is missing from the session, so active-price restore needs manual review.', 'lilleprinsen-price-monitor' ) );
			}

			return array(
				'suggestion_type' => 'restore_previous_active_price',
				'suggested_price' => $original_active_price,
				'status'          => 'pending',
				'reason'          => __( 'Competitor price increased above the previous sale price but below regular price. Suggesting restore to the previous active price captured before the match.', 'lilleprinsen-price-monitor' ),
			);
		}

		if ( 'suggest_restore_previous_sale_price' === $strategy ) {
			return array(
				'suggestion_type' => 'restore_previous_sale_price',
				'suggested_price' => $original_sale_price,
				'status'          => 'pending',
				'reason'          => __( 'Competitor price is above the previous sale price but below regular price. Suggesting restore to the previous sale price.', 'lilleprinsen-price-monitor' ),
			);
		}

		if ( 'suggest_match_competitor' === $strategy ) {
			return array(
				'suggestion_type' => 'price_match_up',
				'suggested_price' => $competitor_price,
				'status'          => 'pending',
				'reason'          => __( 'Competitor price increased above the previous sale price but remains below regular price. Suggesting an exact competitor match.', 'lilleprinsen-price-monitor' ),
			);
		}

		if ( 'suggest_restore_previous_regular_price' === $strategy ) {
			return array(
				'suggestion_type' => 'restore_previous_regular_price',
				'suggested_price' => $original_regular_price,
				'status'          => 'pending',
				'reason'          => __( 'Competitor price increased above the previous sale price but remains below regular price. Settings suggest restoring the previous regular price for manual review.', 'lilleprinsen-price-monitor' ),
			);
		}

		return $this->manual_review( $current_price, __( 'Competitor price increased above the previous sale price but remains below regular price. Manual review is required by the configured recovery strategy.', 'lilleprinsen-price-monitor' ) );
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return array{suggestion_type: string, suggested_price: float, status: string, reason: string}
	 */
	private function plan_for_above_previous_regular_price( float $current_price, float $competitor_price, float $original_regular_price, ?float $original_active_price, array $settings ): array {
		$strategy = $this->setting_choice( $settings, 'recovery_if_competitor_above_previous_regular_price', 'suggest_restore_previous_regular_price' );

		if ( 'suggest_restore_previous_active_price' === $strategy ) {
			if ( null === $original_active_price ) {
				return $this->manual_review( $current_price, __( 'Previous active price is missing from the session, so active-price restore needs manual review.', 'lilleprinsen-price-monitor' ) );
			}

			return array(
				'suggestion_type' => 'restore_previous_active_price',
				'suggested_price' => $original_active_price,
				'status'          => 'pending',
				'reason'          => __( 'Competitor is now above the previous regular price. Suggesting restore to the previous active price captured before the match.', 'lilleprinsen-price-monitor' ),
			);
		}

		if ( 'suggest_restore_previous_regular_price' === $strategy || 'suggest_only' === $strategy ) {
			return array(
				'suggestion_type' => 'restore_previous_regular_price',
				'suggested_price' => $original_regular_price,
				'status'          => 'pending',
				'reason'          => __( 'Competitor is now above the previous regular price. Suggesting restore to previous regular price.', 'lilleprinsen-price-monitor' ),
			);
		}

		if ( 'suggest_match_competitor' === $strategy ) {
			return array(
				'suggestion_type' => 'price_match_up',
				'suggested_price' => $competitor_price,
				'status'          => 'pending',
				'reason'          => __( 'Competitor price increased above the previous regular price. Settings suggest matching competitor price.', 'lilleprinsen-price-monitor' ),
			);
		}

		return $this->manual_review( $current_price, __( 'Competitor price increased above the previous regular price. Settings prefer keeping current price, so manual review is required.', 'lilleprinsen-price-monitor' ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $competitor_links Known competitor links.
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return array{suggestion_type: string, suggested_price: float, status: string, reason: string}|null
	 */
	private function get_recovery_basis_blocker( array $competitor_links, float $triggering_competitor_price, float $proposed_price, float $current_price, array $settings ): ?array {
		$basis = $this->setting_choice( $settings, 'multiple_competitor_recovery_basis', 'lowest_valid_competitor' );

		if ( 'primary_competitor' === $basis ) {
			return $this->get_primary_competitor_blocker( $competitor_links, $proposed_price, $current_price, $settings );
		}

		if ( 'all_competitors_must_increase' === $basis ) {
			return $this->get_all_competitors_blocker( $competitor_links, $proposed_price, $current_price, $settings );
		}

		foreach ( $competitor_links as $link ) {
			if ( ! $this->is_comparable_link( $link, false ) || $this->is_link_stale( $link, $settings ) || empty( $link['last_price'] ) ) {
				continue;
			}

			$last_price = $this->nullable_float( $link['last_price'] );

			if ( null === $last_price || $last_price === $triggering_competitor_price ) {
				continue;
			}

			if ( $last_price < $proposed_price ) {
				return $this->skipped( $current_price, __( 'Recovery suggestion skipped because another active comparable competitor still has a lower last checked price than the proposed recovery price.', 'lilleprinsen-price-monitor' ) );
			}
		}

		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $competitor_links Known competitor links.
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return array{suggestion_type: string, suggested_price: float, status: string, reason: string}|null
	 */
	private function get_primary_competitor_blocker( array $competitor_links, float $proposed_price, float $current_price, array $settings ): ?array {
		foreach ( $competitor_links as $link ) {
			if ( empty( $link['is_primary'] ) || ! $this->is_comparable_link( $link, false ) ) {
				continue;
			}

			if ( $this->is_link_stale( $link, $settings ) || empty( $link['last_price'] ) ) {
				return $this->manual_review( $current_price, __( 'Primary competitor price data is missing or stale, so recovery needs manual review.', 'lilleprinsen-price-monitor' ) );
			}

			$last_price = $this->nullable_float( $link['last_price'] );

			if ( null === $last_price ) {
				return $this->manual_review( $current_price, __( 'Primary competitor does not have a valid last price, so recovery needs manual review.', 'lilleprinsen-price-monitor' ) );
			}

			if ( $last_price < $proposed_price ) {
				return $this->skipped( $current_price, __( 'Recovery suggestion skipped because the primary competitor is still below the proposed recovery price.', 'lilleprinsen-price-monitor' ) );
			}

			return null;
		}

		return $this->manual_review( $current_price, __( 'Recovery basis is primary competitor, but no primary competitor is set for this monitored product.', 'lilleprinsen-price-monitor' ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $competitor_links Known competitor links.
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return array{suggestion_type: string, suggested_price: float, status: string, reason: string}|null
	 */
	private function get_all_competitors_blocker( array $competitor_links, float $proposed_price, float $current_price, array $settings ): ?array {
		$comparable_count = 0;

		foreach ( $competitor_links as $link ) {
			if ( ! $this->is_comparable_link( $link, true ) ) {
				continue;
			}

			$comparable_count++;

			if ( $this->is_link_stale( $link, $settings ) || empty( $link['last_price'] ) ) {
				return $this->manual_review( $current_price, __( 'At least one exact or similar competitor has missing or stale price data, so all-competitor recovery needs manual review.', 'lilleprinsen-price-monitor' ) );
			}

			$last_price = $this->nullable_float( $link['last_price'] );

			if ( null === $last_price ) {
				return $this->manual_review( $current_price, __( 'At least one exact or similar competitor lacks a valid last price, so all-competitor recovery needs manual review.', 'lilleprinsen-price-monitor' ) );
			}

			if ( $last_price < $proposed_price ) {
				return $this->skipped( $current_price, __( 'Recovery suggestion skipped because an exact or similar competitor is still below the proposed recovery price.', 'lilleprinsen-price-monitor' ) );
			}
		}

		if ( 0 === $comparable_count ) {
			return $this->manual_review( $current_price, __( 'All-competitor recovery requires at least one enabled exact or similar competitor with fresh price data.', 'lilleprinsen-price-monitor' ) );
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $link Competitor link row.
	 */
	private function is_comparable_link( array $link, bool $exact_or_similar_only ): bool {
		if ( empty( $link['enabled'] ) ) {
			return false;
		}

		$match_type = sanitize_key( (string) ( $link['match_type'] ?? 'unknown' ) );

		if ( 'not_comparable' === $match_type ) {
			return false;
		}

		if ( $exact_or_similar_only ) {
			return in_array( $match_type, array( 'exact', 'similar' ), true );
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $link Competitor link row.
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	private function is_link_stale( array $link, array $settings ): bool {
		if ( empty( $link['last_checked_at'] ) ) {
			return false;
		}

		$checked_at = strtotime( (string) $link['last_checked_at'] );

		if ( false === $checked_at ) {
			return true;
		}

		$max_age_hours = max( 1, (int) ( $settings['recovery_max_competitor_price_age_hours'] ?? 48 ) );

		return $checked_at < ( $this->current_timestamp() - ( $max_age_hours * HOUR_IN_SECONDS ) );
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	private function exceeds_max_increase( float $current_price, float $suggested_price, array $settings ): bool {
		if ( $current_price <= 0 || $suggested_price <= $current_price ) {
			return false;
		}

		$increase_percent = ( ( $suggested_price - $current_price ) / $current_price ) * 100;
		$max_increase     = isset( $settings['max_allowed_price_increase_percent'] ) && is_numeric( $settings['max_allowed_price_increase_percent'] )
			? (float) $settings['max_allowed_price_increase_percent']
			: 50.0;

		return $increase_percent > $max_increase;
	}

	/**
	 * @return array{suggestion_type: string, suggested_price: float, status: string, reason: string}
	 */
	private function manual_review( float $current_price, string $reason ): array {
		return array(
			'suggestion_type' => 'manual_review',
			'suggested_price' => $current_price,
			'status'          => 'pending',
			'reason'          => $reason,
		);
	}

	/**
	 * @return array{suggestion_type: string, suggested_price: float, status: string, reason: string}
	 */
	private function skipped( float $current_price, string $reason ): array {
		return array(
			'suggestion_type' => 'manual_review',
			'suggested_price' => $current_price,
			'status'          => 'skipped',
			'reason'          => $reason,
		);
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	private function setting_choice( array $settings, string $key, string $fallback ): string {
		return isset( $settings[ $key ] ) && '' !== $settings[ $key ] ? sanitize_key( (string) $settings[ $key ] ) : $fallback;
	}

	/**
	 * @param mixed $value Raw decimal.
	 */
	private function nullable_float( $value ): ?float {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return (float) $value;
	}

	private function current_timestamp(): int {
		return function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time();
	}

	/**
	 * @param mixed $date Date object.
	 */
	private function format_wc_datetime( $date ): ?string {
		if ( ! is_object( $date ) || ! method_exists( $date, 'date' ) ) {
			return null;
		}

		return $date->date( 'Y-m-d H:i:s' );
	}
}
