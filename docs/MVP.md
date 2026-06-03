# MVP Plan

Lilleprinsen Price Monitor is being built in small, reviewable pull requests. The MVP keeps monitoring admin-only, dry-run first, and safe for a large WooCommerce catalog.

## Completed Foundations

### Milestone 0: Project Documentation

- Added `AGENTS.md`.
- Documented purpose, constraints, architecture, and staged scope.

### Milestone 1: Plugin Shell And Custom Tables

- Added the main plugin file, constants, autoloader, activation/deactivation hooks, and uninstall placeholder.
- Added admin-only bootstrap with WooCommerce dependency notice.
- Added custom database schema and schema version option.
- Added initial settings storage.

### Milestone 2: Admin Shell

- Added WooCommerce submenu "Price Monitor".
- Added Dashboard, Products, Approvals, Competitors, Settings, and Logs tabs.
- Added plugin admin assets loaded only on the plugin page.
- Added dashboard cards and system status.

### Milestone 3: Product And Competitor Workflow

- Added bounded product search by ID, SKU, or title, limited to 20 results.
- Added selected products to `lpm_monitored_products`.
- Added paginated monitored product list.
- Added competitor link add, edit, enable, disable, and delete actions.
- Logged admin actions.

### Milestone 4: Manual Price Checks

- Added manual "Test check" for competitor links.
- Added `PriceCheckService` and `PriceParser`.
- Stored last detected competitor price, currency, check time, and error state on `lpm_competitor_links`.
- Kept checks admin-triggered and bounded.

### Milestone 5: Suggestions And Recovery Foundation

- Added `SuggestionService` for dry-run suggestions.
- Added `PriceRecoveryService` for future price-up and restore suggestion decisions.
- Added `lpm_price_suggestions` with `suggestion_type`.
- Added `lpm_price_match_sessions` for dry-run and future real recovery state.
- Added duplicate pending suggestion prevention and safety checks for minimum difference and maximum price drop.

### Milestone 6: Approval Inbox

- Added pricing inbox filters and summary cards.
- Added dry-run approve, reject, and suggested price adjustment.
- Added dry-run price match session creation after approving price-match-down suggestions.
- Added links to WooCommerce product admin and competitor URLs.

### Milestone 7: Production-Safe Operations Skeleton

- Added `JobScheduler` and `CheckCompetitorLinkJob`.
- Kept scheduled checks disabled by default.
- Used Action Scheduler only when available and explicitly enabled.
- Processed only due enabled competitor links in small batches.
- Kept scheduled suggestion creation disabled by default.
- Ensured scheduled checks never update WooCommerce prices.

### Milestone 8: Notification Abstraction

- Added `NotificationService`, `NotificationInterface`, and `LogNotificationChannel`.
- Kept notifications disabled by default.
- Added WhatsApp provider placeholders with no real provider calls.
- Logged notification test events only.

### Milestone 9: Guarded Real Update Foundation

- Added `PriceUpdateService` using WooCommerce CRUD APIs.
- Kept real updates blocked by default through dry-run mode, emergency disable, explicit allow setting, and confirmation requirements.
- Added single-product confirmation flow in the Approvals tab when all guards are enabled.
- Added snapshot validation and max-drop safety validation.

### Milestone 10: Stabilization And QA

- Extract bounded product search into `ProductSearchService`.
- Extract one-time admin notices into `AdminNoticeStore`.
- Add lightweight PHP lint tooling.
- Align docs with current `lpm_*` tables and implemented modules.
- Add a manual test plan.
- Add a safety review document.

### Milestone 11: Price Observation History

- Add `lpm_price_observations` for one historical row per competitor check.
- Keep `lpm_competitor_links` latest-price columns for fast current status.
- Record observed price, currency, extraction method, HTTP status, success/failure, error message, response time, and timestamps.
- Do not store raw HTML or large response bodies.
- Add repository methods for paginated history, recent link history, failed counts, and latest successful observation lookup.
- Add a History tab with filters and pagination.
- Add recent checks on the competitor management screen.
- Add dashboard metrics for checks and failed checks in the last 24 hours plus last successful check time.
- Add retention settings for successful and failed observations without automatic cleanup yet.

### Milestone 12: Pricing Rule Engine

- Add `PricingRuleService` for explainable dry-run suggestion calculations.
- Add global settings for pricing strategy, beat/stay-above amounts, rounding, cost lookup, minimum profit, VAT mode, max increase, and sale/out-of-stock safety.
- Add product-level rule editing for enabled state, priority, strategy, minimum margin, minimum price, and check frequency.
- Route suggestion creation through the pricing rule engine instead of exact-match-only suggestions.
- Store rule details, warnings, and margin-after snapshots on price suggestions.
- Show margin after, warnings, and a compact rule summary in the Approvals inbox.
- Keep WooCommerce price updates guarded and disabled by default.

### Milestone 13: Import / Export And Bulk Actions

- Add an Import / Export tab.
- Add bounded CSV upload, preview, and confirm import for monitored products and competitor links.
- Match import products only by `product_id` or `sku`.
- Reject oversized CSV files and cap preview rows.
- Add CSV exports for monitored products/links, pending suggestions, recent failed checks, and price observations.
- Add selected-row bulk actions for monitored products and competitor links.
- Keep all import/export and bulk workflows admin-only and nonce-protected.

### Milestone 14: Competitor Profiles And Extraction Rules

- Add `lpm_competitors` for global competitor profiles.
- Add nullable `competitor_id` on `lpm_competitor_links` so existing custom-name links keep working.
- Add competitor profile management in the Competitors tab.
- Allow direct competitor links to attach an existing profile or remain custom.
- Add configurable extraction mode, selectors, stock text, timeout, request delay, default currency, JavaScript requirement, and notes.
- Extend `PriceParser` with optional profile rules and limited selector support.
- Return a clear warning for JavaScript-required profiles because the internal checker does not render JavaScript.
- Add a profile-only Test URL action that does not save product/link/observation data.
- Keep all scraping respectful, bounded, admin-only, and dependency-free.

### Milestone 15: Webhook Notifications

- Add `WebhookNotificationChannel` for Make, Zapier, and similar webhook providers.
- Keep notifications and webhook delivery disabled by default.
- Add webhook URL, optional secret, and event toggles for new, blocked, failed-check, and recovery events.
- Add HMAC-SHA256 signature header when a webhook secret is configured.
- Add a test webhook admin action.
- Build structured webhook payloads with product/suggestion data, review links, and human-readable message text.
- Keep direct WhatsApp, Meta Cloud API, and Twilio integrations out of scope.
- Keep real WooCommerce price updates behind logged-in admin confirmation only.
- Add optional tokenized dry-run approve/reject links only; do not allow tokenized real price updates.

## Current Milestone: Production Safety Controls

- Add a shared transient batch lock for manual, scheduled, and WP-CLI check batches.
- Add retry/backoff fields on competitor links with 1-hour, 6-hour, and 24-hour retry windows.
- Skip future-backoff links in due-link batch selection.
- Add manual admin-only retention cleanup for operational logs and price observations.
- Add bounded WP-CLI commands: `wp lpm check-batch --limit=10`, `wp lpm cleanup`, and `wp lpm status`.
- Add dashboard health cards for lock status, scheduled checks, failed checks, real-update possibility, webhook state, and active sessions.
- Keep all production operations bounded, opt-in, and dry-run by default.

## Next Safe Hardening Work

- Add small unit tests for pure parsing, suggestion, and recovery decisions.
- Add focused tests for webhook payload formatting and HMAC signature behavior.
- Add focused tests for limited selector parsing and JavaScript-required profile behavior.
- Add focused tests for `PricingRuleService` rounding, min price, cost, and margin outcomes once a lightweight WordPress test harness exists.
- Add more structured logging around parser extraction methods.
- Add table migration tests where possible.
- Add focused tests for batch locking, retry/backoff, and retention cleanup once a lightweight WordPress test harness exists.
- Consider soft-delete semantics for competitor links if historical link auditability becomes important.

## Later, Explicitly Opt-In Work

These are not part of the current MVP stabilization:

- Real WhatsApp provider integration.
- Automatic or bulk WooCommerce price updates.
- Broad scheduled checks across large portions of the catalog.
- Full crawler behavior or competitor site discovery.
- Bulk product import jobs.
- Advanced reporting over all products or orders.
- Advanced multi-competitor recovery modes beyond conservative lowest-valid checks.
