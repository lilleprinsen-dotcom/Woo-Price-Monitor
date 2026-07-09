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

		$token_links = $this->build_token_links( $context, $settings );
		$review_url  = $this->get_review_url( $event, $context );
		$summary     = $this->build_summary( $event, $context, $product, $review_url, $token_links );
		$message     = $this->build_message_text( $event, $fallback_message, $context, $product, $summary, $token_links );

		return array_merge(
			array(
			'event'             => sanitize_key( $event ),
			'site_url'          => home_url(),
			'plugin_version'    => defined( 'LPM_VERSION' ) ? LPM_VERSION : '',
			'product_id'        => $product_id > 0 ? $product_id : null,
			'product_name'      => $summary['product']['name'],
			'sku'               => $this->get_product_sku( $product, $context ),
			'suggestion_id'     => $this->nullable_int( $context['suggestion_id'] ?? null ),
			'suggestion_type'   => $this->nullable_text( $context['suggestion_type'] ?? null ),
			'current_price'     => $this->nullable_float( $context['current_price'] ?? null ),
			'old_price'         => $this->nullable_float( $context['current_price'] ?? null ),
			'competitor_price'  => $this->nullable_float( $context['competitor_price'] ?? null ),
			'suggested_price'   => $this->nullable_float( $context['suggested_price'] ?? null ),
			'new_price'         => $this->nullable_float( $context['suggested_price'] ?? null ),
			'difference'        => $this->nullable_float( $context['difference'] ?? null ),
			'status'            => $this->nullable_text( $context['status'] ?? null ),
			'reason'            => $this->nullable_text( $context['reason'] ?? ( $context['message'] ?? null ) ),
			'why_it_matters'    => $summary['why_it_matters'],
			'market_context'    => $summary['market_context'],
			'approval_url'      => $review_url,
			'review_url'        => $review_url,
			'competitor_url'    => isset( $context['competitor_url'] ) ? esc_url_raw( (string) $context['competitor_url'] ) : null,
			'created_at'        => $this->nullable_text( $context['created_at'] ?? null ),
			'message_text'      => $message,
			'telegram_text'     => $message,
			'summary'           => $summary,
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
	public function build_message_text( string $event, string $fallback_message, array $context, ?object $product = null, array $summary = array(), array $token_links = array() ): string {
		$product_name = (string) ( $summary['product']['name'] ?? $this->product_name_from_context( $product, $context ) );

		if ( '' === $product_name && ! empty( $context['product_id'] ) ) {
			$product_name = sprintf(
				/* translators: %d: product ID. */
				__( 'Product #%d', 'lilleprinsen-price-monitor' ),
				absint( $context['product_id'] )
			);
		}

		if ( str_starts_with( $event, 'price_suggestion_' ) ) {
			$currency = (string) ( $context['currency'] ?? ( $context['last_currency'] ?? 'NOK' ) );
			$lines = array(
				'Prisvarsel',
				'Produkt: ' . ( '' !== $product_name ? $product_name : __( 'Unknown product', 'lilleprinsen-price-monitor' ) ),
				'Konkurrent: ' . ( (string) ( $summary['competitor']['name'] ?? '' ) ?: __( 'unknown competitor', 'lilleprinsen-price-monitor' ) ),
				'Gammel pris: ' . $this->format_price( $context['current_price'] ?? null, $currency ),
				'Ny anbefalt pris: ' . $this->format_price( $context['suggested_price'] ?? null, $currency ),
				'Konkurrentpris: ' . $this->format_price( $context['competitor_price'] ?? null, $currency ),
				'Marked: ' . ( (string) ( $summary['market_context'] ?? '' ) ?: __( 'No market context available.', 'lilleprinsen-price-monitor' ) ),
				'Hvorfor: ' . ( (string) ( $summary['why_it_matters'] ?? '' ) ?: $this->nullable_text( $context['reason'] ?? '' ) ),
			);

			$link_lines = $this->action_link_lines( $summary, $token_links );
			if ( ! empty( $link_lines ) ) {
				$lines[] = '';
				$lines = array_merge( $lines, $link_lines );
			}

			return trim( implode( "\n", array_filter( $lines, static fn( string $line ): bool => '' !== trim( $line ) || '' === $line ) ) );
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
	 * @param array<string, mixed> $token_links Token/action links.
	 * @return array<string, mixed>
	 */
	private function build_summary( string $event, array $context, ?object $product, string $review_url, array $token_links ): array {
		$currency = strtoupper( sanitize_text_field( (string) ( $context['currency'] ?? ( $context['last_currency'] ?? 'NOK' ) ) ) );
		$currency = '' !== $currency ? $currency : 'NOK';
		$current  = $this->nullable_float( $context['current_price'] ?? null );
		$competitor_price = $this->nullable_float( $context['competitor_price'] ?? null );
		$suggested = $this->nullable_float( $context['suggested_price'] ?? null );

		$links = array(
			'review' => $review_url,
		);
		foreach ( array(
			'approve_dry_run' => 'dry_run_approve_url',
			'match_price'     => 'action_match_price_url',
			'match_price_minus_1' => 'action_match_price_minus_1_url',
			'reject'          => 'reject_url',
		) as $label => $key ) {
			if ( ! empty( $token_links[ $key ] ) ) {
				$links[ $label ] = esc_url_raw( (string) $token_links[ $key ] );
			}
		}

		return array(
			'event'          => sanitize_key( $event ),
			'product'        => array(
				'id'   => $this->nullable_int( $context['product_id'] ?? null ),
				'name' => $this->product_name_from_context( $product, $context ),
				'sku'  => $this->get_product_sku( $product, $context ),
			),
			'competitor'     => array(
				'name'  => $this->nullable_text( $context['competitor_name'] ?? null ),
				'url'   => isset( $context['competitor_url'] ) ? esc_url_raw( (string) $context['competitor_url'] ) : null,
				'price' => $competitor_price,
			),
			'prices'         => array(
				'currency'         => $currency,
				'old_price'        => $current,
				'competitor_price' => $competitor_price,
				'new_price'        => $suggested,
			),
			'market_context' => $this->market_context_text( $current, $competitor_price, $suggested, $currency, $context ),
			'why_it_matters' => $this->why_it_matters_text( $event, $current, $competitor_price, $suggested, $currency, $context ),
			'links'          => $links,
			'approval_note'  => __( 'Matches and price actions require approval. Real WooCommerce price updates stay disabled unless explicitly enabled in settings.', 'lilleprinsen-price-monitor' ),
		);
	}

	/**
	 * @param array<string, mixed> $summary Summary payload.
	 * @param array<string, mixed> $token_links Token/action links.
	 * @return array<int,string>
	 */
	private function action_link_lines( array $summary, array $token_links ): array {
		$links = (array) ( $summary['links'] ?? array() );
		$lines = array();

		if ( ! empty( $links['review'] ) ) {
			$lines[] = 'Review: ' . $links['review'];
		}
		if ( ! empty( $links['approve_dry_run'] ) ) {
			$lines[] = 'Godkjenn dry-run: ' . $links['approve_dry_run'];
		}
		if ( ! empty( $links['match_price'] ) ) {
			$lines[] = 'Match pris dry-run: ' . $links['match_price'];
		}
		if ( ! empty( $links['match_price_minus_1'] ) ) {
			$lines[] = 'Match pris -1 dry-run: ' . $links['match_price_minus_1'];
		}
		if ( ! empty( $links['reject'] ) ) {
			$lines[] = 'Avvis: ' . $links['reject'];
		}
		if ( ! empty( $token_links['action_warning_text'] ) ) {
			$lines[] = (string) $token_links['action_warning_text'];
		}

		return $lines;
	}

	/**
	 * @param mixed $current Current price.
	 * @param mixed $competitor_price Competitor price.
	 * @param mixed $suggested Suggested price.
	 */
	private function market_context_text( $current, $competitor_price, $suggested, string $currency, array $context ): string {
		if ( isset( $context['market_context'] ) && '' !== trim( (string) $context['market_context'] ) ) {
			return sanitize_text_field( (string) $context['market_context'] );
		}
		if ( null === $current || null === $competitor_price ) {
			return __( 'Market context is incomplete because one or more prices are missing.', 'lilleprinsen-price-monitor' );
		}
		if ( $competitor_price < $current ) {
			return sprintf(
				/* translators: 1: formatted amount, 2: formatted competitor price. */
				__( 'A competitor is below your current price by %1$s. Observed competitor price: %2$s.', 'lilleprinsen-price-monitor' ),
				$this->format_price( $current - $competitor_price, $currency ),
				$this->format_price( $competitor_price, $currency )
			);
		}
		if ( $competitor_price > $current ) {
			return sprintf(
				/* translators: 1: formatted competitor price. */
				__( 'Competitor is above your current price at %1$s. This is mainly useful for recovery/manual review.', 'lilleprinsen-price-monitor' ),
				$this->format_price( $competitor_price, $currency )
			);
		}

		return __( 'Competitor price matches your current price. No urgent price-down action is needed.', 'lilleprinsen-price-monitor' );
	}

	/**
	 * @param mixed $current Current price.
	 * @param mixed $competitor_price Competitor price.
	 * @param mixed $suggested Suggested price.
	 */
	private function why_it_matters_text( string $event, $current, $competitor_price, $suggested, string $currency, array $context ): string {
		if ( isset( $context['why_it_matters'] ) && '' !== trim( (string) $context['why_it_matters'] ) ) {
			return sanitize_text_field( (string) $context['why_it_matters'] );
		}
		$reason = $this->nullable_text( $context['reason'] ?? ( $context['message'] ?? null ) );
		if ( 'price_suggestion_blocked' === $event || 'blocked' === (string) ( $context['status'] ?? '' ) ) {
			return $reason ?: __( 'The plugin blocked automatic action and needs admin review before anything changes.', 'lilleprinsen-price-monitor' );
		}
		if ( null !== $current && null !== $competitor_price && $competitor_price < $current ) {
			return sprintf(
				/* translators: 1: formatted suggested price. */
				__( 'This may need attention because the market price is lower than yours. Suggested review price: %1$s.', 'lilleprinsen-price-monitor' ),
				$this->format_price( $suggested, $currency )
			);
		}
		if ( null !== $current && null !== $competitor_price && $competitor_price >= $current ) {
			return __( 'No price-down pressure detected; review only if this is part of a recovery or strategy check.', 'lilleprinsen-price-monitor' );
		}

		return $reason ?: __( 'Review the suggestion before taking action.', 'lilleprinsen-price-monitor' );
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

		$action_links_enabled = ! empty( $settings['whatsapp_action_links_enabled'] ) || ! empty( $settings['ntfy_notifications_enabled'] );

		if ( 'pending' === $status && $action_links_enabled ) {
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

		$reject = ( ! empty( $settings['allow_token_dry_run_approval_links'] ) || $action_links_enabled )
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
	private function product_name_from_context( ?object $product, array $context ): string {
		$name = $this->get_product_name( $product );
		if ( '' !== $name ) {
			return $name;
		}

		foreach ( array( 'product_name', 'name', 'title' ) as $key ) {
			if ( ! empty( $context[ $key ] ) ) {
				$name = sanitize_text_field( (string) $context[ $key ] );
				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		return '';
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
