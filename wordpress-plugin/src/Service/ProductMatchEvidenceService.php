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
	private VisualProductMatchService $visual_matcher;

	private PriceAnomalyService $anomaly_service;

	public function __construct( ?VisualProductMatchService $visual_matcher = null, ?PriceAnomalyService $anomaly_service = null ) {
		$this->visual_matcher = $visual_matcher ?: new VisualProductMatchService();
		$this->anomaly_service = $anomaly_service ?: new PriceAnomalyService();
	}

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
		$price_sanity     = $this->price_sanity_evidence( $product, $discovered );

		if ( '' !== (string) ( $product->normalized_gtin ?? '' ) && (string) $product->normalized_gtin === (string) ( $discovered->normalized_gtin ?? '' ) ) {
			return $this->identifier_result(
				'exact_gtin',
				98,
				array( sprintf( 'Exact EAN/GTIN match: %s.', (string) ( $product->gtin ?? $product->normalized_gtin ) ) ),
				$price_sanity,
				'identifier'
			);
		}

		if ( '' !== (string) ( $product->normalized_sku ?? '' ) && (string) $product->normalized_sku === (string) ( $discovered->normalized_sku ?? '' ) ) {
			return $this->identifier_result(
				'exact_sku',
				95,
				array( sprintf( 'Exact SKU match: %s.', (string) ( $product->sku ?? $product->normalized_sku ) ) ),
				$price_sanity,
				'identifier'
			);
		}

		$brand_matches = $this->brand_matches( $product_brand, $competitor_brand, $competitor_title );
		if ( '' !== (string) ( $product->normalized_mpn ?? '' ) && (string) $product->normalized_mpn === (string) ( $discovered->normalized_mpn ?? '' ) && $brand_matches ) {
			return $this->identifier_result(
				'exact_mpn_brand',
				92,
				array( sprintf( 'Exact MPN match with matching brand: %s.', (string) ( $product->mpn ?? $product->normalized_mpn ) ) ),
				$price_sanity,
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
		$score += (int) $price_sanity['score'];
		$evidence = array_merge( $evidence, $price_sanity['evidence'] );
		$warnings = array_merge( $warnings, $price_sanity['warnings'] );

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

		$visual_result = $this->visual_matcher->compare( $product, $discovered );
		$score += (int) $visual_result['score'];
		$evidence = array_merge( $evidence, $visual_result['evidence'] );
		$warnings = array_merge( $warnings, $visual_result['warnings'] );

		$anomaly_result = $this->anomaly_service->analyze_discovered_match( $discovered );
		if ( ! empty( $anomaly_result['warnings'] ) ) {
			$warnings = array_merge( $warnings, $anomaly_result['warnings'] );
		}
		if ( ! empty( $anomaly_result['blocked'] ) ) {
			$score -= 35;
		}

		$hard_warnings = $this->hard_negative_warnings( $warnings );
		if ( ! empty( $hard_warnings ) ) {
			$score -= min( 35, 10 * count( $hard_warnings ) );
		}

		$score = max( 0, min( 89, $score ) );
		if ( ! empty( $price_sanity['reject'] ) ) {
			return array();
		}

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
	private function identifier_result( string $match_type, int $base_score, array $evidence, array $price_sanity, string $source ): array {
		$score    = max( 0, min( 98, $base_score + (int) $price_sanity['score'] ) );
		$evidence = array_merge( $evidence, $price_sanity['evidence'] );
		$warnings = array_merge( array(), $price_sanity['warnings'] );

		if ( ! empty( $price_sanity['reject'] ) ) {
			return $this->result(
				$match_type,
				min( 59, $score ),
				'Low confidence',
				$evidence,
				$warnings,
				$source
			);
		}

		if ( ! empty( $price_sanity['warn'] ) ) {
			return $this->result(
				$match_type,
				min( 79, $score ),
				'Medium confidence',
				$evidence,
				$warnings,
				$source
			);
		}

		return $this->result( $match_type, $score, 'High confidence', $evidence, $warnings, $source );
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

	/**
	 * @return array{score:int,evidence:array<int,string>,warnings:array<int,string>,warn:bool,reject:bool}
	 */
	private function price_sanity_evidence( object $product, object $discovered ): array {
		$expected_price  = $this->expected_product_price( $product );
		$candidate_price = $this->candidate_price( $discovered );

		if ( null === $expected_price || null === $candidate_price || $expected_price <= 0 ) {
			return array(
				'score'    => 0,
				'evidence' => array(),
				'warnings' => array(),
				'warn'     => false,
				'reject'   => false,
			);
		}

		$ratio   = $candidate_price / $expected_price;
		$percent = round( $ratio * 100 );

		if ( $ratio <= 0.35 ) {
			return array(
				'score'    => -60,
				'evidence' => array(),
				'warnings' => array(
					sprintf(
						'Price sanity reject: competitor price %.2f is only %d%% of our price %.2f. This may be a wrong price, accessory, out-of-stock trap, scraped listing price, or bundle component.',
						$candidate_price,
						$percent,
						$expected_price
					),
				),
				'warn'     => true,
				'reject'   => true,
			);
		}

		if ( $ratio >= 3.0 ) {
			return array(
				'score'    => -45,
				'evidence' => array(),
				'warnings' => array(
					sprintf(
						'Price sanity warning: competitor price %.2f is %d%% of our price %.2f. Review for bundle, wrong product, or scraped wrong page.',
						$candidate_price,
						$percent,
						$expected_price
					),
				),
				'warn'     => true,
				'reject'   => false,
			);
		}

		if ( $ratio <= 0.50 || $ratio >= 2.20 ) {
			return array(
				'score'    => -25,
				'evidence' => array(),
				'warnings' => array(
					sprintf(
						'Price sanity warning: competitor price %.2f is far from our price %.2f (%d%%). Review before approval.',
						$candidate_price,
						$expected_price,
						$percent
					),
				),
				'warn'     => true,
				'reject'   => false,
			);
		}

		if ( $ratio >= 0.60 && $ratio <= 1.80 ) {
			return array(
				'score'    => 5,
				'evidence' => array(
					sprintf(
						'Price sanity check passed: competitor price %.2f is within the expected range of our price %.2f.',
						$candidate_price,
						$expected_price
					),
				),
				'warnings' => array(),
				'warn'     => false,
				'reject'   => false,
			);
		}

		return array(
			'score'    => 0,
			'evidence' => array(),
			'warnings' => array(
				sprintf(
					'Price sanity is uncertain: competitor price %.2f differs noticeably from our price %.2f.',
					$candidate_price,
					$expected_price
				),
			),
			'warn'     => true,
			'reject'   => false,
		);
	}

	private function expected_product_price( object $product ): ?float {
		foreach ( array( 'current_price', 'product_price', 'price', 'sale_price', 'regular_price' ) as $field ) {
			if ( isset( $product->{$field} ) ) {
				$price = $this->price_value( $product->{$field} );
				if ( null !== $price ) {
					return $price;
				}
			}
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$product_id = (int) ( $product->variation_id ?? 0 );
			if ( $product_id <= 0 ) {
				$product_id = (int) ( $product->product_id ?? $product->id ?? 0 );
			}
			$wc_product = $product_id > 0 ? wc_get_product( $product_id ) : null;
			if ( is_object( $wc_product ) && method_exists( $wc_product, 'get_price' ) ) {
				return $this->price_value( $wc_product->get_price() );
			}
		}

		return null;
	}

	private function candidate_price( object $discovered ): ?float {
		foreach ( array( 'monitored_price', 'price', 'sale_price', 'regular_price' ) as $field ) {
			if ( isset( $discovered->{$field} ) ) {
				$price = $this->price_value( $discovered->{$field} );
				if ( null !== $price ) {
					return $price;
				}
			}
		}

		return null;
	}

	private function price_value( $value ): ?float {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = str_replace( array( ' ', "\xc2\xa0" ), '', (string) $value );
		if ( str_contains( $value, ',' ) && ! str_contains( $value, '.' ) ) {
			$value = str_replace( ',', '.', $value );
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$price = round( (float) $value, 4 );

		return $price > 0 ? $price : null;
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

		$replacements = array_merge(
			$this->retail_term_groups(),
			$this->color_term_groups()
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

	/**
	 * @return array<string,array<int,string>>
	 */
	private function retail_term_groups(): array {
		return array(
			'bassinet'  => array( 'bassinet', 'bag', 'liggedel' ),
			'stroller'  => array( 'stroller', 'vogn', 'barnevogn', 'trille', 'triller' ),
			'double'    => array( 'double', 'dobbel', 'soskenvogn', 'søskenvogn' ),
			'single'    => array( 'single', 'singel', 'enkel' ),
			'bundle'    => array( 'bundle', 'package', 'pakke', 'vognpakke', 'inkl', 'ink', 'incl', 'included', 'inkludert', 'with' ),
			'kit'       => array( 'kit', 'sett' ),
		);
	}

	/**
	 * @return array<string,array<int,string>>
	 */
	private function color_term_groups(): array {
		return array(
			'black'      => array( 'black', 'sort', 'midnight black', 'black on black' ),
			'navy'       => array( 'navy', 'marine', 'navy blue', 'dark blue', 'mork bla', 'morkebla', 'mørk blå', 'mørkeblå' ),
			'blue'       => array( 'blue', 'bla', 'blå', 'mid blue' ),
			'grey'       => array( 'grey', 'gray', 'gra', 'grå', 'grey melange', 'gray melange' ),
			'green'      => array( 'green', 'gronn', 'grønn', 'sage', 'forest green' ),
			'beige'      => array( 'beige', 'sand', 'cream', 'creme' ),
			'taupe'      => array( 'taupe', 'tinted taupe' ),
			'anthracite' => array( 'anthracite', 'antrasitt' ),
			'mocha'      => array( 'mocha', 'mokka' ),
			'brown'      => array( 'brown', 'brun' ),
			'white'      => array( 'white', 'hvit' ),
			'red'        => array( 'red', 'rod', 'rød' ),
			'pink'       => array( 'pink', 'rosa' ),
			'purple'     => array( 'purple', 'lilla' ),
			'yellow'     => array( 'yellow', 'gul' ),
			'orange'     => array( 'orange' ),
			'gold'       => array( 'gold', 'gull' ),
			'silver'     => array( 'silver', 'solv', 'sølv' ),
		);
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
		$ignore = array_merge( array_keys( $this->color_term_groups() ), array( 'bundle', 'bassinet', 'stroller', 'single', 'double', 'kit', 'thule', 'baby', 'brand' ) );
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
		$pairs    = array_merge(
			array(
				'bassinet' => array( 'bassinet', 'bag', 'liggedel' ),
				'stroller' => array( 'stroller', 'vogn', 'barnevogn' ),
				'double'   => array( 'double', 'dobbel', 'søskenvogn', 'soskenvogn' ),
			),
			$this->color_term_groups()
		);
		$one_norm = $this->normalize_text( $one );
		$two_norm = $this->normalize_text( $two );
		$color_names = array_keys( $this->color_term_groups() );

		foreach ( $pairs as $canonical => $terms ) {
			$one_terms = array_values( array_filter( $terms, fn( $term ) => str_contains( $one_norm, $this->normalize_text( $term ) ) ) );
			$two_terms = array_values( array_filter( $terms, fn( $term ) => str_contains( $two_norm, $this->normalize_text( $term ) ) ) );
			if ( ! empty( $one_terms ) && ! empty( $two_terms ) && in_array( $canonical, $color_names, true ) ) {
				$evidence[] = sprintf( '%s terminology matches', ucfirst( $canonical ) );
			} elseif ( ! empty( $one_terms ) && ! empty( $two_terms ) && $one_terms[0] !== $two_terms[0] ) {
				$evidence[] = sprintf( '%s matched competitor term %s', ucfirst( $one_terms[0] ), $two_terms[0] );
			} elseif ( ! empty( $one_terms ) && ! empty( $two_terms ) && in_array( $canonical, array( 'bassinet', 'double' ), true ) ) {
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
		$colors_one = array_values( array_intersect( $one, array_keys( $this->color_term_groups() ) ) );
		$colors_two = array_values( array_intersect( $two, array_keys( $this->color_term_groups() ) ) );
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
					|| str_contains( strtolower( $warning ), 'anomaly:' )
			)
		);
	}
}
