<?php
/**
 * Guarded WooCommerce price update foundation.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

use Lilleprinsen\PriceMonitor\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PriceUpdateService {
	private Repository $repository;

	private PriceRecoveryService $recovery_service;

	public function __construct( Repository $repository, ?PriceRecoveryService $recovery_service = null ) {
		$this->repository       = $repository;
		$this->recovery_service = $recovery_service ?? new PriceRecoveryService();
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return array<string, mixed>
	 */
	public function apply_suggestion( int $suggestion_id, array $settings, int $user_id ): array {
		$suggestion = $this->repository->get_price_suggestion( $suggestion_id );

		if ( ! $suggestion ) {
			return $this->failure( __( 'Suggestion was not found.', 'lilleprinsen-price-monitor' ) );
		}

		$validation = $this->validate_update_allowed( $suggestion, $settings );

		if ( ! $validation['success'] ) {
			if ( ! empty( $validation['mark_failed'] ) ) {
				$this->repository->mark_suggestion_failed( $suggestion_id, (string) $validation['message'] );
			}
			return $validation;
		}

		$product = wc_get_product( (int) $suggestion['product_id'] );

		if ( ! is_object( $product ) ) {
			$message = __( 'WooCommerce product was not found.', 'lilleprinsen-price-monitor' );
			return $this->failure( $message );
		}

		$old_state = $this->capture_product_state( $product );
		$write_mode = sanitize_key( (string) ( $settings['price_match_write_mode'] ?? 'sale_price' ) );
		$price = round( (float) $suggestion['suggested_price'], 4 );

		try {
			$this->apply_price_to_product( $product, $price, $write_mode );
			$product->save();
		} catch ( \Throwable $throwable ) {
			$message = $throwable->getMessage();
			$this->repository->write_log( 'error', 'real_price_update_failed', __( 'WooCommerce price update failed.', 'lilleprinsen-price-monitor' ), array( 'suggestion_id' => $suggestion_id, 'error' => $message ), (int) $suggestion['product_id'] );
			$this->repository->mark_suggestion_failed( $suggestion_id, $message );
			return $this->failure( $message );
		}

		$new_state = $this->capture_product_state( $product );
		$this->repository->approve_suggestion_real_update( $suggestion_id, $user_id );
		$this->maybe_update_price_match_session( $suggestion, $old_state, $price, $write_mode, $settings, $user_id );
		$this->repository->write_log(
			'info',
			'real_price_update_applied',
			__( 'WooCommerce price update applied after explicit admin approval.', 'lilleprinsen-price-monitor' ),
			array(
				'suggestion_id' => $suggestion_id,
				'write_mode'    => $write_mode,
				'old_state'     => $old_state,
				'new_state'     => $new_state,
			),
			(int) $suggestion['product_id']
		);

		return array(
			'success' => true,
			'message' => __( 'WooCommerce price was updated after explicit approval.', 'lilleprinsen-price-monitor' ),
		);
	}

	/**
	 * @param array<string, mixed> $suggestion Suggestion row.
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return array<string, mixed>
	 */
	private function validate_update_allowed( array $suggestion, array $settings ): array {
		if ( ! empty( $settings['dry_run_mode'] ) ) {
			return $this->failure( __( 'Dry-run mode is enabled.', 'lilleprinsen-price-monitor' ) );
		}

		if ( ! empty( $settings['disable_all_price_updates'] ) ) {
			return $this->failure( __( 'Emergency price update disable is enabled.', 'lilleprinsen-price-monitor' ) );
		}

		if ( empty( $settings['allow_real_price_updates'] ) ) {
			return $this->failure( __( 'Real price updates are not enabled.', 'lilleprinsen-price-monitor' ) );
		}

		if ( empty( $settings['require_manual_approval'] ) ) {
			return $this->failure( __( 'Manual approval is required for real price updates.', 'lilleprinsen-price-monitor' ) );
		}

		if ( 'blocked' === (string) $suggestion['status'] ) {
			return $this->failure( __( 'Blocked suggestions cannot update prices.', 'lilleprinsen-price-monitor' ) );
		}

		if ( 'pending' !== (string) $suggestion['status'] ) {
			return $this->failure( __( 'Only pending suggestions can update prices.', 'lilleprinsen-price-monitor' ) );
		}

		$allowed_types = isset( $settings['real_update_allowed_suggestion_types'] ) && is_array( $settings['real_update_allowed_suggestion_types'] )
			? $settings['real_update_allowed_suggestion_types']
			: array();

		if ( ! in_array( (string) $suggestion['suggestion_type'], $allowed_types, true ) ) {
			return $this->failure( __( 'Suggestion type is not allowed for real updates.', 'lilleprinsen-price-monitor' ) );
		}

		$suggested_price = (float) $suggestion['suggested_price'];

		if ( $suggested_price <= 0 ) {
			return $this->failure( __( 'Suggested price must be positive.', 'lilleprinsen-price-monitor' ), true );
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return $this->failure( __( 'WooCommerce is unavailable.', 'lilleprinsen-price-monitor' ) );
		}

		$product = wc_get_product( (int) $suggestion['product_id'] );

		if ( ! is_object( $product ) || ! method_exists( $product, 'get_price' ) ) {
			return $this->failure( __( 'WooCommerce product was not found.', 'lilleprinsen-price-monitor' ) );
		}

		$current_price = (float) $product->get_price();
		$snapshot_price = (float) $suggestion['current_price'];

		if ( abs( $current_price - $snapshot_price ) > 0.0001 ) {
			return $this->failure( __( 'product price changed since suggestion was created', 'lilleprinsen-price-monitor' ), true );
		}

		$max_drop_percent = isset( $settings['max_allowed_price_drop_percent'] ) ? (float) $settings['max_allowed_price_drop_percent'] : 25.0;

		if ( $suggested_price < $current_price && $current_price > 0 ) {
			$drop_percent = ( ( $current_price - $suggested_price ) / $current_price ) * 100;

			if ( $drop_percent > $max_drop_percent ) {
				return $this->failure( __( 'Suggested price drop exceeds the configured safety limit.', 'lilleprinsen-price-monitor' ), true );
			}
		}

		return array( 'success' => true );
	}

	private function apply_price_to_product( object $product, float $price, string $write_mode ): void {
		if ( 'regular_price' === $write_mode && method_exists( $product, 'set_regular_price' ) ) {
			$product->set_regular_price( (string) $price );
			if ( method_exists( $product, 'set_price' ) ) {
				$product->set_price( (string) $price );
			}
			return;
		}

		if ( method_exists( $product, 'set_sale_price' ) ) {
			$product->set_sale_price( (string) $price );
		}

		if ( method_exists( $product, 'set_price' ) ) {
			$product->set_price( (string) $price );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function capture_product_state( object $product ): array {
		return array(
			'regular_price' => method_exists( $product, 'get_regular_price' ) ? $product->get_regular_price() : null,
			'sale_price'    => method_exists( $product, 'get_sale_price' ) ? $product->get_sale_price() : null,
			'active_price'  => method_exists( $product, 'get_price' ) ? $product->get_price() : null,
			'sale_start'    => $this->format_wc_datetime( method_exists( $product, 'get_date_on_sale_from' ) ? $product->get_date_on_sale_from() : null ),
			'sale_end'      => $this->format_wc_datetime( method_exists( $product, 'get_date_on_sale_to' ) ? $product->get_date_on_sale_to() : null ),
		);
	}

	/**
	 * @param array<string, mixed> $suggestion Suggestion row.
	 * @param array<string, mixed> $old_state Previous product state.
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	private function maybe_update_price_match_session( array $suggestion, array $old_state, float $matched_price, string $write_mode, array $settings, int $user_id ): void {
		$type = (string) $suggestion['suggestion_type'];

		if ( 'price_match_down' === $type ) {
			$this->repository->create_price_match_session(
				array(
					'product_id'              => (int) $suggestion['product_id'],
					'monitored_product_id'    => (int) $suggestion['monitored_product_id'],
					'suggestion_id'           => (int) $suggestion['id'],
					'status'                  => 'active',
					'original_regular_price'  => $old_state['regular_price'] ?? null,
					'original_sale_price'     => $old_state['sale_price'] ?? null,
					'original_active_price'   => $old_state['active_price'] ?? null,
					'original_sale_start'     => $old_state['sale_start'] ?? null,
					'original_sale_end'       => $old_state['sale_end'] ?? null,
					'matched_price'           => $matched_price,
					'matched_regular_price'   => 'regular_price' === $write_mode ? $matched_price : null,
					'matched_sale_price'      => 'regular_price' === $write_mode ? null : $matched_price,
					'matched_at'              => current_time( 'mysql' ),
					'matched_by'              => $user_id,
					'restore_strategy'        => 'previous_active_price',
					'recovery_strategy'       => (string) ( $settings['recovery_when_competitor_increases'] ?? 'suggest_only' ),
					'last_competitor_price'   => (float) $suggestion['competitor_price'],
					'last_lowest_competitor_price' => (float) $suggestion['competitor_price'],
					'last_checked_at'         => current_time( 'mysql' ),
				)
			);
			return;
		}

		if ( in_array( $type, array( 'restore_previous_regular_price', 'restore_previous_sale_price', 'restore_previous_active_price' ), true ) ) {
			$session = $this->repository->get_active_price_match_session_for_product( (int) $suggestion['product_id'] );

			if ( $session ) {
				$this->repository->end_price_match_session( (int) $session['id'], 'restored' );
			}
		}
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

	/**
	 * @return array<string, mixed>
	 */
	private function failure( string $message, bool $mark_failed = false ): array {
		return array(
			'success'     => false,
			'message'     => $message,
			'mark_failed' => $mark_failed,
		);
	}
}
