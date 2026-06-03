<?php
/**
 * Lightweight frontend price-match display checks.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

use Lilleprinsen\PriceMonitor\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PriceMatchDisplayService {
	private Repository $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return array<string, mixed>
	 */
	public function get_display_state( int $product_id, array $settings, bool $allow_indexed_lookup = false ): array {
		if ( $product_id <= 0 || empty( $settings['price_match_box_enabled'] ) ) {
			return array( 'show' => false );
		}

		$is_matched = $this->has_cached_match_flag( $product_id );

		if ( ! $is_matched && $allow_indexed_lookup ) {
			$is_matched = $this->repository->product_has_active_price_match_session( $product_id );
		}

		if ( ! $is_matched && empty( $settings['price_match_box_hide_if_no_active_match'] ) ) {
			$is_matched = true;
		}

		if ( ! $is_matched ) {
			return array( 'show' => false );
		}

		return array(
			'show'          => true,
			'text'          => (string) ( $settings['price_match_box_text'] ?? '' ),
			'subtext'       => (string) ( $settings['price_match_box_subtext'] ?? '' ),
			'emoji'         => (string) ( $settings['price_match_box_emoji'] ?? '' ),
			'use_theme_color' => ! empty( $settings['price_match_box_use_theme_color'] ),
			'background_color' => (string) ( $settings['price_match_box_background_color'] ?? '' ),
			'text_color'    => (string) ( $settings['price_match_box_text_color'] ?? '' ),
			'border_color'  => (string) ( $settings['price_match_box_border_color'] ?? '' ),
			'border_radius' => isset( $settings['price_match_box_border_radius'] ) ? absint( $settings['price_match_box_border_radius'] ) : 10,
		);
	}

	public function product_is_price_matched( int $product_id, bool $allow_indexed_lookup = false ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		if ( $this->has_cached_match_flag( $product_id ) ) {
			return true;
		}

		return $allow_indexed_lookup && $this->repository->product_has_active_price_match_session( $product_id );
	}

	private function has_cached_match_flag( int $product_id ): bool {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return false;
		}

		return 'yes' === (string) get_post_meta( $product_id, '_lpm_price_matched_active', true )
			|| 'yes' === (string) get_post_meta( $product_id, '_lpm_price_matched_group_active', true );
	}
}
