<?php
/**
 * Admin POST action router.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

use Lilleprinsen\PriceMonitor\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminActionHandler {
	private AdminPage $page;

	public function __construct( AdminPage $page ) {
		$this->page = $page;
	}

	public function handle(): void {
		if ( empty( $_POST['lpm_action'] ) ) {
			return;
		}

		if ( ! Plugin::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage Lilleprinsen Price Monitor.', 'lilleprinsen-price-monitor' ) );
		}

		check_admin_referer( 'lpm_admin_action', 'lpm_nonce' );

		$action = sanitize_key( wp_unslash( $_POST['lpm_action'] ) );
		$this->route( $action );
	}

	private function route( string $action ): void {
		switch ( $action ) {
			case 'add_monitored_product':
				$this->page->handle_add_monitored_product();
				break;
			case 'enable_monitored':
			case 'disable_monitored':
			case 'remove_monitored':
				$this->page->handle_monitored_status_action( $action );
				break;
			case 'update_monitored_rules':
				$this->page->handle_update_monitored_rules();
				break;
			case 'bulk_monitored_products':
				$this->page->handle_bulk_monitored_products();
				break;
			case 'add_competitor_link':
			case 'update_competitor_link':
				$this->page->handle_save_competitor_link( $action );
				break;
			case 'enable_competitor_link':
			case 'disable_competitor_link':
			case 'delete_competitor_link':
				$this->page->handle_competitor_link_action( $action );
				break;
			case 'bulk_competitor_links':
				$this->page->handle_bulk_competitor_links();
				break;
			case 'add_competitor_profile':
			case 'update_competitor_profile':
				$this->page->handle_save_competitor_profile( $action );
				break;
			case 'enable_competitor_profile':
			case 'disable_competitor_profile':
				$this->page->handle_competitor_profile_status_action( $action );
				break;
			case 'test_competitor_profile_url':
				$this->page->handle_test_competitor_profile_url();
				break;
			case 'test_competitor_check':
				$this->page->handle_test_competitor_check();
				break;
			case 'preview_csv_import':
				$this->page->handle_preview_csv_import();
				break;
			case 'confirm_csv_import':
				$this->page->handle_confirm_csv_import();
				break;
			case 'download_csv_template':
				$this->page->handle_download_csv_template();
				break;
			case 'export_csv':
				$this->page->handle_export_csv();
				break;
			case 'create_price_suggestion':
				$this->page->handle_create_price_suggestion();
				break;
			case 'approve_suggestion_dry_run':
				$this->page->handle_approve_suggestion_dry_run();
				break;
			case 'reject_suggestion':
				$this->page->handle_reject_suggestion();
				break;
			case 'update_suggested_price':
				$this->page->handle_update_suggested_price();
				break;
			case 'run_small_check_batch_now':
				$this->page->handle_run_small_check_batch_now();
				break;
			case 'send_test_notification':
				$this->page->handle_send_test_notification();
				break;
			case 'send_test_webhook':
				$this->page->handle_send_test_webhook();
				break;
			case 'run_retention_cleanup':
				$this->page->handle_run_retention_cleanup();
				break;
			case 'end_price_match_session':
				$this->page->handle_end_price_match_session();
				break;
			case 'approve_and_update_price':
				$this->page->handle_approve_and_update_price();
				break;
			default:
				$this->page->redirect_to_tab( 'dashboard', 'unknown_action' );
		}
	}
}
