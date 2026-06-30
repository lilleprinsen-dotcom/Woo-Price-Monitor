<?php
/**
 * WooCommerce product admin integration for discovery selection.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

use Lilleprinsen\PriceMonitor\Database\DiscoveryRepository;
use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Service\CompetitorProductExtractor;
use Lilleprinsen\PriceMonitor\Service\ProductIdentifierService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds product-level controls for discovery inclusion and manual URL testing.
 */
class DiscoveryProductAdmin {
	private Repository $repository;
	private DiscoveryRepository $discovery_repository;
	private ProductIdentifierService $identifiers;
	private CompetitorProductExtractor $extractor;

	/** Constructor. */
	public function __construct( Repository $repository, DiscoveryRepository $discovery_repository, ProductIdentifierService $identifiers, CompetitorProductExtractor $extractor ) {
		$this->repository           = $repository;
		$this->discovery_repository = $discovery_repository;
		$this->identifiers          = $identifiers;
		$this->extractor            = $extractor;
	}

	/** Register hooks. */
	public function register(): void {
		add_action( 'add_meta_boxes_product', array( $this, 'add_metabox' ) );
		add_action( 'save_post_product', array( $this, 'save_metabox' ), 20, 2 );
		add_filter( 'bulk_actions-edit-product', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
	}

	/** Add product metabox. */
	public function add_metabox(): void {
		add_meta_box( 'lpm_discovery_product', __( 'Competitor Price Assistant', 'lilleprinsen-price-monitor' ), array( $this, 'render_metabox' ), 'product', 'side', 'default' );
	}

	/** Render metabox. */
	public function render_metabox( \WP_Post $post ): void {
		wp_nonce_field( 'lpm_save_discovery_product', 'lpm_discovery_product_nonce' );
		$selected    = $this->discovery_repository->get_discovery_product_by_product_id( (int) $post->ID );
		$checked     = $selected && (int) $selected->enabled === 1;
		$competitors = $this->repository->get_competitors( 1, 200 );
		?>
		<p><label><input type="checkbox" name="lpm_include_in_discovery" value="1" <?php checked( $checked ); ?> /> <?php esc_html_e( 'Include in competitor discovery', 'lilleprinsen-price-monitor' ); ?></label></p>
		<p class="description"><?php esc_html_e( 'Only selected products are used when finding competitor matches.', 'lilleprinsen-price-monitor' ); ?></p>
		<hr>
		<p><strong><?php esc_html_e( 'Add competitor URL', 'lilleprinsen-price-monitor' ); ?></strong></p>
		<p><select name="lpm_manual_competitor_id" style="width:100%;"><option value="0"><?php esc_html_e( 'Choose competitor', 'lilleprinsen-price-monitor' ); ?></option><?php foreach ( $competitors as $competitor ) : ?><option value="<?php echo esc_attr( (string) $competitor['id'] ); ?>"><?php echo esc_html( (string) $competitor['name'] ); ?></option><?php endforeach; ?></select></p>
		<p><input type="url" name="lpm_manual_competitor_url" class="widefat" placeholder="https://competitor.no/product" /></p>
		<p><label><input type="checkbox" name="lpm_manual_add_unverified" value="1" /> <?php esc_html_e( 'Add even if unverified', 'lilleprinsen-price-monitor' ); ?></label></p>
		<p class="description"><?php esc_html_e( 'When you save the product, the assistant tests the URL and adds it to regular monitoring if accepted.', 'lilleprinsen-price-monitor' ); ?></p>
		<?php
	}

	/** Save metabox. */
	public function save_metabox( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['lpm_discovery_product_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lpm_discovery_product_nonce'] ) ), 'lpm_save_discovery_product' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$existing = $this->discovery_repository->get_discovery_product_by_product_id( $post_id );
		$include  = ! empty( $_POST['lpm_include_in_discovery'] );
		if ( $include ) {
			$this->discovery_repository->upsert_discovery_product( $post_id, 0, $this->identifiers->get_for_product_id( $post_id ) );
		} elseif ( $existing ) {
			$this->discovery_repository->set_discovery_product_enabled( (int) $existing->id, false );
		}

		$this->handle_manual_url( $post_id );
	}

	/** Manual competitor URL add flow. */
	private function handle_manual_url( int $product_id ): void {
		$url           = isset( $_POST['lpm_manual_competitor_url'] ) ? esc_url_raw( wp_unslash( $_POST['lpm_manual_competitor_url'] ) ) : '';
		$competitor_id = absint( $_POST['lpm_manual_competitor_id'] ?? 0 );
		if ( '' === $url || $competitor_id <= 0 ) {
			return;
		}

		$competitor = $this->repository->get_competitor( $competitor_id );
		if ( ! $competitor ) {
			$this->set_user_notice( __( 'Failed: competitor was not found.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}

		$result = $this->extractor->test_url( $url, $competitor );
		if ( empty( $result['success'] ) ) {
			$this->set_user_notice( __( 'Failed: we could not read this competitor page.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}

		$ids        = $this->identifiers->get_for_product_id( $product_id );
		$safe_match = ( '' !== $ids['normalized_gtin'] && $ids['normalized_gtin'] === (string) ( $result['normalized_gtin'] ?? '' ) ) || ( '' !== $ids['normalized_sku'] && $ids['normalized_sku'] === (string) ( $result['normalized_sku'] ?? '' ) );
		$accepted   = $safe_match || ! empty( $_POST['lpm_manual_add_unverified'] );
		if ( ! $accepted ) {
			$this->set_user_notice( __( 'Unverified match: we could not find this product’s SKU/EAN on the competitor page. Check “Add even if unverified” to add anyway.', 'lilleprinsen-price-monitor' ), 'warning' );
			return;
		}

		$monitored = $this->repository->get_monitored_product_by_product_id( $product_id );
		$created   = $monitored ? array() : $this->repository->add_monitored_product( $product_id, $ids['sku'] );
		$monitored_id = self::monitored_product_id_from_result( $monitored, $created );
		if ( $monitored_id <= 0 ) {
			$this->set_user_notice( __( 'Failed: the product could not be added to monitoring.', 'lilleprinsen-price-monitor' ), 'error' );
			return;
		}

		$data = array(
			'monitored_product_id' => $monitored_id,
			'competitor_id'        => $competitor_id,
			'competitor_name'      => $competitor['name'] ?? '',
			'competitor_url'       => $url,
			'match_type'           => $safe_match ? 'exact' : 'similar',
			'enabled'              => 1,
			'is_primary'           => 0,
		);
		$existing_link = $this->repository->get_competitor_link_by_url( $monitored_id, $url );
		if ( $existing_link ) {
			$this->repository->update_competitor_link( (int) $existing_link['id'], $data );
		} else {
			$this->repository->add_competitor_link( $data );
		}

		$this->set_user_notice( $safe_match ? __( 'Safe match: we found this product’s SKU/EAN on the competitor page and added it to monitoring.', 'lilleprinsen-price-monitor' ) : __( 'Unverified match added to monitoring.', 'lilleprinsen-price-monitor' ), $safe_match ? 'success' : 'warning' );
	}

	/**
	 * Resolve the monitored product ID from an existing row or add result.
	 *
	 * @param array<string,mixed>|null $monitored Existing monitored product row.
	 * @param array<string,mixed>      $created Result from Repository::add_monitored_product().
	 */
	private static function monitored_product_id_from_result( ?array $monitored, array $created ): int {
		if ( $monitored ) {
			return absint( $monitored['id'] ?? 0 );
		}

		if ( empty( $created['success'] ) ) {
			return 0;
		}

		return absint( $created['id'] ?? 0 );
	}

	/** Register product list bulk actions. */
	public function register_bulk_actions( array $actions ): array {
		$actions['lpm_include_discovery'] = __( 'Include in competitor discovery', 'lilleprinsen-price-monitor' );
		$actions['lpm_remove_discovery']  = __( 'Remove from competitor discovery', 'lilleprinsen-price-monitor' );
		return $actions;
	}

	/** Handle product list bulk actions. */
	public function handle_bulk_actions( string $redirect_to, string $action, array $post_ids ): string {
		if ( ! in_array( $action, array( 'lpm_include_discovery', 'lpm_remove_discovery' ), true ) ) {
			return $redirect_to;
		}
		$count = 0;
		foreach ( $post_ids as $post_id ) {
			$post_id = absint( $post_id );
			if ( ! current_user_can( 'edit_product', $post_id ) ) {
				continue;
			}
			if ( 'lpm_include_discovery' === $action ) {
				$this->discovery_repository->upsert_discovery_product( $post_id, 0, $this->identifiers->get_for_product_id( $post_id ) );
				++$count;
			} else {
				$existing = $this->discovery_repository->get_discovery_product_by_product_id( $post_id );
				if ( $existing ) {
					$this->discovery_repository->set_discovery_product_enabled( (int) $existing->id, false );
					++$count;
				}
			}
		}
		return add_query_arg( array( 'lpm_discovery_bulk' => rawurlencode( $action ), 'lpm_discovery_count' => $count ), $redirect_to );
	}

	/** Show notices. */
	public function admin_notice(): void {
		if ( ! empty( $_GET['lpm_discovery_bulk'] ) && ! empty( $_GET['lpm_discovery_count'] ) ) {
			$action  = sanitize_key( wp_unslash( $_GET['lpm_discovery_bulk'] ) );
			$count   = absint( $_GET['lpm_discovery_count'] );
			$message = 'lpm_include_discovery' === $action ? sprintf( _n( '%d product added to competitor discovery.', '%d products added to competitor discovery.', $count, 'lilleprinsen-price-monitor' ), $count ) : sprintf( _n( '%d product removed from competitor discovery.', '%d products removed from competitor discovery.', $count, 'lilleprinsen-price-monitor' ), $count );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		$notice = get_transient( 'lpm_manual_url_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'lpm_manual_url_notice_' . get_current_user_id() );
			echo '<div class="notice notice-' . esc_attr( $notice['type'] ?? 'success' ) . ' is-dismissible"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
		}
	}

	/** Store a one-request admin notice. */
	private function set_user_notice( string $message, string $type ): void {
		set_transient( 'lpm_manual_url_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
	}
}
