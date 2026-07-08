<?php
/**
 * Local regression tests for simplified admin UX routing.
 *
 * @package LilleprinsenPriceMonitor
 */

declare(strict_types=1);

require __DIR__ . '/test-bootstrap.php';
require LPM_TEST_ROOT . '/src/Admin/AdminPage.php';

use Lilleprinsen\PriceMonitor\Admin\AdminPage;

function assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function assert_not_contains( string $needle, string $haystack, string $message ): void {
	if ( false !== strpos( $haystack, $needle ) ) {
		fwrite( STDERR, $message . "\nUnexpected text: " . $needle . "\n" );
		exit( 1 );
	}
}

$reflection = new ReflectionClass( AdminPage::class );
$page       = $reflection->newInstanceWithoutConstructor();

$get_tabs = $reflection->getMethod( 'get_tabs' );
$tabs = $get_tabs->invoke( $page );

assert_same(
	array( 'dashboard', 'products', 'competitors', 'approvals', 'settings_logs' ),
	array_keys( $tabs ),
	'Unified admin should expose exactly five primary tabs.'
);

$get_active_tab = $reflection->getMethod( 'get_active_tab' );

foreach ( array( 'settings', 'logs', 'history', 'import_export', 'groups' ) as $legacy_tab ) {
	$_GET['tab'] = $legacy_tab;
	assert_same( 'settings_logs', $get_active_tab->invoke( $page, $tabs ), 'Legacy tab should route to Settings & Logs: ' . $legacy_tab );
}

$_GET['tab'] = 'competitors';
assert_same( 'competitors', $get_active_tab->invoke( $page, $tabs ), 'Competitors tab should remain directly addressable.' );

$_GET['tab'] = 'unknown';
assert_same( 'dashboard', $get_active_tab->invoke( $page, $tabs ), 'Unknown tab should fall back to Overview.' );

$normalize_redirect_tab = $reflection->getMethod( 'normalize_redirect_tab' );

assert_same( 'settings_logs', $normalize_redirect_tab->invoke( $page, 'history' ), 'History redirects should land in Settings & Logs.' );
assert_same( 'products', $normalize_redirect_tab->invoke( $page, 'products' ), 'Products redirects should not be changed.' );

$admin_source = file_get_contents( LPM_TEST_ROOT . '/src/Admin/AdminPage.php' );
$shell_source = file_get_contents( LPM_TEST_ROOT . '/templates/admin/app-shell.php' );

assert_not_contains( 'render_placeholder_panel', $admin_source, 'Placeholder panels should not remain in the unified admin page.' );
assert_not_contains( "render_embedded( 'products' )", $admin_source, 'Products tab should not embed the legacy discovery products page.' );
assert_not_contains( "render_embedded( 'competitors' )", $admin_source, 'Competitors tab should not embed the legacy discovery competitors page.' );
assert_not_contains( "render_embedded( 'suggestions' )", $admin_source, 'Suggestions tab should not embed the legacy suggested matches page.' );
assert_not_contains( 'Products selected for competitor matching', $admin_source, 'Products tab should not render a duplicate discovery product section.' );
assert_not_contains( 'Suggested competitor product matches', $admin_source, 'Suggestions tab should not render a separate match suggestion section.' );
assert_not_contains( 'data-lpm-tab-panel="groups"', $shell_source, 'Removed Groups tab panel should not remain in the app shell.' );

echo "Admin UX routing tests passed.\n";
