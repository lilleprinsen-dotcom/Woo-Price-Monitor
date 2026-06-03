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

## Current Milestone: Price Observation History

- Add `lpm_price_observations` for one historical row per competitor check.
- Keep `lpm_competitor_links` latest-price columns for fast current status.
- Record observed price, currency, extraction method, HTTP status, success/failure, error message, response time, and timestamps.
- Do not store raw HTML or large response bodies.
- Add repository methods for paginated history, recent link history, failed counts, and latest successful observation lookup.
- Add a History tab with filters and pagination.
- Add recent checks on the competitor management screen.
- Add dashboard metrics for checks and failed checks in the last 24 hours plus last successful check time.
- Add retention settings for successful and failed observations without automatic cleanup yet.

## Next Safe Hardening Work

- Add small unit tests for pure parsing, suggestion, and recovery decisions.
- Add more structured logging around parser extraction methods.
- Add table migration tests where possible.
- Add an explicit admin-only cleanup action for old observations, logs, and historical suggestions.
- Review Action Scheduler locking and duplicate job protection before enabling scheduled checks in production.
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
