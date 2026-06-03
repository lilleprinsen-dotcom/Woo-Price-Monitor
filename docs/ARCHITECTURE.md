# Architecture

Woo Price Monitor is planned as an admin-only WooCommerce plugin with custom tables and dry-run pricing workflows.

## Design Constraints

- Admin-only by default.
- No frontend hooks or customer-facing behavior.
- No scraping, external HTTP requests, competitor checks, or heavy queries on frontend requests.
- No automatic scan of the full product catalog.
- Custom tables for monitor data instead of `wp_postmeta`.
- Paginated, indexed, bounded admin and background operations.
- WooCommerce CRUD APIs for future price updates.

## Planned Modules

### Plugin Bootstrap

Responsible for loading the plugin, checking WooCommerce availability, registering admin-only hooks, and wiring services together.

The bootstrap layer should avoid frontend hooks. Any scheduled or background processing should be registered independently and must run with explicit batch limits.

### Database Schema

Responsible for custom table creation and migrations.

Planned tables:

- `wpm_monitored_products`: selected WooCommerce product IDs, monitor status, timestamps, and optional admin notes.
- `wpm_competitor_urls`: competitor name, direct product URL, active status, and relation to a monitored product.
- `wpm_price_checks`: observed competitor price, currency, source URL, check status, error metadata, and timestamps.
- `wpm_price_suggestions`: current WooCommerce price snapshot, competitor comparison data, suggested price, reason, status, reviewer, and timestamps.
- `wpm_action_logs`: admin actions, object references, before/after snapshots, and timestamps.

Important indexes should cover product IDs, monitor status, competitor URL status, suggestion status, check timestamps, and created timestamps.

### Admin Navigation

Responsible for WooCommerce admin menu entries and screen routing.

Initial screens should include:

- Monitor list.
- Add products to monitor.
- Product monitor detail with competitor URLs.
- Price observations.
- Suggestions and approval queue.
- Settings.

### Product Selection

Responsible for searching and adding selected WooCommerce products to the monitor list.

This module must not scan all products. It should use paginated search, exact product ID lookup, SKU lookup, or bounded WooCommerce product queries. Duplicate monitored product rows should be prevented by a unique product ID index.

### Competitor URL Management

Responsible for adding, editing, disabling, and listing direct competitor product URLs.

URLs are untrusted admin-provided data. They should be validated, sanitized, escaped on output, and stored separately from WooCommerce post meta.

### Price Observation Runner

Responsible for recording competitor price observations.

Manual checks are admin-triggered and bounded. The production-style background skeleton uses `JobScheduler` and `CheckCompetitorLinkJob` to process only enabled competitor links that are due by `last_checked_at` and `check_frequency_hours`, capped by `max_urls_per_batch`.

Action Scheduler is preferred when available. If it is not available, the plugin logs that no fallback job was registered. Background checks must not run from normal frontend page loads, must not scan all products or all links, and must not update WooCommerce prices.

### Suggestion Engine

Responsible for creating proposed price changes from current WooCommerce prices and competitor observations.

The first suggestion rules should be simple and explainable, for example:

- Compare current product price against the lowest recent competitor observation.
- Apply a configurable margin or fixed adjustment.
- Store the current price snapshot and reason text with each suggestion.

Suggestions should not change WooCommerce prices automatically.

Scheduled checks can optionally create dry-run suggestions when `create_suggestions_from_scheduled_checks` is enabled. This setting defaults off.

### Approval Workflow

Responsible for approving, rejecting, and recording decisions for price suggestions.

Dry-run approval remains the default. The real update foundation is behind strict settings:

- `dry_run_mode` must be disabled.
- `disable_all_price_updates` must be disabled.
- `allow_real_price_updates` must be enabled.
- `require_manual_approval` and `require_confirmation_for_real_updates` must be enabled.
- The suggestion must be pending and of an allowed suggestion type.

Real update confirmation is a single-product admin flow. It uses WooCommerce CRUD APIs only and logs old/new price state. Scheduled checks never update prices.

### Price Recovery

Price recovery suggestions use active price match session state to decide whether to suggest matching a higher competitor price or restoring previous active, sale, or regular prices. Recovery settings affect suggestion creation. Ending or creating real match sessions only happens after explicit admin approval.

### Notifications

Notifications are abstracted through `NotificationService` and channel interfaces. The only current channel is `LogNotificationChannel`, which writes audit log entries describing what would have been sent. WhatsApp provider fields are placeholders only; no real WhatsApp, webhook, Twilio, or Meta API call is implemented.

### Logging And Audit Trail

Responsible for storing notable admin actions, check outcomes, suggestion creation, approvals, rejections, and future price update attempts.

Logs should be paginated and queryable by object type, object ID, action, actor, and timestamp.

### Settings

Responsible for plugin configuration such as default margin rules, batch limits, retention windows, and feature flags.

Settings should default to conservative values. Riskier capabilities, especially real competitor checks and product price updates, should be opt-in and implemented in later PRs.

Important safety defaults:

- Scheduled checks disabled.
- Scheduled suggestion creation disabled.
- Notifications disabled.
- Dry-run mode enabled.
- Emergency disable for all price updates enabled.
- Real price updates disabled.

## Request Flow

1. Admin searches for a product by ID, SKU, or keyword.
2. Admin adds selected products to the monitor list.
3. Admin attaches one or more competitor product URLs to each monitored product.
4. Admin records or triggers bounded price observations.
5. The suggestion engine creates dry-run suggestions.
6. Admin approves or rejects suggestions.
7. If real updates are explicitly enabled, admin confirms a single product update.
8. The plugin logs each action.

## Performance Notes

The store size requires predictable work on every request. Admin screens should use explicit limits and indexed filters. Background jobs should claim small batches, store progress, and release locks quickly. No feature should depend on loading all products, all orders, or all monitored URLs into memory.
