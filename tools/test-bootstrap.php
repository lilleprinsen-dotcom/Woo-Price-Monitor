<?php
/**
 * Minimal local CLI test bootstrap.
 *
 * These tests intentionally do not load WordPress. Only small stubs needed by
 * pure services are provided here.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	exit( "Local tests must be run from the CLI.\n" );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'LPM_TEST_ROOT', dirname( __DIR__ ) );

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		unset( $domain );

		return (string) $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		unset( $domain );

		return (string) $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = strip_tags( $value );
		$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $value );

		return trim( (string) $value );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );

		return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( (array) $defaults, (array) $args );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return $GLOBALS['lpm_test_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['lpm_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( (string) $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.test/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		$separator = str_contains( $url, '?' ) ? '&' : '?';

		return $url . $separator . http_build_query( $args );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$text = strip_tags( (string) $text );

		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}

		return $text;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		if ( 'timestamp' === $type ) {
			return time();
		}

		return gmdate( 'Y-m-d H:i:s' );
	}
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Lilleprinsen\\PriceMonitor\\';

		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$file           = LPM_TEST_ROOT . '/src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

function lpm_test_fixture( string $name ): string {
	$path = LPM_TEST_ROOT . '/tests/fixtures/' . $name;

	if ( ! is_readable( $path ) ) {
		throw new RuntimeException( 'Missing fixture: ' . $name );
	}

	return (string) file_get_contents( $path );
}

function lpm_assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function lpm_assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			sprintf(
				'%s Expected %s, got %s.',
				$message,
				var_export( $expected, true ),
				var_export( $actual, true )
			)
		);
	}
}

function lpm_assert_float_equals( float $expected, $actual, string $message, float $delta = 0.0001 ): void {
	if ( ! is_numeric( $actual ) || abs( $expected - (float) $actual ) > $delta ) {
		throw new RuntimeException(
			sprintf(
				'%s Expected %.4f, got %s.',
				$message,
				$expected,
				var_export( $actual, true )
			)
		);
	}
}

function lpm_assert_contains( string $needle, string $haystack, string $message ): void {
	if ( ! str_contains( $haystack, $needle ) ) {
		throw new RuntimeException( $message . ' Missing text: ' . $needle );
	}
}

/**
 * @param array<string, callable> $tests Named tests.
 */
function lpm_run_tests( string $suite_name, array $tests ): void {
	$failures = 0;

	echo $suite_name . "\n";

	foreach ( $tests as $name => $test ) {
		try {
			$test();
			echo '  PASS ' . $name . "\n";
		} catch ( Throwable $throwable ) {
			$failures++;
			echo '  FAIL ' . $name . "\n";
			echo '       ' . $throwable->getMessage() . "\n";
		}
	}

	if ( $failures > 0 ) {
		echo sprintf( "%s failed with %d failure(s).\n", $suite_name, $failures );
		exit( 1 );
	}

	echo sprintf( "%s passed with %d test(s).\n", $suite_name, count( $tests ) );
}
