<?php
/**
 * Admin notices.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin;

use Lilleprinsen\PriceMonitor\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Notices {
	public function render(): void {
		if ( ! Plugin::can_manage() || Plugin::is_woocommerce_active() ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'Lilleprinsen Price Monitor requires WooCommerce to be active. The plugin is loaded safely, but monitoring features are unavailable until WooCommerce is activated.', 'lilleprinsen-price-monitor' ); ?>
			</p>
		</div>
		<?php
	}
}
