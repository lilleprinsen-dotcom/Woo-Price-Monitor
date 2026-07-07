<?php
/**
 * Local tests for market-aware suggestion creation.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return sanitize_text_field( $value );
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';

		public int $insert_id = 0;

		/**
		 * @var array<int, array<string, mixed>>
		 */
		public array $competitor_links = array();

		/**
		 * @var array<string, mixed>
		 */
		public array $last_insert = array();

		public function prepare( string $query, ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[ds]/', (string) $arg, $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function get_var( string $query ) {
			if ( preg_match( '/SHOW TABLES LIKE ([a-zA-Z0-9_]+)/', $query, $match ) ) {
				return $match[1];
			}

			return null;
		}

		public function get_results( string $query, $output = null ): array {
			unset( $output );

			if ( str_contains( $query, 'lpm_competitor_links' ) ) {
				return $this->competitor_links;
			}

			return array();
		}

		public function get_row( string $query, $output = null ) {
			unset( $query, $output );

			return null;
		}

		/**
		 * @param array<string,mixed> $data Insert data.
		 * @param array<int,string> $format Formats.
		 */
		public function insert( string $table, array $data, array $format = array() ): bool {
			unset( $table, $format );
			$this->insert_id++;
			$this->last_insert = $data;

			return true;
		}

		/**
		 * @param array<string,mixed> $data Update data.
		 * @param array<string,mixed> $where Where data.
		 * @param array<int,string> $format Formats.
		 * @param array<int,string> $where_format Where formats.
		 */
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			unset( $table, $where, $format, $where_format );
			$this->last_insert = $data;

			return true;
		}
	}
}

use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Service\SuggestionService;

final class Lpm_Test_Product {
	private string $price;

	public function __construct( string $price ) {
		$this->price = $price;
	}

	public function get_price(): string {
		return $this->price;
	}

	public function is_on_sale(): bool {
		return false;
	}

	public function get_stock_status(): string {
		return 'instock';
	}
}

function lpm_test_suggestion_service( array $links ): SuggestionService {
	global $wpdb;

	$wpdb                   = new wpdb();
	$wpdb->competitor_links = $links;

	return new SuggestionService( new Repository( $wpdb ) );
}

$settings = array(
	'default_currency' => 'NOK',
);

lpm_run_tests(
	'SuggestionService',
	array(
		'equal competitor price is skipped' => static function () use ( $settings ): void {
			$link = array(
				'id'                   => 10,
				'monitored_product_id' => 5,
				'product_id'           => 123,
				'enabled'              => 1,
				'match_type'           => 'exact',
				'last_price'           => 999,
				'last_currency'        => 'NOK',
			);

			$result = lpm_test_suggestion_service( array( $link ) )->create_from_competitor_link(
				array(
					'id'         => 5,
					'product_id' => 123,
				),
				$link,
				new Lpm_Test_Product( '999' ),
				$settings
			);

			lpm_assert_same( 'skipped', $result['status'], 'Equal competitor price should not create a suggestion.' );
			lpm_assert_contains( 'matches the current WooCommerce price', $result['message'], 'Equal price skip should be explained.' );
		},
		'higher competitor price without recovery session is skipped' => static function () use ( $settings ): void {
			$link = array(
				'id'                   => 11,
				'monitored_product_id' => 6,
				'product_id'           => 124,
				'enabled'              => 1,
				'match_type'           => 'exact',
				'last_price'           => 1199,
				'last_currency'        => 'NOK',
			);

			$result = lpm_test_suggestion_service( array( $link ) )->create_from_competitor_link(
				array(
					'id'         => 6,
					'product_id' => 124,
				),
				$link,
				new Lpm_Test_Product( '999' ),
				$settings
			);

			lpm_assert_same( 'skipped', $result['status'], 'Higher competitor price should not create a price-up suggestion without recovery state.' );
			lpm_assert_contains( 'No price-up suggestion', $result['message'], 'Higher price skip should be explained.' );
		},
		'one lower competitor among several does not create market alert' => static function () use ( $settings ): void {
			$links = array(
				array( 'id' => 21, 'monitored_product_id' => 7, 'product_id' => 125, 'enabled' => 1, 'match_type' => 'exact', 'last_price' => 899, 'last_currency' => 'NOK' ),
				array( 'id' => 22, 'monitored_product_id' => 7, 'product_id' => 125, 'enabled' => 1, 'match_type' => 'exact', 'last_price' => 999, 'last_currency' => 'NOK' ),
				array( 'id' => 23, 'monitored_product_id' => 7, 'product_id' => 125, 'enabled' => 1, 'match_type' => 'exact', 'last_price' => 1049, 'last_currency' => 'NOK' ),
			);

			$result = lpm_test_suggestion_service( $links )->create_from_competitor_link(
				array( 'id' => 7, 'product_id' => 125 ),
				$links[0],
				new Lpm_Test_Product( '999' ),
				$settings
			);

			lpm_assert_same( 'skipped', $result['status'], 'A single lower competitor should not create a market alert when several competitors are available.' );
			lpm_assert_contains( 'Only one competitor is below', $result['message'], 'Skip reason should explain isolated competitor movement.' );
		},
		'two lower competitors create market based price alert' => static function () use ( $settings ): void {
			global $wpdb;

			$links = array(
				array( 'id' => 31, 'monitored_product_id' => 8, 'product_id' => 126, 'enabled' => 1, 'match_type' => 'exact', 'last_price' => 899, 'last_currency' => 'NOK' ),
				array( 'id' => 32, 'monitored_product_id' => 8, 'product_id' => 126, 'enabled' => 1, 'match_type' => 'exact', 'last_price' => 929, 'last_currency' => 'NOK' ),
				array( 'id' => 33, 'monitored_product_id' => 8, 'product_id' => 126, 'enabled' => 1, 'match_type' => 'exact', 'last_price' => 1049, 'last_currency' => 'NOK' ),
			);

			$result = lpm_test_suggestion_service( $links )->create_from_competitor_link(
				array( 'id' => 8, 'product_id' => 126 ),
				$links[1],
				new Lpm_Test_Product( '999' ),
				$settings
			);

			lpm_assert_same( 'pending', $result['status'], 'Two lower competitors should create a market-based suggestion.' );
			lpm_assert_float_equals( 899.0, (float) $wpdb->last_insert['competitor_price'], 'Suggestion should use the lowest supported market price.' );
			lpm_assert_same( 31, (int) $wpdb->last_insert['competitor_link_id'], 'Suggestion should point at the lowest competitor link.' );
			$details = json_decode( (string) $wpdb->last_insert['rule_details'], true );
			lpm_assert_same( 2, (int) $details['market_context']['competitors_below_us'], 'Rule details should record market support count.' );
		},
		'single configured competitor can still create price down alert' => static function () use ( $settings ): void {
			$links = array(
				array( 'id' => 41, 'monitored_product_id' => 9, 'product_id' => 127, 'enabled' => 1, 'match_type' => 'exact', 'last_price' => 899, 'last_currency' => 'NOK' ),
			);

			$result = lpm_test_suggestion_service( $links )->create_from_competitor_link(
				array( 'id' => 9, 'product_id' => 127 ),
				$links[0],
				new Lpm_Test_Product( '999' ),
				$settings
			);

			lpm_assert_same( 'pending', $result['status'], 'Single-competitor products should still alert because there is no broader market to compare.' );
		},
		'out of stock competitor does not create price alert' => static function () use ( $settings ): void {
			$links = array(
				array( 'id' => 51, 'monitored_product_id' => 10, 'product_id' => 128, 'enabled' => 1, 'match_type' => 'exact', 'last_price' => 799, 'last_currency' => 'NOK', 'last_stock_status' => 'out_of_stock' ),
			);

			$result = lpm_test_suggestion_service( $links )->create_from_competitor_link(
				array( 'id' => 10, 'product_id' => 128 ),
				$links[0],
				new Lpm_Test_Product( '999' ),
				$settings
			);

			lpm_assert_same( 'skipped', $result['status'], 'Out-of-stock competitors should not create price alerts.' );
			lpm_assert_contains( 'out of stock', $result['message'], 'Out-of-stock skip should be visible.' );
		},
		'market alert uses lowest in stock competitor instead of cheaper out of stock competitor' => static function () use ( $settings ): void {
			global $wpdb;

			$links = array(
				array( 'id' => 61, 'monitored_product_id' => 11, 'product_id' => 129, 'enabled' => 1, 'match_type' => 'exact', 'last_price' => 799, 'last_currency' => 'NOK', 'last_stock_status' => 'out_of_stock' ),
				array( 'id' => 62, 'monitored_product_id' => 11, 'product_id' => 129, 'enabled' => 1, 'match_type' => 'exact', 'last_price' => 899, 'last_currency' => 'NOK', 'last_stock_status' => 'in_stock' ),
				array( 'id' => 63, 'monitored_product_id' => 11, 'product_id' => 129, 'enabled' => 1, 'match_type' => 'exact', 'last_price' => 929, 'last_currency' => 'NOK', 'last_stock_status' => 'in_stock' ),
			);

			$result = lpm_test_suggestion_service( $links )->create_from_competitor_link(
				array( 'id' => 11, 'product_id' => 129 ),
				$links[0],
				new Lpm_Test_Product( '999' ),
				$settings
			);

			lpm_assert_same( 'pending', $result['status'], 'Two in-stock lower competitors should still create a market alert.' );
			lpm_assert_float_equals( 899.0, (float) $wpdb->last_insert['competitor_price'], 'Suggestion should ignore cheaper out-of-stock price and use lowest in-stock price.' );
			lpm_assert_same( 62, (int) $wpdb->last_insert['competitor_link_id'], 'Suggestion should point at the lowest in-stock competitor link.' );
			$details = json_decode( (string) $wpdb->last_insert['rule_details'], true );
			lpm_assert_same( 1, (int) $details['market_context']['out_of_stock_competitors_excluded'], 'Rule details should record excluded out-of-stock competitors.' );
		},
		'anomaly detection blocks scraped category page suggestions' => static function () use ( $settings ): void {
			global $wpdb;

			$link = array(
				'id'                   => 71,
				'monitored_product_id' => 12,
				'product_id'           => 130,
				'enabled'              => 1,
				'match_type'           => 'exact',
				'competitor_url'       => 'https://competitor.no/category/thule',
				'last_price'           => 799,
				'last_currency'        => 'NOK',
			);

			$result = lpm_test_suggestion_service( array( $link ) )->create_from_competitor_link(
				array( 'id' => 12, 'product_id' => 130 ),
				$link,
				new Lpm_Test_Product( '999' ),
				$settings
			);

			lpm_assert_same( 'blocked', $result['status'], 'Scraped category/listing pages should be blocked from normal suggestions.' );
			lpm_assert_contains( 'Anomaly detection blocked', $result['message'], 'Blocked anomaly should be clearly explained.' );
			$warnings = json_decode( (string) $wpdb->last_insert['warnings'], true );
			lpm_assert_contains( 'category', implode( ' ', (array) $warnings ), 'Warnings should mention category/listing page risk.' );
		},
		'anomaly detection blocks fake discount data' => static function () use ( $settings ): void {
			global $wpdb;

			$link = array(
				'id'                   => 72,
				'monitored_product_id' => 13,
				'product_id'           => 131,
				'enabled'              => 1,
				'match_type'           => 'exact',
				'competitor_url'       => 'https://competitor.no/products/seat',
				'last_price'           => 899,
				'last_regular_price'   => 799,
				'last_sale_price'      => 899,
				'last_currency'        => 'NOK',
			);

			$result = lpm_test_suggestion_service( array( $link ) )->create_from_competitor_link(
				array( 'id' => 13, 'product_id' => 131 ),
				$link,
				new Lpm_Test_Product( '999' ),
				$settings
			);

			lpm_assert_same( 'blocked', $result['status'], 'Fake discount data should block normal price suggestions.' );
			$details = json_decode( (string) $wpdb->last_insert['rule_details'], true );
			lpm_assert_same( 'sale_above_regular', (string) $details['anomaly_detection']['details']['checks']['fake_discount'], 'Rule details should preserve the fake-discount reason.' );
		},
		'anomaly detection blocks wildly low prices' => static function () use ( $settings ): void {
			$link = array(
				'id'                   => 73,
				'monitored_product_id' => 14,
				'product_id'           => 132,
				'enabled'              => 1,
				'match_type'           => 'exact',
				'competitor_url'       => 'https://competitor.no/products/seat',
				'last_price'           => 299,
				'last_currency'        => 'NOK',
			);

			$result = lpm_test_suggestion_service( array( $link ) )->create_from_competitor_link(
				array( 'id' => 14, 'product_id' => 132 ),
				$link,
				new Lpm_Test_Product( '999' ),
				$settings
			);

			lpm_assert_same( 'blocked', $result['status'], 'Wildly low competitor prices should be blocked for manual review.' );
			lpm_assert_contains( 'wrong price', implode( ' ', (array) $result['warnings'] ), 'Warning should explain wrong-price risk.' );
		},
	)
);
