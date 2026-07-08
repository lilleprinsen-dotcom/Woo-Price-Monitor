<?php
/**
 * Settings tab renderer.
 *
 * @package LilleprinsenPriceMonitor
 */

namespace Lilleprinsen\PriceMonitor\Admin\Tabs;

use Lilleprinsen\PriceMonitor\Admin\AdminViewHelpers;
use Lilleprinsen\PriceMonitor\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsTab extends AdminViewHelpers {
	/**
	 * @param array<string, mixed> $settings Current settings.
	 */
	public function render( array $settings ): void {
		?>
		<form method="post" class="lpm-settings-form">
			<?php wp_nonce_field( 'lpm_save_settings', 'lpm_settings_nonce' ); ?>
			<input type="hidden" name="lpm_settings_action" value="save" />

			<div class="lpm-grid lpm-grid-two">
				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'General', 'lilleprinsen-price-monitor' ); ?></h2>
					</div>
					<?php
					$this->render_checkbox_field( 'plugin_enabled', __( 'Plugin enabled', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'dry_run_mode', __( 'Dry-run mode', 'lilleprinsen-price-monitor' ), $settings, __( 'Keep this enabled while the plugin records data and suggestions only. Dry-run mode does not update WooCommerce prices.', 'lilleprinsen-price-monitor' ) );
					$this->render_text_field( 'default_currency', __( 'Default currency', 'lilleprinsen-price-monitor' ), $settings, 'NOK' );
					?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'Monitoring', 'lilleprinsen-price-monitor' ); ?></h2>
					</div>
					<?php
					$this->render_select_field( 'default_check_frequency_hours', __( 'Default product check frequency', 'lilleprinsen-price-monitor' ), $settings, Settings::check_interval_options() );
					$this->render_checkbox_field( 'scheduled_checks_enabled', __( 'Scheduled checks enabled', 'lilleprinsen-price-monitor' ), $settings, __( 'Disabled by default. Requires Action Scheduler; otherwise no background job is registered.', 'lilleprinsen-price-monitor' ) );
					$this->render_select_field( 'scheduled_check_interval_hours', __( 'Check approved matches every', 'lilleprinsen-price-monitor' ), $settings, Settings::check_interval_options() );
					$this->render_number_field( 'max_urls_per_batch', __( 'Max URLs per batch', 'lilleprinsen-price-monitor' ), $settings, 1 );
					$this->render_number_field( 'scheduled_batch_spacing_minutes', __( 'Minutes between queued batches', 'lilleprinsen-price-monitor' ), $settings, 1 );
					$this->render_number_field( 'check_batch_lock_minutes', __( 'Batch lock minutes', 'lilleprinsen-price-monitor' ), $settings, 1 );
					$this->render_checkbox_field( 'create_suggestions_from_scheduled_checks', __( 'Create suggestions from scheduled checks', 'lilleprinsen-price-monitor' ), $settings, __( 'Disabled by default. Scheduled checks never update WooCommerce prices.', 'lilleprinsen-price-monitor' ) );
					$this->render_number_field( 'request_timeout_seconds', __( 'Request timeout (seconds)', 'lilleprinsen-price-monitor' ), $settings, 1 );
					$this->render_checkbox_field( 'retry_failed_checks', __( 'Retry failed checks', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_number_field( 'observation_retention_days', __( 'Successful observation retention (days)', 'lilleprinsen-price-monitor' ), $settings, 1 );
					$this->render_number_field( 'failed_observation_retention_days', __( 'Failed observation retention (days)', 'lilleprinsen-price-monitor' ), $settings, 1 );
					?>
					<p class="lpm-field-description"><?php esc_html_e( 'Scheduled checks run in small queued batches. If more approved links are due than one batch can handle, the next batch is spaced out instead of starting everything at once.', 'lilleprinsen-price-monitor' ); ?></p>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'Pricing strategy', 'lilleprinsen-price-monitor' ); ?></h2>
					</div>
					<?php
					$this->render_select_field( 'default_pricing_strategy', __( 'Default pricing strategy', 'lilleprinsen-price-monitor' ), $settings, $this->get_pricing_strategy_options() );
					$this->render_decimal_field( 'beat_competitor_amount', __( 'Beat competitor amount', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_decimal_field( 'stay_above_competitor_amount', __( 'Stay above competitor amount', 'lilleprinsen-price-monitor' ), $settings );
					?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'Rounding', 'lilleprinsen-price-monitor' ); ?></h2>
					</div>
					<?php $this->render_select_field( 'rounding_mode', __( 'Rounding mode', 'lilleprinsen-price-monitor' ), $settings, $this->get_rounding_mode_options() ); ?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'Margin and cost', 'lilleprinsen-price-monitor' ); ?></h2>
					</div>
					<?php
					$this->render_select_field(
						'cost_source',
						__( 'Cost source', 'lilleprinsen-price-monitor' ),
						$settings,
						array(
							'none'            => __( 'None', 'lilleprinsen-price-monitor' ),
							'custom_meta_key' => __( 'Custom meta key', 'lilleprinsen-price-monitor' ),
						)
					);
					$this->render_text_field( 'cost_meta_key', __( 'Cost meta key', 'lilleprinsen-price-monitor' ), $settings, '_cost' );
					$this->render_checkbox_field( 'block_if_cost_missing', __( 'Block if cost is missing', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_decimal_field( 'minimum_profit_amount', __( 'Minimum profit amount', 'lilleprinsen-price-monitor' ), $settings, __( 'Optional. Leave empty to skip fixed profit checks.', 'lilleprinsen-price-monitor' ) );
					$this->render_decimal_field( 'default_min_margin_percent', __( 'Default minimum margin percent', 'lilleprinsen-price-monitor' ), $settings, __( 'Product-level minimum margin overrides this when set.', 'lilleprinsen-price-monitor' ) );
					?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'VAT', 'lilleprinsen-price-monitor' ); ?></h2>
					</div>
					<?php
					$this->render_select_field(
						'price_comparison_vat_mode',
						__( 'Price comparison VAT mode', 'lilleprinsen-price-monitor' ),
						$settings,
						array(
							'consumer_prices_include_vat' => __( 'Consumer prices include VAT', 'lilleprinsen-price-monitor' ),
							'prices_exclude_vat'          => __( 'Prices exclude VAT', 'lilleprinsen-price-monitor' ),
						)
					);
					$this->render_decimal_field( 'vat_rate_percent', __( 'VAT rate percent', 'lilleprinsen-price-monitor' ), $settings );
					?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'Safety', 'lilleprinsen-price-monitor' ); ?></h2>
					</div>
					<?php
					$this->render_decimal_field( 'min_price_difference_to_suggest', __( 'Minimum price difference to suggest', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_decimal_field( 'max_allowed_price_drop_percent', __( 'Max allowed price drop percent', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_decimal_field( 'max_allowed_price_increase_percent', __( 'Max allowed price increase percent', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'block_suggestions_for_sale_products', __( 'Block suggestions for sale products', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'block_suggestions_for_out_of_stock_products', __( 'Block suggestions for out-of-stock products', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'require_manual_approval', __( 'Require manual approval', 'lilleprinsen-price-monitor' ), $settings, __( 'Approval is stored as workflow state only unless every real-update guardrail is explicitly enabled.', 'lilleprinsen-price-monitor' ) );
					$this->render_checkbox_field( 'disable_all_price_updates', __( 'Emergency disable all price updates', 'lilleprinsen-price-monitor' ), $settings, __( 'Default on. Real updates cannot run while this is enabled.', 'lilleprinsen-price-monitor' ) );
					$this->render_checkbox_field( 'allow_real_price_updates', __( 'Allow real price updates', 'lilleprinsen-price-monitor' ), $settings, __( 'Default off. Also requires dry-run mode off and explicit confirmation.', 'lilleprinsen-price-monitor' ) );
					$this->render_checkbox_field( 'require_confirmation_for_real_updates', __( 'Require confirmation for real updates', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_group_field(
						'real_update_allowed_suggestion_types',
						__( 'Allowed real update suggestion types', 'lilleprinsen-price-monitor' ),
						$settings,
						$this->get_real_update_type_options()
					);
					$this->render_checkbox_field( 'allow_partial_group_price_updates', __( 'Allow partial group price updates', 'lilleprinsen-price-monitor' ), $settings, __( 'Default off. If any group member fails safety checks, the intended future behavior is to block the whole real group update.', 'lilleprinsen-price-monitor' ) );
					?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'Price recovery', 'lilleprinsen-price-monitor' ); ?></h2>
						<?php $this->render_status_pill( __( 'Suggestion rules', 'lilleprinsen-price-monitor' ), 'ok' ); ?>
					</div>
					<p class="lpm-field-description"><?php esc_html_e( 'These settings decide what the plugin should suggest when competitor prices go up again after a price match. Real updates still require the separate pricing safety switches and explicit confirmation.', 'lilleprinsen-price-monitor' ); ?></p>
					<?php
					$this->render_select_field(
						'recovery_when_competitor_increases',
						__( 'When competitor price increases', 'lilleprinsen-price-monitor' ),
						$settings,
						array(
							'do_nothing'                           => __( 'Do nothing', 'lilleprinsen-price-monitor' ),
							'suggest_only'                          => __( 'Suggest only', 'lilleprinsen-price-monitor' ),
							'suggest_match_competitor'              => __( 'Suggest matching competitor', 'lilleprinsen-price-monitor' ),
							'suggest_restore_previous_active_price' => __( 'Suggest restore previous active price', 'lilleprinsen-price-monitor' ),
							'suggest_restore_previous_regular_price' => __( 'Suggest restore previous regular price', 'lilleprinsen-price-monitor' ),
							'suggest_restore_previous_sale_price'   => __( 'Suggest restore previous sale price', 'lilleprinsen-price-monitor' ),
						)
					);
					$this->render_select_field(
						'recovery_if_competitor_still_below_previous_sale_price',
						__( 'If competitor stays below previous sale price', 'lilleprinsen-price-monitor' ),
						$settings,
						array(
							'keep_current_price'             => __( 'Keep current price', 'lilleprinsen-price-monitor' ),
							'suggest_match_competitor'       => __( 'Suggest matching competitor', 'lilleprinsen-price-monitor' ),
							'suggest_restore_previous_sale_price' => __( 'Suggest restore previous sale price', 'lilleprinsen-price-monitor' ),
							'suggest_only'                   => __( 'Suggest only', 'lilleprinsen-price-monitor' ),
						)
					);
					$this->render_select_field(
						'recovery_if_competitor_above_previous_regular_price',
						__( 'If competitor rises above previous regular price', 'lilleprinsen-price-monitor' ),
						$settings,
						array(
							'keep_current_price'                => __( 'Keep current price', 'lilleprinsen-price-monitor' ),
							'suggest_restore_previous_regular_price' => __( 'Suggest restore previous regular price', 'lilleprinsen-price-monitor' ),
							'suggest_match_competitor'          => __( 'Suggest matching competitor', 'lilleprinsen-price-monitor' ),
							'suggest_only'                      => __( 'Suggest only', 'lilleprinsen-price-monitor' ),
						)
					);
					$this->render_select_field(
						'multiple_competitor_recovery_basis',
						__( 'Multiple competitor recovery basis', 'lilleprinsen-price-monitor' ),
						$settings,
						array(
							'lowest_valid_competitor'      => __( 'Lowest valid competitor', 'lilleprinsen-price-monitor' ),
							'primary_competitor'           => __( 'Primary competitor', 'lilleprinsen-price-monitor' ),
							'all_competitors_must_increase' => __( 'All competitors must increase', 'lilleprinsen-price-monitor' ),
						)
					);
					$this->render_number_field( 'recovery_max_competitor_price_age_hours', __( 'Max competitor price age for recovery (hours)', 'lilleprinsen-price-monitor' ), $settings, 1 );
					$this->render_select_field(
						'price_match_write_mode',
						__( 'Future price match write mode', 'lilleprinsen-price-monitor' ),
						$settings,
						array(
							'regular_price'        => __( 'Regular price', 'lilleprinsen-price-monitor' ),
							'sale_price'           => __( 'Sale price', 'lilleprinsen-price-monitor' ),
							'temporary_sale_price' => __( 'Temporary sale price', 'lilleprinsen-price-monitor' ),
						)
					);
					?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'UI', 'lilleprinsen-price-monitor' ); ?></h2>
					</div>
					<?php $this->render_number_field( 'rows_per_page', __( 'Rows per page', 'lilleprinsen-price-monitor' ), $settings, 1 ); ?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'External browser worker', 'lilleprinsen-price-monitor' ); ?></h2>
						<?php $this->render_status_pill( ! empty( $settings['external_browser_worker_enabled'] ) ? __( 'Opt-in enabled', 'lilleprinsen-price-monitor' ) : __( 'Disabled by default', 'lilleprinsen-price-monitor' ), ! empty( $settings['external_browser_worker_enabled'] ) ? 'warning' : 'muted' ); ?>
					</div>
					<p class="lpm-field-description"><?php esc_html_e( 'Optional Docker/Playwright worker for JavaScript-heavy competitor stores. Internal checking remains the default; matches still require manual approval.', 'lilleprinsen-price-monitor' ); ?></p>
					<?php
					$this->render_checkbox_field( 'external_browser_worker_enabled', __( 'Enable external browser worker', 'lilleprinsen-price-monitor' ), $settings, __( 'Only competitors explicitly configured for the worker will use it.', 'lilleprinsen-price-monitor' ) );
					$this->render_text_field( 'external_browser_worker_endpoint', __( 'Worker endpoint URL', 'lilleprinsen-price-monitor' ), $settings, 'https://worker.example.com' );
					$this->render_text_field( 'external_browser_worker_secret', __( 'Worker shared secret', 'lilleprinsen-price-monitor' ), $settings, __( 'Used for HMAC request signing', 'lilleprinsen-price-monitor' ) );
					$this->render_number_field( 'external_browser_worker_timeout_seconds', __( 'Worker timeout seconds', 'lilleprinsen-price-monitor' ), $settings, 5 );
					$this->render_number_field( 'external_browser_worker_max_candidates', __( 'Max worker candidates', 'lilleprinsen-price-monitor' ), $settings, 1 );
					?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'Frontend price-match box', 'lilleprinsen-price-monitor' ); ?></h2>
						<?php $this->render_status_pill( ! empty( $settings['price_match_box_enabled'] ) ? __( 'Enabled', 'lilleprinsen-price-monitor' ) : __( 'Disabled by default', 'lilleprinsen-price-monitor' ), ! empty( $settings['price_match_box_enabled'] ) ? 'warning' : 'muted' ); ?>
					</div>
					<p class="lpm-field-description"><?php esc_html_e( 'Shows a small Norwegian price-match message only for products with stored active match state. It does not check competitors or calculate prices on frontend requests.', 'lilleprinsen-price-monitor' ); ?></p>
					<?php
					$this->render_checkbox_field( 'price_match_box_enabled', __( 'Enable frontend price-match box', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'price_match_box_show_on_product_page', __( 'Show on product pages', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'price_match_box_show_on_loop', __( 'Show in product loops', 'lilleprinsen-price-monitor' ), $settings, __( 'Disabled by default. Loop display uses cached/simple state only.', 'lilleprinsen-price-monitor' ) );
					$this->render_select_field(
						'price_match_box_position',
						__( 'Product page position', 'lilleprinsen-price-monitor' ),
						$settings,
						array(
							'below_price'            => __( 'Below price', 'lilleprinsen-price-monitor' ),
							'below_add_to_cart'      => __( 'Below add to cart', 'lilleprinsen-price-monitor' ),
							'product_summary_bottom' => __( 'Product summary bottom', 'lilleprinsen-price-monitor' ),
						)
					);
					$this->render_text_field( 'price_match_box_text', __( 'Main text', 'lilleprinsen-price-monitor' ), $settings, '⭐ Prismatch: Denne varen er matchet mot våre nærmeste konkurrenter.' );
					$this->render_text_field( 'price_match_box_subtext', __( 'Subtext', 'lilleprinsen-price-monitor' ), $settings, 'Rabattkoder kan ikke brukes på prismatch.' );
					$this->render_text_field( 'price_match_box_emoji', __( 'Emoji', 'lilleprinsen-price-monitor' ), $settings, '⭐' );
					$this->render_checkbox_field( 'price_match_box_use_theme_color', __( 'Use theme color where possible', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_text_field( 'price_match_box_background_color', __( 'Background color', 'lilleprinsen-price-monitor' ), $settings, '#f8faf9' );
					$this->render_text_field( 'price_match_box_text_color', __( 'Text color', 'lilleprinsen-price-monitor' ), $settings, '#1f2933' );
					$this->render_text_field( 'price_match_box_border_color', __( 'Border color', 'lilleprinsen-price-monitor' ), $settings, '#d8e2dc' );
					$this->render_number_field( 'price_match_box_border_radius', __( 'Border radius', 'lilleprinsen-price-monitor' ), $settings, 1 );
					$this->render_checkbox_field( 'price_match_box_hide_if_no_active_match', __( 'Hide if no active match', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'price_match_box_show_for_group_matches', __( 'Show for group matches', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'disable_coupons_for_price_matched_products', __( 'Disable coupon discounts on price-matched products', 'lilleprinsen-price-monitor' ), $settings, __( 'Shows a Norwegian notice and removes coupon discount from matched cart lines only.', 'lilleprinsen-price-monitor' ) );
					?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'Retention cleanup', 'lilleprinsen-price-monitor' ); ?></h2>
						<?php $this->render_status_pill( __( 'Manual only', 'lilleprinsen-price-monitor' ), 'muted' ); ?>
					</div>
					<p class="lpm-field-description"><?php esc_html_e( 'Cleanup deletes old debug and operational logs plus old observation rows. Approval and price-update audit logs are preserved.', 'lilleprinsen-price-monitor' ); ?></p>
					<?php
					$this->render_number_field( 'log_retention_days', __( 'Operational log retention (days)', 'lilleprinsen-price-monitor' ), $settings, 1 );
					$this->render_number_field( 'debug_log_retention_days', __( 'Debug log retention (days)', 'lilleprinsen-price-monitor' ), $settings, 1 );
					$this->render_checkbox_field( 'keep_audit_logs_forever', __( 'Keep audit logs forever', 'lilleprinsen-price-monitor' ), $settings, __( 'Recommended. Approval and real-update audit logs are not deleted by cleanup.', 'lilleprinsen-price-monitor' ) );
					?>
				</section>

				<section class="lpm-card">
					<div class="lpm-card-header">
						<h2><?php esc_html_e( 'Notifications', 'lilleprinsen-price-monitor' ); ?></h2>
						<?php $this->render_status_pill( ! empty( $settings['webhook_notifications_enabled'] ) ? __( 'Webhook enabled', 'lilleprinsen-price-monitor' ) : __( 'Disabled by default', 'lilleprinsen-price-monitor' ), ! empty( $settings['webhook_notifications_enabled'] ) ? 'warning' : 'muted' ); ?>
					</div>
					<p class="lpm-field-description"><?php esc_html_e( 'Direct WhatsApp is not implemented. Webhooks can send JSON to Make, Zapier, or another provider that forwards messages to WhatsApp.', 'lilleprinsen-price-monitor' ); ?></p>
					<?php
					$this->render_checkbox_field( 'notifications_enabled', __( 'Notifications enabled', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'notify_on_new_suggestion', __( 'Notify on new suggestion', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'notify_on_blocked_suggestion', __( 'Notify on blocked suggestion', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'notify_on_failed_check', __( 'Notify on failed check', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_text_field( 'notification_phone_number', __( 'Notification phone number', 'lilleprinsen-price-monitor' ), $settings, '+47 ...' );
					$this->render_select_field(
						'whatsapp_provider',
						__( 'WhatsApp provider placeholder', 'lilleprinsen-price-monitor' ),
						$settings,
						array(
							'none'           => __( 'None', 'lilleprinsen-price-monitor' ),
							'meta_cloud_api' => __( 'Meta Cloud API', 'lilleprinsen-price-monitor' ),
							'twilio'         => __( 'Twilio', 'lilleprinsen-price-monitor' ),
							'make_webhook'   => __( 'Make webhook', 'lilleprinsen-price-monitor' ),
							'zapier_webhook' => __( 'Zapier webhook', 'lilleprinsen-price-monitor' ),
						)
					);
					$this->render_checkbox_field( 'webhook_notifications_enabled', __( 'Enable webhook notifications', 'lilleprinsen-price-monitor' ), $settings, __( 'Disabled by default. When enabled, selected events are posted as JSON to the webhook URL.', 'lilleprinsen-price-monitor' ) );
					$this->render_text_field( 'webhook_url', __( 'Webhook URL', 'lilleprinsen-price-monitor' ), $settings, 'https://hook.make.com/...' );
					$this->render_text_field( 'webhook_secret', __( 'Webhook secret', 'lilleprinsen-price-monitor' ), $settings, __( 'Optional HMAC secret', 'lilleprinsen-price-monitor' ) );
					$this->render_checkbox_field( 'webhook_send_on_new_suggestion', __( 'Webhook on new suggestion', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'webhook_send_on_blocked_suggestion', __( 'Webhook on blocked suggestion', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'webhook_send_on_failed_check', __( 'Webhook on failed check', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'webhook_send_on_recovery_suggestion', __( 'Webhook on recovery suggestion', 'lilleprinsen-price-monitor' ), $settings );
					?>
					<p class="lpm-field-description"><?php esc_html_e( 'Token links are disabled by default and can only approve dry-run suggestions or reject suggestions. They can never update WooCommerce prices; real updates still require logged-in admin confirmation and all safety settings.', 'lilleprinsen-price-monitor' ); ?></p>
					<?php
					$this->render_checkbox_field( 'allow_token_dry_run_approval_links', __( 'Allow token dry-run approval links', 'lilleprinsen-price-monitor' ), $settings, __( 'When enabled, webhook notifications can include one-time approve dry-run and reject links.', 'lilleprinsen-price-monitor' ) );
					$this->render_number_field( 'token_link_expiry_hours', __( 'Token link expiry hours', 'lilleprinsen-price-monitor' ), $settings, 1 );
					$this->render_number_field( 'token_retention_days', __( 'Token retention days', 'lilleprinsen-price-monitor' ), $settings, 1 );
					?>
					<p class="lpm-field-description"><?php esc_html_e( 'WhatsApp action links are webhook payload links for Make/Zapier messages. They are disabled by default and only record dry-run actions unless real updates are reviewed by a logged-in admin.', 'lilleprinsen-price-monitor' ); ?></p>
					<?php
					$this->render_checkbox_field( 'whatsapp_action_links_enabled', __( 'Enable webhook/WhatsApp action links', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_number_field( 'whatsapp_action_link_expiry_hours', __( 'Action link expiry hours', 'lilleprinsen-price-monitor' ), $settings, 1 );
					$this->render_checkbox_field( 'allow_token_match_price_dry_run', __( 'Allow Match price dry-run token action', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'allow_token_match_price_minus_1_dry_run', __( 'Allow Match price -1 kr dry-run token action', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'allow_token_reject', __( 'Allow Reject token action', 'lilleprinsen-price-monitor' ), $settings );
					$this->render_checkbox_field( 'allow_unauthenticated_real_price_update_from_token', __( 'Allow unauthenticated real update from token', 'lilleprinsen-price-monitor' ), $settings, __( 'Strong warning: default off and not used by this version. Real price updates still require logged-in admin confirmation.', 'lilleprinsen-price-monitor' ) );
					?>
				</section>
			</div>

			<div class="lpm-form-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'lilleprinsen-price-monitor' ); ?></button>
			</div>
		</form>
		<form method="post" class="lpm-card lpm-card-spaced lpm-inline-form">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="send_test_notification" />
			<button type="submit" class="button"><?php esc_html_e( 'Send test notification', 'lilleprinsen-price-monitor' ); ?></button>
			<span class="lpm-field-description"><?php esc_html_e( 'This writes a log notification entry only.', 'lilleprinsen-price-monitor' ); ?></span>
		</form>
		<form method="post" class="lpm-card lpm-card-spaced lpm-inline-form">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="send_test_webhook" />
			<button type="submit" class="button"><?php esc_html_e( 'Test webhook', 'lilleprinsen-price-monitor' ); ?></button>
			<span class="lpm-field-description"><?php esc_html_e( 'Sends one JSON test payload to the saved webhook URL. No WooCommerce price update link is included.', 'lilleprinsen-price-monitor' ); ?></span>
		</form>
		<form method="post" class="lpm-card lpm-card-spaced lpm-inline-form">
			<?php wp_nonce_field( 'lpm_admin_action', 'lpm_nonce' ); ?>
			<input type="hidden" name="lpm_action" value="run_retention_cleanup" />
			<button type="submit" class="button"><?php esc_html_e( 'Run cleanup now', 'lilleprinsen-price-monitor' ); ?></button>
			<span class="lpm-field-description"><?php esc_html_e( 'Admin-only cleanup for old operational logs, price observations and used/expired token rows. It does not change products or suggestions.', 'lilleprinsen-price-monitor' ); ?></span>
		</form>
		<?php
	}
}
