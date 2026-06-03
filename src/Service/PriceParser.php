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
	 * @param array<string, mixed> $rules Optional competitor extraction rules.
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, stock_status: string, error: string}
	 */
	public function parse( string $html, array $rules = array() ): array {
		$html  = $this->trim_html( $html );
		$rules = $this->normalize_rules( $rules );

		if ( '' === trim( $html ) ) {
			return $this->failure( __( 'The competitor page did not return any HTML.', 'lilleprinsen-price-monitor' ) );
		}

		foreach ( $this->get_extraction_order( $rules ) as $method ) {
			if ( 'selector' === $method ) {
				$result = $this->parse_selector_prices( $html, $rules );
			} elseif ( 'json_ld' === $method ) {
				$result = $this->parse_json_ld( $html, (string) $rules['default_currency'] );
			} elseif ( 'meta_tags' === $method ) {
				$result = $this->parse_meta_tags( $html, (string) $rules['default_currency'] );
			} else {
				$result = $this->parse_visible_price( $html, (string) $rules['default_currency'] );
			}

			if ( null !== $result['price'] ) {
				return $this->with_stock_status( $result, $html, $rules );
			}
		}

		return $this->failure( __( 'No recognizable price was found with the enabled extraction rules.', 'lilleprinsen-price-monitor' ) );
	}

	/**
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, stock_status: string, error: string}
	 */
	private function parse_json_ld( string $html, string $default_currency ): array {
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
				'success'           => true,
				'price'             => $price,
				'currency'          => $this->normalize_currency( $offer['priceCurrency'] ?? ( $offer['currency'] ?? $default_currency ) ),
				'extraction_method' => 'json_ld_offer',
				'stock_status'      => '',
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
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, stock_status: string, error: string}
	 */
	private function parse_meta_tags( string $html, string $default_currency ): array {
		$price_keys    = array( 'product:price:amount', 'og:price:amount', 'twitter:price:amount', 'price', 'product_price', 'amount' );
		$currency_keys = array( 'product:price:currency', 'og:price:currency', 'twitter:price:currency', 'pricecurrency', 'currency' );
		$currency      = $this->normalize_currency( $default_currency );

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
					'success'           => true,
					'price'             => $price,
					'currency'          => $currency,
					'extraction_method' => 'meta_' . sanitize_key( $key ),
					'stock_status'      => '',
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
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, stock_status: string, error: string}
	 */
	private function parse_visible_price( string $html, string $default_currency ): array {
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
					'success'           => true,
					'price'             => $price,
					'currency'          => $this->normalize_currency( $default_currency ),
					'extraction_method' => 'visible_nok_regex',
					'stock_status'      => '',
					'error'             => '',
				);
			}
		}

		return $this->failure( '' );
	}

	/**
	 * @param array<string, mixed> $rules Normalized extraction rules.
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, stock_status: string, error: string}
	 */
	private function parse_selector_prices( string $html, array $rules ): array {
		$selectors = array(
			'selector_sale_price' => (string) $rules['sale_price_selector'],
			'selector_price'      => (string) $rules['price_selector'],
		);

		foreach ( $selectors as $method => $selector ) {
			if ( '' === $selector ) {
				continue;
			}

			$text = $this->get_selector_text( $html, $selector );

			if ( '' === $text ) {
				continue;
			}

			$price = $this->normalize_price( $text );

			if ( null !== $price ) {
				return array(
					'success'           => true,
					'price'             => $price,
					'currency'          => (string) $rules['default_currency'],
					'extraction_method' => $method,
					'stock_status'      => '',
					'error'             => '',
				);
			}
		}

		return $this->failure( '' );
	}

	/**
	 * @param array<string, mixed> $rules Extraction rules.
	 * @return array<string, mixed>
	 */
	private function normalize_rules( array $rules ): array {
		$mode = isset( $rules['price_extraction_mode'] ) ? sanitize_key( (string) $rules['price_extraction_mode'] ) : 'auto';

		if ( ! in_array( $mode, array( 'auto', 'json_ld', 'meta_tags', 'selector', 'visible_regex' ), true ) ) {
			$mode = 'auto';
		}

		return array(
			'price_extraction_mode' => $mode,
			'price_selector'        => isset( $rules['price_selector'] ) ? sanitize_text_field( (string) $rules['price_selector'] ) : '',
			'sale_price_selector'   => isset( $rules['sale_price_selector'] ) ? sanitize_text_field( (string) $rules['sale_price_selector'] ) : '',
			'stock_selector'        => isset( $rules['stock_selector'] ) ? sanitize_text_field( (string) $rules['stock_selector'] ) : '',
			'stock_in_text'         => isset( $rules['stock_in_text'] ) ? sanitize_text_field( (string) $rules['stock_in_text'] ) : '',
			'stock_out_text'        => isset( $rules['stock_out_text'] ) ? sanitize_text_field( (string) $rules['stock_out_text'] ) : '',
			'json_ld_enabled'       => ! array_key_exists( 'json_ld_enabled', $rules ) || ! empty( $rules['json_ld_enabled'] ),
			'meta_tags_enabled'     => ! array_key_exists( 'meta_tags_enabled', $rules ) || ! empty( $rules['meta_tags_enabled'] ),
			'visible_regex_enabled' => ! array_key_exists( 'visible_regex_enabled', $rules ) || ! empty( $rules['visible_regex_enabled'] ),
			'default_currency'      => $this->normalize_currency( $rules['default_currency'] ?? 'NOK' ),
		);
	}

	/**
	 * @param array<string, mixed> $rules Normalized extraction rules.
	 * @return array<int, string>
	 */
	private function get_extraction_order( array $rules ): array {
		$order = array();
		$mode  = (string) $rules['price_extraction_mode'];

		if ( 'selector' === $mode && ( '' !== (string) $rules['price_selector'] || '' !== (string) $rules['sale_price_selector'] ) ) {
			$order[] = 'selector';
		} elseif ( 'json_ld' === $mode && ! empty( $rules['json_ld_enabled'] ) ) {
			$order[] = 'json_ld';
		} elseif ( 'meta_tags' === $mode && ! empty( $rules['meta_tags_enabled'] ) ) {
			$order[] = 'meta_tags';
		} elseif ( 'visible_regex' === $mode && ! empty( $rules['visible_regex_enabled'] ) ) {
			$order[] = 'visible_regex';
		}

		if ( ! empty( $rules['json_ld_enabled'] ) ) {
			$order[] = 'json_ld';
		}

		if ( ! empty( $rules['meta_tags_enabled'] ) ) {
			$order[] = 'meta_tags';
		}

		if ( ! empty( $rules['visible_regex_enabled'] ) ) {
			$order[] = 'visible_regex';
		}

		if ( '' !== (string) $rules['price_selector'] || '' !== (string) $rules['sale_price_selector'] ) {
			$order[] = 'selector';
		}

		return array_values( array_unique( $order ) );
	}

	/**
	 * @param array<string, mixed> $rules Normalized extraction rules.
	 * @param array{success: bool, price: float|null, currency: string, extraction_method: string, stock_status: string, error: string} $result Result.
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, stock_status: string, error: string}
	 */
	private function with_stock_status( array $result, string $html, array $rules ): array {
		$result['stock_status'] = $this->detect_stock_status( $html, $rules );

		return $result;
	}

	/**
	 * @param array<string, mixed> $rules Normalized extraction rules.
	 */
	private function detect_stock_status( string $html, array $rules ): string {
		$selector  = (string) $rules['stock_selector'];
		$in_text   = $this->normalize_match_text( (string) $rules['stock_in_text'] );
		$out_text  = $this->normalize_match_text( (string) $rules['stock_out_text'] );

		if ( '' === $selector || ( '' === $in_text && '' === $out_text ) ) {
			return '';
		}

		$text = $this->normalize_match_text( $this->get_selector_text( $html, $selector ) );

		if ( '' === $text ) {
			return 'unknown';
		}

		if ( '' !== $out_text && str_contains( $text, $out_text ) ) {
			return 'out_of_stock';
		}

		if ( '' !== $in_text && str_contains( $text, $in_text ) ) {
			return 'in_stock';
		}

		return 'unknown';
	}

	private function get_selector_text( string $html, string $selector ): string {
		$fallback_text = $this->get_selector_text_fallback( $html, $selector );

		if ( '' !== $fallback_text ) {
			return $fallback_text;
		}

		$xpath_expression = $this->selector_to_xpath( $selector );

		if ( null === $xpath_expression || ! class_exists( '\DOMDocument' ) || ! class_exists( '\DOMXPath' ) ) {
			return '';
		}

		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$options  = defined( 'LIBXML_NONET' ) ? LIBXML_NONET : 0;
		$loaded   = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html, $options );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return '';
		}

		$xpath = new \DOMXPath( $document );
		$nodes = $xpath->query( $xpath_expression );

		if ( ! $nodes || 0 === $nodes->length ) {
			return '';
		}

		$text_parts = array();

		foreach ( $nodes as $node ) {
			if ( count( $text_parts ) >= 5 ) {
				break;
			}

			$text = '';

			if ( $node instanceof \DOMElement ) {
				$text = $node->getAttribute( 'content' );

				if ( '' === $text ) {
					$text = $node->getAttribute( 'data-price' );
				}
			}

			if ( '' === $text ) {
				$text = $node->textContent;
			}

			$text = trim( preg_replace( '/\s+/', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5 ) ) ?? '' );

			if ( '' !== $text ) {
				$text_parts[] = $text;
			}
		}

		return trim( implode( ' ', $text_parts ) );
	}

	private function get_selector_text_fallback( string $html, string $selector ): string {
		$matcher = $this->simple_selector_matcher( $selector );

		if ( null === $matcher ) {
			return '';
		}

		if ( ! preg_match_all( '#<([A-Za-z][A-Za-z0-9:-]*)(\s[^>]*)?>(.*?)</\1>#is', $html, $matches, PREG_SET_ORDER ) ) {
			return '';
		}

		$text_parts = array();

		foreach ( $matches as $match ) {
			if ( count( $text_parts ) >= 5 ) {
				break;
			}

			$attributes = $this->parse_html_attributes( (string) ( $match[2] ?? '' ) );

			if ( ! $this->attributes_match_selector( $attributes, $matcher ) ) {
				continue;
			}

			$text = $this->extract_fallback_node_text( (string) ( $match[3] ?? '' ), $attributes );

			if ( '' !== $text ) {
				$text_parts[] = $text;
			}
		}

		return trim( implode( ' ', $text_parts ) );
	}

	/**
	 * @return array{type: string, name: string, value: string}|null
	 */
	private function simple_selector_matcher( string $selector ): ?array {
		$selector = trim( $selector );

		if ( preg_match( '/^\.([A-Za-z0-9_-]+)$/', $selector, $match ) ) {
			return array(
				'type'  => 'class',
				'name'  => 'class',
				'value' => $match[1],
			);
		}

		if ( preg_match( '/^#([A-Za-z0-9_-]+)$/', $selector, $match ) ) {
			return array(
				'type'  => 'id',
				'name'  => 'id',
				'value' => $match[1],
			);
		}

		if ( preg_match( '/^\[([A-Za-z0-9_:-]+)=["\']([^"\']+)["\']\]$/', $selector, $match ) ) {
			return array(
				'type'  => 'attribute',
				'name'  => strtolower( $match[1] ),
				'value' => $match[2],
			);
		}

		return null;
	}

	/**
	 * @return array<string, string>
	 */
	private function parse_html_attributes( string $raw_attributes ): array {
		$attributes = array();

		if ( ! preg_match_all( '/([A-Za-z_:][A-Za-z0-9_.:-]*)\s*=\s*(["\'])(.*?)\2/s', $raw_attributes, $matches, PREG_SET_ORDER ) ) {
			return $attributes;
		}

		foreach ( $matches as $match ) {
			$attributes[ strtolower( $match[1] ) ] = html_entity_decode( (string) $match[3], ENT_QUOTES | ENT_HTML5 );
		}

		return $attributes;
	}

	/**
	 * @param array<string, string> $attributes Parsed attributes.
	 * @param array{type: string, name: string, value: string} $matcher Selector matcher.
	 */
	private function attributes_match_selector( array $attributes, array $matcher ): bool {
		if ( 'class' === $matcher['type'] ) {
			$classes = preg_split( '/\s+/', trim( (string) ( $attributes['class'] ?? '' ) ) );

			return is_array( $classes ) && in_array( $matcher['value'], $classes, true );
		}

		if ( 'id' === $matcher['type'] ) {
			return (string) ( $attributes['id'] ?? '' ) === $matcher['value'];
		}

		return array_key_exists( $matcher['name'], $attributes ) && (string) $attributes[ $matcher['name'] ] === $matcher['value'];
	}

	/**
	 * @param array<string, string> $attributes Parsed attributes.
	 */
	private function extract_fallback_node_text( string $inner_html, array $attributes ): string {
		foreach ( array( 'content', 'data-price', 'aria-label', 'title' ) as $attribute ) {
			if ( ! empty( $attributes[ $attribute ] ) ) {
				return $this->normalize_extracted_text( $attributes[ $attribute ] );
			}
		}

		return $this->normalize_extracted_text( wp_strip_all_tags( $inner_html ) );
	}

	private function normalize_extracted_text( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5 );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( is_string( $text ) ? $text : '' );
	}

	private function normalize_match_text( string $text ): string {
		$text = $this->normalize_extracted_text( $text );

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $text, 'UTF-8' );
		}

		return strtolower( $text );
	}

	private function selector_to_xpath( string $selector ): ?string {
		$selector = trim( $selector );

		if ( '' === $selector ) {
			return null;
		}

		if ( preg_match( '/^\.([A-Za-z0-9_-]+)$/', $selector, $match ) ) {
			return "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $match[1] . " ')]";
		}

		if ( preg_match( '/^#([A-Za-z0-9_-]+)$/', $selector, $match ) ) {
			return "//*[@id='" . $match[1] . "']";
		}

		if ( preg_match( '/^\[([A-Za-z0-9_:-]+)=["\']([^"\']+)["\']\]$/', $selector, $match ) ) {
			return '//*[@' . $match[1] . '=' . $this->xpath_literal( $match[2] ) . ']';
		}

		return null;
	}

	private function xpath_literal( string $value ): string {
		if ( ! str_contains( $value, "'" ) ) {
			return "'" . $value . "'";
		}

		if ( ! str_contains( $value, '"' ) ) {
			return '"' . $value . '"';
		}

		$parts = explode( "'", $value );

		return "concat('" . implode( "', \"'\", '", $parts ) . "')";
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
	 * @return array{success: bool, price: float|null, currency: string, extraction_method: string, stock_status: string, error: string}
	 */
	private function failure( string $error ): array {
		return array(
			'success'           => false,
			'price'             => null,
			'currency'          => '',
			'extraction_method' => '',
			'stock_status'      => '',
			'error'             => $error,
		);
	}
}
