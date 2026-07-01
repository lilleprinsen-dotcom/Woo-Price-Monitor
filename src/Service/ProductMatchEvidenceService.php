<?php
/**
 * Evidence-based product matching for competitor discovery.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scores competitor product matches with explicit positive and negative evidence.
 */
class ProductMatchEvidenceService {
	/**
	 * Score one selected product against one extracted competitor product.
	 *
	 * @return array<string,mixed>
	 */
	public function score_match( object $product, object $discovered ): array {
		$product_title    = $this->product_title( $product );
		$competitor_title = (string) ( $discovered->title ?? '' );
		$product_brand    = (string) ( $product->brand ?? '' );
		$competitor_brand = (string) ( $discovered->brand ?? '' );

		if ( '' !== (string) ( $product->normalized_gtin ?? '' ) && (string) $product->normalized_gtin === (string) ( $discovered->normalized_gtin ?? '' ) ) {
			return $this->result(
				'exact_gtin',
				98,
				'High confidence',
				array( sprintf( 'Exact EAN/GTIN match: %s.', (string) ( $product->gtin ?? $product->normalized_gtin ) ) ),
				array(),
				'identifier'
			);
		}

		if ( '' !== (string) ( $product->normalized_sku ?? '' ) && (string) $product->normalized_sku === (string) ( $discovered->normalized_sku ?? '' ) ) {
			return $this->result(
				'exact_sku',
				95,
				'High confidence',
				array( sprintf( 'Exact SKU match: %s.', (string) ( $product->sku ?? $product->normalized_sku ) ) ),
				array(),
				'identifier'
			);
		}

		$brand_matches = $this->brand_matches( $product_brand, $competitor_brand, $competitor_title );
		if ( '' !== (string) ( $product->normalized_mpn ?? '' ) && (string) $product->normalized_mpn === (string) ( $discovered->normalized_mpn ?? '' ) && $brand_matches ) {
			return $this->result(
				'exact_mpn_brand',
				92,
				'High confidence',
				array( sprintf( 'Exact MPN match with matching brand: %s.', (string) ( $product->mpn ?? $product->normalized_mpn ) ) ),
				array(),
				'identifier'
			);
		}

		$product_tokens    = $this->canonical_tokens( $product_title . ' ' . $product_brand );
		$competitor_tokens = $this->canonical_tokens( $competitor_title . ' ' . $competitor_brand );
		$product_class     = $this->classify_text( $product_title );
		$competitor_class  = $this->classify_text( $competitor_title );
		$evidence          = array();
		$warnings          = array();
		$score             = 0;

		if ( $brand_matches ) {
			$score += 20;
			$evidence[] = sprintf( 'Brand matches: %s.', $product_brand );
		}

		$family_hits      = $this->family_term_hits( $product_tokens, $competitor_tokens );
		$family_hit_count = count( $family_hits );
		if ( $family_hit_count >= 3 ) {
			$score += 28;
			$evidence[] = 'Product family/model terms match: ' . implode( ', ', array_slice( array_keys( $family_hits ), 0, 5 ) ) . '.';
		} elseif ( $family_hit_count >= 2 ) {
			$score += 18;
			$evidence[] = 'Some product family/model terms match: ' . implode( ', ', array_keys( $family_hits ) ) . '.';
		}

		$synonyms = $this->synonym_evidence( $product_title, $competitor_title );
		if ( ! empty( $synonyms ) ) {
			$score += min( 16, 8 * count( $synonyms ) );
			$evidence[] = 'Vocabulary match: ' . implode( '; ', $synonyms ) . '.';
		}

		$title_overlap = $this->title_token_overlap( $product_tokens, $competitor_tokens );
		if ( $title_overlap >= 70 ) {
			$score += 18;
			$evidence[] = 'Strong normalized title-token overlap.';
		} elseif ( $title_overlap >= 45 ) {
			$score += 10;
			$evidence[] = 'Partial normalized title-token overlap.';
		}

		$color_result = $this->color_evidence( $product_tokens, $competitor_tokens );
		$score += (int) $color_result['score'];
		$evidence = array_merge( $evidence, $color_result['evidence'] );
		$warnings = array_merge( $warnings, $color_result['warnings'] );

		$variant_result = $this->variant_evidence( $product_tokens, $competitor_tokens );
		$score += (int) $variant_result['score'];
		$evidence = array_merge( $evidence, $variant_result['evidence'] );
		$warnings = array_merge( $warnings, $variant_result['warnings'] );

		$type_result = $this->product_type_evidence( $product_class, $competitor_class );
		$score += (int) $type_result['score'];
		$evidence = array_merge( $evidence, $type_result['evidence'] );
		$warnings = array_merge( $warnings, $type_result['warnings'] );

		$hard_warnings = $this->hard_negative_warnings( $warnings );
		if ( ! empty( $hard_warnings ) ) {
			$score -= min( 35, 10 * count( $hard_warnings ) );
		}

		$score = max( 0, min( 89, $score ) );
		if ( $brand_matches && $score >= 55 && empty( $hard_warnings ) ) {
			return $this->result( 'brand_title_evidence', $score, 'Medium confidence', $evidence, $warnings, 'evidence' );
		}

		if ( $score >= 35 ) {
			return $this->result( 'title_only', min( 59, $score ), 'Low confidence', $evidence, $warnings, 'title' );
		}

		return array();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function result( string $match_type, int $score, string $label, array $evidence, array $warnings, string $source ): array {
		$parts = array();
		if ( ! empty( $evidence ) ) {
			$parts[] = 'Evidence: ' . implode( ' ', array_values( array_unique( array_filter( array_map( 'strval', $evidence ) ) ) ) );
		}
		if ( ! empty( $warnings ) ) {
			$parts[] = 'Warnings: ' . implode( ' ', array_values( array_unique( array_filter( array_map( 'strval', $warnings ) ) ) ) );
		}
		if ( 'High confidence' !== $label ) {
			$parts[] = 'No exact SKU/EAN/MPN+brand was found, so this cannot be high confidence and must be approved manually.';
		}

		return array(
			'match_type'       => $match_type,
			'confidence_score' => $score,
			'confidence_label' => $label,
			'explanation'      => implode( ' ', $parts ),
			'evidence'         => array_values( array_unique( $evidence ) ),
			'warnings'         => array_values( array_unique( $warnings ) ),
			'evidence_source'  => $source,
		);
	}

	private function product_title( object $product ): string {
		foreach ( array( 'product_name', 'title', 'name' ) as $field ) {
			if ( ! empty( $product->{$field} ) ) {
				return (string) $product->{$field};
			}
		}

		if ( function_exists( 'get_the_title' ) ) {
			$title = get_the_title( (int) ( $product->product_id ?? 0 ) );
			if ( '' !== (string) $title ) {
				return (string) $title;
			}
		}

		return (string) ( $product->sku ?? '' );
	}

	private function brand_matches( string $product_brand, string $competitor_brand, string $competitor_title ): bool {
		$one = $this->normalize_text( $product_brand );
		$two = $this->normalize_text( $competitor_brand );
		$title = ' ' . $this->normalize_text( $competitor_title ) . ' ';

		return '' !== $one && ( $one === $two || str_contains( $title, ' ' . $one . ' ' ) );
	}

	/**
	 * @return array<int,string>
	 */
	private function canonical_tokens( string $text ): array {
		$text = $this->normalize_text( $text );
		$text = preg_replace( '/\bblack\s+on\s+black\b/u', ' black ', $text );
		$text = preg_replace( '/\bmidnight\s+black\b/u', ' black ', (string) $text );
		$text = preg_replace( '/\bmid\s+blue\b/u', ' blue ', (string) $text );

		$replacements = array(
			'bassinet'  => array( 'bassinet', 'bag', 'liggedel' ),
			'stroller'  => array( 'stroller', 'vogn', 'barnevogn', 'trille', 'triller' ),
			'double'    => array( 'double', 'dobbel', 'soskenvogn', 'søskenvogn' ),
			'single'    => array( 'single', 'singel', 'enkel' ),
			'black'     => array( 'black', 'sort' ),
			'blue'      => array( 'blue', 'bla', 'blå' ),
			'bundle'    => array( 'bundle', 'package', 'pakke', 'vognpakke', 'inkl', 'ink', 'incl', 'included', 'inkludert', 'with' ),
			'kit'       => array( 'kit', 'sett' ),
		);

		foreach ( $replacements as $canonical => $terms ) {
			foreach ( $terms as $term ) {
				$text = preg_replace( '/\b' . preg_quote( $term, '/' ) . '\b/u', ' ' . $canonical . ' ', (string) $text );
			}
		}

		$tokens = preg_split( '/\s+/u', (string) $text ) ?: array();
		$tokens = array_values(
			array_filter(
				$tokens,
				static function ( string $token ): bool {
					return '' !== $token && ! in_array( $token, array( 'and', 'or', 'the', 'for', 'med', 'og', 'til', 'on' ), true );
				}
			)
		);

		return array_values( array_unique( $tokens ) );
	}

	private function normalize_text( string $value ): string {
		$value = strtolower( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$value = str_replace( array( '&ndash;', '&mdash;', '–', '—' ), ' ', $value );
		$value = preg_replace( '/[^a-z0-9æøå]+/u', ' ', $value );
		$value = str_replace( array( 'æ', 'ø', 'å' ), array( 'ae', 'o', 'a' ), (string) $value );
		$value = preg_replace( '/\s+/', ' ', (string) $value );

		return trim( (string) $value );
	}

	/**
	 * @return array<string,bool>
	 */
	private function family_term_hits( array $one, array $two ): array {
		$ignore = array( 'black', 'blue', 'bundle', 'bassinet', 'stroller', 'single', 'double', 'kit', 'thule', 'baby', 'brand' );
		$one    = array_diff( $one, $ignore );
		$two    = array_diff( $two, $ignore );
		$hits   = array_intersect( $one, $two );

		return array_fill_keys( array_values( $hits ), true );
	}

	/**
	 * @return array<int,string>
	 */
	private function synonym_evidence( string $one, string $two ): array {
		$evidence = array();
		$pairs    = array(
			'bassinet' => array( 'bassinet', 'bag', 'liggedel' ),
			'stroller' => array( 'stroller', 'vogn', 'barnevogn' ),
			'double'   => array( 'double', 'dobbel', 'søskenvogn', 'soskenvogn' ),
			'black'    => array( 'black', 'sort', 'midnight black', 'black on black' ),
			'blue'     => array( 'blue', 'blå', 'bla', 'mid blue' ),
		);
		$one_norm = $this->normalize_text( $one );
		$two_norm = $this->normalize_text( $two );

		foreach ( $pairs as $canonical => $terms ) {
			$one_terms = array_values( array_filter( $terms, fn( $term ) => str_contains( $one_norm, $this->normalize_text( $term ) ) ) );
			$two_terms = array_values( array_filter( $terms, fn( $term ) => str_contains( $two_norm, $this->normalize_text( $term ) ) ) );
			if ( ! empty( $one_terms ) && ! empty( $two_terms ) && $one_terms[0] !== $two_terms[0] ) {
				$evidence[] = sprintf( '%s matched competitor term %s', ucfirst( $one_terms[0] ), $two_terms[0] );
			} elseif ( ! empty( $one_terms ) && ! empty( $two_terms ) && in_array( $canonical, array( 'bassinet', 'double', 'black', 'blue' ), true ) ) {
				$evidence[] = sprintf( '%s terminology matches', ucfirst( $canonical ) );
			}
		}

		return array_values( array_unique( $evidence ) );
	}

	private function title_token_overlap( array $one, array $two ): int {
		if ( empty( $one ) || empty( $two ) ) {
			return 0;
		}

		return (int) round( ( count( array_intersect( $one, $two ) ) / max( 1, count( $one ) ) ) * 100 );
	}

	/**
	 * @return array{score:int,evidence:array<int,string>,warnings:array<int,string>}
	 */
	private function color_evidence( array $one, array $two ): array {
		$colors_one = array_values( array_intersect( $one, array( 'black', 'blue' ) ) );
		$colors_two = array_values( array_intersect( $two, array( 'black', 'blue' ) ) );
		if ( empty( $colors_one ) || empty( $colors_two ) ) {
			return array( 'score' => 0, 'evidence' => array(), 'warnings' => array( 'Color/variant is uncertain.' ) );
		}
		if ( ! empty( array_intersect( $colors_one, $colors_two ) ) ) {
			return array( 'score' => 8, 'evidence' => array( 'Color/variant appears to match.' ), 'warnings' => array() );
		}

		return array( 'score' => -18, 'evidence' => array(), 'warnings' => array( 'Color/variant mismatch: our product appears ' . implode( '/', $colors_one ) . ', competitor appears ' . implode( '/', $colors_two ) . '.' ) );
	}

	/**
	 * @return array{score:int,evidence:array<int,string>,warnings:array<int,string>}
	 */
	private function variant_evidence( array $one, array $two ): array {
		$variants = array( 'single', 'double' );
		$one_hits = array_values( array_intersect( $one, $variants ) );
		$two_hits = array_values( array_intersect( $two, $variants ) );
		if ( empty( $one_hits ) || empty( $two_hits ) ) {
			return array( 'score' => 0, 'evidence' => array(), 'warnings' => array() );
		}
		if ( ! empty( array_intersect( $one_hits, $two_hits ) ) ) {
			return array( 'score' => 8, 'evidence' => array( 'Single/double variant appears to match.' ), 'warnings' => array() );
		}

		return array( 'score' => -30, 'evidence' => array(), 'warnings' => array( 'Single/double variant mismatch.' ) );
	}

	/**
	 * @return array{score:int,evidence:array<int,string>,warnings:array<int,string>}
	 */
	private function product_type_evidence( string $one, string $two ): array {
		if ( $one === $two && 'unknown' !== $one ) {
			return array( 'score' => 10, 'evidence' => array( 'Product type appears to match: ' . $one . '.' ), 'warnings' => array() );
		}
		if ( 'bundle' === $two && 'bundle' !== $one ) {
			return array( 'score' => -25, 'evidence' => array(), 'warnings' => array( 'Competitor title appears to be a bundle/package.' ) );
		}
		if ( 'accessory' === $one && in_array( $two, array( 'bundle', 'base_product' ), true ) ) {
			return array( 'score' => -25, 'evidence' => array(), 'warnings' => array( 'Our product looks like an accessory, but competitor result looks like ' . $two . '.' ) );
		}
		if ( 'unknown' === $one || 'unknown' === $two ) {
			return array( 'score' => 0, 'evidence' => array(), 'warnings' => array( 'Product type is uncertain.' ) );
		}

		return array( 'score' => -15, 'evidence' => array(), 'warnings' => array( 'Product type may not match: our product is ' . $one . ', competitor is ' . $two . '.' ) );
	}

	private function classify_text( string $text ): string {
		$tokens = $this->canonical_tokens( $text );
		if ( in_array( 'bundle', $tokens, true ) ) {
			return 'bundle';
		}
		if ( in_array( 'bassinet', $tokens, true ) || in_array( 'kit', $tokens, true ) ) {
			return 'accessory';
		}
		if ( in_array( 'stroller', $tokens, true ) ) {
			return 'base_product';
		}

		return 'unknown';
	}

	/**
	 * @return array<int,string>
	 */
	private function hard_negative_warnings( array $warnings ): array {
		return array_values(
			array_filter(
				$warnings,
				static fn( string $warning ): bool => str_contains( strtolower( $warning ), 'mismatch' ) || str_contains( strtolower( $warning ), 'bundle/package' )
			)
		);
	}
}
