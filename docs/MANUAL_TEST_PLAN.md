# Manual Test Plan

Use a staging WooCommerce site with a small set of products. Keep dry-run mode enabled unless specifically testing blocked real-update settings.

## Local CLI QA

- [ ] Run `bash tools/lint-php.sh` or `composer run lint:php`.
- [ ] Run `bash tools/run-local-tests.sh` or `composer run test:local`.
- [ ] Run `composer run qa` when Composer is available.
- [ ] Confirm local tests pass for `PriceParser`, `PricingRuleService`, and `PriceRecoveryService`.
- [ ] Remember local tests use minimal WordPress stubs only; they do not replace staging admin, WooCommerce, database, HTTP, Action Scheduler, WP-CLI, or webhook testing.

## Checklist

- [ ] Activate the plugin from WordPress admin.
- [ ] Confirm the WooCommerce submenu "Price Monitor" appears.
- [ ] Confirm the custom tables exist: `lpm_monitored_products`, `lpm_competitors`, `lpm_competitor_links`, `lpm_price_observations`, `lpm_price_suggestions`, `lpm_price_match_sessions`, and `lpm_logs`.
- [ ] Deactivate WooCommerce temporarily and confirm the admin dependency notice appears without a fatal error.
- [ ] Reactivate WooCommerce and open WooCommerce > Price Monitor.
- [ ] Save Settings and confirm the success notice appears.
- [ ] Confirm Dashboard, Settings, and Logs still load after the admin tab renderer refactor.
- [ ] Confirm POST actions still show notices and redirects after the admin action handler refactor.
- [ ] Confirm dry-run mode is visible on the Dashboard and enabled by default.
- [ ] Confirm Dashboard health cards show last check time, checks last 24 hours, failed checks last 24 hours, batch lock status, scheduled checks, pending/blocked suggestions, active price match sessions, real-update possibility, and webhook notification state.
- [ ] Confirm Dashboard warnings appear when WooCommerce is inactive, dry-run mode is disabled, emergency update disable is off, scheduled checks use a large batch size, or many checks have failed.
- [ ] Confirm notifications and webhook notifications are disabled by default.
- [ ] Add a staging Make/Zapier/webhook URL, optional secret, and enable webhook notifications.
- [ ] Click Test webhook and confirm the provider receives a JSON payload with `event`, `site_url`, `plugin_version`, `message_text`, `review_url`, and no real price-update action link.
- [ ] If a webhook secret is set, confirm the request includes an `X-LPM-Signature` header.
- [ ] Break the webhook URL temporarily and confirm the admin flow continues while a webhook failure is logged.
- [ ] On Products, confirm the pre-search message says to search by name, SKU, or ID.
- [ ] Search by product ID and confirm at most 20 results.
- [ ] Search by SKU and confirm the expected product appears.
- [ ] Search by product title and confirm the query is bounded and does not load a full dropdown.
- [ ] Add a product to monitoring and confirm it appears in Existing monitored products.
- [ ] Click Edit rules for the monitored product and update priority, strategy, min margin, min price, check frequency, and enabled state.
- [ ] Confirm the rule changes are saved and logged.
- [ ] Select monitored products on the current Products page and apply bulk enable/disable.
- [ ] Select monitored products and apply bulk priority, strategy, check frequency, min margin, and min price changes.
- [ ] Confirm monitored product bulk actions affect only selected rows on the current page.
- [ ] Disable and re-enable monitoring for that product.
- [ ] Open Competitors without a selected product and add a global competitor profile.
- [ ] Edit the profile and update domain, default currency, request delay, request timeout, extraction mode, selectors, stock text, enabled state, JSON-LD/meta/regex flags, JavaScript requirement, and notes.
- [ ] Confirm the competitor profile overview shows enabled state, delay, extraction mode, JavaScript flag, link count, success rate, and last check.
- [ ] Use the profile Test URL field with a simple page and confirm the result card shows price, currency, stock status, extraction method, HTTP status, and error/warning.
- [ ] Mark the profile as requiring JavaScript and confirm profile testing returns the clear internal-checker warning without trying browser automation.
- [ ] Confirm profile-only Test URL does not create a product observation row or update a competitor link.
- [ ] Click Manage competitors for the monitored product.
- [ ] Add a competitor link with a valid http/https URL and attach the competitor profile.
- [ ] Add a competitor link with "No profile / custom name" and confirm custom-name links still work.
- [ ] Mark one competitor link as primary and confirm any previous primary link for the same monitored product is cleared.
- [ ] Leave competitor name blank while selecting a profile and confirm the profile name is used.
- [ ] Try an invalid URL and confirm validation blocks it.
- [ ] Edit the competitor link and confirm changes persist.
- [ ] Disable and re-enable the competitor link.
- [ ] Confirm the competitor link table shows the attached profile and a JavaScript warning pill when applicable.
- [ ] Select competitor links and apply bulk enable/disable, set match type, and delete selected on a staging-only link.
- [ ] Confirm competitor bulk actions affect only selected links.
- [ ] Click Test check on a simple competitor/test page and confirm detected price, currency, stock status when configured, and extraction method notice.
- [ ] Confirm a JavaScript-required profile attached to a link returns the clear warning and does not attempt browser rendering.
- [ ] Confirm each Test check creates a row in the History tab without storing raw HTML.
- [ ] Confirm failed Test check stores a clear error and writes a log.
- [ ] Create a suggestion from a competitor link with `last_price`.
- [ ] If webhook notifications are enabled for new suggestions, confirm the webhook payload includes product/suggestion fields and a WordPress admin `review_url`.
- [ ] Confirm the Approvals row shows margin after, warnings, and a rule summary when rule data is available.
- [ ] Confirm skipped suggestions are logged when the price difference is below the configured minimum.
- [ ] Confirm blocked suggestions are created when the drop exceeds the max allowed drop percent.
- [ ] If webhook notifications are enabled for blocked suggestions, confirm a blocked suggestion sends a webhook payload.
- [ ] If webhook notifications are enabled for recovery suggestions, create a staging recovery suggestion and confirm it sends a webhook payload.
- [ ] Set recovery basis to `primary_competitor`, create a fresh primary competitor price, and confirm recovery uses it.
- [ ] Set recovery basis to `primary_competitor` with no primary link and confirm recovery becomes manual review.
- [ ] Set recovery basis to `all_competitors_must_increase` and confirm recovery is skipped while any enabled exact/similar competitor remains lower.
- [ ] Set `recovery_max_competitor_price_age_hours` low on staging and confirm stale primary/exact/similar competitor data forces manual review.
- [ ] Confirm recovery suggestions show original regular, original sale, original active, current WooCommerce, new competitor, and suggested recovery prices.
- [ ] Open Approvals and confirm pending, blocked, approved dry-run, rejected, and recovery counts.
- [ ] Edit a suggested price and confirm the old/new price is logged.
- [ ] Approve a pending suggestion as dry-run and confirm WooCommerce product price is unchanged.
- [ ] Reject a suggestion and confirm status and logs update.
- [ ] Confirm real update controls remain blocked by default with dry-run mode and emergency disable enabled.
- [ ] Confirm webhook `review_url` and `approval_url` require normal WordPress admin login.
- [ ] Confirm no unauthenticated webhook link can perform a real WooCommerce price update.
- [ ] Open Logs and test filters for level, event, and product ID.
- [ ] Confirm log pagination does not load all rows at once.
- [ ] Open History and test filters for product ID, competitor link ID, success/failed, and date range.
- [ ] Confirm History shows active price match sessions with original active price, matched price, recovery strategy, status, and actions.
- [ ] End an `active_dry_run` price match session from History and confirm WooCommerce price is unchanged.
- [ ] Confirm real active sessions cannot be ended from the dry-run-only History action.
- [ ] Confirm the competitor management screen shows the latest five checks for the selected monitored product.
- [ ] Open Import / Export and download the sample CSV template.
- [ ] Upload a small valid CSV and confirm the preview shows valid rows before anything is committed.
- [ ] Upload rows with missing products, invalid URLs, invalid strategy, invalid enabled values, and duplicate competitor URLs; confirm warnings/errors are clear.
- [ ] Confirm a valid preview and verify monitored products/rules/competitor links are created or updated.
- [ ] Confirm invalid preview rows are logged as skipped during confirm.
- [ ] Try a file larger than the configured cap and confirm it is rejected.
- [ ] Export monitored products/links and confirm CSV includes product/rule/link fields without exceeding the safe row cap.
- [ ] Export pending suggestions, recent failed checks, and price observations.
- [ ] Confirm scheduled checks are disabled by default in Settings.
- [ ] Confirm `check_batch_lock_minutes` defaults to `10` and can be saved.
- [ ] Click Run one small check batch now only on staging and confirm it respects `max_urls_per_batch`.
- [ ] Set a temporary `lpm_check_batch_lock` transient in staging and confirm Run one small check batch now is skipped with a clear warning and log entry.
- [ ] Force a failed competitor check and confirm the competitor link increments `consecutive_failures` and sets `next_check_after`.
- [ ] Force a successful competitor check and confirm `consecutive_failures` resets to `0` and `next_check_after` clears.
- [ ] Confirm batch selection skips links where `next_check_after` is in the future.
- [ ] Confirm competitor profile `request_delay_seconds` prevents checking two links from the same profile too close together in a batch.
- [ ] Save retention settings for operational logs, debug logs, successful observations, failed observations, and audit logs.
- [ ] Click Run cleanup now in Settings and confirm old operational/debug logs and old observations are deleted while approval/update audit logs remain.
- [ ] Run `wp lpm status` on staging and confirm it prints plugin enabled, dry-run, scheduled checks, pending suggestions, failed checks last 24h, active sessions, emergency update disable, real-update possibility, WooCommerce state, and lock state.
- [ ] Run `wp lpm check-batch --limit=1` on staging and confirm it respects the lock and does not update WooCommerce prices.
- [ ] Run `wp lpm cleanup` on staging and confirm it logs the cleanup summary.
- [ ] Send test notification and confirm it writes a log entry only.
- [ ] Confirm no WhatsApp, webhook, SMS, or email provider call is made.

## Pricing Rule Examples

- [ ] Example 1: Current price `1299`, competitor price `1199`, strategy `match_competitor`, rounding `none`. Create a suggestion and confirm suggested price is `1199`.
- [ ] Example 2: Current price `1299`, competitor price `1199`, strategy `match_competitor`, rounding `end_99`. Create a suggestion and confirm suggested price remains `1199` or the nearest compatible `end_99` result.
- [ ] Example 3: Set cost source to custom meta key, store cost `1000`, set minimum margin `25%`, competitor price `1099`. Create a suggestion and confirm it is blocked with a margin explanation.
- [ ] Example 4: Set product minimum price `1190`, competitor price `1090`. Create a suggestion and confirm it is blocked because the suggested price is below the product minimum price.
