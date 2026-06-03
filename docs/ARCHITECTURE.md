# Architecture

Lilleprinsen Price Monitor is an admin-only WooCommerce competitor price monitoring plugin. It stores monitor state in custom tables, keeps admin queries bounded, and treats price changes as an explicitly guarded workflow.

## Runtime Boundaries

- The main plugin bootstrap only initializes during `is_admin()`, `wp_doing_cron()`, or `WP_CLI`.
- Normal frontend requests should not load the plugin coordinator, admin screens, admin assets, product search, price checks, or job scheduling.
- Manual competitor checks are admin-triggered and use `wp_remote_get()` with the configured timeout.
- Scheduled checks are disabled by default and only use the Action Scheduler path when explicitly enabled.
- Scheduled checks never update WooCommerce prices.
- Real price updates are blocked unless dry-run mode is off, emergency disable is off, real updates are allowed, manual approval is required, and explicit confirmation is submitted.

## Modules

### Bootstrap

`lilleprinsen-price-monitor.php` defines plugin constants, registers the autoloader, activation/deactivation hooks, and initializes `Plugin` only for admin, cron, or WP-CLI contexts.

`src/Plugin.php` wires settings, repository, admin UI, services, notification channels, job scheduler, retention cleanup, and WP-CLI commands. Capability checks use `manage_woocommerce` or `manage_options`.

For cron and WP-CLI contexts, `Plugin` also runs the schema version check directly so batch commands do not query newly added columns before an admin page visit has triggered `admin_init`.

### Activation And Schema

`src/Activator.php` creates custom tables and settings defaults. `src/Database/Schema.php` owns `dbDelta()` table definitions and schema version storage.

Current custom tables:

- `lpm_monitored_products`
- `lpm_competitors`
- `lpm_competitor_links`
- `lpm_price_observations`
- `lpm_price_suggestions`
- `lpm_price_match_sessions`
- `lpm_logs`

`src/Database/Repository.php` provides paginated reads, safe count queries, writes, logging, observation history, suggestion review state, competitor link state, and price match session helpers.

Competitor links include retry/backoff state through `consecutive_failures` and `next_check_after`. Failed checks delay the next batch attempt by 1 hour, 6 hours, then 24 hours for later failures. Successful checks reset the failure counter and clear the backoff timestamp.

### Admin UI

`src/Admin/AdminMenu.php` adds the WooCommerce submenu. `src/Admin/AdminPage.php` remains the main admin coordinator and still renders Products, Approvals, Competitors, History, and Import / Export while the admin UI is being split gradually.

Current admin split:

- `src/Admin/AdminActionHandler.php` owns POST action nonce/capability checks, action key sanitization, and action routing. It calls public action methods on `AdminPage` as an interim bridge.
- `src/Admin/AdminViewHelpers.php` contains shared UI helpers used by extracted tab renderers.
- `src/Admin/Tabs/DashboardTab.php` renders the Dashboard tab.
- `src/Admin/Tabs/SettingsTab.php` renders the Settings tab.
- `src/Admin/Tabs/LogsTab.php` renders the Logs tab.
- `src/Admin/Tabs/ProductsTab.php`, `ApprovalsTab.php`, `CompetitorsTab.php`, `HistoryTab.php`, and `ImportExportTab.php` are lightweight placeholders for future extraction; current behavior still lives in `AdminPage`.

The Competitors tab has two layers:

- Global competitor profiles in `lpm_competitors` for reusable domain, delay, timeout, extraction mode, selector, stock text, reliability, and JavaScript-requirement metadata.
- Product-specific direct competitor links in `lpm_competitor_links`, optionally attached to a profile through nullable `competitor_id`.

`src/Admin/ProductSearchService.php` contains the bounded WooCommerce product search flow:

- exact product ID lookup
- SKU lookup
- limited title query
- max 20 display results
- conversion of WooCommerce product objects into escaped-ready display arrays

`src/Admin/AdminNoticeStore.php` stores one-time user-specific notices across redirects. `src/Admin/Notices.php` renders dependency notices, including WooCommerce inactive state.

`src/Admin/CsvImportService.php` provides bounded CSV import preview and commit behavior. It accepts CSV files up to 512 KB and previews up to 500 non-empty rows. Product matching uses `product_id` first, then `sku`; no title search, full catalog scan, or broad WooCommerce query is used during import.

`src/Assets/AdminAssets.php` loads `assets/admin.css` and `assets/admin.js` only on the plugin admin page.

### Price Checking

`src/Service/PriceCheckService.php` runs a single bounded competitor URL check from admin or job contexts. It uses global settings or competitor profile overrides for timeout, sends a reasonable user agent, handles `WP_Error` and non-200 responses, and creates one `lpm_price_observations` row for each attempted check when the repository is available.

Observation rows store check metadata such as product ID, competitor link ID, observed price, currency, stock status, extraction method, HTTP status, success flag, error message, response time, and checked timestamp. Raw HTML and full response bodies are not stored.

`src/Service/PriceParser.php` parses fetched HTML with optional competitor profile rules. Supported extraction modes are `auto`, `json_ld`, `meta_tags`, `selector`, and `visible_regex`. The default auto flow remains:

1. JSON-LD Product offers price.
2. Common product price meta tags.
3. Basic visible NOK/kr price patterns.

If a profile uses selector mode, limited selector extraction is attempted first. Selector support is intentionally small and dependency-free: `.class`, `#id`, and `[attr="value"]` patterns are translated through `DOMDocument` and `DOMXPath`. Profile stock selectors can classify stock as `in_stock`, `out_of_stock`, or `unknown` when matching text is configured.

Profiles marked as requiring JavaScript return a clear warning because the internal checker does not render JavaScript. Browser automation, anti-bot bypassing, and external scraper workers are future work and are not implemented here.

### Pricing Rules And Suggestions

`src/Service/PricingRuleService.php` is the explainable rule engine for dry-run suggestions. It calculates the suggested price and returns a status, plain-English reason, structured rule details, margin snapshot when product cost is available, and warnings.

Current rule inputs include current WooCommerce price, competitor price, suggestion type, monitored product row, active price match session, optional product cost, optional currency, sale state, and stock status.

Current rule controls include:

- default and product-level pricing strategy
- beat/stay-above amounts
- rounding mode
- product-level minimum price
- default or product-level minimum margin percent
- optional custom-meta cost lookup for one product at suggestion time
- optional minimum profit amount
- VAT comparison mode label and VAT rate
- maximum allowed drop and increase percentages
- sale-product and out-of-stock blocking settings

`src/Service/SuggestionService.php` compares the current WooCommerce product price with a competitor link's last detected price, asks `PricingRuleService` for the final dry-run decision, prevents duplicate pending suggestions for the same observed competitor price, and stores pending, blocked, skipped, or manual-review outcomes. Manual-review outcomes are stored as approval-inbox suggestions with `suggestion_type = manual_review` and workflow status `pending`.

Suggestion rows also store `margin_after_change`, `rule_details`, and `warnings` when available so the Approvals inbox can explain why a suggestion was made or blocked.

Suggestion types include:

- `price_match_down`
- `price_match_up`
- `restore_previous_active_price`
- `restore_previous_regular_price`
- `restore_previous_sale_price`
- `manual_review`
- `blocked`

### Price Recovery

`src/Service/PriceRecoveryService.php` determines future price-up or restore suggestions from an active price match session. It does not update WooCommerce prices. It checks prior active, sale, and regular price state and avoids recovery suggestions when another active competitor still has a lower valid last price than the proposed recovery price.

### Guarded Price Updates

`src/Service/PriceUpdateService.php` is the first guarded real-update foundation. It uses WooCommerce CRUD APIs and never direct SQL price metadata writes. It validates dry-run mode, emergency disable, explicit allow setting, manual approval, suggestion status, allowed suggestion type, positive suggested price, unchanged product price snapshot, and maximum drop limit.

This service is present for future controlled use. Defaults keep real updates blocked.

### Jobs

`src/Jobs/JobScheduler.php` registers the Action Scheduler action and an admin-only scheduling check. Scheduled checks are disabled by default. When enabled without Action Scheduler, it logs a warning and does not register a fallback job.

`src/Jobs/CheckCompetitorLinkJob.php` processes only due enabled competitor links for enabled monitored products, capped by `max_urls_per_batch`. It skips links attached to disabled competitor profiles and applies a simple request-delay guard so one batch does not check multiple links from the same delayed profile too quickly. It can create suggestions only when `create_suggestions_from_scheduled_checks` is enabled, which defaults off.

### Notifications

`src/Notifications/NotificationService.php` routes notification events through configured channels. `src/Notifications/NotificationInterface.php` defines the channel contract.

Current notification modules:

- `src/Notifications/LogNotificationChannel.php` writes log entries for debugging/audit.
- `src/Notifications/WebhookNotificationChannel.php` posts JSON payloads to Make, Zapier, or another webhook provider when enabled.
- `src/Notifications/NotificationMessageBuilder.php` builds structured payloads and human-readable `message_text`.
- `src/Service/ReviewLinkService.php` builds safe WordPress admin review links.

Notifications are disabled by default. Webhook notifications also require `webhook_notifications_enabled = 1` and a valid `webhook_url`. The webhook channel can include an `X-LPM-Signature` HMAC-SHA256 header when `webhook_secret` is set. Webhook failures are logged and do not block the admin or batch flow.

Webhook payloads can be received by Make/Zapier and forwarded to WhatsApp by those external tools. Direct Meta WhatsApp Cloud API and Twilio WhatsApp integrations are not implemented.

Review links in notification payloads point to normal WordPress admin pages and require the usual admin login. No unauthenticated real WooCommerce price-update links are created.

### Retention Cleanup

`src/Service/RetentionService.php` provides an admin-only and WP-CLI-invoked cleanup workflow. It deletes old debug and known operational logs plus old price observation rows based on retention settings. Approval and real price-update audit logs are preserved. Cleanup is not scheduled automatically.

### WP-CLI

`src/Cli/Command.php` registers safe commands when `WP_CLI` is available:

- `wp lpm check-batch --limit=10`
- `wp lpm cleanup`
- `wp lpm status`

The check-batch command is capped at 100 links, respects the shared batch lock, and does not update WooCommerce prices. Status prints dry-run state, scheduled-check state, pending/blocked suggestions, failed checks in the last 24 hours, active price match sessions, emergency update disable state, real-update possibility, WooCommerce state, and batch lock state.

### Settings

`src/Settings/Settings.php` stores one option: `lpm_settings`. It defines defaults, sanitization, getters, updates, and settings form handling.

Important conservative defaults:

- `dry_run_mode = 1`
- `scheduled_checks_enabled = 0`
- `create_suggestions_from_scheduled_checks = 0`
- `disable_all_price_updates = 1`
- `allow_real_price_updates = 0`
- `require_confirmation_for_real_updates = 1`
- `notifications_enabled = 0`
- `webhook_notifications_enabled = 0`
- `webhook_url = ''`
- `webhook_send_on_new_suggestion = 1`
- `webhook_send_on_blocked_suggestion = 1`
- `webhook_send_on_failed_check = 0`
- `webhook_send_on_recovery_suggestion = 1`
- `allow_token_dry_run_approval_links = 0`
- `token_link_expiry_hours = 24`
- `max_urls_per_batch = 10`
- `check_batch_lock_minutes = 10`
- `observation_retention_days = 90`
- `failed_observation_retention_days = 30`
- `log_retention_days = 90`
- `debug_log_retention_days = 14`
- `keep_audit_logs_forever = 1`
- `default_pricing_strategy = match_competitor`
- `rounding_mode = none`
- `cost_source = none`
- `block_if_cost_missing = 0`
- `max_allowed_price_increase_percent = 50`
- `block_suggestions_for_sale_products = 0`
- `block_suggestions_for_out_of_stock_products = 0`
- `rows_per_page = 25`

Retention cleanup is manual and admin-only. Automatic cleanup is not implemented.

## Request Flows

### Product Monitoring

1. Admin searches by product ID, SKU, or name.
2. Product search returns at most 20 results.
3. Admin adds a selected product to monitoring.
4. Repository inserts or re-enables a row in `lpm_monitored_products`.
5. Action is logged in `lpm_logs`.

### CSV Import

1. Admin uploads a CSV on the Import / Export tab.
2. The file is rejected if it is not `.csv`, is empty, is larger than 512 KB, or exceeds the preview row cap.
3. `CsvImportService` validates each row by product ID or SKU only.
4. Preview shows valid rows, warnings, invalid rows, product matches, not-found products, duplicate products, and duplicate competitor links.
5. Confirm import reads the transient preview, adds or re-enables monitored products, applies provided rule fields, adds non-duplicate competitor links, and logs imported/skipped rows.
6. Blank optional rule fields leave existing values unchanged during commit.

### CSV Export

Exports are admin-only POST actions protected by the normal admin nonce. Exports stream CSV responses and are capped at 1,000 rows by default.

Current export types:

- monitored products and competitor links
- pending suggestions
- recent failed competitor checks
- price observations

### Bulk Actions

Products and competitor links support selected-row bulk actions on the current paginated page. Bulk actions apply only to submitted IDs, capped at 100 IDs per request.

Current monitored product bulk actions:

- enable selected
- disable selected
- set priority
- set strategy
- set check frequency
- set minimum margin
- set minimum price
- disable selected monitoring rows

Current competitor link bulk actions:

- enable selected
- disable selected
- delete selected
- set match type

### Competitor Check And Suggestion

1. Admin optionally creates a global competitor profile with extraction rules.
2. Admin adds or edits a competitor link for one monitored product and can attach that profile.
3. Admin clicks "Test check".
4. `PriceCheckService` fetches one URL and `PriceParser` attempts price extraction using profile rules when present.
5. Repository creates a price observation history row without storing raw HTML.
6. Repository updates the competitor link's last price, currency, stock status, timestamp, and error state.
7. Admin can create a dry-run suggestion from the stored last price.
8. `PricingRuleService` applies strategy, rounding, min price, margin/cost, and safety rules.
9. Suggestion appears in the Approvals inbox with rule summary, warnings, and margin-after data when available.

### Batch Checks

1. Admin clicks "Run one small check batch now", Action Scheduler invokes the scheduled action, or WP-CLI runs `wp lpm check-batch`.
2. `CheckCompetitorLinkJob` acquires the shared `lpm_check_batch_lock` transient.
3. If the lock exists, the batch is skipped and a log entry records that another batch is running.
4. Repository selects only due enabled competitor links for enabled monitored products, capped by the configured limit.
5. Links with `next_check_after` in the future are skipped by the SQL selection.
6. Competitor profile `request_delay_seconds` is respected in SQL selection and within the in-memory batch.
7. The lock is released after normal completion; fatal errors rely on transient expiry.

### Retention Cleanup

1. Admin clicks "Run cleanup now" in Settings or WP-CLI runs `wp lpm cleanup`.
2. `RetentionService` computes retention cutoffs from settings.
3. Repository deletes old debug and operational log events while preserving approval/update audit logs.
4. Repository deletes successful and failed observations according to their separate retention settings.
5. A cleanup summary is logged.

### Competitor Profile Test

1. Admin edits a competitor profile.
2. Admin enters a one-off Test URL.
3. `PriceCheckService` fetches and parses that URL with the profile rules.
4. The result is shown in the admin UI with price, currency, stock status, extraction method, HTTP status, and error/warning.
5. No product, competitor link, or observation row is updated by the profile-only test.

### Observation History

1. Admin opens the History tab.
2. Admin filters by product ID, competitor link ID, success/failure, and date range.
3. Repository returns paginated observation rows ordered by check time.
4. The competitor management screen also shows the latest five observations for the selected monitored product.

### Approval

1. Admin reviews pending or blocked suggestions.
2. Admin can adjust the suggested price.
3. Admin approves dry-run or rejects.
4. Dry-run approval logs the decision and may create a dry-run price match session for recovery state.
5. WooCommerce prices are not changed unless every real-update guard is explicitly satisfied and a separate confirmation is submitted.

### Webhook Notifications

1. Admin enables notifications and webhook notifications in Settings.
2. Admin saves a Make/Zapier/webhook URL and optional secret.
3. New, blocked, recovery, and failed-check events call `NotificationService`.
4. The log channel writes audit entries according to the log notification toggles.
5. The webhook channel checks its own event toggles and posts a bounded JSON payload.
6. Payloads include product/suggestion context, `message_text`, `review_url`, and `approval_url`.
7. `approval_url` is a normal admin review URL, not an unauthenticated update action.
8. Optional token dry-run approval link settings are stored for future work only; token approval is not implemented yet.

## Performance Notes

The store size requires predictable request work. Admin lists use pagination, product search is bounded, custom table queries use indexed columns, background work is capped, and WP-CLI operations require explicit bounded commands. No current flow loads all products, all orders, or all competitor links into memory.
