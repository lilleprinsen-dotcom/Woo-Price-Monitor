<?php
/**
 * Local tests for webhook/Telegram notification payloads.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

namespace {
	require_once __DIR__ . '/test-bootstrap.php';

	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	if ( ! function_exists( 'number_format_i18n' ) ) {
		function number_format_i18n( $number, $decimals = 0 ) {
			return number_format( (float) $number, (int) $decimals, '.', ' ' );
		}
	}

	if ( ! function_exists( 'sanitize_textarea_field' ) ) {
		function sanitize_textarea_field( $value ) {
			return sanitize_text_field( $value );
		}
	}

	if ( ! class_exists( 'wpdb' ) ) {
		class wpdb {
			public string $prefix = 'wp_';

			/**
			 * @var array<string,mixed>
			 */
			public array $suggestion = array();

			public int $insert_id = 1;

			public function prepare( string $query, ...$args ): string {
				return $query . '|' . implode( '|', array_map( 'strval', $args ) );
			}

			public function get_var( string $query ) {
				if ( str_starts_with( $query, 'SHOW TABLES LIKE' ) ) {
					$parts = explode( '|', $query );
					return end( $parts );
				}

				return null;
			}

			public function get_row( string $query, $output = null ) {
				unset( $query, $output );

				return $this->suggestion;
			}

			/**
			 * @param array<string,mixed> $data Insert data.
			 * @param array<int,string>   $format Insert format.
			 */
			public function insert( string $table, array $data, array $format = array() ) {
				unset( $table, $data, $format );
				$this->insert_id++;

				return 1;
			}
		}
	}

	if ( ! isset( $GLOBALS['wpdb'] ) ) {
		$GLOBALS['wpdb'] = new \wpdb();
	}
}

namespace Lilleprinsen\PriceMonitor\Admin {
	if ( ! class_exists( AdminPage::class ) ) {
		final class AdminPage {
			public const SLUG = 'lilleprinsen-price-monitor';
		}
	}
}

namespace {
	require_once LPM_TEST_ROOT . '/src/Database/Schema.php';
	require_once LPM_TEST_ROOT . '/src/Database/Repository.php';
	require_once LPM_TEST_ROOT . '/src/Service/ReviewLinkService.php';
	require_once LPM_TEST_ROOT . '/src/Service/ApprovalTokenService.php';
	require_once LPM_TEST_ROOT . '/src/Notifications/NotificationMessageBuilder.php';
	require_once LPM_TEST_ROOT . '/src/Notifications/NotificationInterface.php';
	require_once LPM_TEST_ROOT . '/src/Notifications/NtfyNotificationChannel.php';
	require_once LPM_TEST_ROOT . '/src/Settings/Settings.php';

	use Lilleprinsen\PriceMonitor\Database\Repository;
	use Lilleprinsen\PriceMonitor\Notifications\NotificationMessageBuilder;
	use Lilleprinsen\PriceMonitor\Notifications\NtfyNotificationChannel;
	use Lilleprinsen\PriceMonitor\Service\ApprovalTokenService;
	use Lilleprinsen\PriceMonitor\Settings\Settings;

	final class LpmNotificationTokenRepository {
		/**
		 * @var array<int,array<string,mixed>>
		 */
		public array $tokens = array();

		public int $next_id = 1;

		/**
		 * @param array<string,mixed> $data Token row.
		 */
		public function create_approval_token( array $data ): int {
			$id = $this->next_id++;
			$data['id'] = $id;
			$this->tokens[ $id ] = $data;

			return $id;
		}

		/**
		 * @param array<string,mixed> $context Log context.
		 */
		public function write_log( string $level, string $event, string $message, array $context = array(), ?int $product_id = null ): int {
			unset( $level, $event, $message, $context, $product_id );

			return 1;
		}
	}

	lpm_run_tests(
		'NotificationMessageBuilder',
		array(
			'Webhook payload includes simplified Telegram text and action links' => static function (): void {
				global $wpdb;

				$wpdb = new \wpdb();
				$wpdb->suggestion = array(
					'id'                => 55,
					'suggestion_id'     => 55,
					'product_id'        => 123,
					'suggestion_type'   => 'price_match_down',
					'status'            => 'pending',
					'current_price'     => 1299,
					'competitor_price'  => 1199,
					'suggested_price'   => 1199,
					'difference'        => -100,
					'competitor_name'   => 'Babycare',
					'competitor_url'    => 'https://babycare.no/product',
					'reason'            => 'Market competitor moved below us.',
				);

				$token_repo = new LpmNotificationTokenRepository();
				$builder = new NotificationMessageBuilder( new Repository( $wpdb ), null, new ApprovalTokenService( $token_repo ) );
				$payload = $builder->build_payload(
					'price_suggestion_pending',
					'fallback',
					array(
						'suggestion_id' => 55,
						'product_name'  => 'Thule Urban Glide 3',
						'sku'           => '20110754',
					),
					123,
					array(
						'allow_token_dry_run_approval_links' => 1,
						'token_link_expiry_hours' => 24,
						'whatsapp_action_links_enabled' => 0,
						'whatsapp_action_link_expiry_hours' => 24,
						'allow_token_match_price_dry_run' => 0,
						'allow_token_match_price_minus_1_dry_run' => 0,
						'allow_token_reject' => 1,
					)
				);

				lpm_assert_same( 'Thule Urban Glide 3', $payload['product_name'], 'Payload should use product name from notification context.' );
				lpm_assert_same( 1299.0, $payload['old_price'], 'Payload should expose old/current price explicitly.' );
				lpm_assert_same( 1199.0, $payload['new_price'], 'Payload should expose new/suggested price explicitly.' );
				lpm_assert_true( str_contains( (string) $payload['telegram_text'], 'Produkt: Thule Urban Glide 3' ), 'Telegram text should be simple and product-first.' );
				lpm_assert_true( str_contains( (string) $payload['telegram_text'], 'Konkurrent: Babycare' ), 'Telegram text should show competitor.' );
				lpm_assert_true( str_contains( (string) $payload['telegram_text'], 'Marked:' ), 'Telegram text should include market context.' );
				lpm_assert_true( str_contains( (string) $payload['telegram_text'], 'Hvorfor:' ), 'Telegram text should explain why the alert matters.' );
				lpm_assert_true( ! empty( $payload['summary']['links']['approve_dry_run'] ), 'Summary should expose approve dry-run link.' );
				lpm_assert_true( ! empty( $payload['summary']['links']['reject'] ), 'Summary should expose reject link.' );
				lpm_assert_true( str_contains( (string) $payload['telegram_text'], 'Godkjenn dry-run:' ), 'Telegram text should include approve link label.' );
				lpm_assert_true( str_contains( (string) $payload['telegram_text'], 'Avvis:' ), 'Telegram text should include reject link label.' );
			},
			'ntfy payload sends three iPhone action buttons from approval tokens' => static function (): void {
				global $wpdb;

				$wpdb = new \wpdb();
				$wpdb->suggestion = array(
					'id'                => 77,
					'suggestion_id'     => 77,
					'product_id'        => 321,
					'suggestion_type'   => 'price_match_down',
					'status'            => 'pending',
					'current_price'     => 8999,
					'competitor_price'  => 8499,
					'suggested_price'   => 8499,
					'difference'        => -500,
					'competitor_name'   => 'Jollyroom',
					'competitor_url'    => 'https://jollyroom.no/product',
					'reason'            => 'Several comparable competitors are below us.',
				);

				$GLOBALS['lpm_test_http_responses'] = array(
					'https://ntfy.sh/lilleprinsen-test-topic' => array(
						'response' => array( 'code' => 200 ),
						'body'     => '{"id":"test"}',
					),
				);
				unset( $GLOBALS['lpm_test_last_http_request'] );

				$token_repo = new LpmNotificationTokenRepository();
				$builder = new NotificationMessageBuilder( new Repository( $wpdb ), null, new ApprovalTokenService( $token_repo ) );
				$channel = new NtfyNotificationChannel( new Repository( $wpdb ), $builder, new ApprovalTokenService( $token_repo ) );
				$sent = $channel->send(
					'price_suggestion_pending',
					'fallback',
					array(
						'suggestion_id' => 77,
						'product_name'  => 'BeSafe Stretch2 Black Soft Breeze',
						'sku'           => '11048209-BlackSoBr-Std',
					),
					321,
					array(
						'ntfy_notifications_enabled' => 1,
						'ntfy_server_url' => 'https://ntfy.sh',
						'ntfy_topic' => 'lilleprinsen-test-topic',
						'ntfy_access_token' => '',
						'ntfy_priority' => 'high',
						'ntfy_send_on_new_suggestion' => 1,
						'ntfy_send_on_blocked_suggestion' => 1,
						'ntfy_send_on_failed_check' => 0,
						'ntfy_send_on_recovery_suggestion' => 1,
						'token_link_expiry_hours' => 24,
						'whatsapp_action_link_expiry_hours' => 24,
						'allow_token_match_price_dry_run' => 1,
						'allow_token_match_price_minus_1_dry_run' => 1,
						'allow_token_reject' => 1,
					)
				);

				lpm_assert_true( $sent, 'ntfy notification should be sent.' );
				lpm_assert_same( 'POST', $GLOBALS['lpm_test_last_http_request']['method'], 'ntfy should use POST.' );
				lpm_assert_same( 'https://ntfy.sh/lilleprinsen-test-topic', $GLOBALS['lpm_test_last_http_request']['url'], 'ntfy should post to the topic endpoint.' );

				$body = json_decode( (string) $GLOBALS['lpm_test_last_http_request']['args']['body'], true );
				lpm_assert_same( 'high', $body['priority'], 'ntfy should use configured priority.' );
				lpm_assert_true( str_contains( (string) $body['message'], 'Produkt: BeSafe Stretch2 Black Soft Breeze' ), 'ntfy message should be product-first.' );
				lpm_assert_same( 3, count( $body['actions'] ?? array() ), 'ntfy should include three action buttons.' );
				lpm_assert_same( 'Match price', $body['actions'][0]['label'], 'First button should match competitor price.' );
				lpm_assert_same( 'Match -1', $body['actions'][1]['label'], 'Second button should beat competitor by 1.' );
				lpm_assert_same( 'Reject', $body['actions'][2]['label'], 'Third button should reject.' );
				lpm_assert_true( str_contains( (string) $body['actions'][0]['url'], 'lpm_token_action=match_price' ), 'Match price button should use token action URL.' );
				lpm_assert_true( str_contains( (string) $body['actions'][1]['url'], 'lpm_token_action=match_price_minus_1' ), 'Match -1 button should use token action URL.' );
				lpm_assert_true( str_contains( (string) $body['actions'][2]['url'], 'lpm_token_action=reject' ), 'Reject button should use token action URL.' );

				unset( $GLOBALS['lpm_test_http_responses'], $GLOBALS['lpm_test_last_http_request'] );
			},
			'ntfy onboarding enables the notification master switch and sanitizes topic' => static function (): void {
				$settings = ( new Settings() )->sanitize(
					array(
						'notifications_enabled' => 0,
						'ntfy_notifications_enabled' => 1,
						'ntfy_server_url' => 'https://ntfy.sh',
						'ntfy_topic' => ' lilleprinsen prices / test! ',
						'ntfy_priority' => 'urgent',
					)
				);

				lpm_assert_same( 1, $settings['notifications_enabled'], 'Enabling ntfy should keep the notification master switch enabled.' );
				lpm_assert_same( 1, $settings['ntfy_notifications_enabled'], 'ntfy should remain enabled.' );
				lpm_assert_same( 'lilleprinsenpricestest', $settings['ntfy_topic'], 'ntfy topic should be safe for a topic URL path.' );
				lpm_assert_same( 'urgent', $settings['ntfy_priority'], 'Valid ntfy priority should be saved.' );
			},
		)
	);
}
