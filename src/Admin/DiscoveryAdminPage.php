<?php
/**
 * Admin page for Competitor Price Assistant discovery.
 *
 * @package LillePrinsen\PriceMonitor\Admin
 */

namespace LillePrinsen\PriceMonitor\Admin;

use LillePrinsen\PriceMonitor\Database\DiscoveryRepository;
use LillePrinsen\PriceMonitor\Database\DiscoverySchema;
use LillePrinsen\PriceMonitor\Database\Repository;
use LillePrinsen\PriceMonitor\Service\CompetitorProductExtractor;
use LillePrinsen\PriceMonitor\Service\MatchSuggestionService;
use LillePrinsen\PriceMonitor\Service\ProductIdentifierService;
use LillePrinsen\PriceMonitor\Settings\DiscoverySettings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Simple admin workflow for selected-product competitor discovery.
 */
class DiscoveryAdminPage {
    private Repository $repository;
    private DiscoveryRepository $discovery_repository;
    private DiscoverySettings $settings;
    private ProductIdentifierService $identifiers;
    private CompetitorProductExtractor $extractor;
    private MatchSuggestionService $matcher;
    private string $notice = '';
    private string $notice_type = 'success';
    /** @var array<string,mixed>|null */
    private ?array $last_test = null;

    /**
     * Constructor.
     */
    public function __construct(
        Repository $repository,
        DiscoveryRepository $discovery_repository,
        DiscoverySettings $settings,
        ProductIdentifierService $identifiers,
        CompetitorProductExtractor $extractor,
        MatchSuggestionService $matcher
    ) {
        $this->repository           = $repository;
        $this->discovery_repository = $discovery_repository;
        $this->settings             = $settings;
        $this->identifiers          = $identifiers;
        $this->extractor            = $extractor;
        $this->matcher              = $matcher;
    }

    /**
     * Register submenu.
     */
    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Competitor Price Assistant', 'lilleprinsen-price-monitor' ),
            __( 'Competitor Prices', 'lilleprinsen-price-monitor' ),
            'manage_woocommerce',
            'lpm-competitor-prices',
            array( $this, 'render' )
        );
    }

    /**
     * Handle form actions.
     */
    public function handle_actions(): void {
        if ( empty( $_POST['lpm_discovery_action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage competitor discovery.', 'lilleprinsen-price-monitor' ) );
        }

        check_admin_referer( 'lpm_discovery_action', 'lpm_discovery_nonce' );
        DiscoverySchema::maybe_upgrade();

        $action = sanitize_key( wp_unslash( $_POST['lpm_discovery_action'] ) );
        switch ( $action ) {
            case 'save_settings':
                $this->settings->update( wp_unslash( $_POST ) );
                $this->set_notice( __( 'Discovery settings saved.', 'lilleprinsen-price-monitor' ) );
                break;
            case 'add_products_by_sku':
                $this->handle_add_products_by_sku();
                break;
            case 'remove_product':
                $this->handle_remove_product();
                break;
            case 'test_product_page':
                $this->handle_test_product_page();
                break;
            case 'approve_suggestion':
                $this->handle_approve_suggestion();
                break;
            case 'reject_suggestion':
                $this->handle_reject_suggestion();
                break;
        }
    }

    /**
     * Render page.
     */
    public function render(): void {
        DiscoverySchema::maybe_upgrade();
        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'overview';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Competitor Price Assistant', 'lilleprinsen-price-monitor' ); ?></h1>
            <p><?php esc_html_e( 'Find competitor product matches for the products you choose. Nothing is approved until you say so.', 'lilleprinsen-price-monitor' ); ?></p>
            <?php $this->render_notice(); ?>
            <?php $this->render_tabs( $view ); ?>
            <?php
            switch ( $view ) {
                case 'products':
                    $this->render_products();
                    break;
                case 'competitors':
                    $this->render_competitors();
                    break;
                case 'suggestions':
                    $this->render_suggestions();
                    break;
                case 'settings':
                    $this->render_settings();
                    break;
                default:
                    $this->render_overview();
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render tabs.
     */
    private function render_tabs( string $current ): void {
        $tabs = array(
            'overview'    => __( 'Overview', 'lilleprinsen-price-monitor' ),
            'products'    => __( 'Products to Monitor', 'lilleprinsen-price-monitor' ),
            'competitors' => __( 'Find Matches', 'lilleprinsen-price-monitor' ),
            'suggestions' => __( 'Suggested Matches', 'lilleprinsen-price-monitor' ),
            'settings'    => __( 'Advanced Settings', 'lilleprinsen-price-monitor' ),
        );

        echo '<h2 class="nav-tab-wrapper">';
        foreach ( $tabs as $key => $label ) {
            $url = add_query_arg( array( 'page' => 'lpm-competitor-prices', 'view' => $key ), admin_url( 'admin.php' ) );
            printf(
                '<a class="nav-tab %s" href="%s">%s</a>',
                esc_attr( $current === $key ? 'nav-tab-active' : '' ),
                esc_url( $url ),
                esc_html( $label )
            );
        }
        echo '</h2>';
    }

    /**
     * Overview.
     */
    private function render_overview(): void {
        $quality = $this->discovery_repository->get_identifier_quality_counts();
        $pending = $this->discovery_repository->count_suggestions( 'pending' );
        $competitors = $this->repository->get_competitors( 1, 200 );
        ?>
        <div class="lpm-admin-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:16px;">
            <?php $this->metric( __( 'Products selected', 'lilleprinsen-price-monitor' ), $quality['selected'] ); ?>
            <?php $this->metric( __( 'With SKU', 'lilleprinsen-price-monitor' ), $quality['with_sku'] ); ?>
            <?php $this->metric( __( 'With EAN/GTIN', 'lilleprinsen-price-monitor' ), $quality['with_gtin'] ); ?>
            <?php $this->metric( __( 'Pending suggested matches', 'lilleprinsen-price-monitor' ), $pending ); ?>
        </div>
        <?php if ( $quality['duplicates'] > 0 ) : ?>
            <div class="notice notice-warning inline"><p><?php printf( esc_html__( '%d duplicate identifiers were found among selected products. Review these before approving low-confidence suggestions.', 'lilleprinsen-price-monitor' ), absint( $quality['duplicates'] ) ); ?></p></div>
        <?php endif; ?>
        <h2><?php esc_html_e( 'Health Status', 'lilleprinsen-price-monitor' ); ?></h2>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Status', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Approved monitored links', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Last checked', 'lilleprinsen-price-monitor' ); ?></th></tr></thead>
            <tbody>
            <?php foreach ( $competitors as $competitor ) : ?>
                <tr>
                    <td><?php echo esc_html( $competitor['name'] ?? '' ); ?></td>
                    <td><?php echo esc_html( empty( $competitor['enabled'] ) ? __( 'Paused', 'lilleprinsen-price-monitor' ) : __( 'Working', 'lilleprinsen-price-monitor' ) ); ?></td>
                    <td><?php echo esc_html( (string) ( $competitor['link_count'] ?? 0 ) ); ?></td>
                    <td><?php echo esc_html( (string) ( $competitor['last_check'] ?? __( 'Not checked yet', 'lilleprinsen-price-monitor' ) ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $competitors ) ) : ?>
                <tr><td colspan="4"><?php esc_html_e( 'Add a competitor and test one product page to get started.', 'lilleprinsen-price-monitor' ); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Products tab.
     */
    private function render_products(): void {
        $page     = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $products = $this->discovery_repository->get_selected_products( $page, 50, $search );
        $total    = $this->discovery_repository->count_selected_products( $search );
        $counts   = $this->discovery_repository->get_pending_suggestion_counts_by_discovery_product_ids( wp_list_pluck( $products, 'id' ) );
        ?>
        <h2><?php esc_html_e( 'Products to Monitor', 'lilleprinsen-price-monitor' ); ?></h2>
        <p><?php esc_html_e( 'Only these products are used when finding competitor matches. This keeps discovery fast even on large stores.', 'lilleprinsen-price-monitor' ); ?></p>
        <form method="post" style="margin:16px 0;">
            <?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?>
            <input type="hidden" name="lpm_discovery_action" value="add_products_by_sku" />
            <label for="lpm_sku_list"><strong><?php esc_html_e( 'Add products by SKU', 'lilleprinsen-price-monitor' ); ?></strong></label>
            <textarea id="lpm_sku_list" name="sku_list" class="large-text" rows="3" placeholder="SKU-1&#10;SKU-2"></textarea>
            <p><button class="button button-primary"><?php esc_html_e( 'Add products', 'lilleprinsen-price-monitor' ); ?></button></p>
        </form>
        <form method="get" style="margin:12px 0;">
            <input type="hidden" name="page" value="lpm-competitor-prices" />
            <input type="hidden" name="view" value="products" />
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search selected products', 'lilleprinsen-price-monitor' ); ?>" />
            <button class="button"><?php esc_html_e( 'Search', 'lilleprinsen-price-monitor' ); ?></button>
        </form>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e( 'Product', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'SKU', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'EAN/GTIN', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Brand', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Pending suggestions', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Last discovery run', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Status', 'lilleprinsen-price-monitor' ); ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ( $products as $product ) : ?>
                <tr>
                    <td><a href="<?php echo esc_url( get_edit_post_link( (int) $product->product_id ) ); ?>"><?php echo esc_html( get_the_title( (int) $product->product_id ) ); ?></a></td>
                    <td><?php echo esc_html( (string) $product->sku ); ?></td>
                    <td><?php echo esc_html( (string) $product->gtin ); ?></td>
                    <td><?php echo esc_html( (string) $product->brand ); ?></td>
                    <td><?php echo esc_html( (string) ( $counts[ (int) $product->id ] ?? 0 ) ); ?></td>
                    <td><?php echo esc_html( (string) ( $product->last_discovery_at ?: __( 'Not run yet', 'lilleprinsen-price-monitor' ) ) ); ?></td>
                    <td><?php echo esc_html( (string) $product->status ); ?></td>
                    <td>
                        <form method="post">
                            <?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?>
                            <input type="hidden" name="lpm_discovery_action" value="remove_product" />
                            <input type="hidden" name="discovery_product_id" value="<?php echo esc_attr( (string) $product->id ); ?>" />
                            <button class="button-link-delete"><?php esc_html_e( 'Remove', 'lilleprinsen-price-monitor' ); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $products ) ) : ?>
                <tr><td colspan="8"><?php esc_html_e( 'No products selected yet.', 'lilleprinsen-price-monitor' ); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php $this->pagination( $total, $page, 'products', $search ); ?>
        <?php
    }

    /**
     * Competitors/test tab.
     */
    private function render_competitors(): void {
        $competitors = $this->repository->get_competitors( 1, 200 );
        ?>
        <h2><?php esc_html_e( 'Find Matches', 'lilleprinsen-price-monitor' ); ?></h2>
        <p><?php esc_html_e( 'Paste one competitor product URL. The assistant will show what it found and suggest matches from your selected products.', 'lilleprinsen-price-monitor' ); ?></p>
        <form method="post" style="max-width:900px;">
            <?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?>
            <input type="hidden" name="lpm_discovery_action" value="test_product_page" />
            <table class="form-table" role="presentation">
                <tr><th><label for="competitor_id"><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></label></th><td><select id="competitor_id" name="competitor_id"><option value="0"><?php esc_html_e( 'Create new competitor', 'lilleprinsen-price-monitor' ); ?></option><?php foreach ( $competitors as $competitor ) : ?><option value="<?php echo esc_attr( (string) $competitor['id'] ); ?>"><?php echo esc_html( $competitor['name'] ); ?></option><?php endforeach; ?></select></td></tr>
                <tr><th><label for="competitor_name"><?php esc_html_e( 'Competitor name', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="competitor_name" name="competitor_name" type="text" class="regular-text" /></td></tr>
                <tr><th><label for="competitor_domain"><?php esc_html_e( 'Website/domain', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="competitor_domain" name="competitor_domain" type="text" class="regular-text" placeholder="example.no" /></td></tr>
                <tr><th><label for="product_url"><?php esc_html_e( 'Example product URL', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="product_url" name="product_url" type="url" class="large-text" required /></td></tr>
            </table>
            <p><button class="button button-primary"><?php esc_html_e( 'Test Product Page', 'lilleprinsen-price-monitor' ); ?></button></p>
        </form>
        <?php $this->render_last_test(); ?>
        <?php
    }

    /**
     * Suggestions tab.
     */
    private function render_suggestions(): void {
        $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $suggestions = $this->discovery_repository->get_suggestions( 'pending', $page, 50 );
        $total = $this->discovery_repository->count_suggestions( 'pending' );
        ?>
        <h2><?php esc_html_e( 'Suggested Matches', 'lilleprinsen-price-monitor' ); ?></h2>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e( 'Our product', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Competitor', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Why this match was suggested', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Confidence', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Date discovered', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Actions', 'lilleprinsen-price-monitor' ); ?></th></tr></thead>
            <tbody>
            <?php foreach ( $suggestions as $suggestion ) : ?>
                <?php $competitor = $this->repository->get_competitor( (int) $suggestion->competitor_id ); ?>
                <tr>
                    <td><a href="<?php echo esc_url( get_edit_post_link( (int) $suggestion->product_id ) ); ?>"><?php echo esc_html( get_the_title( (int) $suggestion->product_id ) ); ?></a></td>
                    <td><?php echo esc_html( $competitor['name'] ?? __( 'Competitor', 'lilleprinsen-price-monitor' ) ); ?><br><a href="<?php echo esc_url( $suggestion->competitor_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open competitor page', 'lilleprinsen-price-monitor' ); ?></a></td>
                    <td><?php echo esc_html( (string) $suggestion->explanation ); ?></td>
                    <td><strong><?php echo esc_html( (string) $suggestion->confidence_label ); ?></strong></td>
                    <td><?php echo esc_html( (string) $suggestion->created_at ); ?></td>
                    <td>
                        <form method="post" style="display:inline-block;margin-right:8px;">
                            <?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?>
                            <input type="hidden" name="lpm_discovery_action" value="approve_suggestion" />
                            <input type="hidden" name="suggestion_id" value="<?php echo esc_attr( (string) $suggestion->id ); ?>" />
                            <button class="button button-primary"><?php esc_html_e( 'Approve', 'lilleprinsen-price-monitor' ); ?></button>
                        </form>
                        <form method="post" style="display:inline-block;">
                            <?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?>
                            <input type="hidden" name="lpm_discovery_action" value="reject_suggestion" />
                            <input type="hidden" name="suggestion_id" value="<?php echo esc_attr( (string) $suggestion->id ); ?>" />
                            <button class="button"><?php esc_html_e( 'Reject', 'lilleprinsen-price-monitor' ); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $suggestions ) ) : ?>
                <tr><td colspan="6"><?php esc_html_e( 'No pending suggestions yet.', 'lilleprinsen-price-monitor' ); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php $this->pagination( $total, $page, 'suggestions' ); ?>
        <?php
    }

    /**
     * Settings tab.
     */
    private function render_settings(): void {
        $settings = $this->settings->get_all();
        ?>
        <h2><?php esc_html_e( 'Advanced Settings', 'lilleprinsen-price-monitor' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'lpm_discovery_action', 'lpm_discovery_nonce' ); ?>
            <input type="hidden" name="lpm_discovery_action" value="save_settings" />
            <table class="form-table" role="presentation">
                <tr><th><?php esc_html_e( 'Discovery schedule', 'lilleprinsen-price-monitor' ); ?></th><td><label><input type="checkbox" name="discovery_enabled" value="1" <?php checked( ! empty( $settings['discovery_enabled'] ) ); ?> /> <?php esc_html_e( 'Allow weekly discovery jobs', 'lilleprinsen-price-monitor' ); ?></label></td></tr>
                <tr><th><label for="max_pages"><?php esc_html_e( 'Max product pages per competitor run', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="max_pages" type="number" name="discovery_max_product_pages_per_run" min="1" max="500" value="<?php echo esc_attr( (string) $settings['discovery_max_product_pages_per_run'] ); ?>" /></td></tr>
                <tr><th><label for="delay"><?php esc_html_e( 'Request delay', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="delay" type="number" name="discovery_request_delay_seconds" min="0" max="30" value="<?php echo esc_attr( (string) $settings['discovery_request_delay_seconds'] ); ?>" /> <?php esc_html_e( 'seconds', 'lilleprinsen-price-monitor' ); ?></td></tr>
                <tr><th><label for="ean_keys"><?php esc_html_e( 'EAN/GTIN fallback meta keys', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="ean_keys" type="text" name="discovery_identifier_meta_keys" class="large-text" value="<?php echo esc_attr( (string) $settings['discovery_identifier_meta_keys'] ); ?>" /><p class="description"><?php esc_html_e( 'Examples: _alg_ean, _wpm_gtin_code, _global_unique_id, ean, gtin, barcode', 'lilleprinsen-price-monitor' ); ?></p></td></tr>
                <tr><th><label for="mpn_keys"><?php esc_html_e( 'MPN meta keys', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="mpn_keys" type="text" name="discovery_mpn_meta_keys" class="large-text" value="<?php echo esc_attr( (string) $settings['discovery_mpn_meta_keys'] ); ?>" /></td></tr>
                <tr><th><label for="brand_keys"><?php esc_html_e( 'Brand meta keys', 'lilleprinsen-price-monitor' ); ?></label></th><td><input id="brand_keys" type="text" name="discovery_brand_meta_keys" class="large-text" value="<?php echo esc_attr( (string) $settings['discovery_brand_meta_keys'] ); ?>" /></td></tr>
                <tr><th><?php esc_html_e( 'Safety', 'lilleprinsen-price-monitor' ); ?></th><td><label><input type="checkbox" name="discovery_same_domain_only" value="1" <?php checked( ! empty( $settings['discovery_same_domain_only'] ) ); ?> /> <?php esc_html_e( 'Only test URLs on the competitor website by default', 'lilleprinsen-price-monitor' ); ?></label></td></tr>
            </table>
            <p><button class="button button-primary"><?php esc_html_e( 'Save settings', 'lilleprinsen-price-monitor' ); ?></button></p>
        </form>
        <?php
    }

    /**
     * Add products by SKU list.
     */
    private function handle_add_products_by_sku(): void {
        $raw = isset( $_POST['sku_list'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sku_list'] ) ) : '';
        $items = preg_split( '/[\s,;]+/', $raw );
        $added = 0;
        $missing = 0;

        foreach ( $items as $item ) {
            $item = trim( (string) $item );
            if ( '' === $item ) {
                continue;
            }
            $product_id = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $item ) : 0;
            if ( ! $product_id && ctype_digit( $item ) ) {
                $product_id = absint( $item );
            }
            if ( $product_id > 0 ) {
                $this->discovery_repository->upsert_discovery_product( $product_id, 0, $this->identifiers->get_for_product_id( $product_id ) );
                ++$added;
            } else {
                ++$missing;
            }
        }

        $this->set_notice( sprintf( __( 'Added %1$d products. %2$d entries were not found.', 'lilleprinsen-price-monitor' ), $added, $missing ), $missing ? 'warning' : 'success' );
    }

    /**
     * Remove selected product.
     */
    private function handle_remove_product(): void {
        $id = absint( $_POST['discovery_product_id'] ?? 0 );
        if ( $id > 0 ) {
            $this->discovery_repository->set_discovery_product_enabled( $id, false );
        }
        $this->set_notice( __( 'Product removed from competitor discovery.', 'lilleprinsen-price-monitor' ) );
    }

    /**
     * Test one competitor URL and create suggestions.
     */
    private function handle_test_product_page(): void {
        $url = isset( $_POST['product_url'] ) ? esc_url_raw( wp_unslash( $_POST['product_url'] ) ) : '';
        $competitor_id = absint( $_POST['competitor_id'] ?? 0 );
        $competitor = $competitor_id > 0 ? $this->repository->get_competitor( $competitor_id ) : null;

        if ( ! $competitor ) {
            $name   = isset( $_POST['competitor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['competitor_name'] ) ) : '';
            $domain = isset( $_POST['competitor_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['competitor_domain'] ) ) : '';
            if ( '' === $name ) {
                $this->set_notice( __( 'Enter a competitor name before testing.', 'lilleprinsen-price-monitor' ), 'error' );
                return;
            }
            $competitor_id = $this->repository->add_competitor(
                array(
                    'name'    => $name,
                    'domain'  => $domain,
                    'enabled' => 1,
                )
            );
            $competitor = $competitor_id > 0 ? $this->repository->get_competitor( $competitor_id ) : null;
        }

        if ( ! $competitor ) {
            $this->set_notice( __( 'The competitor profile could not be saved.', 'lilleprinsen-price-monitor' ), 'error' );
            return;
        }

        $result = $this->extractor->test_url( $url, $competitor );
        $this->last_test = $result;

        if ( empty( $result['success'] ) ) {
            $this->set_notice( (string) $result['message'], 'error' );
            return;
        }

        $discovered_id = $this->discovery_repository->store_discovered_product( $competitor_id, $url, $result );
        $discovered = $this->discovery_repository->get_discovered_product( $discovered_id );
        $suggestion_ids = array();
        if ( $discovered ) {
            $suggestion_ids = $this->matcher->create_suggestions( $discovered_id, $discovered, $this->discovery_repository->get_enabled_products_for_matching( 500 ) );
        }

        $this->set_notice( sprintf( __( '%1$s Created %2$d suggested matches for review.', 'lilleprinsen-price-monitor' ), (string) $result['message'], count( $suggestion_ids ) ), 'success' );
    }

    /**
     * Approve suggestion into existing competitor link flow.
     */
    private function handle_approve_suggestion(): void {
        $id = absint( $_POST['suggestion_id'] ?? 0 );
        $suggestion = $this->discovery_repository->get_suggestion( $id );
        if ( ! $suggestion ) {
            $this->set_notice( __( 'Suggestion was not found.', 'lilleprinsen-price-monitor' ), 'error' );
            return;
        }

        $product = $this->discovery_repository->get_discovery_product( (int) $suggestion->discovery_product_id );
        $competitor = $this->repository->get_competitor( (int) $suggestion->competitor_id );
        $monitored = $this->repository->get_monitored_product_by_product_id( (int) $suggestion->product_id );
        if ( ! $monitored ) {
            $monitored_id = $this->repository->add_monitored_product( (int) $suggestion->product_id, $product ? (string) $product->sku : '' );
        } else {
            $monitored_id = (int) $monitored['id'];
        }

        if ( $monitored_id <= 0 ) {
            $this->set_notice( __( 'The product could not be added to monitoring.', 'lilleprinsen-price-monitor' ), 'error' );
            return;
        }

        $match_type = 'High confidence' === (string) $suggestion->confidence_label ? 'exact' : 'similar';
        $existing_link = $this->repository->get_competitor_link_by_url( $monitored_id, (string) $suggestion->competitor_url );
        if ( $existing_link ) {
            $link_id = (int) $existing_link['id'];
            $this->repository->update_competitor_link(
                $link_id,
                array(
                    'monitored_product_id' => $monitored_id,
                    'competitor_id'        => (int) $suggestion->competitor_id,
                    'competitor_name'      => $competitor['name'] ?? '',
                    'competitor_url'       => (string) $suggestion->competitor_url,
                    'match_type'           => $match_type,
                    'enabled'              => 1,
                    'is_primary'           => 0,
                )
            );
        } else {
            $link_id = $this->repository->add_competitor_link(
                array(
                    'monitored_product_id' => $monitored_id,
                    'competitor_id'        => (int) $suggestion->competitor_id,
                    'competitor_name'      => $competitor['name'] ?? '',
                    'competitor_url'       => (string) $suggestion->competitor_url,
                    'match_type'           => $match_type,
                    'enabled'              => 1,
                    'is_primary'           => 0,
                )
            );
        }

        $this->discovery_repository->approve_suggestion( $id, get_current_user_id(), $link_id );
        $this->set_notice( __( 'Suggestion approved. The competitor URL is now part of regular price monitoring.', 'lilleprinsen-price-monitor' ) );
    }

    /**
     * Reject suggestion.
     */
    private function handle_reject_suggestion(): void {
        $id = absint( $_POST['suggestion_id'] ?? 0 );
        if ( $id > 0 ) {
            $this->discovery_repository->reject_suggestion( $id, get_current_user_id() );
        }
        $this->set_notice( __( 'Suggestion rejected. It will not keep reappearing unless the product data changes.', 'lilleprinsen-price-monitor' ) );
    }

    /**
     * Render last test output.
     */
    private function render_last_test(): void {
        if ( null === $this->last_test ) {
            return;
        }
        $fields = array(
            'title'         => __( 'Product title', 'lilleprinsen-price-monitor' ),
            'sku'           => __( 'SKU', 'lilleprinsen-price-monitor' ),
            'gtin'          => __( 'EAN/GTIN', 'lilleprinsen-price-monitor' ),
            'mpn'           => __( 'MPN', 'lilleprinsen-price-monitor' ),
            'brand'         => __( 'Brand', 'lilleprinsen-price-monitor' ),
            'regular_price' => __( 'Regular price', 'lilleprinsen-price-monitor' ),
            'sale_price'    => __( 'Sale price', 'lilleprinsen-price-monitor' ),
            'currency'      => __( 'Currency', 'lilleprinsen-price-monitor' ),
            'stock_status'  => __( 'Stock status', 'lilleprinsen-price-monitor' ),
            'image_url'     => __( 'Image', 'lilleprinsen-price-monitor' ),
            'canonical_url' => __( 'Canonical URL', 'lilleprinsen-price-monitor' ),
        );
        $sources = is_array( $this->last_test['sources'] ?? null ) ? $this->last_test['sources'] : array();
        ?>
        <h3><?php esc_html_e( 'Detected values', 'lilleprinsen-price-monitor' ); ?></h3>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e( 'Field', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Detected value', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Source', 'lilleprinsen-price-monitor' ); ?></th><th><?php esc_html_e( 'Confidence', 'lilleprinsen-price-monitor' ); ?></th></tr></thead>
            <tbody>
            <?php foreach ( $fields as $key => $label ) : ?>
                <tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( (string) ( $this->last_test[ $key ] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $sources[ $key ] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $this->last_test['confidence_status'] ?? '' ) ); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ( ! empty( $this->last_test['technical_details'] ) ) : ?>
            <details style="margin-top:12px;"><summary><?php esc_html_e( 'Show details', 'lilleprinsen-price-monitor' ); ?></summary><pre><?php echo esc_html( (string) $this->last_test['technical_details'] ); ?></pre></details>
        <?php endif; ?>
        <?php
    }

    /**
     * Metric box.
     */
    private function metric( string $label, int $value ): void {
        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:14px;"><strong style="font-size:24px;display:block;">' . esc_html( (string) $value ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
    }

    /**
     * Pagination links.
     */
    private function pagination( int $total, int $page, string $view, string $search = '' ): void {
        $pages = (int) ceil( $total / 50 );
        if ( $pages <= 1 ) {
            return;
        }
        echo '<p class="tablenav-pages">';
        for ( $i = 1; $i <= $pages; $i++ ) {
            $url = add_query_arg( array( 'page' => 'lpm-competitor-prices', 'view' => $view, 'paged' => $i, 's' => $search ), admin_url( 'admin.php' ) );
            printf( '<a class="button %s" href="%s">%s</a> ', esc_attr( $i === $page ? 'button-primary' : '' ), esc_url( $url ), esc_html( (string) $i ) );
        }
        echo '</p>';
    }

    /**
     * Set notice.
     */
    private function set_notice( string $message, string $type = 'success' ): void {
        $this->notice = $message;
        $this->notice_type = $type;
    }

    /**
     * Render notice.
     */
    private function render_notice(): void {
        if ( '' === $this->notice ) {
            return;
        }
        printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $this->notice_type ), esc_html( $this->notice ) );
    }
}
