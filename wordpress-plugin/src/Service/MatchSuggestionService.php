<?php
/**
 * Match scoring for competitor discovery suggestions.
 *
 * @package Lilleprinsen\PriceMonitor\Service
 */

namespace Lilleprinsen\PriceMonitor\Service;

use Lilleprinsen\PriceMonitor\Database\DiscoveryRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds deterministic, explainable match suggestions.
 */
class MatchSuggestionService {
    private DiscoveryRepository $repository;
    private ProductMatchEvidenceService $evidence_service;

    /**
     * Constructor.
     */
    public function __construct( DiscoveryRepository $repository, ?ProductMatchEvidenceService $evidence_service = null ) {
        $this->repository = $repository;
        $this->evidence_service = $evidence_service ?: new ProductMatchEvidenceService();
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
        return $this->evidence_service->score_match( $product, $discovered );
    }

    /**
     * Identity fingerprint used to avoid duplicate/rejected suggestion spam.
     *
     * Price and broad page content are intentionally excluded so a rejected
     * candidate does not reappear unless the candidate identity changes.
     */
    private function fingerprint( object $product, object $discovered, string $match_type ): string {
        $parts = array(
            (int) $product->product_id,
            (int) $product->variation_id,
            (int) $discovered->competitor_id,
            (string) $discovered->url_hash,
            $this->fingerprint_text( (string) ( $discovered->normalized_sku ?? $discovered->sku ?? '' ) ),
            $this->fingerprint_text( (string) ( $discovered->normalized_gtin ?? $discovered->gtin ?? '' ) ),
            $this->fingerprint_text( (string) ( $discovered->normalized_mpn ?? $discovered->mpn ?? '' ) ),
            $this->fingerprint_text( (string) ( $discovered->brand ?? '' ) ),
            $this->fingerprint_text( (string) ( $discovered->title ?? '' ) ),
            $this->fingerprint_url( (string) ( $discovered->image_url ?? '' ) ),
            (string) $product->normalized_sku,
            (string) $product->normalized_gtin,
            (string) $product->normalized_mpn,
            $match_type,
        );

        return hash( 'sha256', implode( '|', $parts ) );
    }

    private function fingerprint_text( string $value ): string {
        $value = html_entity_decode( strtolower( trim( $value ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        return (string) preg_replace( '/\s+/', ' ', $value );
    }

    private function fingerprint_url( string $url ): string {
        $path = wp_parse_url( $url, PHP_URL_PATH );

        return $this->fingerprint_text( (string) $path );
    }

}
