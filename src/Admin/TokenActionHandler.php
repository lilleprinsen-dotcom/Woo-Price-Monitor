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
		} elseif ( ApprovalTokenService::ACTION_MATCH_PRICE === $action || ApprovalTokenService::ACTION_MATCH_PRICE_MINUS_1 === $action ) {
			if ( 'pending' !== $status ) {
				$this->token_service->mark_used( $token_id );
				return $this->blocked_response( $suggestion_id, $action, __( 'Only pending suggestions can use match-price token actions.', 'lilleprinsen-price-monitor' ) );
			}

			$new_price = (float) ( $suggestion['competitor_price'] ?? 0 );

			if ( ApprovalTokenService::ACTION_MATCH_PRICE_MINUS_1 === $action ) {
				$new_price -= 1;
			}

			if ( $new_price <= 0 ) {
				$this->token_service->mark_used( $token_id );
				return $this->blocked_response( $suggestion_id, $action, __( 'The requested token action would create an invalid suggested price.', 'lilleprinsen-price-monitor' ) );
			}

			$validation_result = $this->validate_match_price_action( $suggestion, $new_price );

			if ( empty( $validation_result['success'] ) ) {
				$this->token_service->mark_used( $token_id );
				return $this->blocked_response( $suggestion_id, $action, (string) $validation_result['message'] );
			}

			$price_updated = $this->repository->update_suggested_price( $suggestion_id, round( $new_price, 4 ) );
			$updated       = $price_updated && $this->repository->approve_suggestion_dry_run( $suggestion_id, 0 );
			$title         = ApprovalTokenService::ACTION_MATCH_PRICE_MINUS_1 === $action ? __( 'Match price -1 kr recorded', 'lilleprinsen-price-monitor' ) : __( 'Match price recorded', 'lilleprinsen-price-monitor' );
			$message       = __( 'Dry-run match action recorded. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' );
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
		$this->repository->write_log( 'info', $this->get_success_log_event( $action ), __( 'Dry-run token action completed.', 'lilleprinsen-price-monitor' ), array( 'suggestion_id' => $suggestion_id, 'action' => $action ) );

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
		$this->repository->write_log( 'warning', str_starts_with( $action, 'match_price' ) ? 'notification_action_blocked' : 'token_action_blocked', $message, array( 'suggestion_id' => $suggestion_id, 'action' => $action ) );

		return array(
			'title'       => __( 'Token action blocked', 'lilleprinsen-price-monitor' ),
			'message'     => $message . ' ' . __( 'WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ),
			'status_code' => 403,
		);
	}

	/**
	 * Token match actions are dry-run only, but they still need conservative
	 * price safety checks before changing the stored suggestion price.
	 *
	 * @param array<string, mixed> $suggestion Suggestion row.
	 * @return array{success: bool, message: string}
	 */
	private function validate_match_price_action( array $suggestion, float $new_price ): array {
		if ( $new_price <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'The requested token action would create an invalid suggested price.', 'lilleprinsen-price-monitor' ),
			);
		}

		$current_price = (float) ( $suggestion['current_price'] ?? 0 );
		$settings      = $this->settings->get_all();

		if ( $current_price > 0 ) {
			$drop_percent = (float) ( $settings['max_allowed_price_drop_percent'] ?? 0 );

			if ( $new_price < $current_price && $drop_percent > 0 ) {
				$actual_drop = ( ( $current_price - $new_price ) / $current_price ) * 100;

				if ( $actual_drop > $drop_percent ) {
					return array(
						'success' => false,
						'message' => sprintf(
							/* translators: 1: actual drop percent, 2: allowed drop percent. */
							__( 'The requested token action is blocked because the price drop is %.2f%%, above the configured %.2f%% limit.', 'lilleprinsen-price-monitor' ),
							$actual_drop,
							$drop_percent
						),
					);
				}
			}

			$increase_percent = (float) ( $settings['max_allowed_price_increase_percent'] ?? 0 );

			if ( $new_price > $current_price && $increase_percent > 0 ) {
				$actual_increase = ( ( $new_price - $current_price ) / $current_price ) * 100;

				if ( $actual_increase > $increase_percent ) {
					return array(
						'success' => false,
						'message' => sprintf(
							/* translators: 1: actual increase percent, 2: allowed increase percent. */
							__( 'The requested token action is blocked because the price increase is %.2f%%, above the configured %.2f%% limit.', 'lilleprinsen-price-monitor' ),
							$actual_increase,
							$increase_percent
						),
					);
				}
			}
		}

		$monitored_product = $this->get_monitored_product_for_suggestion( $suggestion );
		$min_price         = $this->extract_positive_decimal( $monitored_product['min_price'] ?? null );

		if ( null !== $min_price && $new_price < $min_price ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: requested price, 2: minimum price. */
					__( 'The requested token action is blocked because %.2f is below the monitored product minimum price %.2f.', 'lilleprinsen-price-monitor' ),
					$new_price,
					$min_price
				),
			);
		}

		if ( ! empty( $suggestion['applies_to_group'] ) ) {
			$group_result = $this->validate_group_match_price_action( $suggestion, $new_price );

			if ( empty( $group_result['success'] ) ) {
				return $group_result;
			}
		}

		return array(
			'success' => true,
			'message' => '',
		);
	}

	/**
	 * @param array<string, mixed> $suggestion Suggestion row.
	 * @return array<string, mixed>
	 */
	private function get_monitored_product_for_suggestion( array $suggestion ): array {
		if ( ! method_exists( $this->repository, 'get_monitored_product' ) ) {
			return array();
		}

		$monitored_product = $this->repository->get_monitored_product( absint( $suggestion['monitored_product_id'] ?? 0 ) );

		return is_array( $monitored_product ) ? $monitored_product : array();
	}

	/**
	 * @param array<string, mixed> $suggestion Suggestion row.
	 * @return array{success: bool, message: string}
	 */
	private function validate_group_match_price_action( array $suggestion, float $new_price ): array {
		if ( ! method_exists( $this->repository, 'get_product_group_members' ) ) {
			return array(
				'success' => false,
				'message' => __( 'The requested group token action is blocked because group members could not be validated.', 'lilleprinsen-price-monitor' ),
			);
		}

		$group_id = absint( $suggestion['group_id'] ?? 0 );
		$members  = $group_id > 0 ? $this->repository->get_product_group_members( $group_id, true ) : array();

		if ( empty( $members ) ) {
			return array(
				'success' => false,
				'message' => __( 'The requested group token action is blocked because no enabled group members were found.', 'lilleprinsen-price-monitor' ),
			);
		}

		foreach ( $members as $member ) {
			if ( ! is_array( $member ) ) {
				continue;
			}

			$member_min_price = $this->extract_positive_decimal( $member['min_price'] ?? null );

			if ( null !== $member_min_price && $new_price < $member_min_price ) {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: 1: product ID, 2: requested price, 3: minimum price. */
						__( 'The requested group token action is blocked because product %1$d would receive %2$.2f, below its minimum price %3$.2f.', 'lilleprinsen-price-monitor' ),
						absint( $member['product_id'] ?? 0 ),
						$new_price,
						$member_min_price
					),
				);
			}
		}

		// TODO: Extend token dry-run validation with full group cost/margin checks once those rules are centralized for all group members.
		return array(
			'success' => true,
			'message' => '',
		);
	}

	/**
	 * @param mixed $value Raw decimal value.
	 */
	private function extract_positive_decimal( $value ): ?float {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = is_scalar( $value ) ? (float) $value : 0.0;

		return $value > 0 ? $value : null;
	}

	private function get_success_log_event( string $action ): string {
		if ( ApprovalTokenService::ACTION_MATCH_PRICE === $action ) {
			return 'notification_action_match_price_used';
		}

		if ( ApprovalTokenService::ACTION_MATCH_PRICE_MINUS_1 === $action ) {
			return 'notification_action_match_price_minus_1_used';
		}

		if ( ApprovalTokenService::ACTION_REJECT === $action ) {
			return 'notification_action_reject_used';
		}

		return 'token_used';
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
