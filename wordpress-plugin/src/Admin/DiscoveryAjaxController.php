<?php
/**
 * AJAX controller for live competitor discovery.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

use Lilleprinsen\PriceMonitor\Database\DiscoveryRepository;
use Lilleprinsen\PriceMonitor\Plugin;
use Lilleprinsen\PriceMonitor\Service\ManualDiscoveryService;
use Lilleprinsen\PriceMonitor\Service\ProductIdentifierService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles manual discovery run lifecycle requests.
 */
class DiscoveryAjaxController {
	private ManualDiscoveryService $manual_discovery;
	private ProductSearchService $product_search;
	private DiscoveryRepository $discovery_repository;
	private ProductIdentifierService $identifiers;

	/** Constructor. */
	public function __construct( ManualDiscoveryService $manual_discovery, ProductSearchService $product_search, DiscoveryRepository $discovery_repository, ProductIdentifierService $identifiers ) {
		$this->manual_discovery     = $manual_discovery;
		$this->product_search       = $product_search;
		$this->discovery_repository = $discovery_repository;
		$this->identifiers          = $identifiers;
	}

	/** Register AJAX hooks. */
	public function register(): void {
		$actions = array(
			'lpm_manual_discovery_create'  => 'create_run',
			'lpm_manual_discovery_process' => 'process_run',
			'lpm_manual_discovery_cancel'  => 'cancel_run',
			'lpm_manual_discovery_retest'  => 'retest_run',
			'lpm_manual_discovery_approve' => 'approve_suggestion',
			'lpm_manual_discovery_reject'  => 'reject_suggestion',
			'lpm_discovery_search_products' => 'search_products',
			'lpm_discovery_add_product'    => 'add_product',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	/** Search WooCommerce products for quick discovery selection. */
	public function search_products(): void {
		$this->guard();

		$query = isset( $_REQUEST['query'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['query'] ) ) : '';
		if ( '' === trim( $query ) ) {
			$this->send_success( array( 'products' => array() ) );
		}

		if ( ! is_numeric( $query ) && strlen( trim( $query ) ) < 3 ) {
			$this->send_success(
				array(
					'products' => array(),
					'message'  => __( 'Type at least 3 characters, or enter a numeric product ID.', 'lilleprinsen-price-monitor' ),
				)
			);
		}

		$this->send_success(
			array(
				'products' => $this->product_search->search( $query, 20 ),
				'message'  => __( 'Choose a product below to add it to competitor discovery.', 'lilleprinsen-price-monitor' ),
			)
		);
	}

	/** Add one searched WooCommerce product to selected competitor discovery products. */
	public function add_product(): void {
		$this->guard();

		$product_id = $this->request_absint( 'product_id' );
		$product    = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product || ! is_object( $product ) ) {
			$this->send_error( __( 'Product not found.', 'lilleprinsen-price-monitor' ), 404 );
		}

		$variation_id = method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;
		$parent_id    = $variation_id > 0 && method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : ( method_exists( $product, 'get_id' ) ? (int) $product->get_id() : $product_id );
		$existing     = $this->discovery_repository->get_discovery_product_by_product_id( $parent_id, $variation_id );
		$selected_id  = $this->discovery_repository->upsert_discovery_product( $parent_id, $variation_id, $this->identifiers->get_for_product( $product ) );

		if ( $selected_id <= 0 ) {
			$this->send_error( __( 'Product could not be added to competitor discovery.', 'lilleprinsen-price-monitor' ), 500 );
		}

		$this->send_success(
			array(
				'message'              => $existing && (int) $existing->enabled === 1 ? __( 'Product was already selected for competitor discovery.', 'lilleprinsen-price-monitor' ) : __( 'Product added to competitor discovery.', 'lilleprinsen-price-monitor' ),
				'discovery_product_id' => $selected_id,
				'product_id'           => $parent_id,
				'variation_id'         => $variation_id,
				'product_label'        => $this->product_label( $product ),
			)
		);
	}

	/** Cancel a running manual discovery run. */
	public function cancel_run(): void {
		$this->guard();

		$result = $this->manual_discovery->cancel_run( $this->request_run_id() );
		if ( empty( $result['success'] ) ) {
			$this->send_error( (string) ( $result['message'] ?? __( 'Manual discovery run could not be cancelled.', 'lilleprinsen-price-monitor' ) ), 404 );
		}

		$this->send_success( $result );
	}

	/** Create a targeted one-product/one-competitor retest run. */
	public function retest_run(): void {
		$this->guard();

		$result = $this->manual_discovery->create_retest_run( $this->request_absint( 'discovery_product_id' ), $this->request_absint( 'competitor_id' ) );
		if ( empty( $result['success'] ) ) {
			$this->send_error( (string) ( $result['message'] ?? __( 'Retest could not be started.', 'lilleprinsen-price-monitor' ) ), 400 );
		}

		$this->send_success( array( 'run' => $result ) );
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

		$run_id = $this->request_run_id();
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

	private function request_run_id(): string {
		return isset( $_REQUEST['run_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['run_id'] ) ) : '';
	}

	private function product_label( object $product ): string {
		$name = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : __( 'Selected product', 'lilleprinsen-price-monitor' );
		$sku  = method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';

		return '' !== $sku ? sprintf( '%1$s - %2$s', $name, $sku ) : $name;
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
