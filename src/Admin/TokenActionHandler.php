<?php
/**
 * Handles unauthenticated token actions for dry-run approvals only.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

use Lilleprinsen\PriceMonitor\Service\ApprovalTokenService;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TokenActionHandler {
	/**
	 * Repository-like storage. Kept untyped for small local CLI tests.
	 *
	 * @var object
	 */
	private $repository;

	private Settings $settings;

	private ApprovalTokenService $token_service;

	/**
	 * @param object $repository Repository-like storage.
	 */
	public function __construct( $repository, Settings $settings, ApprovalTokenService $token_service ) {
		$this->repository    = $repository;
		$this->settings      = $settings;
		$this->token_service = $token_service;
	}

	public function handle(): void {
		$settings = $this->settings->get_all();

		if ( empty( $settings['allow_token_dry_run_approval_links'] ) ) {
			$this->render_confirmation( __( 'Token links are disabled.', 'lilleprinsen-price-monitor' ), __( 'Use the WordPress admin approval inbox to review this suggestion.', 'lilleprinsen-price-monitor' ), 403 );
		}

		$token  = isset( $_GET['lpm_token'] ) ? sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['lpm_token'] ) ) ) : '';
		$action = isset( $_GET['lpm_token_action'] ) ? sanitize_key( wp_unslash( $_GET['lpm_token_action'] ) ) : '';

		$validation = $this->token_service->validate_token( $token, $action );

		if ( empty( $validation['success'] ) ) {
			$this->render_confirmation( __( 'Token link could not be used.', 'lilleprinsen-price-monitor' ), (string) ( $validation['message'] ?? __( 'This token link is invalid or expired.', 'lilleprinsen-price-monitor' ) ), 403 );
		}

		$result = $this->apply_token_action( $validation );

		$this->render_confirmation( $result['title'], $result['message'], $result['status_code'] );
	}

	/**
	 * @param array<string, mixed> $validation Valid token data.
	 * @return array<string, mixed>
	 */
	public function apply_token_action( array $validation ): array {
		$suggestion_id = absint( $validation['suggestion_id'] ?? 0 );
		$token_id      = absint( $validation['token_id'] ?? 0 );
		$action        = sanitize_key( (string) ( $validation['action'] ?? '' ) );
		$suggestion    = $this->repository->get_price_suggestion( $suggestion_id );

		if ( ! $suggestion ) {
			$this->token_service->mark_used( $token_id );
			$this->repository->write_log( 'warning', 'token_action_blocked', __( 'Token action blocked because the suggestion was not found.', 'lilleprinsen-price-monitor' ), array( 'suggestion_id' => $suggestion_id, 'action' => $action ) );

			return array(
				'title'       => __( 'Suggestion not found', 'lilleprinsen-price-monitor' ),
				'message'     => __( 'This suggestion could not be found. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ),
				'status_code' => 404,
			);
		}

		$status = (string) ( $suggestion['status'] ?? '' );

		if ( ApprovalTokenService::ACTION_APPROVE_DRY_RUN === $action ) {
			if ( 'pending' !== $status ) {
				$this->token_service->mark_used( $token_id );
				return $this->blocked_response( $suggestion_id, $action, __( 'Only pending suggestions can be approved by token.', 'lilleprinsen-price-monitor' ) );
			}

			$updated = $this->repository->approve_suggestion_dry_run( $suggestion_id, 0 );
			$title   = __( 'Dry-run approval recorded', 'lilleprinsen-price-monitor' );
			$message = __( 'Dry-run approval recorded. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' );
		} elseif ( ApprovalTokenService::ACTION_REJECT === $action ) {
			if ( ! in_array( $status, array( 'pending', 'blocked' ), true ) ) {
				$this->token_service->mark_used( $token_id );
				return $this->blocked_response( $suggestion_id, $action, __( 'Only pending or blocked suggestions can be rejected by token.', 'lilleprinsen-price-monitor' ) );
			}

			$updated = $this->repository->reject_suggestion( $suggestion_id, 0 );
			$title   = __( 'Suggestion rejected', 'lilleprinsen-price-monitor' );
			$message = __( 'Suggestion rejected. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' );
		} else {
			$this->token_service->mark_used( $token_id );
			return $this->blocked_response( $suggestion_id, $action, __( 'Unsupported token action.', 'lilleprinsen-price-monitor' ) );
		}

		if ( empty( $updated ) ) {
			$this->repository->write_log( 'warning', 'token_action_blocked', __( 'Token action failed while updating the suggestion status.', 'lilleprinsen-price-monitor' ), array( 'suggestion_id' => $suggestion_id, 'action' => $action ) );

			return array(
				'title'       => __( 'Action could not be recorded', 'lilleprinsen-price-monitor' ),
				'message'     => __( 'The token was valid, but the suggestion could not be updated. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ),
				'status_code' => 500,
			);
		}

		$this->token_service->mark_used( $token_id );
		$this->repository->write_log( 'info', 'token_used', __( 'Dry-run token action completed.', 'lilleprinsen-price-monitor' ), array( 'suggestion_id' => $suggestion_id, 'action' => $action ) );

		return array(
			'title'       => $title,
			'message'     => $message,
			'status_code' => 200,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function blocked_response( int $suggestion_id, string $action, string $message ): array {
		$this->repository->write_log( 'warning', 'token_action_blocked', $message, array( 'suggestion_id' => $suggestion_id, 'action' => $action ) );

		return array(
			'title'       => __( 'Token action blocked', 'lilleprinsen-price-monitor' ),
			'message'     => $message . ' ' . __( 'WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ),
			'status_code' => 403,
		);
	}

	private function render_confirmation( string $title, string $message, int $status_code ): void {
		$html = '<div style="font-family:system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;max-width:720px;margin:48px auto;padding:28px;border:1px solid #dcdcde;border-radius:8px;background:#fff;">'
			. '<h1 style="margin-top:0;font-size:24px;">' . esc_html( $title ) . '</h1>'
			. '<p style="font-size:16px;line-height:1.5;">' . esc_html( $message ) . '</p>'
			. '<p style="color:#646970;">' . esc_html__( 'Real WooCommerce price updates always require logged-in admin confirmation and are never performed by token links.', 'lilleprinsen-price-monitor' ) . '</p>'
			. '</div>';

		wp_die( $html, esc_html( $title ), array( 'response' => $status_code ) );
	}
}
