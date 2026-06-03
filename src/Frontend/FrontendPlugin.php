<?php
/**
 * Optional lightweight frontend hooks.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Frontend;

use Lilleprinsen\PriceMonitor\Database\Repository;
use Lilleprinsen\PriceMonitor\Service\PriceMatchDisplayService;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FrontendPlugin {
	private Settings $settings;

	private PriceMatchDisplayService $display_service;

	private bool $coupon_notice_added = false;

	public function __construct( ?Settings $settings = null, ?Repository $repository = null ) {
		$this->settings        = $settings ?? new Settings();
		$repository            = $repository ?? new Repository();
		$this->display_service = new PriceMatchDisplayService( $repository );
	}

	public function init(): void {
		$settings = $this->settings->get_all();

		if ( ! empty( $settings['price_match_box_enabled'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );

			if ( ! empty( $settings['price_match_box_show_on_product_page'] ) ) {
				add_action( 'woocommerce_single_product_summary', array( $this, 'render_single_box' ), $this->get_single_position_priority( (string) $settings['price_match_box_position'] ) );
			}

			if ( ! empty( $settings['price_match_box_show_on_loop'] ) ) {
				add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'render_loop_box' ), 12 );
			}
		}

		if ( ! empty( $settings['disable_coupons_for_price_matched_products'] ) ) {
			add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'filter_coupon_discount_amount' ), 10, 5 );
		}
	}

	public function enqueue_styles(): void {
		wp_enqueue_style( 'lpm-price-match-box', LPM_PLUGIN_URL . 'assets/price-match-box.css', array(), LPM_VERSION );
	}

	public function render_single_box(): void {
		global $product;

		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$this->render_box_for_product( (int) $product->get_id(), true );
	}

	public function render_loop_box(): void {
		global $product;

		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$this->render_box_for_product( (int) $product->get_id(), false );
	}

	/**
	 * @param mixed $discount Current discount.
	 * @param mixed $discounting_amount Discounting amount.
	 * @param array<string, mixed> $cart_item Cart item.
	 * @param mixed $single Single item flag.
	 * @param mixed $coupon Coupon object.
	 */
	public function filter_coupon_discount_amount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		$product_id = $this->get_cart_item_product_id( is_array( $cart_item ) ? $cart_item : array() );

		if ( $product_id <= 0 || ! $this->display_service->product_is_price_matched( $product_id, true ) ) {
			return $discount;
		}

		if ( ! $this->coupon_notice_added && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Rabattkoder kan ikke brukes på prismatch.', 'lilleprinsen-price-monitor' ), 'notice' );
			$this->coupon_notice_added = true;
		}

		return 0;
	}

	private function render_box_for_product( int $product_id, bool $allow_indexed_lookup ): void {
		$state = $this->display_service->get_display_state( $product_id, $this->settings->get_all(), $allow_indexed_lookup );

		if ( empty( $state['show'] ) ) {
			return;
		}

		$style = $this->build_inline_style( $state );
		?>
		<div class="lpm-price-match-box" <?php echo '' !== $style ? 'style="' . esc_attr( $style ) . '"' : ''; ?>>
			<div class="lpm-price-match-box__main">
				<?php if ( '' !== (string) $state['emoji'] ) : ?>
					<span class="lpm-price-match-box__emoji" aria-hidden="true"><?php echo esc_html( (string) $state['emoji'] ); ?></span>
				<?php endif; ?>
				<span><?php echo esc_html( (string) $state['text'] ); ?></span>
			</div>
			<?php if ( '' !== (string) $state['subtext'] ) : ?>
				<div class="lpm-price-match-box__subtext"><?php echo esc_html( (string) $state['subtext'] ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $state Display state.
	 */
	private function build_inline_style( array $state ): string {
		$styles = array();

		if ( empty( $state['use_theme_color'] ) ) {
			foreach ( array( 'background_color' => '--lpm-price-match-bg', 'text_color' => '--lpm-price-match-text', 'border_color' => '--lpm-price-match-border' ) as $key => $var ) {
				if ( ! empty( $state[ $key ] ) ) {
					$color = sanitize_hex_color( (string) $state[ $key ] );

					if ( is_string( $color ) && '' !== $color ) {
						$styles[] = $var . ':' . $color;
					}
				}
			}
		}

		$styles[] = '--lpm-price-match-radius:' . min( 40, max( 0, absint( $state['border_radius'] ?? 10 ) ) ) . 'px';

		return implode( ';', array_filter( $styles ) );
	}

	private function get_single_position_priority( string $position ): int {
		if ( 'below_add_to_cart' === $position ) {
			return 31;
		}

		if ( 'product_summary_bottom' === $position ) {
			return 45;
		}

		return 11;
	}

	/**
	 * @param array<string, mixed> $cart_item Cart item.
	 */
	private function get_cart_item_product_id( array $cart_item ): int {
		if ( ! empty( $cart_item['variation_id'] ) ) {
			return absint( $cart_item['variation_id'] );
		}

		return ! empty( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
	}
}
