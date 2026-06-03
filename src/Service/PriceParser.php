<?php
/**
 * Lightweight competitor page price parser.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PriceParser {
	/**
	 * @return array{price: float|null, currency: string, extraction_method: string, error: string}
	 */
	public function parse( string $html ): array {
		$html = $this->trim_html( $html );

		if ( '' === trim( $html ) ) {
			return $this->failure( __( 'The competitor page did not return any HTML.', 'lilleprinsen-price-monitor' ) );
		}

		$json_ld_result = $this->parse_json_ld( $html );

		if ( null !== $json_ld_result['price'] ) {
			return $json_ld_result;
		}

		$meta_result = $this->parse_meta_tags( $html );

		if ( null !== $meta_result['price'] ) {
			return $meta_result;
		}

		$visible_result = $this->parse_visible_price( $html );

		if ( null !== $visible_result['price'] ) {
			return $visible_result;
		}

		return $this->failure( __( 'No recognizable price was found in JSON-LD, meta tags, or visible NOK/kr text.', 'lilleprinsen-price-monitor' ) );
	}

	/**
	 * @return array{price: float|null, currency: string, extraction_method: string, error: string}
	 */
	private function parse_json_ld( string $html ): array {
		if ( ! preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
			return $this->failure( '' );
		}

		foreach ( $matches[1] as $raw_json ) {
			$decoded = json_decode( html_entity_decode( trim( $raw_json ), ENT_QUOTES | ENT_HTML5 ), true );

			if ( null === $decoded ) {
				continue;
			}

			$offer = $this->find_offer_with_price( $decoded );

			if ( null === $offer ) {
				continue;
			}

			$price = $this->normalize_price( $offer['price'] ?? ( $offer['lowPrice'] ?? null ) );

			if ( null === $price ) {
				continue;
			}

			return array(
				'price'             => $price,
				'currency'          => $this->normalize_currency( $offer['priceCurrency'] ?? ( $offer['currency'] ?? 'NOK' ) ),
				'extraction_method' => 'json_ld_offer',
				'error'             => '',
			);
		}

		return $this->failure( '' );
	}

	/**
	 * @param mixed $node JSON-LD node.
	 * @return array<string, mixed>|null
	 */
	private function find_offer_with_price( $node ): ?array {
		if ( ! is_array( $node ) ) {
			return null;
		}

		if ( isset( $node['price'] ) || isset( $node['lowPrice'] ) ) {
			return $node;
		}

		if ( isset( $node['offers'] ) ) {
			$offer = $this->find_offer_with_price( $node['offers'] );

			if ( null !== $offer ) {
				return $offer;
			}
		}

		foreach ( $node as $child ) {
			$offer = $this->find_offer_with_price( $child );

			if ( null !== $offer ) {
				return $offer;
			}
		}

		return null;
	}

	/**
	 * @return array{price: float|null, currency: string, extraction_method: string, error: string}
	 */
	private function parse_meta_tags( string $html ): array {
		$price_keys    = array( 'product:price:amount', 'og:price:amount', 'twitter:price:amount', 'price', 'product_price', 'amount' );
		$currency_keys = array( 'product:price:currency', 'og:price:currency', 'twitter:price:currency', 'pricecurrency', 'currency' );
		$currency      = 'NOK';

		foreach ( $currency_keys as $key ) {
			$value = $this->get_meta_content( $html, $key );

			if ( '' !== $value ) {
				$currency = $this->normalize_currency( $value );
				break;
			}
		}

		foreach ( $price_keys as $key ) {
			$value = $this->get_meta_content( $html, $key );
			$price = $this->normalize_price( $value );

			if ( null !== $price ) {
				return array(
					'price'             => $price,
					'currency'          => $currency,
					'extraction_method' => 'meta_' . sanitize_key( $key ),
					'error'             => '',
				);
			}
		}

		return $this->failure( '' );
	}

	private function get_meta_content( string $html, string $key ): string {
		$escaped = preg_quote( $key, '#' );
		$pattern = '#<meta[^>]+(?:property|name|itemprop)=["\']' . $escaped . '["\'][^>]+content=["\']([^"\']+)["\'][^>]*>#i';

		if ( preg_match( $pattern, $html, $match ) ) {
			return html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5 );
		}

		$reverse_pattern = '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name|itemprop)=["\']' . $escaped . '["\'][^>]*>#i';

		if ( preg_match( $reverse_pattern, $html, $match ) ) {
			return html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5 );
		}

		return '';
	}

	/**
	 * @return array{price: float|null, currency: string, extraction_method: string, error: string}
	 */
	private function parse_visible_price( string $html ): array {
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5 );
		$text = preg_replace( '/\s+/', ' ', $text );

		if ( ! is_string( $text ) || '' === $text ) {
			return $this->failure( '' );
		}

		$patterns = array(
			'/\bNOK\s*([0-9][0-9\s.]*[,\.]?[0-9]{0,2})\b/i',
			'/\bkr\s*([0-9][0-9\s.]*[,\.]?[0-9]{0,2})\b/i',
			'/\b([0-9][0-9\s.]*[,\.]?[0-9]{0,2})\s*(?:NOK|kr)\b/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( ! preg_match( $pattern, $text, $match ) ) {
				continue;
			}

			$price = $this->normalize_price( $match[1] );

			if ( null !== $price ) {
				return array(
					'price'             => $price,
					'currency'          => 'NOK',
					'extraction_method' => 'visible_nok_regex',
					'error'             => '',
				);
			}
		}

		return $this->failure( '' );
	}

	/**
	 * @param mixed $value Raw price.
	 */
	private function normalize_price( $value ): ?float {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = trim( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5 ) );
		$value = preg_replace( '/[^0-9,.\s]/', '', $value );

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$value = trim( $value );
		$last_comma = strrpos( $value, ',' );
		$last_dot   = strrpos( $value, '.' );

		if ( false !== $last_comma && false !== $last_dot ) {
			$decimal_separator = $last_comma > $last_dot ? ',' : '.';
			$thousand_separator = ',' === $decimal_separator ? '.' : ',';
			$value = str_replace( $thousand_separator, '', $value );
			$value = str_replace( $decimal_separator, '.', $value );
		} elseif ( false !== $last_comma ) {
			$value = str_replace( ' ', '', $value );
			$value = str_replace( ',', '.', $value );
		} else {
			$value = str_replace( array( ' ', ',' ), '', $value );
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$price = round( (float) $value, 4 );

		return $price > 0 ? $price : null;
	}

	/**
	 * @param mixed $currency Raw currency.
	 */
	private function normalize_currency( $currency ): string {
		$currency = strtoupper( sanitize_text_field( (string) $currency ) );
		$currency = preg_replace( '/[^A-Z]/', '', $currency );

		return is_string( $currency ) && '' !== $currency ? substr( $currency, 0, 10 ) : 'NOK';
	}

	private function trim_html( string $html ): string {
		return substr( $html, 0, 1048576 );
	}

	/**
	 * @return array{price: float|null, currency: string, extraction_method: string, error: string}
	 */
	private function failure( string $error ): array {
		return array(
			'price'             => null,
			'currency'          => '',
			'extraction_method' => '',
			'error'             => $error,
		);
	}
}
