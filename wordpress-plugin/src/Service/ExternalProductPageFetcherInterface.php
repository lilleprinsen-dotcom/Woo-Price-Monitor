<?php
/**
 * Optional external product-page fetcher contract.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extension point for a future configured scraper/browser worker.
 *
 * The bundled checker intentionally does not render JavaScript. Implementations
 * of this interface must be explicitly configured by site owners before use.
 */
interface ExternalProductPageFetcherInterface {
	/**
	 * Whether this external worker is configured and safe to use.
	 */
	public function is_enabled(): bool;

	/**
	 * Fetch rendered product-page HTML.
	 *
	 * @param string              $url Competitor product URL.
	 * @param array<string,mixed> $competitor Competitor profile.
	 * @return array{success:bool,html:string,error:string}
	 */
	public function fetch_product_html( string $url, array $competitor ): array;
}
