# Manual Test Plan

Use a staging WooCommerce site with a small set of products. Keep dry-run mode enabled unless specifically testing blocked real-update settings.

## Checklist

- [ ] Activate the plugin from WordPress admin.
- [ ] Confirm the WooCommerce submenu "Price Monitor" appears.
- [ ] Confirm the custom tables exist: `lpm_monitored_products`, `lpm_competitor_links`, `lpm_price_suggestions`, `lpm_price_match_sessions`, and `lpm_logs`.
- [ ] Deactivate WooCommerce temporarily and confirm the admin dependency notice appears without a fatal error.
- [ ] Reactivate WooCommerce and open WooCommerce > Price Monitor.
- [ ] Save Settings and confirm the success notice appears.
- [ ] Confirm dry-run mode is visible on the Dashboard and enabled by default.
- [ ] On Products, confirm the pre-search message says to search by name, SKU, or ID.
- [ ] Search by product ID and confirm at most 20 results.
- [ ] Search by SKU and confirm the expected product appears.
- [ ] Search by product title and confirm the query is bounded and does not load a full dropdown.
- [ ] Add a product to monitoring and confirm it appears in Existing monitored products.
- [ ] Disable and re-enable monitoring for that product.
- [ ] Click Manage competitors for the monitored product.
- [ ] Add a competitor link with a valid http/https URL.
- [ ] Try an invalid URL and confirm validation blocks it.
- [ ] Edit the competitor link and confirm changes persist.
- [ ] Disable and re-enable the competitor link.
- [ ] Click Test check on a simple competitor/test page and confirm detected price, currency, and extraction method notice.
- [ ] Confirm each Test check creates a row in the History tab without storing raw HTML.
- [ ] Confirm failed Test check stores a clear error and writes a log.
- [ ] Create a suggestion from a competitor link with `last_price`.
- [ ] Confirm skipped suggestions are logged when the price difference is below the configured minimum.
- [ ] Confirm blocked suggestions are created when the drop exceeds the max allowed drop percent.
- [ ] Open Approvals and confirm pending, blocked, approved dry-run, rejected, and recovery counts.
- [ ] Edit a suggested price and confirm the old/new price is logged.
- [ ] Approve a pending suggestion as dry-run and confirm WooCommerce product price is unchanged.
- [ ] Reject a suggestion and confirm status and logs update.
- [ ] Confirm real update controls remain blocked by default with dry-run mode and emergency disable enabled.
- [ ] Open Logs and test filters for level, event, and product ID.
- [ ] Confirm log pagination does not load all rows at once.
- [ ] Open History and test filters for product ID, competitor link ID, success/failed, and date range.
- [ ] Confirm the competitor management screen shows the latest five checks for the selected monitored product.
- [ ] Confirm scheduled checks are disabled by default in Settings.
- [ ] Click Run one small check batch now only on staging and confirm it respects `max_urls_per_batch`.
- [ ] Send test notification and confirm it writes a log entry only.
- [ ] Confirm no WhatsApp, webhook, SMS, or email provider call is made.
