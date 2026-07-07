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

	if ( ! class_exists( 'wpdb' ) ) {
		class wpdb {
			public string $prefix = 'wp_';

			/**
			 * @var array<string,mixed>
			 */
			public array $suggestion = array();

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

	use Lilleprinsen\PriceMonitor\Database\Repository;
	use Lilleprinsen\PriceMonitor\Notifications\NotificationMessageBuilder;
	use Lilleprinsen\PriceMonitor\Service\ApprovalTokenService;

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
		)
	);
}
