<?php
/**
 * AJAX controller for live competitor discovery.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

use Lilleprinsen\PriceMonitor\Plugin;
use Lilleprinsen\PriceMonitor\Service\ManualDiscoveryService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles manual discovery run lifecycle requests.
 */
class DiscoveryAjaxController {
	private ManualDiscoveryService $manual_discovery;

	/** Constructor. */
	public function __construct( ManualDiscoveryService $manual_discovery ) {
		$this->manual_discovery = $manual_discovery;
	}

	/** Register AJAX hooks. */
	public function register(): void {
		$actions = array(
			'lpm_manual_discovery_create'  => 'create_run',
			'lpm_manual_discovery_process' => 'process_run',
			'lpm_manual_discovery_approve' => 'approve_suggestion',
			'lpm_manual_discovery_reject'  => 'reject_suggestion',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	/** Create a manual discovery run. */
	public function create_run(): void {
		$this->guard();

		$run = $this->manual_discovery->create_run( $this->request_absint( 'discovery_product_id' ), $this->request_absint( 'competitor_id' ) );
		$this->send_success( array( 'run' => $run ) );
	}

	/** Process the next batch. */
	public function process_run(): void {
		$this->guard();

		$run_id = isset( $_REQUEST['run_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['run_id'] ) ) : '';
		if ( '' === $run_id ) {
			$this->send_error( __( 'Manual discovery run is missing.', 'lilleprinsen-price-monitor' ), 400 );
		}

		$result = $this->manual_discovery->process_batch( $run_id, $this->request_absint( 'batch_size' ) ?: 1 );
		if ( empty( $result['success'] ) ) {
			$this->send_error( (string) ( $result['message'] ?? __( 'Manual discovery run failed.', 'lilleprinsen-price-monitor' ) ), 404 );
		}

		$this->send_success( $result );
	}

	/** Approve a live result suggestion. */
	public function approve_suggestion(): void {
		$this->guard();

		$result = $this->manual_discovery->approve_suggestion( $this->request_absint( 'suggestion_id' ), get_current_user_id() );
		if ( empty( $result['success'] ) ) {
			$this->send_error( (string) ( $result['message'] ?? __( 'Suggestion could not be approved.', 'lilleprinsen-price-monitor' ) ), 400 );
		}

		$this->send_success( $result );
	}

	/** Reject a live result suggestion. */
	public function reject_suggestion(): void {
		$this->guard();

		$result = $this->manual_discovery->reject_suggestion( $this->request_absint( 'suggestion_id' ), get_current_user_id() );
		if ( empty( $result['success'] ) ) {
			$this->send_error( (string) ( $result['message'] ?? __( 'Suggestion could not be rejected.', 'lilleprinsen-price-monitor' ) ), 400 );
		}

		$this->send_success( $result );
	}

	private function guard(): void {
		if ( ! check_ajax_referer( 'lpm_discovery_ajax', 'nonce', false ) ) {
			$this->send_error( __( 'Security check failed.', 'lilleprinsen-price-monitor' ), 403 );
		}

		if ( ! Plugin::can_manage() ) {
			$this->send_error( __( 'You do not have permission to manage competitor discovery.', 'lilleprinsen-price-monitor' ), 403 );
		}
	}

	private function request_absint( string $key ): int {
		return isset( $_REQUEST[ $key ] ) ? absint( wp_unslash( $_REQUEST[ $key ] ) ) : 0;
	}

	/** @param array<string,mixed> $data Response data. */
	private function send_success( array $data = array() ): void {
		wp_send_json_success( $data );
	}

	/** @param array<string,mixed> $extra Extra response data. */
	private function send_error( string $message, int $status_code = 400, array $extra = array() ): void {
		wp_send_json_error( array_merge( array( 'message' => $message ), $extra ), $status_code );
	}
}
