<?php
/**
 * Local tests for PriceParser.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/test-bootstrap.php';

use Lilleprinsen\PriceMonitor\Service\PriceParser;

$parser = new PriceParser();

$selector_rules = array(
	'price_extraction_mode' => 'selector',
	'price_selector'        => '.price-current',
	'default_currency'      => 'NOK',
);

lpm_run_tests(
	'PriceParser',
	array(
		'JSON-LD Product offers price' => static function () use ( $parser ): void {
			$result = $parser->parse( lpm_test_fixture( 'json-ld-product.html' ) );

			lpm_assert_true( $result['success'], 'JSON-LD parser should succeed.' );
			lpm_assert_float_equals( 1199.0, $result['price'], 'JSON-LD price should match.' );
			lpm_assert_same( 'NOK', $result['currency'], 'JSON-LD currency should match.' );
			lpm_assert_same( 'json_ld_offer', $result['extraction_method'], 'JSON-LD method should be recorded.' );
		},
		'Meta tag price' => static function () use ( $parser ): void {
			$result = $parser->parse( lpm_test_fixture( 'meta-price.html' ) );

			lpm_assert_true( $result['success'], 'Meta parser should succeed.' );
			lpm_assert_float_equals( 1299.5, $result['price'], 'Meta price should match.' );
			lpm_assert_same( 'NOK', $result['currency'], 'Meta currency should match.' );
			lpm_assert_same( 'meta_productpriceamount', $result['extraction_method'], 'Meta method should be recorded.' );
		},
		'Visible NOK price fallback' => static function () use ( $parser ): void {
			$result = $parser->parse( lpm_test_fixture( 'nok-visible-price.html' ) );

			lpm_assert_true( $result['success'], 'Visible NOK parser should succeed.' );
			lpm_assert_float_equals( 1199.9, $result['price'], 'Visible NOK price should handle decimal comma.' );
			lpm_assert_same( 'NOK', $result['currency'], 'Visible fallback should use NOK.' );
			lpm_assert_same( 'visible_nok_regex', $result['extraction_method'], 'Visible method should be recorded.' );
		},
		'Selector price extraction' => static function () use ( $parser, $selector_rules ): void {
			$result = $parser->parse( lpm_test_fixture( 'selector-price.html' ), $selector_rules );

			lpm_assert_true( $result['success'], 'Selector parser should succeed.' );
			lpm_assert_float_equals( 1098.5, $result['price'], 'Selector price should handle thousand separator and decimal comma.' );
			lpm_assert_same( 'selector_price', $result['extraction_method'], 'Selector method should be recorded.' );
		},
		'Attribute selector price extraction' => static function () use ( $parser ): void {
			$result = $parser->parse(
				'<html><body><span data-lpm-price="main" content="1 249,00"></span></body></html>',
				array(
					'price_extraction_mode' => 'selector',
					'price_selector'        => '[data-lpm-price="main"]',
					'default_currency'      => 'NOK',
				)
			);

			lpm_assert_true( $result['success'], 'Attribute selector parser should succeed.' );
			lpm_assert_float_equals( 1249.0, $result['price'], 'Attribute selector should read content attribute.' );
			lpm_assert_same( 'selector_price', $result['extraction_method'], 'Selector method should be recorded.' );
		},
		'Nested fallback selector reads data-lpm-price attribute' => static function () use ( $parser ): void {
			$result = $parser->parse(
				'<html><body><section><div><span itemprop="price" data-lpm-price="1 349,00"></span></div></section></body></html>',
				array(
					'price_extraction_mode' => 'selector',
					'price_selector'        => '[itemprop="price"]',
					'default_currency'      => 'NOK',
				)
			);

			lpm_assert_true( $result['success'], 'Nested fallback selector should succeed.' );
			lpm_assert_float_equals( 1349.0, $result['price'], 'Fallback should read data-lpm-price when content/data-price are absent.' );
			lpm_assert_same( 'selector_price', $result['extraction_method'], 'Selector method should be recorded.' );
		},
		'Stock in text' => static function () use ( $parser, $selector_rules ): void {
			$result = $parser->parse(
				lpm_test_fixture( 'stock-in.html' ),
				array_merge(
					$selector_rules,
					array(
						'stock_selector' => '.stock-message',
						'stock_in_text'  => 'på lager',
						'stock_out_text' => 'ikke på lager',
					)
				)
			);

			lpm_assert_true( $result['success'], 'Stock in parser should still extract price.' );
			lpm_assert_same( 'in_stock', $result['stock_status'], 'Stock in text should be detected.' );
		},
		'Stock out text' => static function () use ( $parser, $selector_rules ): void {
			$result = $parser->parse(
				lpm_test_fixture( 'stock-out.html' ),
				array_merge(
					$selector_rules,
					array(
						'stock_selector' => '.stock-message',
						'stock_in_text'  => 'på lager',
						'stock_out_text' => 'ikke på lager',
					)
				)
			);

			lpm_assert_true( $result['success'], 'Stock out parser should still extract price.' );
			lpm_assert_same( 'out_of_stock', $result['stock_status'], 'Stock out text should be detected before stock in text.' );
		},
		'Stock out wins with normalized text' => static function () use ( $parser, $selector_rules ): void {
			$result = $parser->parse(
				'<html><body><span class="price-current">kr 899</span><p id="stock-state">På' . "\n" . ' lager - Ikke PÅ lager akkurat nå</p></body></html>',
				array_merge(
					$selector_rules,
					array(
						'stock_selector' => '#stock-state',
						'stock_in_text'  => 'på lager',
						'stock_out_text' => 'ikke på lager',
					)
				)
			);

			lpm_assert_true( $result['success'], 'Stock parser should still extract price.' );
			lpm_assert_same( 'out_of_stock', $result['stock_status'], 'Out-of-stock text should win after case and whitespace normalization.' );
		},
		'No price failure' => static function () use ( $parser ): void {
			$result = $parser->parse( lpm_test_fixture( 'no-price.html' ) );

			lpm_assert_true( ! $result['success'], 'No-price fixture should fail.' );
			lpm_assert_same( null, $result['price'], 'Failed parse should not return a price.' );
			lpm_assert_contains( 'No recognizable price', $result['error'], 'Failed parse should explain missing price.' );
		},
		'Currency fallback to NOK' => static function () use ( $parser ): void {
			$result = $parser->parse( '<html><body><p>NOK 749</p></body></html>', array( 'default_currency' => '' ) );

			lpm_assert_true( $result['success'], 'Visible parser should succeed with empty currency.' );
			lpm_assert_same( 'NOK', $result['currency'], 'Empty default currency should normalize to NOK.' );
		},
		'Thousand separator handling' => static function () use ( $parser ): void {
			$result = $parser->parse( '<html><body><p>NOK 1.299,95</p></body></html>' );

			lpm_assert_true( $result['success'], 'Visible parser should succeed with mixed separators.' );
			lpm_assert_float_equals( 1299.95, $result['price'], 'Mixed thousand and decimal separators should normalize.' );
		},
	)
);
