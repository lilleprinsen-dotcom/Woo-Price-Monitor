<?php
/**
 * Lightweight admin AJAX controller.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Database\Schema;
use Lilleprinsen\PriceMonitor\Notifications\NotificationService;
use Lilleprinsen\PriceMonitor\Plugin;
use Lilleprinsen\PriceMonitor\Service\PriceCheckService;
use Lilleprinsen\PriceMonitor\Service\SuggestionService;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminAjaxController {
	private Repository $repository;

	private Settings $settings;

	private ProductSearchService $product_search;

	private PriceCheckService $price_check;

	private SuggestionService $suggestions;

	private NotificationService $notifications;

	public function __construct( Repository $repository, Settings $settings, ProductSearchService $product_search, PriceCheckService $price_check, SuggestionService $suggestions, NotificationService $notifications ) {
		$this->repository     = $repository;
		$this->settings       = $settings;
		$this->product_search = $product_search;
		$this->price_check    = $price_check;
		$this->suggestions    = $suggestions;
		$this->notifications  = $notifications;
	}

	public function register(): void {
		$actions = array(
			'lpm_search_products'                => 'search_products',
			'lpm_add_product_to_monitoring'      => 'add_product_to_monitoring',
			'lpm_load_monitored_product_details' => 'load_monitored_product_details',
			'lpm_load_competitor_links'          => 'load_competitor_links',
			'lpm_save_competitor_link'           => 'save_competitor_link',
			'lpm_test_competitor_link'           => 'test_competitor_link',
			'lpm_create_suggestion'              => 'create_suggestion',
			'lpm_load_suggestion_details'        => 'load_suggestion_details',
			'lpm_approve_suggestion_dry_run'     => 'approve_suggestion_dry_run',
			'lpm_reject_suggestion'              => 'reject_suggestion',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

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

		$this->send_success( array( 'products' => $this->product_search->search( $query, 20 ) ) );
	}

	public function add_product_to_monitoring(): void {
		$this->guard();

		$product_id = $this->request_absint( 'product_id' );
		$product    = $this->get_product( $product_id );

		if ( ! $product ) {
			$this->send_error( __( 'Product not found.', 'lilleprinsen-price-monitor' ), 404 );
		}

		$sku    = method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';
		$result = $this->repository->add_monitored_product( $product_id, $sku );

		if ( empty( $result['success'] ) ) {
			$this->send_error( $this->message_for_monitoring_code( (string) ( $result['code'] ?? '' ) ), 400, $result );
		}

		$this->repository->write_log(
			'info',
			'monitored_product_added_ajax',
			__( 'Product added to monitoring from async search.', 'lilleprinsen-price-monitor' ),
			array( 'monitored_product_id' => (int) ( $result['id'] ?? 0 ) ),
			$product_id
		);

		$this->send_success(
			array(
				'message'              => $this->message_for_monitoring_code( (string) ( $result['code'] ?? '' ) ),
				'monitored_product_id' => (int) ( $result['id'] ?? 0 ),
				'product'              => $this->format_product( $product ),
			)
		);
	}

	public function load_monitored_product_details(): void {
		$this->guard();

		$monitored_product_id = $this->request_absint( 'monitored_product_id' );
		$monitored_product    = $this->repository->get_monitored_product( $monitored_product_id );

		if ( ! $monitored_product ) {
			$this->send_error( __( 'Monitored product not found.', 'lilleprinsen-price-monitor' ), 404 );
		}

		$product_id = absint( $monitored_product['product_id'] ?? 0 );
		$product    = $this->get_product( $product_id );
		$links      = $this->repository->get_competitor_links_for_monitored_product( $monitored_product_id );

		$this->send_success(
			array(
				'monitored_product' => $this->sanitize_row( $monitored_product ),
				'product'           => $product ? $this->format_product( $product ) : null,
				'competitor_links'  => $this->format_competitor_links( $links ),
				'profiles'          => $this->format_competitor_profiles( $this->repository->get_competitors( 1, 100 ) ),
				'observations'      => $this->format_observations( $this->repository->get_price_observations( array( 'product_id' => $product_id ), 1, 10 ) ),
				'suggestions'       => $this->format_suggestions( $this->repository->get_price_suggestions( array( 'view' => 'all', 'product_id' => $product_id ), 1, 10 ) ),
				'logs'              => $this->format_logs( $this->repository->get_logs( array( 'product_id' => $product_id ), 1, 10 ) ),
				'active_session'    => $this->sanitize_row( $this->repository->get_active_price_match_session_for_product( $product_id ) ),
			)
		);
	}

	public function load_competitor_links(): void {
		$this->guard();

		$monitored_product_id = $this->request_absint( 'monitored_product_id' );
		$monitored_product    = $this->repository->get_monitored_product( $monitored_product_id );

		if ( ! $monitored_product ) {
			$this->send_error( __( 'Monitored product not found.', 'lilleprinsen-price-monitor' ), 404 );
		}

		$this->send_success(
			array(
				'competitor_links' => $this->format_competitor_links( $this->repository->get_competitor_links_for_monitored_product( $monitored_product_id ) ),
				'profiles'         => $this->format_competitor_profiles( $this->repository->get_competitors( 1, 100 ) ),
			)
		);
	}

	public function save_competitor_link(): void {
		$this->guard();

		$monitored_product_id = $this->request_absint( 'monitored_product_id' );
		$link_id              = $this->request_absint( 'competitor_link_id' );
		$monitored_product    = $this->repository->get_monitored_product( $monitored_product_id );

		if ( ! $monitored_product ) {
			$this->send_error( __( 'Monitored product not found.', 'lilleprinsen-price-monitor' ), 404 );
		}

		$data = $this->get_competitor_link_request_data( $monitored_product_id );

		if ( isset( $data['error'] ) ) {
			$this->send_error( (string) $data['error'], 400 );
		}

		if ( $link_id > 0 ) {
			$link = $this->repository->get_competitor_link( $link_id );

			if ( ! $link || (int) $link['monitored_product_id'] !== $monitored_product_id ) {
				$this->send_error( __( 'Competitor link not found.', 'lilleprinsen-price-monitor' ), 404 );
			}

			$updated = $this->repository->update_competitor_link( $link_id, $data );

			if ( ! $updated ) {
				$this->send_error( __( 'Competitor link could not be updated.', 'lilleprinsen-price-monitor' ), 500 );
			}

			$event   = 'competitor_link_updated_ajax';
			$message = __( 'Competitor link updated.', 'lilleprinsen-price-monitor' );
		} else {
			$link_id = $this->repository->add_competitor_link( $data );

			if ( $link_id <= 0 ) {
				$this->send_error( __( 'Competitor link could not be added.', 'lilleprinsen-price-monitor' ), 500 );
			}

			$event   = 'competitor_link_added_ajax';
			$message = __( 'Competitor link added.', 'lilleprinsen-price-monitor' );
		}

		if ( ! $this->update_competitor_link_price_field_override( $link_id, $data['price_field_override'] ?? null ) ) {
			$this->send_error( __( 'Competitor price field choice could not be saved.', 'lilleprinsen-price-monitor' ), 500 );
		}

		$this->repository->write_log( 'info', $event, $message, array( 'competitor_link_id' => $link_id ), (int) $monitored_product['product_id'] );

		$this->send_success(
			array(
				'message'          => $message,
				'competitor_links' => $this->format_competitor_links( $this->repository->get_competitor_links_for_monitored_product( $monitored_product_id ) ),
			)
		);
	}

	public function test_competitor_link(): void {
		$this->guard();

		$link = $this->get_requested_competitor_link();
		$result = $this->price_check->test_check( $link, $this->settings->get_all() );
		$this->repository->update_competitor_check_result(
			(int) $link['id'],
			$result['success'] ? (float) $result['price'] : null,
			(string) $result['currency'],
			$result['success'] ? null : (string) $result['error'],
			$result['success'] ? (string) $result['stock_status'] : null
		);

		$monitored_product = $this->repository->get_monitored_product( (int) $link['monitored_product_id'] );
		$product_id        = $monitored_product ? (int) $monitored_product['product_id'] : null;

		$this->repository->write_log(
			! empty( $result['success'] ) ? 'info' : 'error',
			! empty( $result['success'] ) ? 'competitor_check_succeeded_ajax' : 'competitor_check_failed_ajax',
			! empty( $result['success'] ) ? __( 'Async competitor test check detected a price.', 'lilleprinsen-price-monitor' ) : __( 'Async competitor test check failed.', 'lilleprinsen-price-monitor' ),
			array(
				'competitor_link_id' => (int) $link['id'],
				'price'              => $result['price'],
				'currency'           => $result['currency'],
				'price_field'        => $result['price_field'] ?? '',
				'regular_price'      => $result['regular_price'] ?? null,
				'sale_price'         => $result['sale_price'] ?? null,
				'extraction_method'  => $result['extraction_method'],
				'error'              => $result['error'],
				'http_status'        => $result['http_status'],
				'response_time_ms'   => $result['response_time_ms'],
			),
			$product_id
		);

		$this->send_success(
			array(
				'result'           => $this->format_check_result( $result ),
				'message'          => ! empty( $result['success'] )
					? __( 'Detected price. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' )
					: __( 'Check failed. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ),
				'competitor_links' => $this->format_competitor_links( $this->repository->get_competitor_links_for_monitored_product( (int) $link['monitored_product_id'] ) ),
				'observations'     => $this->format_observations( $this->repository->get_recent_observations_for_competitor_link( (int) $link['id'], 5 ) ),
			)
		);
	}

	public function create_suggestion(): void {
		$this->guard();

		$link              = $this->get_requested_competitor_link();
		$monitored_product = $this->repository->get_monitored_product( (int) $link['monitored_product_id'] );

		if ( ! $monitored_product ) {
			$this->send_error( __( 'Monitored product not found.', 'lilleprinsen-price-monitor' ), 404 );
		}

		$product = $this->get_product( (int) $monitored_product['product_id'] );

		if ( ! $product ) {
			$this->send_error( __( 'WooCommerce product not found.', 'lilleprinsen-price-monitor' ), 404 );
		}

		$result = $this->suggestions->create_from_competitor_link( $monitored_product, $link, $product, $this->settings->get_all() );
		$status = (string) ( $result['status'] ?? 'error' );

		$this->repository->write_log(
			'error' === $status ? 'error' : ( 'blocked' === $status || 'skipped' === $status ? 'warning' : 'info' ),
			'price_suggestion_' . sanitize_key( $status ) . '_ajax',
			(string) ( $result['message'] ?? __( 'Dry-run price suggestion action completed.', 'lilleprinsen-price-monitor' ) ),
			array(
				'competitor_link_id' => (int) $link['id'],
				'suggestion_id'      => (int) ( $result['suggestion_id'] ?? 0 ),
				'status'             => $status,
			),
			(int) $monitored_product['product_id']
		);

		if ( in_array( $status, array( 'pending', 'blocked' ), true ) ) {
			$settings = $this->settings->get_all();
			$this->notifications->send(
				'price_suggestion_' . $status,
				__( 'Price Monitor would send a suggestion notification.', 'lilleprinsen-price-monitor' ),
				$settings,
				$result,
				(int) $monitored_product['product_id']
			);
		}

		$this->send_success(
			array(
				'result'      => $result,
				'message'     => (string) ( $result['message'] ?? __( 'Suggestion action completed.', 'lilleprinsen-price-monitor' ) ),
				'suggestions' => $this->format_suggestions( $this->repository->get_price_suggestions( array( 'view' => 'all', 'product_id' => (int) $monitored_product['product_id'] ), 1, 10 ) ),
			)
		);
	}

	public function load_suggestion_details(): void {
		$this->guard();

		$suggestion_id = $this->request_absint( 'suggestion_id' );
		$suggestion    = $this->repository->get_price_suggestion( $suggestion_id );

		if ( ! $suggestion ) {
			$this->send_error( __( 'Suggestion not found.', 'lilleprinsen-price-monitor' ), 404 );
		}

		$product_id = absint( $suggestion['product_id'] ?? 0 );
		$product    = $this->get_product( $product_id );

		$this->send_success(
			array(
				'suggestion'     => $this->format_suggestion( $suggestion ),
				'product'        => $product ? $this->format_product( $product ) : null,
				'active_session' => $this->sanitize_row( $this->repository->get_active_price_match_session_for_product( $product_id ) ),
			)
		);
	}

	public function approve_suggestion_dry_run(): void {
		$this->guard();

		$suggestion = $this->get_reviewable_suggestion( array( 'pending' ) );
		$updated    = $this->repository->approve_suggestion_dry_run( (int) $suggestion['id'], get_current_user_id() );

		if ( ! $updated ) {
			$this->send_error( __( 'Dry-run approval could not be recorded.', 'lilleprinsen-price-monitor' ), 500 );
		}

		$this->repository->write_log( 'info', 'suggestion_approved_dry_run_ajax', __( 'Dry-run suggestion approval recorded from pricing inbox.', 'lilleprinsen-price-monitor' ), array( 'suggestion_id' => (int) $suggestion['id'] ), (int) $suggestion['product_id'] );

		$this->send_success(
			array(
				'message'    => __( 'Dry-run approval recorded. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ),
				'suggestion' => $this->format_suggestion( $this->repository->get_price_suggestion( (int) $suggestion['id'] ) ),
			)
		);
	}

	public function reject_suggestion(): void {
		$this->guard();

		$suggestion = $this->get_reviewable_suggestion( array( 'pending', 'blocked' ) );
		$updated    = $this->repository->reject_suggestion( (int) $suggestion['id'], get_current_user_id() );

		if ( ! $updated ) {
			$this->send_error( __( 'Suggestion could not be rejected.', 'lilleprinsen-price-monitor' ), 500 );
		}

		$this->repository->write_log( 'info', 'suggestion_rejected_ajax', __( 'Suggestion rejected from pricing inbox.', 'lilleprinsen-price-monitor' ), array( 'suggestion_id' => (int) $suggestion['id'] ), (int) $suggestion['product_id'] );

		$this->send_success(
			array(
				'message'    => __( 'Suggestion rejected. WooCommerce price was not changed.', 'lilleprinsen-price-monitor' ),
				'suggestion' => $this->format_suggestion( $this->repository->get_price_suggestion( (int) $suggestion['id'] ) ),
			)
		);
	}

	private function guard(): void {
		if ( ! check_ajax_referer( 'lpm_admin_ajax', 'nonce', false ) ) {
			$this->send_error( __( 'Security check failed.', 'lilleprinsen-price-monitor' ), 403 );
		}

		if ( ! Plugin::can_manage() ) {
			$this->send_error( __( 'You do not have permission to manage Lilleprinsen Price Monitor.', 'lilleprinsen-price-monitor' ), 403 );
		}
	}

	private function request_absint( string $key ): int {
		return isset( $_REQUEST[ $key ] ) ? absint( wp_unslash( $_REQUEST[ $key ] ) ) : 0;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_competitor_link_request_data( int $monitored_product_id ): array {
		$competitor_id        = $this->request_absint( 'competitor_id' );
		$profile              = $competitor_id > 0 ? $this->repository->get_competitor( $competitor_id ) : null;
		$name                 = isset( $_REQUEST['competitor_name'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['competitor_name'] ) ) : '';
		$url                  = isset( $_REQUEST['competitor_url'] ) ? esc_url_raw( wp_unslash( $_REQUEST['competitor_url'] ) ) : '';
		$match_type           = isset( $_REQUEST['match_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['match_type'] ) ) : 'unknown';
		$price_field_override = isset( $_REQUEST['price_field_override'] ) ? $this->sanitize_price_field_override( wp_unslash( $_REQUEST['price_field_override'] ) ) : null;

		if ( $competitor_id > 0 && ! $profile ) {
			return array( 'error' => __( 'Competitor profile not found.', 'lilleprinsen-price-monitor' ) );
		}

		if ( '' === $name && $profile ) {
			$name = (string) $profile['name'];
		}

		if ( '' === $name ) {
			return array( 'error' => __( 'Competitor name is required.', 'lilleprinsen-price-monitor' ) );
		}

		if ( ! $this->is_valid_http_url( $url ) ) {
			return array( 'error' => __( 'Competitor URL must be a valid http/https URL.', 'lilleprinsen-price-monitor' ) );
		}

		return array(
			'monitored_product_id' => $monitored_product_id,
			'competitor_id'        => $competitor_id,
			'competitor_name'      => $name,
			'competitor_url'       => $url,
			'match_type'           => $match_type,
			'price_field_override' => $price_field_override,
			'enabled'              => ! empty( $_REQUEST['enabled'] ) ? 1 : 0,
			'is_primary'           => ! empty( $_REQUEST['is_primary'] ) ? 1 : 0,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_requested_competitor_link(): array {
		$link_id = $this->request_absint( 'competitor_link_id' );
		$link    = $this->repository->get_competitor_link( $link_id );

		if ( ! $link ) {
			$this->send_error( __( 'Competitor link not found.', 'lilleprinsen-price-monitor' ), 404 );
		}

		return $link;
	}

	/**
	 * @param array<int, string> $allowed_statuses Allowed statuses.
	 * @return array<string, mixed>
	 */
	private function get_reviewable_suggestion( array $allowed_statuses ): array {
		$suggestion_id = $this->request_absint( 'suggestion_id' );
		$suggestion    = $this->repository->get_price_suggestion( $suggestion_id );

		if ( ! $suggestion ) {
			$this->send_error( __( 'Suggestion not found.', 'lilleprinsen-price-monitor' ), 404 );
		}

		if ( ! in_array( (string) ( $suggestion['status'] ?? '' ), $allowed_statuses, true ) ) {
			$this->send_error( __( 'Suggestion is not in a reviewable state.', 'lilleprinsen-price-monitor' ), 400 );
		}

		return $suggestion;
	}

	private function get_product( int $product_id ): ?object {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		return is_object( $product ) ? $product : null;
	}

	private function format_product( object $product ): array {
		$product_id = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		$sku        = method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';

		return array(
			'id'           => $product_id,
			'name'         => method_exists( $product, 'get_name' ) ? sanitize_text_field( (string) $product->get_name() ) : __( 'Untitled product', 'lilleprinsen-price-monitor' ),
			'sku'          => $sku,
			'price'        => method_exists( $product, 'get_price' ) ? (string) $product->get_price() : '',
			'price_html'   => method_exists( $product, 'get_price_html' ) ? wp_kses_post( (string) $product->get_price_html() ) : '',
			'stock_status' => method_exists( $product, 'get_stock_status' ) ? sanitize_key( (string) $product->get_stock_status() ) : '',
			'thumbnail'    => $this->get_product_thumbnail( $product ),
			'edit_url'     => $product_id > 0 ? get_edit_post_link( $product_id, 'raw' ) : '',
		);
	}

	private function get_product_thumbnail( object $product ): string {
		if ( ! method_exists( $product, 'get_image_id' ) ) {
			return '<span class="lpm-thumb-placeholder"></span>';
		}

		$image_id = (int) $product->get_image_id();

		if ( $image_id <= 0 ) {
			return '<span class="lpm-thumb-placeholder"></span>';
		}

		$image = wp_get_attachment_image( $image_id, array( 48, 48 ), false, array( 'class' => 'lpm-product-thumb' ) );

		return is_string( $image ) && '' !== $image ? wp_kses_post( $image ) : '<span class="lpm-thumb-placeholder"></span>';
	}

	/**
	 * @param array<int, array<string, mixed>> $links Competitor links.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_competitor_links( array $links ): array {
		return array_map(
			function ( array $link ): array {
				$link          = $this->sanitize_row( $link );
				$profile_field = 'sale_price_first';
				$profile       = ! empty( $link['competitor_id'] ) ? $this->repository->get_competitor( (int) $link['competitor_id'] ) : null;

				if ( $profile && ! empty( $profile['monitored_price_field'] ) ) {
					$profile_field = $this->sanitize_price_field( $profile['monitored_price_field'] );
				}

				$override = $this->sanitize_price_field_override( $link['price_field_override'] ?? '' );
				$effective = $override ?: $profile_field;

				$link['price_field_override']       = $override;
				$link['price_field_override_label'] = $override ? $this->get_price_field_label( $override ) : '';
				$link['profile_price_field']        = $profile_field;
				$link['profile_price_field_label']  = $this->get_price_field_label( $profile_field );
				$link['effective_price_field']      = $effective;
				$link['effective_price_field_label'] = $this->get_price_field_label( $effective );
				$link['recent_observations']        = $this->format_observations( $this->repository->get_recent_observations_for_competitor_link( (int) $link['id'], 5 ) );

				return $link;
			},
			$links
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $profiles Competitor profiles.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_competitor_profiles( array $profiles ): array {
		return array_map(
			function ( array $profile ): array {
				$profile = $this->sanitize_row( $profile );
				$field   = $this->sanitize_price_field( $profile['monitored_price_field'] ?? 'sale_price_first' );

				$profile['monitored_price_field']       = $field;
				$profile['monitored_price_field_label'] = $this->get_price_field_label( $field );

				return $profile;
			},
			$profiles
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $observations Observations.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_observations( array $observations ): array {
		return array_map(
			function ( array $observation ): array {
				$observation = $this->sanitize_row( $observation );
				$field       = $this->sanitize_price_field( $observation['price_field'] ?? '' );

				$observation['price_field_label'] = '' !== $field ? $this->get_price_field_label( $field ) : '';

				return $observation;
			},
			$observations
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $suggestions Suggestions.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_suggestions( array $suggestions ): array {
		return array_map( array( $this, 'format_suggestion' ), $suggestions );
	}

	/**
	 * @param array<string, mixed>|null $suggestion Suggestion row.
	 * @return array<string, mixed>|null
	 */
	private function format_suggestion( ?array $suggestion ): ?array {
		if ( ! $suggestion ) {
			return null;
		}

		$suggestion = $this->sanitize_row( $suggestion );

		foreach ( array( 'rule_details', 'warnings' ) as $json_key ) {
			if ( ! empty( $suggestion[ $json_key ] ) && is_string( $suggestion[ $json_key ] ) ) {
				$decoded = json_decode( $suggestion[ $json_key ], true );

				if ( is_array( $decoded ) ) {
					$suggestion[ $json_key . '_parsed' ] = $decoded;
				}
			}
		}

		return $suggestion;
	}

	/**
	 * @param array<int, array<string, mixed>> $logs Logs.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_logs( array $logs ): array {
		return array_map( array( $this, 'sanitize_row' ), $logs );
	}

	/**
	 * @param array<string, mixed> $result Check result.
	 * @return array<string, mixed>
	 */
	private function format_check_result( array $result ): array {
		$result = $this->sanitize_row( $result );
		$field  = $this->sanitize_price_field( $result['price_field'] ?? '' );

		$result['price_field_label'] = '' !== $field ? $this->get_price_field_label( $field ) : '';

		return $result;
	}

	/**
	 * @param mixed $override Raw override.
	 */
	private function sanitize_price_field_override( $override ): ?string {
		$field = sanitize_key( (string) $override );

		if ( '' === $field || 'profile_default' === $field ) {
			return null;
		}

		return $this->sanitize_price_field( $field );
	}

	/**
	 * @param mixed $field Raw field.
	 */
	private function sanitize_price_field( $field ): string {
		$field = sanitize_key( (string) $field );

		return in_array( $field, array_keys( $this->get_price_field_options() ), true ) ? $field : 'sale_price_first';
	}

	/**
	 * @return array<string, string>
	 */
	private function get_price_field_options(): array {
		return array(
			'sale_price_first' => __( 'Sale price first', 'lilleprinsen-price-monitor' ),
			'sale_price'       => __( 'Sale price only', 'lilleprinsen-price-monitor' ),
			'regular_price'    => __( 'Regular price only', 'lilleprinsen-price-monitor' ),
			'price_selector'   => __( 'Current price selector', 'lilleprinsen-price-monitor' ),
			'lowest_price'     => __( 'Lowest detected price', 'lilleprinsen-price-monitor' ),
		);
	}

	private function get_price_field_label( string $field ): string {
		$options = $this->get_price_field_options();

		return $options[ $field ] ?? $options['sale_price_first'];
	}

	private function update_competitor_link_price_field_override( int $link_id, ?string $override ): bool {
		global $wpdb;

		if ( $link_id <= 0 ) {
			return false;
		}

		$tables = Schema::table_names();
		$table  = $tables['competitor_links'];

		if ( ! $this->table_has_column( $table, 'price_field_override' ) ) {
			return true;
		}

		$result = $wpdb->update(
			$table,
			array(
				'price_field_override' => $override,
				'updated_at'           => current_time( 'mysql' ),
			),
			array( 'id' => $link_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	private function table_has_column( string $table, string $column ): bool {
		global $wpdb;

		$result = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );

		return $column === $result;
	}

	/**
	 * @param array<string, mixed>|null $row Database row.
	 * @return array<string, mixed>|null
	 */
	private function sanitize_row( ?array $row ): ?array {
		if ( null === $row ) {
			return null;
		}

		$sanitized = array();

		foreach ( $row as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $value;
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$sanitized[ $key ] = $value;
			} elseif ( null === $value ) {
				$sanitized[ $key ] = null;
			} else {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}

	private function message_for_monitoring_code( string $code ): string {
		$messages = array(
			'monitoring_added'     => __( 'Product added to monitoring.', 'lilleprinsen-price-monitor' ),
			'monitoring_reenabled' => __( 'Product monitoring re-enabled.', 'lilleprinsen-price-monitor' ),
			'already_monitored'    => __( 'Product is already monitored.', 'lilleprinsen-price-monitor' ),
			'invalid_product'      => __( 'Invalid product.', 'lilleprinsen-price-monitor' ),
			'missing_table'        => __( 'Monitoring table is missing.', 'lilleprinsen-price-monitor' ),
		);

		return $messages[ $code ] ?? __( 'Monitoring action completed.', 'lilleprinsen-price-monitor' );
	}

	private function is_valid_http_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& ! empty( $parts['host'] )
			&& ! empty( $parts['scheme'] )
			&& in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * @param array<string, mixed> $data Response payload.
	 */
	private function send_success( array $data = array() ): void {
		wp_send_json_success( $data );
	}

	/**
	 * @param array<string, mixed> $extra Extra payload.
	 */
	private function send_error( string $message, int $status_code = 400, array $extra = array() ): void {
		wp_send_json_error( array_merge( array( 'message' => $message ), $extra ), $status_code );
	}
}
