<?php
/**
 * Detect unsafe competitor price anomalies before suggestions are trusted.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PriceAnomalyService {
	/**
	 * @param array<string,mixed> $context Price-check context.
	 * @return array{blocked:bool,warnings:array<int,string>,details:array<string,mixed>,reason:string}
	 */
	public function analyze_competitor_link( array $context ): array {
		$current_price    = $this->normalize_price( $context['current_price'] ?? null );
		$competitor_price = $this->normalize_price( $context['competitor_price'] ?? null );
		$link             = isset( $context['competitor_link'] ) && is_array( $context['competitor_link'] ) ? $context['competitor_link'] : array();
		$market_prices    = $this->market_prices( isset( $context['market_links'] ) && is_array( $context['market_links'] ) ? $context['market_links'] : array() );
		$warnings         = array();
		$details          = array(
			'checks' => array(),
		);
		$blocked          = false;

		if ( null === $current_price || null === $competitor_price ) {
			return $this->result( false, array(), $details );
		}

		$url = (string) ( $link['competitor_url'] ?? '' );
		if ( $this->looks_like_listing_url( $url ) ) {
			$blocked = true;
			$warnings[] = __( 'Anomaly: competitor URL looks like a search, category, brand, or listing page rather than a product page.', 'lilleprinsen-price-monitor' );
			$details['checks']['scraped_listing_or_category_page'] = true;
		}

		$match_type = sanitize_key( (string) ( $link['match_type'] ?? '' ) );
		if ( in_array( $match_type, array( 'bundle', 'not_comparable', 'different_variant' ), true ) ) {
			$blocked = true;
			$warnings[] = __( 'Anomaly: competitor link is marked as bundle, not comparable, or a different variant.', 'lilleprinsen-price-monitor' );
			$details['checks']['unsafe_match_type'] = $match_type;
		}

		$stock_status = $this->normalize_stock_status( $link['last_stock_status'] ?? $link['stock_status'] ?? '' );
		if ( 'out_of_stock' === $stock_status ) {
			$blocked = true;
			$warnings[] = __( 'Anomaly: competitor price is out of stock and should not drive market alerts.', 'lilleprinsen-price-monitor' );
			$details['checks']['out_of_stock_price_trap'] = true;
		}

		$regular_price = $this->normalize_price( $link['last_regular_price'] ?? $link['observed_regular_price'] ?? $link['regular_price'] ?? null );
		$sale_price    = $this->normalize_price( $link['last_sale_price'] ?? $link['observed_sale_price'] ?? $link['sale_price'] ?? null );
		if ( null !== $regular_price && null !== $sale_price ) {
			$discount_percent = $regular_price > 0 ? ( ( $regular_price - $sale_price ) / $regular_price ) * 100 : 0;
			$details['checks']['discount_percent'] = round( $discount_percent, 2 );

			if ( $sale_price > $regular_price ) {
				$blocked = true;
				$warnings[] = __( 'Anomaly: sale price is higher than regular price, so the scraped discount is inconsistent.', 'lilleprinsen-price-monitor' );
				$details['checks']['fake_discount'] = 'sale_above_regular';
			} elseif ( $discount_percent > 0 && $discount_percent < 2.0 ) {
				$warnings[] = __( 'Anomaly: discount is too small to treat as a meaningful market move.', 'lilleprinsen-price-monitor' );
				$details['checks']['fake_discount'] = 'tiny_discount';
			} elseif ( $discount_percent >= 70.0 ) {
				$warnings[] = __( 'Anomaly: discount is unusually large. Review whether the result is a bundle, accessory, outlet item, or scraped wrong price.', 'lilleprinsen-price-monitor' );
				$details['checks']['large_discount'] = true;
			}
		}

		$price_ratio = $competitor_price / $current_price;
		$details['checks']['competitor_to_our_price_ratio'] = round( $price_ratio, 4 );
		if ( $price_ratio <= 0.35 ) {
			$blocked = true;
			$warnings[] = __( 'Anomaly: competitor price is far below our price. This may be a wrong price, accessory, bundle component, or scraped listing value.', 'lilleprinsen-price-monitor' );
			$details['checks']['wild_price_vs_our_price'] = 'too_low';
		} elseif ( $price_ratio >= 3.0 ) {
			$warnings[] = __( 'Anomaly: competitor price is far above our price. Review whether this is a bundle or wrong product.', 'lilleprinsen-price-monitor' );
			$details['checks']['wild_price_vs_our_price'] = 'too_high';
		}

		$market_median = $this->median_price( $market_prices );
		if ( null !== $market_median && $market_median > 0 ) {
			$market_ratio = $competitor_price / $market_median;
			$details['checks']['competitor_to_market_median_ratio'] = round( $market_ratio, 4 );
			$details['checks']['market_median_price'] = $market_median;

			if ( count( $market_prices ) >= 2 && $market_ratio <= 0.45 ) {
				$blocked = true;
				$warnings[] = __( 'Anomaly: competitor price is far below the observed market median. Treat it as unsafe until manually reviewed.', 'lilleprinsen-price-monitor' );
				$details['checks']['wild_price_vs_market'] = 'too_low';
			} elseif ( count( $market_prices ) >= 2 && $market_ratio >= 2.5 ) {
				$warnings[] = __( 'Anomaly: competitor price is far above the observed market median. It may be a bundle or wrong page.', 'lilleprinsen-price-monitor' );
				$details['checks']['wild_price_vs_market'] = 'too_high';
			}
		}

		return $this->result( $blocked, $warnings, $details );
	}

	/**
	 * @return array{blocked:bool,warnings:array<int,string>,details:array<string,mixed>,reason:string}
	 */
	public function analyze_discovered_match( object $discovered ): array {
		$warnings = array();
		$details  = array(
			'checks' => array(),
		);
		$blocked  = false;
		$url      = (string) ( $discovered->url ?? $discovered->product_url ?? $discovered->competitor_url ?? '' );
		$title    = (string) ( $discovered->title ?? '' );

		if ( $this->looks_like_listing_url( $url ) ) {
			$blocked = true;
			$warnings[] = 'Anomaly: candidate URL looks like a search, category, brand, or listing page rather than a product page.';
			$details['checks']['scraped_listing_or_category_page'] = true;
		}

		if ( $this->text_contains_bundle_signal( $title ) ) {
			$warnings[] = 'Anomaly: competitor title includes bundle/package wording. Review carefully before approval.';
			$details['checks']['bundle_or_package_signal'] = true;
		}

		$stock_status = $this->normalize_stock_status( $discovered->stock_status ?? '' );
		if ( 'out_of_stock' === $stock_status ) {
			$warnings[] = 'Anomaly: competitor product appears out of stock; do not use this price as market pressure.';
			$details['checks']['out_of_stock_price_trap'] = true;
		}

		$regular_price = $this->normalize_price( $discovered->regular_price ?? null );
		$sale_price    = $this->normalize_price( $discovered->sale_price ?? null );
		if ( null !== $regular_price && null !== $sale_price && $sale_price > $regular_price ) {
			$blocked = true;
			$warnings[] = 'Anomaly: sale price is higher than regular price, so the scraped discount is inconsistent.';
			$details['checks']['fake_discount'] = 'sale_above_regular';
		}

		return $this->result( $blocked, $warnings, $details );
	}

	/**
	 * @param array<int,array<string,mixed>> $market_links Market competitor links.
	 * @return array<int,float>
	 */
	private function market_prices( array $market_links ): array {
		$prices = array();
		foreach ( $market_links as $link ) {
			if ( 'out_of_stock' === $this->normalize_stock_status( $link['last_stock_status'] ?? $link['stock_status'] ?? '' ) ) {
				continue;
			}
			$price = $this->normalize_price( $link['last_price'] ?? null );
			if ( null !== $price ) {
				$prices[] = $price;
			}
		}
		sort( $prices, SORT_NUMERIC );

		return $prices;
	}

	/**
	 * @param array<int,float> $prices Sorted or unsorted prices.
	 */
	private function median_price( array $prices ): ?float {
		$count = count( $prices );
		if ( 0 === $count ) {
			return null;
		}
		sort( $prices, SORT_NUMERIC );
		$middle = intdiv( $count, 2 );

		return 1 === $count % 2 ? round( (float) $prices[ $middle ], 4 ) : round( ( (float) $prices[ $middle - 1 ] + (float) $prices[ $middle ] ) / 2, 4 );
	}

	private function looks_like_listing_url( string $url ): bool {
		$path  = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$query = strtolower( (string) wp_parse_url( $url, PHP_URL_QUERY ) );

		if ( '' === $path && '' === $query ) {
			return false;
		}

		if ( '' !== $query && preg_match( '/(?:^|&)(?:q|s|search|text|query)=/i', $query ) ) {
			return true;
		}

		return (bool) preg_match( '#/(?:catalogsearch|search|sok|category|kategori|product-category|collections?|brand|brands|merke|merker|varemerker|tag|product-tag)(?:/|$)#i', $path );
	}

	private function text_contains_bundle_signal( string $text ): bool {
		$text = strtolower( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		return (bool) preg_match( '/\b(?:bundle|package|pakke|vognpakke|sett|set|inkl|ink|incl|included|inkludert|with)\b/u', $text );
	}

	private function normalize_stock_status( $status ): string {
		$status = strtolower( trim( str_replace( array( '-', ' ' ), '_', (string) $status ) ) );

		if ( in_array( $status, array( 'outofstock', 'out_of_stock', 'sold_out', 'utsolgt', 'ikke_pa_lager', 'ikke_paa_lager' ), true ) ) {
			return 'out_of_stock';
		}

		if ( in_array( $status, array( 'instock', 'in_stock', 'available', 'pa_lager', 'paa_lager' ), true ) ) {
			return 'in_stock';
		}

		return 'unknown';
	}

	private function normalize_price( $value ): ?float {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$price = round( (float) $value, 4 );

		return $price > 0 ? $price : null;
	}

	/**
	 * @param array<int,string> $warnings Warning messages.
	 * @param array<string,mixed> $details Details.
	 * @return array{blocked:bool,warnings:array<int,string>,details:array<string,mixed>,reason:string}
	 */
	private function result( bool $blocked, array $warnings, array $details ): array {
		$warnings = array_values( array_unique( array_filter( array_map( 'strval', $warnings ) ) ) );

		return array(
			'blocked'  => $blocked,
			'warnings' => $warnings,
			'details'  => $details,
			'reason'   => $blocked ? __( 'Anomaly detection blocked this competitor price from becoming a normal price suggestion.', 'lilleprinsen-price-monitor' ) : '',
		);
	}
}
