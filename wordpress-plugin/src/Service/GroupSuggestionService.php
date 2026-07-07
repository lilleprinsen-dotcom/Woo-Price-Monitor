<?php
/**
 * Product group suggestion and update safety helpers.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GroupSuggestionService {
	/**
	 * Repository-like storage. Kept untyped so local CLI tests can use a tiny fake.
	 *
	 * @var object
	 */
	private $repository;

	private PricingRuleService $pricing_rule_service;

	/**
	 * @param object $repository Repository-like storage.
	 */
	public function __construct( $repository, ?PricingRuleService $pricing_rule_service = null ) {
		$this->repository           = $repository;
		$this->pricing_rule_service = $pricing_rule_service ?? new PricingRuleService();
	}

	/**
	 * @param array<string, mixed> $monitored_product Monitored product row.
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @param array<string, mixed> $context Optional validation context.
	 * @return array<string, mixed>|null
	 */
	public function get_group_context( array $monitored_product, float $suggested_price, array $settings = array(), array $context = array() ): ?array {
		if ( ! method_exists( $this->repository, 'get_active_product_group_for_monitored_product' ) ) {
			return null;
		}

		$group = $this->repository->get_active_product_group_for_monitored_product( absint( $monitored_product['id'] ?? 0 ) );

		if ( ! is_array( $group ) || empty( $group ) ) {
			return null;
		}

		$pricing_mode = $this->sanitize_pricing_mode( (string) ( $group['pricing_mode'] ?? 'shared_price' ) );

		if ( 'primary_product_controls_group' === $pricing_mode && ! $this->monitored_product_is_primary( $monitored_product, $group ) ) {
			return array(
				'skip'         => true,
				'group_id'     => (int) $group['id'],
				'pricing_mode' => $pricing_mode,
				'reason'       => __( 'This product belongs to a primary-controlled group, and only the primary product may drive group-wide suggestions.', 'lilleprinsen-price-monitor' ),
			);
		}

		$members = method_exists( $this->repository, 'get_product_group_members' )
			? $this->repository->get_product_group_members( (int) $group['id'], true )
			: array();

		$report = $this->validate_group_members( $group, is_array( $members ) ? $members : array(), $suggested_price, $settings, $context );
		$reason = 'manual_review_only' === $pricing_mode
			? __( 'This product belongs to a manual-review-only group. The suggestion is marked for manual review before any group action.', 'lilleprinsen-price-monitor' )
			: __( 'This product belongs to a price group. The suggestion is marked as group-aware and should be reviewed for all enabled members.', 'lilleprinsen-price-monitor' );

		if ( ! empty( $report['reason'] ) ) {
			$reason .= ' ' . (string) $report['reason'];
		}

		return array(
			'skip'                => false,
			'force_manual_review' => 'manual_review_only' === $pricing_mode,
			'blocked'             => empty( $report['success'] ),
			'group_id'            => (int) $group['id'],
			'pricing_mode'        => $pricing_mode,
			'reason'              => $reason,
			'affected_products'   => $report['affected_products'],
			'blocked_products'    => $report['blocked_products'],
			'warnings'            => $report['warnings'],
			'can_update_group'    => $report['can_update_group'],
			'details'             => array(
				'group_id'           => (int) $group['id'],
				'group_name'         => (string) ( $group['name'] ?? '' ),
				'pricing_mode'       => $pricing_mode,
				'member_count'       => count( $members ),
				'primary_product_id' => isset( $group['primary_product_id'] ) ? (int) $group['primary_product_id'] : 0,
				'affected_products'  => $report['affected_products'],
				'blocked_products'   => $report['blocked_products'],
				'warnings'           => $report['warnings'],
				'can_update_group'   => $report['can_update_group'],
			),
		);
	}

	/**
	 * @param array<string, mixed> $group Product group row.
	 * @param array<int, array<string, mixed>> $members Enabled group member rows.
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @param array<string, mixed> $context Optional validation context.
	 * @return array<string, mixed>
	 */
	public function validate_group_members( array $group, array $members, float $suggested_price, array $settings, array $context = array() ): array {
		$affected_products = array();
		$eligible_products = array();
		$blocked_products  = array();
		$warnings          = array();

		if ( empty( $members ) ) {
			return array(
				'success'           => false,
				'affected_products' => array(),
				'eligible_products' => array(),
				'blocked_products'  => array(),
				'warnings'          => array( __( 'The group has no enabled members.', 'lilleprinsen-price-monitor' ) ),
				'reason'            => __( 'The group has no enabled members.', 'lilleprinsen-price-monitor' ),
				'can_update_group'  => false,
			);
		}

		foreach ( $members as $member ) {
			$product_id = absint( $member['product_id'] ?? 0 );

			if ( $product_id <= 0 ) {
				continue;
			}

			$affected_products[] = $product_id;
			$product_warnings    = $this->validate_single_member( $group, $member, $suggested_price, $settings, $context );

			if ( empty( $product_warnings ) ) {
				$eligible_products[] = $product_id;
				continue;
			}

			$blocked_products[] = array(
				'product_id' => $product_id,
				'reasons'    => $product_warnings,
			);
			$warnings = array_merge( $warnings, $product_warnings );
		}

		$affected_products = array_values( array_unique( array_map( 'absint', $affected_products ) ) );
		$eligible_products = array_values( array_unique( array_map( 'absint', $eligible_products ) ) );
		$warnings          = array_values( array_unique( array_filter( $warnings ) ) );
		$success           = empty( $blocked_products );

		return array(
			'success'           => $success,
			'affected_products' => $affected_products,
			'eligible_products' => $eligible_products,
			'blocked_products'  => $blocked_products,
			'warnings'          => $warnings,
			'reason'            => $success
				? __( 'All enabled group members passed the current group safety checks.', 'lilleprinsen-price-monitor' )
				: __( 'One or more enabled group members failed group safety checks.', 'lilleprinsen-price-monitor' ),
			'can_update_group'  => $success,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $sessions Active sessions keyed or listed.
	 * @return array{mixed: bool, warnings: array<int, string>, reason: string}
	 */
	public function detect_mixed_original_price_states( array $sessions ): array {
		$regular = array();
		$sale    = array();
		$active  = array();

		foreach ( $sessions as $session ) {
			if ( ! is_array( $session ) ) {
				continue;
			}

			$regular[] = $this->price_signature( $session['original_regular_price'] ?? null );
			$sale[]    = $this->price_signature( $session['original_sale_price'] ?? null );
			$active[]  = $this->price_signature( $session['original_active_price'] ?? null );
		}

		$mixed = count( array_unique( $regular ) ) > 1 || count( array_unique( $sale ) ) > 1 || count( array_unique( $active ) ) > 1;

		return array(
			'mixed'    => $mixed,
			'warnings' => $mixed ? array( __( 'Group members have different original prices. Manual review required.', 'lilleprinsen-price-monitor' ) ) : array(),
			'reason'   => $mixed ? __( 'Group members have different original prices. Manual review required.', 'lilleprinsen-price-monitor' ) : '',
		);
	}

	/**
	 * @param array<string, mixed> $monitored_product Monitored product row.
	 * @param array<string, mixed> $group Group row.
	 */
	private function monitored_product_is_primary( array $monitored_product, array $group ): bool {
		$primary_product_id = absint( $group['primary_product_id'] ?? 0 );

		if ( $primary_product_id <= 0 ) {
			return false;
		}

		return $primary_product_id === absint( $monitored_product['product_id'] ?? 0 );
	}

	/**
	 * @param array<string, mixed> $group Product group row.
	 * @param array<string, mixed> $member Group member row.
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @param array<string, mixed> $context Optional validation context.
	 * @return array<int, string>
	 */
	private function validate_single_member( array $group, array $member, float $suggested_price, array $settings, array $context ): array {
		unset( $group );

		$product_id = absint( $member['product_id'] ?? 0 );
		$warnings   = array();
		$product    = $this->get_product_for_validation( $product_id, $context );

		if ( $suggested_price <= 0 ) {
			$warnings[] = sprintf(
				/* translators: %d: product ID. */
				__( 'Product %d has an invalid suggested price.', 'lilleprinsen-price-monitor' ),
				$product_id
			);
		}

		if ( ! empty( $context['check_product_exists'] ) && ! is_object( $product ) ) {
			$warnings[] = sprintf(
				/* translators: %d: product ID. */
				__( 'Product %d could not be loaded.', 'lilleprinsen-price-monitor' ),
				$product_id
			);
		}

		if ( ! empty( $context['require_published'] ) && is_object( $product ) && method_exists( $product, 'get_status' ) && 'publish' !== (string) $product->get_status() ) {
			$warnings[] = sprintf(
				/* translators: %d: product ID. */
				__( 'Product %d is not published.', 'lilleprinsen-price-monitor' ),
				$product_id
			);
		}

		$min_price = $this->positive_decimal_or_null( $member['min_price'] ?? null );

		if ( null !== $min_price && $suggested_price < $min_price ) {
			$warnings[] = sprintf(
				/* translators: 1: product ID, 2: minimum price. */
				__( 'Product %1$d has a minimum price of %2$s.', 'lilleprinsen-price-monitor' ),
				$product_id,
				(string) $member['min_price']
			);
		}

		$cost_warning = $this->validate_member_margin( $product_id, $member, $suggested_price, $settings );

		if ( '' !== $cost_warning ) {
			$warnings[] = $cost_warning;
		}

		$current_price = $this->get_product_price( $product );

		if ( null !== $current_price ) {
			$max_drop_percent = isset( $settings['max_allowed_price_drop_percent'] ) ? (float) $settings['max_allowed_price_drop_percent'] : 25.0;

			if ( $suggested_price < $current_price && $current_price > 0 ) {
				$drop_percent = ( ( $current_price - $suggested_price ) / $current_price ) * 100;

				if ( $drop_percent > $max_drop_percent ) {
					$warnings[] = sprintf(
						/* translators: 1: product ID, 2: drop percent, 3: allowed percent. */
						__( 'Product %1$d would drop by %2$.2f%%, above the configured %3$.2f%% limit.', 'lilleprinsen-price-monitor' ),
						$product_id,
						$drop_percent,
						$max_drop_percent
					);
				}
			}
		}

		if ( ! empty( $context['real_update'] ) && ! empty( $context['expected_current_prices'] ) && is_array( $context['expected_current_prices'] ) && array_key_exists( $product_id, $context['expected_current_prices'] ) && null !== $current_price ) {
			$expected_price = (float) $context['expected_current_prices'][ $product_id ];

			if ( abs( $current_price - $expected_price ) > 0.0001 ) {
				$warnings[] = sprintf(
					/* translators: %d: product ID. */
					__( 'Product %d price changed since the suggestion was created.', 'lilleprinsen-price-monitor' ),
					$product_id
				);
			}
		}

		if ( ! empty( $context['block_conflicting_sessions'] ) && method_exists( $this->repository, 'get_active_price_match_session_for_product' ) ) {
			$session = $this->repository->get_active_price_match_session_for_product( $product_id );

			if ( is_array( $session ) && ! empty( $session ) && absint( $session['suggestion_id'] ?? 0 ) !== absint( $context['current_suggestion_id'] ?? 0 ) ) {
				$warnings[] = sprintf(
					/* translators: %d: product ID. */
					__( 'Product %d already has an active price match session.', 'lilleprinsen-price-monitor' ),
					$product_id
				);
			}
		}

		return $warnings;
	}

	/**
	 * @param array<string, mixed> $member Group member row.
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	private function validate_member_margin( int $product_id, array $member, float $suggested_price, array $settings ): string {
		$cost = null;

		if ( 'custom_meta_key' === (string) ( $settings['cost_source'] ?? 'none' ) && ! empty( $settings['cost_meta_key'] ) && function_exists( 'get_post_meta' ) ) {
			$cost = $this->positive_decimal_or_null( get_post_meta( $product_id, (string) $settings['cost_meta_key'], true ) );
		}

		if ( null === $cost ) {
			return ! empty( $settings['block_if_cost_missing'] )
				? sprintf(
					/* translators: %d: product ID. */
					__( 'Product %d is missing cost data required by settings.', 'lilleprinsen-price-monitor' ),
					$product_id
				)
				: '';
		}

		$minimum_margin = $this->positive_decimal_or_null( $member['min_margin_percent'] ?? null );

		if ( null === $minimum_margin ) {
			$minimum_margin = $this->positive_decimal_or_null( $settings['default_min_margin_percent'] ?? null );
		}

		if ( null === $minimum_margin || $suggested_price <= 0 ) {
			return '';
		}

		$margin_after = ( ( $suggested_price - $cost ) / $suggested_price ) * 100;

		return $margin_after < $minimum_margin
			? sprintf(
				/* translators: 1: product ID, 2: margin percent, 3: required margin percent. */
				__( 'Product %1$d margin would be %2$.2f%%, below the required %3$.2f%%.', 'lilleprinsen-price-monitor' ),
				$product_id,
				$margin_after,
				$minimum_margin
			)
			: '';
	}

	/**
	 * @param mixed $product Product object.
	 */
	private function get_product_price( $product ): ?float {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_price' ) ) {
			return null;
		}

		$price = $product->get_price();

		return is_numeric( $price ) && (float) $price > 0 ? (float) $price : null;
	}

	/**
	 * @param array<string, mixed> $context Optional validation context.
	 * @return object|null
	 */
	private function get_product_for_validation( int $product_id, array $context ) {
		if ( isset( $context['products'][ $product_id ] ) && is_object( $context['products'][ $product_id ] ) ) {
			return $context['products'][ $product_id ];
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );

			return is_object( $product ) ? $product : null;
		}

		return null;
	}

	/**
	 * @param mixed $value Raw decimal.
	 */
	private function positive_decimal_or_null( $value ): ?float {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$value = (float) $value;

		return $value > 0 ? $value : null;
	}

	private function price_signature( $value ): string {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return 'null';
		}

		return number_format( (float) $value, 4, '.', '' );
	}

	private function sanitize_pricing_mode( string $pricing_mode ): string {
		return in_array( $pricing_mode, array( 'shared_price', 'primary_product_controls_group', 'manual_review_only' ), true )
			? $pricing_mode
			: 'shared_price';
	}
}
