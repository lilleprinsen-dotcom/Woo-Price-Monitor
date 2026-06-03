<?php
/**
 * Conservative uninstall handler.
 *
 * @package LilleprinsenPriceMonitor
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'lpm_settings' );
delete_option( 'lpm_schema_version' );
