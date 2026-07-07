<?php
/**
 * Local tests for dry-run approval token behavior.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';

use Lilleprinsen\PriceMonitor\Service\ApprovalTokenService;
use Lilleprinsen\PriceMonitor\Admin\TokenActionHandler;
use Lilleprinsen\PriceMonitor\Settings\Settings;

final class LpmFakeApprovalTokenRepository {
	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $tokens = array();

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $suggestions = array();

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $logs = array();

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $monitored_products = array();

	/**
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	public array $group_members = array();

	public int $next_token_id = 1;

	public int $price_update_calls = 0;

	/**
	 * @param array<string, mixed> $data Token row.
	 */
	public function create_approval_token( array $data ): int {
		$id = $this->next_token_id++;

		$data['id'] = $id;
		$data['used_at'] = $data['used_at'] ?? null;
		$this->tokens[ $id ] = $data;

		return $id;
	}

	public function delete_existing_approval_tokens_for_suggestion_action( int $suggestion_id, string $action ): int {
		$deleted = 0;

		foreach ( $this->tokens as $id => $token ) {
			if ( (int) $token['suggestion_id'] === $suggestion_id && (string) $token['action'] === $action && empty( $token['used_at'] ) ) {
				unset( $this->tokens[ $id ] );
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_approval_token_by_hash( string $token_hash ): ?array {
		foreach ( $this->tokens as $token ) {
			if ( (string) $token['token_hash'] === $token_hash ) {
				return $token;
			}
		}

		return null;
	}

	public function mark_approval_token_used( int $token_id, string $ip = '', string $user_agent = '' ): bool {
		if ( empty( $this->tokens[ $token_id ] ) || ! empty( $this->tokens[ $token_id ]['used_at'] ) ) {
			return false;
		}

		$this->tokens[ $token_id ]['used_at'] = current_time( 'mysql' );
		$this->tokens[ $token_id ]['used_ip'] = $ip;
		$this->tokens[ $token_id ]['used_user_agent'] = $user_agent;

		return true;
	}

	public function delete_old_approval_tokens( string $cutoff ): int {
		$deleted = 0;

		foreach ( $this->tokens as $id => $token ) {
			if ( ( ! empty( $token['used_at'] ) && (string) $token['used_at'] < $cutoff ) || (string) $token['expires_at'] < $cutoff ) {
				unset( $this->tokens[ $id ] );
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_price_suggestion( int $suggestion_id ): ?array {
		return $this->suggestions[ $suggestion_id ] ?? null;
	}

	public function approve_suggestion_dry_run( int $suggestion_id, int $user_id ): bool {
		if ( empty( $this->suggestions[ $suggestion_id ] ) ) {
			return false;
		}

		$this->suggestions[ $suggestion_id ]['status'] = 'approved_dry_run';
		$this->suggestions[ $suggestion_id ]['approved_by'] = $user_id;

		return true;
	}

	public function update_suggested_price( int $suggestion_id, float $suggested_price ): bool {
		if ( empty( $this->suggestions[ $suggestion_id ] ) ) {
			return false;
		}

		$this->suggestions[ $suggestion_id ]['suggested_price'] = $suggested_price;
		$this->suggestions[ $suggestion_id ]['difference'] = round( $suggested_price - (float) ( $this->suggestions[ $suggestion_id ]['current_price'] ?? 0 ), 4 );

		return true;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_monitored_product( int $monitored_product_id ): ?array {
		return $this->monitored_products[ $monitored_product_id ] ?? null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_product_group_members( int $group_id, bool $enabled_only = false ): array {
		$members = $this->group_members[ $group_id ] ?? array();

		if ( ! $enabled_only ) {
			return $members;
		}

		return array_values(
			array_filter(
				$members,
				static fn( array $member ): bool => ! empty( $member['enabled'] )
			)
		);
	}

	public function reject_suggestion( int $suggestion_id, int $user_id ): bool {
		if ( empty( $this->suggestions[ $suggestion_id ] ) ) {
			return false;
		}

		$this->suggestions[ $suggestion_id ]['status'] = 'rejected';
		$this->suggestions[ $suggestion_id ]['rejected_by'] = $user_id;

		return true;
	}

	/**
	 * @param array<string, mixed> $context Log context.
	 */
	public function write_log( string $level, string $event, string $message, array $context = array(), ?int $product_id = null ): int {
		$this->logs[] = compact( 'level', 'event', 'message', 'context', 'product_id' );

		return count( $this->logs );
	}
}

function lpm_token_settings( bool $enabled = true ): array {
	return array(
		'allow_token_dry_run_approval_links' => $enabled ? 1 : 0,
		'token_link_expiry_hours' => 24,
		'whatsapp_action_links_enabled' => 0,
		'whatsapp_action_link_expiry_hours' => 24,
		'allow_token_match_price_dry_run' => 1,
		'allow_token_match_price_minus_1_dry_run' => 1,
		'allow_token_reject' => 1,
	);
}

function lpm_action_token_settings(): array {
	$settings = lpm_token_settings( false );
	$settings['whatsapp_action_links_enabled'] = 1;

	return $settings;
}

function lpm_token_handler( LpmFakeApprovalTokenRepository $repo, array $settings = array() ): TokenActionHandler {
	$GLOBALS['lpm_test_options'][ Settings::OPTION_NAME ] = array_merge(
		array(
			'max_allowed_price_drop_percent' => 25,
			'max_allowed_price_increase_percent' => 50,
		),
		$settings
	);

	return new TokenActionHandler( $repo, new Settings(), new ApprovalTokenService( $repo ) );
}

function lpm_token_validation( int $suggestion_id, string $action ): array {
	return array(
		'success'       => true,
		'token_id'      => 1,
		'suggestion_id' => $suggestion_id,
		'action'        => $action,
	);
}

lpm_run_tests(
	'ApprovalTokenService',
	array(
		'token generation stores only hash' => static function (): void {
			$repo    = new LpmFakeApprovalTokenRepository();
			$service = new ApprovalTokenService( $repo );
			$result  = $service->create_token( 10, ApprovalTokenService::ACTION_APPROVE_DRY_RUN, lpm_token_settings() );

			lpm_assert_true( ! empty( $result['success'] ), 'Token should be created.' );
			lpm_assert_true( ! empty( $result['token'] ), 'Raw token should be returned once for link building.' );

			$stored = reset( $repo->tokens );

			lpm_assert_true( is_array( $stored ), 'Stored token row should exist.' );
			lpm_assert_true( (string) $stored['token_hash'] !== (string) $result['token'], 'Stored value must not be raw token.' );
			lpm_assert_same( hash( 'sha256', (string) $result['token'] ), (string) $stored['token_hash'], 'Stored token hash should match raw token hash.' );
		},
		'regenerated notification tokens keep older telegram links valid' => static function (): void {
			$repo    = new LpmFakeApprovalTokenRepository();
			$service = new ApprovalTokenService( $repo );
			$first   = $service->create_token( 10, ApprovalTokenService::ACTION_REJECT, lpm_token_settings() );
			$second  = $service->create_token( 10, ApprovalTokenService::ACTION_REJECT, lpm_token_settings() );

			lpm_assert_true( ! empty( $first['success'] ), 'First token should be created.' );
			lpm_assert_true( ! empty( $second['success'] ), 'Second token should be created.' );
			lpm_assert_same( 2, count( $repo->tokens ), 'A regenerated notification link must not delete older unused links.' );

			$first_validation  = $service->validate_token( (string) $first['token'], ApprovalTokenService::ACTION_REJECT );
			$second_validation = $service->validate_token( (string) $second['token'], ApprovalTokenService::ACTION_REJECT );

			lpm_assert_true( ! empty( $first_validation['success'] ), 'Older Telegram link should remain valid until used or expired.' );
			lpm_assert_true( ! empty( $second_validation['success'] ), 'Newer Telegram link should also be valid.' );
		},
		'expired token fails' => static function (): void {
			$repo    = new LpmFakeApprovalTokenRepository();
			$service = new ApprovalTokenService( $repo );
			$result  = $service->create_token( 10, ApprovalTokenService::ACTION_REJECT, lpm_token_settings() );
			$repo->tokens[ (int) $result['token_id'] ]['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

			$validation = $service->validate_token( (string) $result['token'], ApprovalTokenService::ACTION_REJECT );

			lpm_assert_same( false, $validation['success'], 'Expired token should fail.' );
			lpm_assert_same( 'expired', $validation['code'], 'Expired token should return expired code.' );
		},
		'used token fails' => static function (): void {
			$repo    = new LpmFakeApprovalTokenRepository();
			$service = new ApprovalTokenService( $repo );
			$result  = $service->create_token( 10, ApprovalTokenService::ACTION_REJECT, lpm_token_settings() );
			$service->mark_used( (int) $result['token_id'] );

			$validation = $service->validate_token( (string) $result['token'], ApprovalTokenService::ACTION_REJECT );

			lpm_assert_same( false, $validation['success'], 'Used token should fail.' );
			lpm_assert_same( 'used', $validation['code'], 'Used token should return used code.' );
		},
		'wrong action fails' => static function (): void {
			$repo    = new LpmFakeApprovalTokenRepository();
			$service = new ApprovalTokenService( $repo );
			$result  = $service->create_token( 10, ApprovalTokenService::ACTION_REJECT, lpm_token_settings() );

			$validation = $service->validate_token( (string) $result['token'], ApprovalTokenService::ACTION_APPROVE_DRY_RUN );

			lpm_assert_same( false, $validation['success'], 'Wrong action should fail.' );
			lpm_assert_same( 'wrong_action', $validation['code'], 'Wrong action should return wrong_action code.' );
		},
		'match price action is disabled by default' => static function (): void {
			$repo    = new LpmFakeApprovalTokenRepository();
			$service = new ApprovalTokenService( $repo );
			$result  = $service->create_token( 10, ApprovalTokenService::ACTION_MATCH_PRICE, lpm_token_settings() );

			lpm_assert_same( false, $result['success'], 'Match price token should not be created while action links are disabled.' );
			lpm_assert_same( array(), $repo->tokens, 'Disabled action should not store token rows.' );
		},
		'match price actions can create one-time dry-run links when enabled' => static function (): void {
			$repo    = new LpmFakeApprovalTokenRepository();
			$service = new ApprovalTokenService( $repo );
			$result  = $service->create_token( 10, ApprovalTokenService::ACTION_MATCH_PRICE_MINUS_1, lpm_action_token_settings() );

			lpm_assert_true( ! empty( $result['success'] ), 'Match price -1 token should be created when action links are enabled.' );
			lpm_assert_same( ApprovalTokenService::ACTION_MATCH_PRICE_MINUS_1, $result['action'], 'Action should be stored exactly.' );
			lpm_assert_true( ! empty( $result['url'] ), 'Token URL should be returned for webhook payloads.' );
		},
		'approve dry run does not call price updates' => static function (): void {
			$repo = new LpmFakeApprovalTokenRepository();
			$repo->suggestions[10] = array(
				'id' => 10,
				'status' => 'pending',
			);

			$updated = $repo->approve_suggestion_dry_run( 10, 0 );

			lpm_assert_true( $updated, 'Dry-run approval should update suggestion status.' );
			lpm_assert_same( 'approved_dry_run', $repo->suggestions[10]['status'], 'Dry-run approval status should be recorded.' );
			lpm_assert_same( 0, $repo->price_update_calls, 'Dry-run token approval must not call a price update service.' );
		},
		'match price token action records dry run only' => static function (): void {
			$repo = new LpmFakeApprovalTokenRepository();
			$repo->tokens[1] = array(
				'id' => 1,
				'suggestion_id' => 30,
				'action' => ApprovalTokenService::ACTION_MATCH_PRICE,
				'used_at' => null,
			);
			$repo->suggestions[30] = array(
				'id' => 30,
				'status' => 'pending',
				'current_price' => 1299,
				'competitor_price' => 1199,
				'monitored_product_id' => 300,
				'applies_to_group' => 0,
			);
			$repo->monitored_products[300] = array( 'id' => 300, 'min_price' => 1000 );

			$result = lpm_token_handler( $repo )->apply_token_action( lpm_token_validation( 30, ApprovalTokenService::ACTION_MATCH_PRICE ) );

			lpm_assert_same( 200, $result['status_code'], 'Match-price token action should be recorded.' );
			lpm_assert_float_equals( 1199.0, $repo->suggestions[30]['suggested_price'], 'Suggested price should match competitor price.' );
			lpm_assert_same( 'approved_dry_run', $repo->suggestions[30]['status'], 'Match-price action should dry-run approve only.' );
			lpm_assert_same( 0, $repo->price_update_calls, 'Token match action must not call a WooCommerce price update.' );
		},
		'match price minus one token action records dry run only' => static function (): void {
			$repo = new LpmFakeApprovalTokenRepository();
			$repo->tokens[1] = array(
				'id' => 1,
				'suggestion_id' => 31,
				'action' => ApprovalTokenService::ACTION_MATCH_PRICE_MINUS_1,
				'used_at' => null,
			);
			$repo->suggestions[31] = array(
				'id' => 31,
				'status' => 'pending',
				'current_price' => 1299,
				'competitor_price' => 1199,
				'monitored_product_id' => 301,
			);
			$repo->monitored_products[301] = array( 'id' => 301, 'min_price' => 1000 );

			$result = lpm_token_handler( $repo )->apply_token_action( lpm_token_validation( 31, ApprovalTokenService::ACTION_MATCH_PRICE_MINUS_1 ) );

			lpm_assert_same( 200, $result['status_code'], 'Match-price-minus-one token action should be recorded.' );
			lpm_assert_float_equals( 1198.0, $repo->suggestions[31]['suggested_price'], 'Suggested price should be competitor price minus 1.' );
			lpm_assert_same( 'approved_dry_run', $repo->suggestions[31]['status'], 'Match-price-minus-one action should dry-run approve only.' );
			lpm_assert_same( 0, $repo->price_update_calls, 'Token match -1 action must not call a WooCommerce price update.' );
		},
		'match price token action blocks invalid requested price' => static function (): void {
			$repo = new LpmFakeApprovalTokenRepository();
			$repo->tokens[1] = array( 'id' => 1, 'suggestion_id' => 32, 'action' => ApprovalTokenService::ACTION_MATCH_PRICE_MINUS_1, 'used_at' => null );
			$repo->suggestions[32] = array(
				'id' => 32,
				'status' => 'pending',
				'current_price' => 100,
				'competitor_price' => 0.5,
				'monitored_product_id' => 302,
			);

			$result = lpm_token_handler( $repo )->apply_token_action( lpm_token_validation( 32, ApprovalTokenService::ACTION_MATCH_PRICE_MINUS_1 ) );

			lpm_assert_same( 403, $result['status_code'], 'Invalid match-price-minus-one token action should be blocked.' );
			lpm_assert_same( 'pending', $repo->suggestions[32]['status'], 'Blocked token action should not approve the suggestion.' );
			lpm_assert_true( ! isset( $repo->suggestions[32]['suggested_price'] ), 'Blocked token action should not update suggested price.' );
		},
		'match price token action respects max drop limit' => static function (): void {
			$repo = new LpmFakeApprovalTokenRepository();
			$repo->tokens[1] = array( 'id' => 1, 'suggestion_id' => 33, 'action' => ApprovalTokenService::ACTION_MATCH_PRICE, 'used_at' => null );
			$repo->suggestions[33] = array(
				'id' => 33,
				'status' => 'pending',
				'current_price' => 100,
				'competitor_price' => 50,
				'monitored_product_id' => 303,
			);

			$result = lpm_token_handler( $repo, array( 'max_allowed_price_drop_percent' => 10 ) )->apply_token_action( lpm_token_validation( 33, ApprovalTokenService::ACTION_MATCH_PRICE ) );

			lpm_assert_same( 403, $result['status_code'], 'Suspicious token price drop should be blocked.' );
			lpm_assert_same( 'pending', $repo->suggestions[33]['status'], 'Blocked drop should not approve the suggestion.' );
			lpm_assert_true( ! isset( $repo->suggestions[33]['suggested_price'] ), 'Blocked drop should not update suggested price.' );
		},
		'match price token action respects monitored min price' => static function (): void {
			$repo = new LpmFakeApprovalTokenRepository();
			$repo->tokens[1] = array( 'id' => 1, 'suggestion_id' => 34, 'action' => ApprovalTokenService::ACTION_MATCH_PRICE, 'used_at' => null );
			$repo->suggestions[34] = array(
				'id' => 34,
				'status' => 'pending',
				'current_price' => 120,
				'competitor_price' => 90,
				'monitored_product_id' => 304,
			);
			$repo->monitored_products[304] = array( 'id' => 304, 'min_price' => 95 );

			$result = lpm_token_handler( $repo, array( 'max_allowed_price_drop_percent' => 100 ) )->apply_token_action( lpm_token_validation( 34, ApprovalTokenService::ACTION_MATCH_PRICE ) );

			lpm_assert_same( 403, $result['status_code'], 'Token action below min price should be blocked.' );
			lpm_assert_same( 'pending', $repo->suggestions[34]['status'], 'Blocked min price should not approve the suggestion.' );
			lpm_assert_true( ! isset( $repo->suggestions[34]['suggested_price'] ), 'Blocked min price should not update suggested price.' );
		},
		'group match price token action respects member min price' => static function (): void {
			$repo = new LpmFakeApprovalTokenRepository();
			$repo->tokens[1] = array( 'id' => 1, 'suggestion_id' => 35, 'action' => ApprovalTokenService::ACTION_MATCH_PRICE, 'used_at' => null );
			$repo->suggestions[35] = array(
				'id' => 35,
				'status' => 'pending',
				'current_price' => 120,
				'competitor_price' => 100,
				'monitored_product_id' => 305,
				'group_id' => 70,
				'applies_to_group' => 1,
			);
			$repo->group_members[70] = array(
				array( 'product_id' => 501, 'enabled' => 1, 'min_price' => 101 ),
				array( 'product_id' => 502, 'enabled' => 1, 'min_price' => 90 ),
			);

			$result = lpm_token_handler( $repo, array( 'max_allowed_price_drop_percent' => 100 ) )->apply_token_action( lpm_token_validation( 35, ApprovalTokenService::ACTION_MATCH_PRICE ) );

			lpm_assert_same( 403, $result['status_code'], 'Group token action below member min price should be blocked.' );
			lpm_assert_same( 'pending', $repo->suggestions[35]['status'], 'Blocked group action should not approve the suggestion.' );
			lpm_assert_true( ! isset( $repo->suggestions[35]['suggested_price'] ), 'Blocked group action should not update suggested price.' );
		},
		'reject works for pending and blocked suggestions only' => static function (): void {
			$repo = new LpmFakeApprovalTokenRepository();
			$repo->suggestions[20] = array( 'id' => 20, 'status' => 'pending' );
			$repo->suggestions[21] = array( 'id' => 21, 'status' => 'blocked' );

			lpm_assert_true( in_array( $repo->suggestions[20]['status'], array( 'pending', 'blocked' ), true ), 'Pending suggestion should be rejectable.' );
			lpm_assert_true( $repo->reject_suggestion( 20, 0 ), 'Pending suggestion should reject.' );
			lpm_assert_true( in_array( $repo->suggestions[21]['status'], array( 'pending', 'blocked' ), true ), 'Blocked suggestion should be rejectable.' );
			lpm_assert_true( $repo->reject_suggestion( 21, 0 ), 'Blocked suggestion should reject.' );
			lpm_assert_same( 'rejected', $repo->suggestions[20]['status'], 'Pending suggestion should become rejected.' );
			lpm_assert_same( 'rejected', $repo->suggestions[21]['status'], 'Blocked suggestion should become rejected.' );
		},
	)
);
