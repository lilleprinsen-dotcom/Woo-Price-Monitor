<?php
/**
 * Visual image matching helpers for competitor discovery.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

use Lilleprinsen\PriceMonitor\Settings\DiscoverySettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares product images through bounded embeddings/signatures.
 */
class VisualProductMatchService {
	private ?DiscoverySettings $settings;

	/** @var array<string,array<int,float>|null> */
	private array $memory_cache = array();

	public function __construct( ?DiscoverySettings $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * @return array{score:int,evidence:array<int,string>,warnings:array<int,string>,similarity:?float,status:string}
	 */
	public function compare( object $product, object $discovered ): array {
		if ( ! $this->enabled() ) {
			return $this->empty_result( 'disabled' );
		}

		$product_url    = $this->product_image_url( $product );
		$competitor_url = (string) ( $discovered->image_url ?? $discovered->competitor_image_url ?? '' );

		if ( '' === $product_url || '' === $competitor_url ) {
			return $this->empty_result( 'missing_image' );
		}

		$product_embedding = $this->embedding_for_object( $product, $product_url, 'product' );
		$competitor_embedding = $this->embedding_for_object( $discovered, $competitor_url, 'competitor' );

		if ( empty( $product_embedding ) || empty( $competitor_embedding ) ) {
			return $this->empty_result( 'missing_embedding' );
		}

		$similarity = $this->cosine_similarity( $product_embedding, $competitor_embedding );
		if ( null === $similarity ) {
			return $this->empty_result( 'invalid_embedding' );
		}

		if ( $similarity >= 0.92 ) {
			return array(
				'score'      => 12,
				'evidence'   => array( sprintf( 'Product images are visually very similar (%.0f%%).' , $similarity * 100 ) ),
				'warnings'   => array(),
				'similarity' => round( $similarity, 4 ),
				'status'     => 'strong_match',
			);
		}

		if ( $similarity >= 0.84 ) {
			return array(
				'score'      => 6,
				'evidence'   => array( sprintf( 'Product images appear visually similar (%.0f%%).' , $similarity * 100 ) ),
				'warnings'   => array(),
				'similarity' => round( $similarity, 4 ),
				'status'     => 'possible_match',
			);
		}

		if ( $similarity <= 0.65 ) {
			return array(
				'score'      => -22,
				'evidence'   => array(),
				'warnings'   => array( sprintf( 'Visual image mismatch: product images look different (%.0f%% similarity).' , $similarity * 100 ) ),
				'similarity' => round( $similarity, 4 ),
				'status'     => 'mismatch',
			);
		}

		return array(
			'score'      => 0,
			'evidence'   => array( sprintf( 'Product image similarity is inconclusive (%.0f%%).' , $similarity * 100 ) ),
			'warnings'   => array( 'Visual image match is uncertain.' ),
			'similarity' => round( $similarity, 4 ),
			'status'     => 'uncertain',
		);
	}

	private function enabled(): bool {
		return null === $this->settings || ! empty( $this->settings->get( 'discovery_visual_matching_enabled' ) );
	}

	/**
	 * @return array<int,float>|null
	 */
	private function embedding_for_object( object $object, string $url, string $role ): ?array {
		foreach ( array( 'visual_embedding', 'image_embedding' ) as $field ) {
			if ( isset( $object->{$field} ) ) {
				$embedding = $this->normalize_embedding( $object->{$field} );
				if ( ! empty( $embedding ) ) {
					return $embedding;
				}
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters(
				'lpm_visual_product_embedding',
				null,
				$url,
				array(
					'role'       => $role,
					'product_id' => (int) ( $object->product_id ?? 0 ),
					'title'      => (string) ( $object->title ?? $object->product_name ?? '' ),
				)
			);
			$embedding = $this->normalize_embedding( $filtered );
			if ( ! empty( $embedding ) ) {
				return $embedding;
			}
		}

		if ( ! $this->remote_fetch_enabled() ) {
			return null;
		}

		return $this->embedding_from_image_url( $url );
	}

	private function remote_fetch_enabled(): bool {
		return null !== $this->settings && ! empty( $this->settings->get( 'discovery_visual_remote_image_embeddings_enabled' ) );
	}

	/**
	 * @return array<int,float>|null
	 */
	private function embedding_from_image_url( string $url ): ?array {
		$url = $this->safe_image_url( $url );
		if ( '' === $url ) {
			return null;
		}

		$cache_key = 'lpm_visual_embedding_' . md5( $url );
		if ( array_key_exists( $cache_key, $this->memory_cache ) ) {
			return $this->memory_cache[ $cache_key ];
		}

		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $cache_key );
			$embedding = $this->normalize_embedding( $cached );
			if ( ! empty( $embedding ) ) {
				$this->memory_cache[ $cache_key ] = $embedding;
				return $embedding;
			}
		}

		if ( ! function_exists( 'wp_remote_get' ) || ! function_exists( 'imagecreatefromstring' ) ) {
			$this->memory_cache[ $cache_key ] = null;
			return null;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 6,
				'redirection'         => 2,
				'limit_response_size' => 1000000,
				'user-agent'          => 'LilleprinsenPriceMonitor/visual-match',
			)
		);

		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
			$this->memory_cache[ $cache_key ] = null;
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > 1000000 ) {
			$this->memory_cache[ $cache_key ] = null;
			return null;
		}

		$embedding = $this->embedding_from_image_bytes( $body );
		$this->memory_cache[ $cache_key ] = $embedding;

		if ( ! empty( $embedding ) && function_exists( 'set_transient' ) ) {
			set_transient( $cache_key, $embedding, defined( 'WEEK_IN_SECONDS' ) ? WEEK_IN_SECONDS : 7 * DAY_IN_SECONDS );
		}

		return $embedding;
	}

	/**
	 * @return array<int,float>|null
	 */
	private function embedding_from_image_bytes( string $bytes ): ?array {
		$image = @imagecreatefromstring( $bytes );
		if ( ! $image ) {
			return null;
		}

		$width = imagesx( $image );
		$height = imagesy( $image );
		if ( $width <= 0 || $height <= 0 ) {
			imagedestroy( $image );
			return null;
		}

		$thumb = imagecreatetruecolor( 8, 8 );
		if ( ! $thumb ) {
			imagedestroy( $image );
			return null;
		}

		imagecopyresampled( $thumb, $image, 0, 0, 0, 0, 8, 8, $width, $height );
		$embedding = array();
		for ( $y = 0; $y < 8; $y++ ) {
			for ( $x = 0; $x < 8; $x++ ) {
				$rgb = imagecolorat( $thumb, $x, $y );
				$embedding[] = ( ( $rgb >> 16 ) & 0xFF ) / 255;
				$embedding[] = ( ( $rgb >> 8 ) & 0xFF ) / 255;
				$embedding[] = ( $rgb & 0xFF ) / 255;
			}
		}

		imagedestroy( $thumb );
		imagedestroy( $image );

		return $embedding;
	}

	private function product_image_url( object $product ): string {
		foreach ( array( 'product_image_url', 'image_url', 'thumbnail_url' ) as $field ) {
			if ( ! empty( $product->{$field} ) ) {
				return $this->safe_image_url( (string) $product->{$field} );
			}
		}

		$product_id = (int) ( $product->variation_id ?? 0 ) ?: (int) ( $product->product_id ?? 0 );
		if ( $product_id <= 0 || ! function_exists( 'get_post_thumbnail_id' ) || ! function_exists( 'wp_get_attachment_image_url' ) ) {
			return '';
		}

		$image_id = (int) get_post_thumbnail_id( $product_id );
		if ( $image_id <= 0 ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $image_id, 'full' );

		return is_string( $url ) ? $this->safe_image_url( $url ) : '';
	}

	private function safe_image_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	/**
	 * @param mixed $value Embedding candidate.
	 * @return array<int,float>
	 */
	private function normalize_embedding( $value ): array {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value = is_array( $decoded ) ? $decoded : preg_split( '/[\s,]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$embedding = array();
		foreach ( $value as $item ) {
			if ( is_numeric( $item ) ) {
				$embedding[] = (float) $item;
			}
		}

		return count( $embedding ) >= 8 ? array_values( array_slice( $embedding, 0, 2048 ) ) : array();
	}

	/**
	 * @param array<int,float> $one First embedding.
	 * @param array<int,float> $two Second embedding.
	 */
	private function cosine_similarity( array $one, array $two ): ?float {
		$length = min( count( $one ), count( $two ) );
		if ( $length <= 0 ) {
			return null;
		}

		$dot = 0.0;
		$norm_one = 0.0;
		$norm_two = 0.0;
		for ( $index = 0; $index < $length; $index++ ) {
			$a = (float) $one[ $index ];
			$b = (float) $two[ $index ];
			$dot += $a * $b;
			$norm_one += $a * $a;
			$norm_two += $b * $b;
		}

		if ( $norm_one <= 0 || $norm_two <= 0 ) {
			return null;
		}

		return max( 0.0, min( 1.0, $dot / ( sqrt( $norm_one ) * sqrt( $norm_two ) ) ) );
	}

	/**
	 * @return array{score:int,evidence:array<int,string>,warnings:array<int,string>,similarity:?float,status:string}
	 */
	private function empty_result( string $status ): array {
		return array(
			'score'      => 0,
			'evidence'   => array(),
			'warnings'   => array(),
			'similarity' => null,
			'status'     => $status,
		);
	}
}
