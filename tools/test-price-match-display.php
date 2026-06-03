<?php
/**
 * Local tests for frontend price-match display safety.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';

use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Frontend\FrontendPlugin;
use Lilleprinsen\PriceMonitor\Service\PriceMatchDisplayService;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! class_exists( 'wpdb' ) ) {
	final class wpdb {
		public string $prefix = 'wp_';

		/**
		 * @var array<int, bool>
		 */
		public array $real_active_products = array();

		public function prepare( string $query, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}

			foreach ( $args as $arg ) {
				if ( is_int( $arg ) || is_float( $arg ) ) {
					$query = preg_replace( '/%d|%f/', (string) $arg, $query, 1 ) ?? $query;
					continue;
				}

				$query = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function get_var( string $query ) {
			if ( str_starts_with( $query, 'SHOW TABLES LIKE ' ) && preg_match( "/'([^']+)'/", $query, $match ) ) {
				return $match[1];
			}

			if ( preg_match( '/product_id = ([0-9]+)/', $query, $match ) && str_contains( $query, "status = 'active'" ) ) {
				return ! empty( $this->real_active_products[ (int) $match[1] ] ) ? 1 : null;
			}

			return null;
		}
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		unset( $single );

		return $GLOBALS['lpm_test_post_meta'][ (int) $post_id ][ (string) $key ] ?? '';
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $message, $type = 'notice' ): void {
		$GLOBALS['lpm_test_wc_notices'][] = array(
			'message' => (string) $message,
			'type'    => (string) $type,
		);
	}
}

function lpm_display_service( wpdb $database ): PriceMatchDisplayService {
	global $wpdb;

	$wpdb = $database;

	return new PriceMatchDisplayService( new Repository( $database ) );
}

lpm_run_tests(
	'PriceMatchDisplayService',
	array(
		'dry-run cached match flag is ignored' => static function (): void {
			$GLOBALS['lpm_test_post_meta'] = array(
				10 => array( '_lpm_price_matched_active' => 'yes' ),
			);

			$database = new wpdb();
			$service  = lpm_display_service( $database );
			$state    = $service->get_display_state(
				10,
				array(
					'price_match_box_enabled' => 1,
					'price_match_box_hide_if_no_active_match' => 1,
				),
				true
			);

			lpm_assert_same( false, $state['show'], 'Legacy/dry-run yes meta should not show the frontend box.' );
			lpm_assert_same( false, $service->product_is_price_matched( 10, true ), 'Legacy/dry-run yes meta should not count for coupon exclusion.' );
		},
		'real cached match flag is accepted without indexed lookup' => static function (): void {
			$GLOBALS['lpm_test_post_meta'] = array(
				11 => array( '_lpm_price_matched_active' => 'real' ),
			);

			$database = new wpdb();
			$service  = lpm_display_service( $database );
			$state    = $service->get_display_state(
				11,
				array(
					'price_match_box_enabled' => 1,
					'price_match_box_hide_if_no_active_match' => 1,
					'price_match_box_text' => 'Prismatch',
					'price_match_box_subtext' => 'Rabattkoder kan ikke brukes på prismatch.',
				),
				false
			);

			lpm_assert_same( true, $state['show'], 'Real cached meta should show the frontend box.' );
			lpm_assert_same( true, $service->product_is_price_matched( 11, false ), 'Real cached meta should count for coupon exclusion.' );
		},
		'real active session lookup is accepted when allowed' => static function (): void {
			$GLOBALS['lpm_test_post_meta'] = array();

			$database = new wpdb();
			$database->real_active_products[12] = true;
			$service  = lpm_display_service( $database );
			$state    = $service->get_display_state(
				12,
				array(
					'price_match_box_enabled' => 1,
					'price_match_box_hide_if_no_active_match' => 1,
				),
				true
			);

			lpm_assert_same( true, $state['show'], 'Indexed real active session lookup should show the frontend box when allowed.' );
			lpm_assert_same( true, $service->product_is_price_matched( 12, true ), 'Indexed real active session lookup should count for coupon exclusion when allowed.' );
		},
		'dry-run session lookup is not treated as active frontend state' => static function (): void {
			$GLOBALS['lpm_test_post_meta'] = array();

			$database = new wpdb();
			$service  = lpm_display_service( $database );
			$state    = $service->get_display_state(
				13,
				array(
					'price_match_box_enabled' => 1,
					'price_match_box_hide_if_no_active_match' => 1,
				),
				true
			);

			lpm_assert_same( false, $state['show'], 'No real active lookup row should hide the frontend box.' );
			lpm_assert_same( false, $service->product_is_price_matched( 13, true ), 'No real active lookup row should not count for coupon exclusion.' );
		},
		'dry-run state does not remove coupon discount' => static function (): void {
			$GLOBALS['lpm_test_post_meta'] = array(
				14 => array( '_lpm_price_matched_active' => 'yes' ),
			);
			$GLOBALS['lpm_test_wc_notices'] = array();

			$database = new wpdb();
			global $wpdb;
			$wpdb = $database;
			$plugin = new FrontendPlugin( new Settings(), new Repository( $database ) );

			$discount = $plugin->filter_coupon_discount_amount( 25, 100, array( 'product_id' => 14 ), true, null );

			lpm_assert_same( 25, $discount, 'Dry-run/legacy match state should not remove coupon discount.' );
			lpm_assert_same( array(), $GLOBALS['lpm_test_wc_notices'], 'Dry-run/legacy match state should not add coupon notice.' );
		},
		'real active state removes coupon discount' => static function (): void {
			$GLOBALS['lpm_test_post_meta'] = array();
			$GLOBALS['lpm_test_wc_notices'] = array();

			$database = new wpdb();
			$database->real_active_products[15] = true;
			global $wpdb;
			$wpdb = $database;
			$plugin = new FrontendPlugin( new Settings(), new Repository( $database ) );

			$discount = $plugin->filter_coupon_discount_amount( 25, 100, array( 'product_id' => 15 ), true, null );

			lpm_assert_same( 0, $discount, 'Real active match state should remove coupon discount for that line.' );
			lpm_assert_same( 'Rabattkoder kan ikke brukes på prismatch.', $GLOBALS['lpm_test_wc_notices'][0]['message'] ?? '', 'Real active match state should add the Norwegian coupon notice.' );
		},
	)
);
