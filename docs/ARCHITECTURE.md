# Architecture

Lilleprinsen Price Monitor is an admin-only WooCommerce competitor price monitoring plugin. It stores monitor state in custom tables, keeps admin queries bounded, and treats price changes as an explicitly guarded workflow.

## Runtime Boundaries

- The main plugin bootstrap only initializes during `is_admin()` or `wp_doing_cron()`.
- Normal frontend requests should not load the plugin coordinator, admin screens, admin assets, product search, price checks, or job scheduling.
- Manual competitor checks are admin-triggered and use `wp_remote_get()` with the configured timeout.
- Scheduled checks are disabled by default and only use the Action Scheduler path when explicitly enabled.
- Scheduled checks never update WooCommerce prices.
- Real price updates are blocked unless dry-run mode is off, emergency disable is off, real updates are allowed, manual approval is required, and explicit confirmation is submitted.

## Modules

### Bootstrap

`lilleprinsen-price-monitor.php` defines plugin constants, registers the autoloader, activation/deactivation hooks, and initializes `Plugin` only for admin or cron contexts.

`src/Plugin.php` wires settings, repository, admin UI, services, notification channels, and job scheduler. Capability checks use `manage_woocommerce` or `manage_options`.

### Activation And Schema

`src/Activator.php` creates custom tables and settings defaults. `src/Database/Schema.php` owns `dbDelta()` table definitions and schema version storage.

Current custom tables:

- `lpm_monitored_products`
- `lpm_competitor_links`
- `lpm_price_observations`
- `lpm_price_suggestions`
- `lpm_price_match_sessions`
- `lpm_logs`

`src/Database/Repository.php` provides paginated reads, safe count queries, writes, logging, observation history, suggestion review state, competitor link state, and price match session helpers.

### Admin UI

`src/Admin/AdminMenu.php` adds the WooCommerce submenu. `src/Admin/AdminPage.php` remains the main renderer and action controller for the Dashboard, Products, Approvals, Competitors, History, Settings, and Logs tabs.

`src/Admin/ProductSearchService.php` contains the bounded WooCommerce product search flow:

- exact product ID lookup
- SKU lookup
- limited title query
- max 20 display results
- conversion of WooCommerce product objects into escaped-ready display arrays

`src/Admin/AdminNoticeStore.php` stores one-time user-specific notices across redirects. `src/Admin/Notices.php` renders dependency notices, including WooCommerce inactive state.

`src/Assets/AdminAssets.php` loads `assets/admin.css` and `assets/admin.js` only on the plugin admin page.

### Price Checking

`src/Service/PriceCheckService.php` runs a single bounded competitor URL check from admin or job contexts. It uses settings for timeout, sends a reasonable user agent, handles `WP_Error` and non-200 responses, and creates one `lpm_price_observations` row for each attempted check when the repository is available.

Observation rows store check metadata such as product ID, competitor link ID, observed price, currency, extraction method, HTTP status, success flag, error message, response time, and checked timestamp. Raw HTML and full response bodies are not stored.

`src/Service/PriceParser.php` parses the fetched HTML in this order:

1. JSON-LD Product offers price.
2. Common product price meta tags.
3. Basic visible NOK/kr price patterns.

Parsing is intentionally MVP-level and does not crawl other pages.

### Suggestions

`src/Service/SuggestionService.php` compares the current WooCommerce product price with a competitor link's last detected price. It applies minimum difference and maximum price drop safety settings, prevents duplicate pending suggestions for the same observed competitor price, and stores pending, blocked, skipped, or manual-review outcomes.

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

`src/Jobs/CheckCompetitorLinkJob.php` processes only due enabled competitor links for enabled monitored products, capped by `max_urls_per_batch`. It can create suggestions only when `create_suggestions_from_scheduled_checks` is enabled, which defaults off.

### Notifications

`src/Notifications/NotificationService.php` routes notification events through configured channels. `src/Notifications/NotificationInterface.php` defines the channel contract. `src/Notifications/LogNotificationChannel.php` writes log entries describing what would have been sent.

Notifications are disabled by default, and the only current channel is log-only. WhatsApp provider settings are placeholders and make no external provider calls.

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
- `max_urls_per_batch = 10`
- `observation_retention_days = 90`
- `failed_observation_retention_days = 30`
- `rows_per_page = 25`

Retention settings are stored for future admin-only cleanup. Automatic cleanup is not implemented yet.

## Request Flows

### Product Monitoring

1. Admin searches by product ID, SKU, or name.
2. Product search returns at most 20 results.
3. Admin adds a selected product to monitoring.
4. Repository inserts or re-enables a row in `lpm_monitored_products`.
5. Action is logged in `lpm_logs`.

### Competitor Check And Suggestion

1. Admin adds or edits a competitor link for one monitored product.
2. Admin clicks "Test check".
3. `PriceCheckService` fetches one URL and `PriceParser` attempts price extraction.
4. Repository creates a price observation history row without storing raw HTML.
5. Repository updates the competitor link's last price, currency, timestamp, and error state.
6. Admin can create a dry-run suggestion from the stored last price.
7. Suggestion appears in the Approvals inbox.

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

## Performance Notes

The store size requires predictable request work. Admin lists use pagination, product search is bounded, custom table queries use indexed columns, and background work is capped. No current flow loads all products, all orders, or all competitor links into memory.
