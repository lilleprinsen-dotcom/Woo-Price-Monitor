<?php
/**
 * Builds webhook notification payloads and human-readable messages.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Notifications;

use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Service\ApprovalTokenService;
use Lilleprinsen\PriceMonitor\Service\ReviewLinkService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NotificationMessageBuilder {
	private Repository $repository;

	private ReviewLinkService $review_links;

	private ?ApprovalTokenService $approval_tokens;

	public function __construct( Repository $repository, ?ReviewLinkService $review_links = null, ?ApprovalTokenService $approval_tokens = null ) {
		$this->repository      = $repository;
		$this->review_links    = $review_links ?? new ReviewLinkService();
		$this->approval_tokens = $approval_tokens;
	}

	/**
	 * @param array<string, mixed> $context Notification context.
	 * @return array<string, mixed>
	 */
	public function build_payload( string $event, string $fallback_message, array $context, ?int $product_id = null, array $settings = array() ): array {
		$context = $this->hydrate_context( $context );
		$product = $this->get_product( $product_id ?? absint( $context['product_id'] ?? 0 ) );
		$product_id = $product_id ?? absint( $context['product_id'] ?? 0 );

		if ( $product && $product_id <= 0 && method_exists( $product, 'get_id' ) ) {
			$product_id = (int) $product->get_id();
		}

		$review_url = $this->get_review_url( $event, $context );
		$message    = $this->build_message_text( $event, $fallback_message, $context, $product );
		$token_links = $this->build_token_links( $context, $settings );

		return array_merge(
			array(
			'event'             => sanitize_key( $event ),
			'site_url'          => home_url(),
			'plugin_version'    => defined( 'LPM_VERSION' ) ? LPM_VERSION : '',
			'product_id'        => $product_id > 0 ? $product_id : null,
			'product_name'      => $this->get_product_name( $product ),
			'sku'               => $this->get_product_sku( $product, $context ),
			'suggestion_id'     => $this->nullable_int( $context['suggestion_id'] ?? null ),
			'suggestion_type'   => $this->nullable_text( $context['suggestion_type'] ?? null ),
			'current_price'     => $this->nullable_float( $context['current_price'] ?? null ),
			'competitor_price'  => $this->nullable_float( $context['competitor_price'] ?? null ),
			'suggested_price'   => $this->nullable_float( $context['suggested_price'] ?? null ),
			'difference'        => $this->nullable_float( $context['difference'] ?? null ),
			'status'            => $this->nullable_text( $context['status'] ?? null ),
			'reason'            => $this->nullable_text( $context['reason'] ?? ( $context['message'] ?? null ) ),
			'approval_url'      => $review_url,
			'review_url'        => $review_url,
			'competitor_url'    => isset( $context['competitor_url'] ) ? esc_url_raw( (string) $context['competitor_url'] ) : null,
			'created_at'        => $this->nullable_text( $context['created_at'] ?? null ),
			'message_text'      => $message,
			'context'           => $this->sanitize_context( $context ),
			),
			$token_links
		);
	}

	/**
	 * @param array<string, mixed> $context Notification context.
	 * @return array<string, mixed>
	 */
	public function hydrate_context( array $context ): array {
		$suggestion_id = absint( $context['suggestion_id'] ?? 0 );

		if ( $suggestion_id <= 0 ) {
			return $context;
		}

		$suggestion = $this->repository->get_price_suggestion( $suggestion_id );

		if ( ! $suggestion ) {
			return $context;
		}

		return array_merge( $suggestion, $context );
	}

	/**
	 * @param array<string, mixed> $context Notification context.
	 */
	public function build_message_text( string $event, string $fallback_message, array $context, ?object $product = null ): string {
		$product_name = $this->get_product_name( $product );

		if ( '' === $product_name && ! empty( $context['product_id'] ) ) {
			$product_name = sprintf(
				/* translators: %d: product ID. */
				__( 'Product #%d', 'lilleprinsen-price-monitor' ),
				absint( $context['product_id'] )
			);
		}

		if ( str_starts_with( $event, 'price_suggestion_' ) ) {
			$currency = (string) ( $context['currency'] ?? ( $context['last_currency'] ?? 'NOK' ) );
			return trim(
				sprintf(
					/* translators: 1: product name, 2: current price, 3: competitor price, 4: suggested price. */
					__( "Prisforslag: %1\$s\nDin pris: %2\$s\nKonkurrent: %3\$s\nForslag: %4\$s\n\nVelg: Match pris, Match pris -1 kr, Avvis eller review i admin.", 'lilleprinsen-price-monitor' ),
					'' !== $product_name ? $product_name : __( 'Unknown product', 'lilleprinsen-price-monitor' ),
					$this->format_price( $context['current_price'] ?? null, $currency ),
					$this->format_price( $context['competitor_price'] ?? null, $currency ),
					$this->format_price( $context['suggested_price'] ?? null, $currency )
				)
			);
		}

		if ( 'failed_check' === $event ) {
			return trim(
				sprintf(
					/* translators: 1: product name, 2: error. */
					__( 'Competitor price check failed for %1$s. Error: %2$s', 'lilleprinsen-price-monitor' ),
					'' !== $product_name ? $product_name : __( 'unknown product', 'lilleprinsen-price-monitor' ),
					(string) ( $context['error'] ?? $fallback_message )
				)
			);
		}

		return '' !== trim( $fallback_message ) ? trim( $fallback_message ) : __( 'Lilleprinsen Price Monitor notification.', 'lilleprinsen-price-monitor' );
	}

	/**
	 * @param array<string, mixed> $context Notification context.
	 */
	private function get_review_url( string $event, array $context ): string {
		if ( str_starts_with( $event, 'price_suggestion_' ) || ! empty( $context['suggestion_id'] ) ) {
			return $this->review_links->get_suggestion_review_url( $context );
		}

		return add_query_arg(
			array(
				'page' => \Lilleprinsen\PriceMonitor\Admin\AdminPage::SLUG,
				'tab'  => 'logs',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @param array<string, mixed> $context Notification context.
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return array<string, mixed>
	 */
	private function build_token_links( array $context, array $settings ): array {
		if ( ! $this->approval_tokens ) {
			return array();
		}

		$suggestion_id = absint( $context['suggestion_id'] ?? 0 );
		$status        = (string) ( $context['status'] ?? '' );

		if ( $suggestion_id <= 0 || ! in_array( $status, array( 'pending', 'blocked' ), true ) ) {
			return array();
		}

		$links = array();

		if ( 'pending' === $status && ! empty( $settings['allow_token_dry_run_approval_links'] ) ) {
			$approve = $this->approval_tokens->create_token( $suggestion_id, ApprovalTokenService::ACTION_APPROVE_DRY_RUN, $settings );

			if ( ! empty( $approve['success'] ) ) {
				$links['dry_run_approve_url'] = esc_url_raw( (string) $approve['url'] );
				$links['token_expires_at']    = $this->nullable_text( $approve['expires_at'] ?? null );
			}
		}

		if ( 'pending' === $status && ! empty( $settings['whatsapp_action_links_enabled'] ) ) {
			$match = $this->approval_tokens->create_token( $suggestion_id, ApprovalTokenService::ACTION_MATCH_PRICE, $settings );

			if ( ! empty( $match['success'] ) ) {
				$links['action_match_price_url'] = esc_url_raw( (string) $match['url'] );
				$links['action_link_expires_at'] = $this->nullable_text( $match['expires_at'] ?? null );
			}

			$match_minus_1 = $this->approval_tokens->create_token( $suggestion_id, ApprovalTokenService::ACTION_MATCH_PRICE_MINUS_1, $settings );

			if ( ! empty( $match_minus_1['success'] ) ) {
				$links['action_match_price_minus_1_url'] = esc_url_raw( (string) $match_minus_1['url'] );

				if ( empty( $links['action_link_expires_at'] ) ) {
					$links['action_link_expires_at'] = $this->nullable_text( $match_minus_1['expires_at'] ?? null );
				}
			}

			$links['action_warning_text'] = __( 'Token action links record dry-run approve/reject only. Real WooCommerce updates require logged-in admin confirmation.', 'lilleprinsen-price-monitor' );
		}

		$reject = ( ! empty( $settings['allow_token_dry_run_approval_links'] ) || ! empty( $settings['whatsapp_action_links_enabled'] ) )
			? $this->approval_tokens->create_token( $suggestion_id, ApprovalTokenService::ACTION_REJECT, $settings )
			: array();

		if ( ! empty( $reject['success'] ) ) {
			$links['reject_url'] = esc_url_raw( (string) $reject['url'] );
			$links['action_reject_url'] = esc_url_raw( (string) $reject['url'] );

			if ( empty( $links['token_expires_at'] ) ) {
				$links['token_expires_at'] = $this->nullable_text( $reject['expires_at'] ?? null );
			}

			if ( empty( $links['action_link_expires_at'] ) ) {
				$links['action_link_expires_at'] = $this->nullable_text( $reject['expires_at'] ?? null );
			}
		}

		return $links;
	}

	private function get_product( int $product_id ): ?object {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		return is_object( $product ) ? $product : null;
	}

	private function get_product_name( ?object $product ): string {
		return $product && method_exists( $product, 'get_name' ) ? sanitize_text_field( (string) $product->get_name() ) : '';
	}

	/**
	 * @param array<string, mixed> $context Notification context.
	 */
	private function get_product_sku( ?object $product, array $context ): ?string {
		if ( $product && method_exists( $product, 'get_sku' ) ) {
			$sku = sanitize_text_field( (string) $product->get_sku() );

			if ( '' !== $sku ) {
				return $sku;
			}
		}

		return $this->nullable_text( $context['sku'] ?? null );
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private function nullable_int( $value ): ?int {
		$int = absint( $value );

		return $int > 0 ? $int : null;
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private function nullable_float( $value ): ?float {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return round( (float) $value, 4 );
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private function nullable_text( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = sanitize_text_field( (string) $value );

		return '' === $value ? null : $value;
	}

	/**
	 * @param mixed $value Raw price.
	 */
	private function format_price( $value, string $currency ): string {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return __( 'unknown', 'lilleprinsen-price-monitor' );
		}

		$currency = strtoupper( sanitize_text_field( $currency ) );
		$currency = '' !== $currency ? $currency : 'NOK';

		return number_format_i18n( (float) $value, 2 ) . ' ' . $currency;
	}

	/**
	 * @param array<string, mixed> $context Notification context.
	 * @return array<string, mixed>
	 */
	private function sanitize_context( array $context ): array {
		$sanitized = array();

		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$sanitized[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : null;
		}

		return $sanitized;
	}
}
