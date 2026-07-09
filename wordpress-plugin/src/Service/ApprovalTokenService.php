<?php
/**
 * Creates and validates dry-run approval tokens.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApprovalTokenService {
	public const ACTION_APPROVE_DRY_RUN = 'approve_dry_run';
	public const ACTION_REJECT          = 'reject';
	public const ACTION_MATCH_PRICE     = 'match_price';
	public const ACTION_MATCH_PRICE_MINUS_1 = 'match_price_minus_1';

	/**
	 * Repository-like storage. Kept untyped so local CLI tests can use a tiny fake.
	 *
	 * @var object
	 */
	private $repository;

	/**
	 * @param object $repository Repository-like storage.
	 */
	public function __construct( $repository ) {
		$this->repository = $repository;
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return array<string, mixed>
	 */
	public function create_token( int $suggestion_id, string $action, array $settings ): array {
		$action = $this->sanitize_action( $action );

		if ( $suggestion_id <= 0 || '' === $action ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid token request.', 'lilleprinsen-price-monitor' ),
			);
		}

		if ( ! $this->settings_allow_action( $action, $settings ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Token links are disabled.', 'lilleprinsen-price-monitor' ),
			);
		}

		$hours_key  = $this->is_whatsapp_action( $action ) ? 'whatsapp_action_link_expiry_hours' : 'token_link_expiry_hours';
		$hours      = min( 168, max( 1, absint( $settings[ $hours_key ] ?? 24 ) ) );
		$expires_at = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $hours * HOUR_IN_SECONDS ) );
		$token      = $this->generate_token();
		$token_hash = $this->hash_token( $token );

		$token_id = (int) $this->repository->create_approval_token(
			array(
				'suggestion_id' => $suggestion_id,
				'action'        => $action,
				'token_hash'    => $token_hash,
				'expires_at'    => $expires_at,
				'created_at'    => current_time( 'mysql' ),
			)
		);

		if ( $token_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => __( 'Token could not be stored.', 'lilleprinsen-price-monitor' ),
			);
		}

		$this->repository->write_log(
			'info',
			$this->is_whatsapp_action( $action ) ? 'notification_action_link_created' : 'token_created',
			__( 'One-time suggestion token created.', 'lilleprinsen-price-monitor' ),
			array(
				'suggestion_id' => $suggestion_id,
				'action'        => $action,
				'expires_at'    => $expires_at,
			)
		);

		return array(
			'success'    => true,
			'token_id'   => $token_id,
			'token'      => $token,
			'action'     => $action,
			'url'        => $this->build_token_url( $token, $action ),
			'expires_at' => $expires_at,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function validate_token( string $token, string $action ): array {
		$token  = sanitize_text_field( $token );
		$action = $this->sanitize_action( $action );

		if ( '' === $token || '' === $action ) {
			$this->log_validation_failure( 'token_invalid', __( 'Dry-run token validation failed.', 'lilleprinsen-price-monitor' ), array( 'action' => $action ) );

			return $this->validation_error( 'invalid', __( 'This token link is invalid.', 'lilleprinsen-price-monitor' ) );
		}

		$row = $this->repository->get_approval_token_by_hash( $this->hash_token( $token ) );

		if ( ! is_array( $row ) ) {
			$this->log_validation_failure( 'token_invalid', __( 'Dry-run token was not found.', 'lilleprinsen-price-monitor' ), array( 'action' => $action ) );

			return $this->validation_error( 'invalid', __( 'This token link is invalid.', 'lilleprinsen-price-monitor' ) );
		}

		if ( $action !== (string) ( $row['action'] ?? '' ) ) {
			$this->log_validation_failure( 'token_invalid', __( 'Dry-run token action did not match.', 'lilleprinsen-price-monitor' ), array( 'token_id' => absint( $row['id'] ?? 0 ), 'action' => $action ) );

			return $this->validation_error( 'wrong_action', __( 'This token link cannot be used for that action.', 'lilleprinsen-price-monitor' ) );
		}

		if ( ! empty( $row['used_at'] ) ) {
			$this->log_validation_failure( 'token_reuse_attempt', __( 'A used dry-run token was submitted again.', 'lilleprinsen-price-monitor' ), array( 'token_id' => absint( $row['id'] ?? 0 ), 'action' => $action ) );

			return $this->validation_error( 'used', __( 'This token link has already been used.', 'lilleprinsen-price-monitor' ) );
		}

		$expires_at = strtotime( (string) ( $row['expires_at'] ?? '' ) );

		if ( false === $expires_at || $expires_at < current_time( 'timestamp' ) ) {
			$this->log_validation_failure( 'token_expired', __( 'An expired dry-run token was submitted.', 'lilleprinsen-price-monitor' ), array( 'token_id' => absint( $row['id'] ?? 0 ), 'action' => $action ) );

			return $this->validation_error( 'expired', __( 'This token link has expired.', 'lilleprinsen-price-monitor' ) );
		}

		return array(
			'success'       => true,
			'token_id'      => absint( $row['id'] ?? 0 ),
			'suggestion_id' => absint( $row['suggestion_id'] ?? 0 ),
			'action'        => $action,
			'row'           => $row,
		);
	}

	public function mark_used( int $token_id ): bool {
		return (bool) $this->repository->mark_approval_token_used( $token_id, $this->get_request_ip(), $this->get_request_user_agent() );
	}

	public function build_token_url( string $token, string $action ): string {
		return add_query_arg(
			array(
				'action'           => 'lpm_token_action',
				'lpm_token'        => $token,
				'lpm_token_action' => $this->sanitize_action( $action ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public function cleanup_expired_tokens(): int {
		return (int) $this->repository->delete_old_approval_tokens( current_time( 'mysql' ) );
	}

	public function is_allowed_action( string $action ): bool {
		return '' !== $this->sanitize_action( $action );
	}

	private function generate_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	private function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	private function sanitize_action( string $action ): string {
		$action = sanitize_key( $action );

		return in_array( $action, array( self::ACTION_APPROVE_DRY_RUN, self::ACTION_REJECT, self::ACTION_MATCH_PRICE, self::ACTION_MATCH_PRICE_MINUS_1 ), true ) ? $action : '';
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	private function settings_allow_action( string $action, array $settings ): bool {
		if ( self::ACTION_APPROVE_DRY_RUN === $action ) {
			return ! empty( $settings['allow_token_dry_run_approval_links'] );
		}

		if ( self::ACTION_REJECT === $action ) {
			return ! empty( $settings['allow_token_dry_run_approval_links'] )
				|| ( ! empty( $settings['whatsapp_action_links_enabled'] ) && ! empty( $settings['allow_token_reject'] ) )
				|| ( ! empty( $settings['ntfy_notifications_enabled'] ) && ! empty( $settings['allow_token_reject'] ) );
		}

		if ( self::ACTION_MATCH_PRICE === $action ) {
			return ( ! empty( $settings['whatsapp_action_links_enabled'] ) || ! empty( $settings['ntfy_notifications_enabled'] ) ) && ! empty( $settings['allow_token_match_price_dry_run'] );
		}

		if ( self::ACTION_MATCH_PRICE_MINUS_1 === $action ) {
			return ( ! empty( $settings['whatsapp_action_links_enabled'] ) || ! empty( $settings['ntfy_notifications_enabled'] ) ) && ! empty( $settings['allow_token_match_price_minus_1_dry_run'] );
		}

		return false;
	}

	private function is_whatsapp_action( string $action ): bool {
		return in_array( $action, array( self::ACTION_MATCH_PRICE, self::ACTION_MATCH_PRICE_MINUS_1, self::ACTION_REJECT ), true );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function validation_error( string $code, string $message ): array {
		return array(
			'success' => false,
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * @param array<string, mixed> $context Log context.
	 */
	private function log_validation_failure( string $event, string $message, array $context ): void {
		$this->repository->write_log( 'warning', $event, $message, $context );
	}

	private function get_request_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '';

		return substr( $ip, 0, 100 );
	}

	private function get_request_user_agent(): string {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';

		return substr( $user_agent, 0, 255 );
	}
}
