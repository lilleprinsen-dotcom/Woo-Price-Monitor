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

The MVP should stay dry-run and may use manual entry or placeholder check records. Real external HTTP fetching should not be included until explicitly requested in a later milestone. Any future runner must be admin-triggered or scheduled with strict limits and must never run from frontend requests.

### Suggestion Engine

Responsible for creating proposed price changes from current WooCommerce prices and competitor observations.

The first suggestion rules should be simple and explainable, for example:

- Compare current product price against the lowest recent competitor observation.
- Apply a configurable margin or fixed adjustment.
- Store the current price snapshot and reason text with each suggestion.

Suggestions should not change WooCommerce prices automatically.

### Approval Workflow

Responsible for approving, rejecting, and recording decisions for price suggestions.

The MVP approval action should remain dry-run. When real updates are eventually added, the update path must use WooCommerce CRUD functions and log the before/after state.

### Logging And Audit Trail

Responsible for storing notable admin actions, check outcomes, suggestion creation, approvals, rejections, and future price update attempts.

Logs should be paginated and queryable by object type, object ID, action, actor, and timestamp.

### Settings

Responsible for plugin configuration such as default margin rules, batch limits, retention windows, and feature flags.

Settings should default to conservative values. Riskier capabilities, especially real competitor checks and product price updates, should be opt-in and implemented in later PRs.

## Request Flow

1. Admin searches for a product by ID, SKU, or keyword.
2. Admin adds selected products to the monitor list.
3. Admin attaches one or more competitor product URLs to each monitored product.
4. Admin records or triggers bounded price observations.
5. The suggestion engine creates dry-run suggestions.
6. Admin approves or rejects suggestions.
7. The plugin logs each action.

## Performance Notes

The store size requires predictable work on every request. Admin screens should use explicit limits and indexed filters. Background jobs should claim small batches, store progress, and release locks quickly. No feature should depend on loading all products, all orders, or all monitored URLs into memory.
