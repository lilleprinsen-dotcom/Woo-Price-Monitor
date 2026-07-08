<?php
/**
 * Local regression tests for admin product search relevance.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require __DIR__ . '/test-bootstrap.php';
require LPM_TEST_ROOT . '/src/Plugin.php';
require LPM_TEST_ROOT . '/src/Admin/ProductSearchService.php';

use Lilleprinsen\PriceMonitor\Admin\ProductSearchService;

class WooCommerce {}

final class LpmSearchProduct {
	private int $id;
	private string $name;
	private string $sku;

	public function __construct( int $id, string $name, string $sku = '' ) {
		$this->id   = $id;
		$this->name = $name;
		$this->sku  = $sku;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_sku(): string {
		return $this->sku;
	}

	public function get_price_html(): string {
		return '—';
	}

	public function get_stock_status(): string {
		return 'instock';
	}

	public function get_image_id(): int {
		return 0;
	}
}

$GLOBALS['lpm_search_products'] = array(
	1 => new LpmSearchProduct( 1, 'Konges Sløjd Roli strap dress ocs - cameo rose', 'KS105470-ROSE' ),
	2 => new LpmSearchProduct( 2, 'Maxi-Cosi Mobifix Pro Authentic Black (0 til 7 år)' ),
	3 => new LpmSearchProduct( 3, 'Neonate Neck Strap - BC', 'SPNSBC' ),
	4 => new LpmSearchProduct( 4, 'BeSafe Stretch2 - Black Soft Breeze', '11048209-BlackSoBr-Std' ),
	5 => new LpmSearchProduct( 5, 'BeSafe Go Beyond - Black SoftBreeze', '11036236-BlackSoBr-Std' ),
);
$GLOBALS['lpm_meta_query_calls'] = 0;

function wc_get_product( $product_id ) {
	return $GLOBALS['lpm_search_products'][ (int) $product_id ] ?? null;
}

function wc_get_product_id_by_sku( $sku ) {
	foreach ( $GLOBALS['lpm_search_products'] as $product ) {
		if ( $product->get_sku() === (string) $sku ) {
			return $product->get_id();
		}
	}

	return 0;
}

function wc_get_products( array $args ) {
	if ( isset( $args['meta_query'] ) ) {
		++$GLOBALS['lpm_meta_query_calls'];

		return array_values( $GLOBALS['lpm_search_products'] );
	}

	return array_values( $GLOBALS['lpm_search_products'] );
}

function lpm_search_assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "  PASS {$message}\n";
}

echo "ProductSearchService\n";

$service = new ProductSearchService();
$results = $service->search( 'Besafe stretch black soft breeze', 20 );
$names   = array_map(
	static function ( array $row ): string {
		return (string) $row['name'];
	},
	$results
);

lpm_search_assert_true( ! empty( $names ), 'relevant title search returns results' );
lpm_search_assert_true( 'BeSafe Stretch2 - Black Soft Breeze' === $names[0], 'strong title match is ranked first' );
lpm_search_assert_true( ! in_array( 'Maxi-Cosi Mobifix Pro Authentic Black (0 til 7 år)', $names, true ), 'single-token color matches are filtered out' );
lpm_search_assert_true( ! in_array( 'Konges Sløjd Roli strap dress ocs - cameo rose', $names, true ), 'unrelated broad search results are filtered out' );

$short_results = $service->search( 'Besafe stretch 2', 20 );
$short_names   = array_map(
	static function ( array $row ): string {
		return (string) $row['name'];
	},
	$short_results
);
lpm_search_assert_true( array( 'BeSafe Stretch2 - Black Soft Breeze' ) === $short_names, 'short product-name search returns only relevant products' );
lpm_search_assert_true( 0 === (int) $GLOBALS['lpm_meta_query_calls'], 'normal product-name searches do not run identifier meta lookup' );

$sku_results = $service->search( '11048209-BlackSoBr-Std', 20 );
lpm_search_assert_true( ! empty( $sku_results ) && 4 === (int) $sku_results[0]['id'], 'exact SKU match still works before title ranking' );
lpm_search_assert_true( (int) $GLOBALS['lpm_meta_query_calls'] > 0, 'identifier-shaped searches can still use identifier meta lookup' );

echo "ProductSearchService passed.\n";
