<?php
/**
 * Local tests for market-aware suggestion creation.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';

		/**
		 * @var array<int, array<string, mixed>>
		 */
		public array $competitor_links = array();

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
	)
);
