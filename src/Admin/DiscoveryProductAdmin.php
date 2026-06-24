<?php
/**
 * WooCommerce product admin integration for discovery selection.
 *
 * @package LillePrinsen\PriceMonitor\Admin
 */

namespace LillePrinsen\PriceMonitor\Admin;

use LillePrinsen\PriceMonitor\Database\DiscoveryRepository;
use LillePrinsen\PriceMonitor\Service\ProductIdentifierService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds simple product-level controls for discovery inclusion.
 */
class DiscoveryProductAdmin {
    private DiscoveryRepository $repository;
    private ProductIdentifierService $identifiers;

    /**
     * Constructor.
     */
    public function __construct( DiscoveryRepository $repository, ProductIdentifierService $identifiers ) {
        $this->repository  = $repository;
        $this->identifiers = $identifiers;
    }

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action( 'add_meta_boxes_product', array( $this, 'add_metabox' ) );
        add_action( 'save_post_product', array( $this, 'save_metabox' ), 20, 2 );
        add_filter( 'bulk_actions-edit-product', array( $this, 'register_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_actions' ), 10, 3 );
        add_action( 'admin_notices', array( $this, 'bulk_action_notice' ) );
    }

    /**
     * Add product metabox.
     */
    public function add_metabox(): void {
        add_meta_box(
            'lpm_discovery_product',
            __( 'Competitor Price Assistant', 'lilleprinsen-price-monitor' ),
            array( $this, 'render_metabox' ),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render metabox.
     */
    public function render_metabox( \WP_Post $post ): void {
        wp_nonce_field( 'lpm_save_discovery_product', 'lpm_discovery_product_nonce' );
        $selected = $this->repository->get_discovery_product_by_product_id( (int) $post->ID );
        $checked  = $selected && (int) $selected->enabled === 1;
        ?>
        <p>
            <label>
                <input type="checkbox" name="lpm_include_in_discovery" value="1" <?php checked( $checked ); ?> />
                <?php esc_html_e( 'Include in competitor discovery', 'lilleprinsen-price-monitor' ); ?>
            </label>
        </p>
        <p class="description"><?php esc_html_e( 'Only selected products are used when finding competitor matches.', 'lilleprinsen-price-monitor' ); ?></p>
        <?php
    }

    /**
     * Save metabox.
     */
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

        $existing = $this->repository->get_discovery_product_by_product_id( $post_id );
        $include  = ! empty( $_POST['lpm_include_in_discovery'] );

        if ( $include ) {
            $this->repository->upsert_discovery_product( $post_id, 0, $this->identifiers->get_for_product_id( $post_id ) );
        } elseif ( $existing ) {
            $this->repository->set_discovery_product_enabled( (int) $existing->id, false );
        }
    }

    /**
     * Register product list bulk actions.
     *
     * @param array<string,string> $actions Actions.
     * @return array<string,string>
     */
    public function register_bulk_actions( array $actions ): array {
        $actions['lpm_include_discovery'] = __( 'Include in competitor discovery', 'lilleprinsen-price-monitor' );
        $actions['lpm_remove_discovery']  = __( 'Remove from competitor discovery', 'lilleprinsen-price-monitor' );

        return $actions;
    }

    /**
     * Handle product list bulk actions.
     *
     * @param string     $redirect_to Redirect URL.
     * @param string     $action Action.
     * @param array<int> $post_ids Product IDs.
     */
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
                $this->repository->upsert_discovery_product( $post_id, 0, $this->identifiers->get_for_product_id( $post_id ) );
                ++$count;
            } else {
                $existing = $this->repository->get_discovery_product_by_product_id( $post_id );
                if ( $existing ) {
                    $this->repository->set_discovery_product_enabled( (int) $existing->id, false );
                    ++$count;
                }
            }
        }

        return add_query_arg(
            array(
                'lpm_discovery_bulk' => rawurlencode( $action ),
                'lpm_discovery_count'=> $count,
            ),
            $redirect_to
        );
    }

    /**
     * Show bulk action result.
     */
    public function bulk_action_notice(): void {
        if ( empty( $_GET['lpm_discovery_bulk'] ) || empty( $_GET['lpm_discovery_count'] ) ) {
            return;
        }

        $action = sanitize_key( wp_unslash( $_GET['lpm_discovery_bulk'] ) );
        $count  = absint( $_GET['lpm_discovery_count'] );
        $message = 'lpm_include_discovery' === $action
            ? sprintf( _n( '%d product added to competitor discovery.', '%d products added to competitor discovery.', $count, 'lilleprinsen-price-monitor' ), $count )
            : sprintf( _n( '%d product removed from competitor discovery.', '%d products removed from competitor discovery.', $count, 'lilleprinsen-price-monitor' ), $count );

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
    }
}
