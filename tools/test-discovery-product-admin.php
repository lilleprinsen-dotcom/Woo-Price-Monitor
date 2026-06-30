<?php
/**
 * Local tests for product edit discovery admin helpers.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';

use Lilleprinsen\PriceMonitor\Admin\DiscoveryProductAdmin;

$method = new ReflectionMethod( DiscoveryProductAdmin::class, 'monitored_product_id_from_result' );

lpm_run_tests(
	'DiscoveryProductAdmin',
	array(
		'Manual URL flow uses existing monitored product ID' => static function () use ( $method ): void {
			$id = $method->invoke( null, array( 'id' => 44 ), array() );

			lpm_assert_same( 44, $id, 'Existing monitored product ID should be used directly.' );
		},
		'Manual URL flow extracts ID from add_monitored_product result array' => static function () use ( $method ): void {
			$id = $method->invoke(
				null,
				null,
				array(
					'success' => true,
					'code'    => 'monitoring_added',
					'id'      => 55,
				)
			);

			lpm_assert_same( 55, $id, 'Created monitored product ID should come from the result array id key.' );
		},
		'Manual URL flow rejects failed add_monitored_product result arrays' => static function () use ( $method ): void {
			$id = $method->invoke(
				null,
				null,
				array(
					'success' => false,
					'code'    => 'monitoring_add_failed',
					'id'      => 77,
				)
			);

			lpm_assert_same( 0, $id, 'Failed add result must not be treated as a raw integer monitored ID.' );
		},
	)
);
