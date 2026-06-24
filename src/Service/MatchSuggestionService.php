<?php
/**
 * Match scoring for competitor discovery suggestions.
 *
 * @package LillePrinsen\PriceMonitor\Service
 */

namespace LillePrinsen\PriceMonitor\Service;

use LillePrinsen\PriceMonitor\Database\DiscoveryRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds deterministic, explainable match suggestions.
 */
class MatchSuggestionService {
    private DiscoveryRepository $repository;

    /**
     * Constructor.
     */
    public function __construct( DiscoveryRepository $repository ) {
        $this->repository = $repository;
    }

    /**
     * Create suggestions for a discovered competitor product.
     *
     * @param int                 $discovered_product_id Discovered row ID.
     * @param object              $discovered Discovered row.
     * @param array<int,object>   $selected_products Selected products.
     * @return array<int,int> Suggestion IDs.
     */
    public function create_suggestions( int $discovered_product_id, object $discovered, array $selected_products ): array {
        $ids = array();

        foreach ( $selected_products as $product ) {
            $match = $this->score_match( $product, $discovered );
            if ( empty( $match ) ) {
                continue;
            }

            $fingerprint = $this->fingerprint( $product, $discovered, $match['match_type'] );
            $id = $this->repository->create_suggestion_if_new(
                array(
                    'discovery_product_id' => (int) $product->id,
                    'discovered_product_id'=> $discovered_product_id,
                    'product_id'           => (int) $product->product_id,
                    'variation_id'         => (int) $product->variation_id,
                    'competitor_id'        => (int) $discovered->competitor_id,
                    'competitor_url'       => (string) $discovered->url,
                    'match_type'           => $match['match_type'],
                    'confidence_score'     => $match['confidence_score'],
                    'confidence_label'     => $match['confidence_label'],
                    'explanation'          => $match['explanation'],
                    'fingerprint'          => $fingerprint,
                )
            );

            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Score one possible match.
     *
     * @return array<string,mixed>
     */
    public function score_match( object $product, object $discovered ): array {
        if ( '' !== (string) $product->normalized_gtin && (string) $product->normalized_gtin === (string) $discovered->normalized_gtin ) {
            return array(
                'match_type'       => 'exact_gtin',
                'confidence_score' => 98,
                'confidence_label' => 'High confidence',
                'explanation'      => sprintf( 'Matched because EAN/GTIN %s was found on the competitor product page.', $product->gtin ),
            );
        }

        if ( '' !== (string) $product->normalized_sku && (string) $product->normalized_sku === (string) $discovered->normalized_sku ) {
            return array(
                'match_type'       => 'exact_sku',
                'confidence_score' => 95,
                'confidence_label' => 'High confidence',
                'explanation'      => sprintf( 'Matched because SKU %s was found on the competitor product page.', $product->sku ),
            );
        }

        if ( '' !== (string) $product->normalized_mpn && (string) $product->normalized_mpn === (string) $discovered->normalized_mpn && $this->same_brand( (string) $product->brand, (string) $discovered->brand ) ) {
            return array(
                'match_type'       => 'exact_mpn_brand',
                'confidence_score' => 92,
                'confidence_label' => 'High confidence',
                'explanation'      => sprintf( 'Matched because MPN %s and the brand both match.', $product->mpn ),
            );
        }

        $brand_matches = $this->same_brand( (string) $product->brand, (string) $discovered->brand );
        $title_score   = $this->title_similarity( $product, $discovered );

        if ( $brand_matches && $title_score >= 78 ) {
            return array(
                'match_type'       => 'brand_title',
                'confidence_score' => min( 89, $title_score ),
                'confidence_label' => 'Medium confidence',
                'explanation'      => 'Possible match because the brand matches and the product names are very similar, but no exact SKU/EAN was found.',
            );
        }

        if ( $title_score >= 86 ) {
            return array(
                'match_type'       => 'title_only',
                'confidence_score' => min( 59, $title_score ),
                'confidence_label' => 'Low confidence',
                'explanation'      => 'Possible match because the product names are similar, but no strong identifier was found.',
            );
        }

        return array();
    }

    /**
     * Content fingerprint used to avoid duplicate/rejected suggestion spam.
     */
    private function fingerprint( object $product, object $discovered, string $match_type ): string {
        $parts = array(
            (int) $product->product_id,
            (int) $product->variation_id,
            (int) $discovered->competitor_id,
            (string) $discovered->url_hash,
            (string) $discovered->content_hash,
            (string) $product->normalized_sku,
            (string) $product->normalized_gtin,
            (string) $product->normalized_mpn,
            $match_type,
        );

        return hash( 'sha256', implode( '|', $parts ) );
    }

    /**
     * Compare normalized brands.
     */
    private function same_brand( string $one, string $two ): bool {
        $one = $this->normalize_text( $one );
        $two = $this->normalize_text( $two );

        return '' !== $one && $one === $two;
    }

    /**
     * Compare product title/name.
     */
    private function title_similarity( object $product, object $discovered ): int {
        $product_name = '';
        if ( function_exists( 'get_the_title' ) ) {
            $product_name = get_the_title( (int) $product->product_id );
        }

        $one = $this->normalize_text( $product_name );
        $two = $this->normalize_text( (string) $discovered->title );

        if ( '' === $one || '' === $two ) {
            return 0;
        }

        similar_text( $one, $two, $percent );

        return (int) round( $percent );
    }

    /**
     * Normalize text for soft comparison.
     */
    private function normalize_text( string $value ): string {
        $value = strtolower( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $value = preg_replace( '/[^a-z0-9æøå]+/u', ' ', $value );
        $value = preg_replace( '/\s+/', ' ', (string) $value );

        return trim( (string) $value );
    }
}
