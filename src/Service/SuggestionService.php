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

	public function __construct( Repository $repository, ?PriceRecoveryService $recovery_service = null ) {
		$this->repository       = $repository;
		$this->recovery_service = $recovery_service ?? new PriceRecoveryService();
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
		$competitor_price = $this->normalize_price( $competitor_link['last_price'] ?? null );

		if ( null === $current_price || null === $competitor_price ) {
			return $this->skipped( __( 'Current product price or competitor price is missing.', 'lilleprinsen-price-monitor' ) );
		}

		$absolute_difference = abs( $current_price - $competitor_price );
		$min_difference      = $this->setting_float( $settings, 'min_price_difference_to_suggest', 10.0 );

		if ( $absolute_difference < $min_difference ) {
			return $this->skipped(
				sprintf(
					/* translators: 1: price difference, 2: configured minimum. */
					__( 'Price difference %.2f is below the configured minimum %.2f.', 'lilleprinsen-price-monitor' ),
					$absolute_difference,
					$min_difference
				)
			);
		}

		$active_session = $this->repository->get_active_price_match_session_for_product( (int) $monitored_product['product_id'] );
		$suggestion = $this->build_suggestion_payload( $monitored_product, $competitor_link, $current_price, $competitor_price, $settings, $active_session );

		if ( 'skipped' === (string) $suggestion['status'] ) {
			return $this->skipped( (string) $suggestion['reason'] );
		}

		if ( $this->repository->has_duplicate_pending_suggestion( (int) $monitored_product['id'], (int) $competitor_link['id'], $competitor_price ) ) {
			return $this->skipped( __( 'A pending suggestion already exists for this competitor price.', 'lilleprinsen-price-monitor' ) );
		}

		$max_drop_percent = $this->setting_float( $settings, 'max_allowed_price_drop_percent', 25.0 );

		if ( 'price_match_down' === $suggestion['suggestion_type'] && $current_price > 0 ) {
			$drop_percent = ( ( $current_price - $competitor_price ) / $current_price ) * 100;

			if ( $drop_percent > $max_drop_percent ) {
				$suggestion['status'] = 'blocked';
				$suggestion['reason'] = sprintf(
					/* translators: 1: drop percent, 2: configured max drop percent. */
					__( 'Price drop of %.2f%% exceeds the configured maximum %.2f%%. Blocking for manual review.', 'lilleprinsen-price-monitor' ),
					$drop_percent,
					$max_drop_percent
				);
			}
		}

		$suggestion_id = $this->repository->create_price_suggestion( $suggestion );

		if ( $suggestion_id <= 0 ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Could not create price suggestion.', 'lilleprinsen-price-monitor' ),
			);
		}

		return array(
			'status'          => $suggestion['status'],
			'message'         => $suggestion['reason'],
			'suggestion_id'   => $suggestion_id,
			'suggestion_type' => $suggestion['suggestion_type'],
			'suggested_price' => $suggestion['suggested_price'],
		);
	}

	/**
	 * @param array<string, mixed> $monitored_product Monitored product row.
	 * @param array<string, mixed> $competitor_link Competitor link row.
	 * @param array<string, mixed> $settings Sanitized plugin settings.
	 * @param array<string, mixed>|null $active_session Active match session.
	 * @return array<string, mixed>
	 */
	private function build_suggestion_payload( array $monitored_product, array $competitor_link, float $current_price, float $competitor_price, array $settings, ?array $active_session ): array {
		$suggested_price = $competitor_price;
		$status          = 'pending';
		$suggestion_type = 'manual_review';
		$reason          = __( 'Competitor price differs from current WooCommerce price. Manual dry-run review required.', 'lilleprinsen-price-monitor' );

		if ( $competitor_price < $current_price ) {
			$suggestion_type = 'price_match_down';
			$reason          = __( 'Competitor price is lower than the current WooCommerce price. Suggesting an exact dry-run match.', 'lilleprinsen-price-monitor' );
		} elseif ( $active_session ) {
			$competitor_links = $this->repository->get_competitor_links_for_monitored_product( (int) $monitored_product['id'] );
			$recovery         = $this->recovery_service->determine_recovery_suggestion(
				$current_price,
				$competitor_price,
				$active_session,
				$settings,
				$competitor_links
			);

			$suggestion_type = (string) $recovery['suggestion_type'];
			$suggested_price = (float) $recovery['suggested_price'];
			$status          = (string) $recovery['status'];
			$reason          = (string) $recovery['reason'];
		}

		return array(
			'monitored_product_id' => (int) $monitored_product['id'],
			'competitor_link_id'   => (int) $competitor_link['id'],
			'product_id'           => (int) $monitored_product['product_id'],
			'current_price'        => $current_price,
			'competitor_price'     => $competitor_price,
			'suggested_price'      => $suggested_price,
			'difference'           => $suggested_price - $current_price,
			'suggestion_type'      => $suggestion_type,
			'status'               => $status,
			'reason'               => $reason,
		);
	}

	private function get_product_price( object $product ): ?float {
		if ( ! method_exists( $product, 'get_price' ) ) {
			return null;
		}

		return $this->normalize_price( $product->get_price() );
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
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	private function setting_float( array $settings, string $key, float $fallback ): float {
		if ( ! isset( $settings[ $key ] ) || '' === $settings[ $key ] || ! is_numeric( $settings[ $key ] ) ) {
			return $fallback;
		}

		return max( 0, (float) $settings[ $key ] );
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
