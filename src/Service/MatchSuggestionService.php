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

}
