# Lilleprinsen Price Monitor

Lilleprinsen Price Monitor is an admin-only WordPress/WooCommerce plugin for selected competitor price monitoring. It lets store admins add specific WooCommerce products to a monitored list, attach direct competitor product URLs, run bounded manual price checks, create dry-run price suggestions, and approve or reject suggestions in an admin pricing inbox.

The plugin is built for a high-traffic WooCommerce store with around 100k products and 100k orders. It is conservative by default: it does not run heavy frontend work, does not scan the full catalog, and keeps WooCommerce price updates blocked unless several explicit safety settings are changed and a single-product confirmation flow is used.

## Current Scope

Implemented foundation:

- Admin page under WooCommerce: Dashboard, Products, Approvals, Competitors, Groups, History, Settings, and Logs.
- Product search by ID, SKU, or bounded title query, limited to 20 results.
- Custom database tables for monitoring rows, competitor profiles, competitor links, product groups, price observations, suggestions, price match sessions, approval tokens, and logs.
- Competitor profile management with reusable domain, timing, extraction, selector, stock text, and JavaScript-requirement settings.
- Competitor link management with optional profile attachment and manual "Test check" action.
- Price parsing MVP with JSON-LD, price meta tags, limited selector extraction, stock text detection, and NOK/kr visible text fallback.
- Import / Export tab with bounded CSV preview/confirm import, safe CSV exports, and selected-row bulk actions.
- Pricing rule engine for dry-run suggestions with strategy, rounding, min price, margin, cost, VAT-mode labels, and safety checks.
- Product-level rule overrides for enabled state, priority, strategy, minimum margin, minimum price, and check cadence.
- Dry-run suggestion creation for price-match-down, price-up/recovery, restore, manual-review, skipped, and blocked scenarios.
- Approval inbox with dry-run approve, reject, suggested-price adjustment, product links, and competitor links.
- Price recovery service that suggests safe recovery actions from price match session data.
- Background job skeleton using Action Scheduler when available, disabled by default.
- Shared batch lock, retry/backoff, and profile request-delay handling for bounded check batches.
- Admin-only manual retention cleanup for old operational logs and price observations.
- Bounded WP-CLI commands for check batches, cleanup, and operational status.
- Notification abstraction with log and webhook channels. Webhooks can send JSON to Make, Zapier, or another provider; no direct WhatsApp calls are made.
- Optional one-time token links for webhook dry-run approval, match-price dry-run actions, and rejection. Token links are disabled by default and can never update WooCommerce prices.
- Product groups for related monitored products that should share pricing decisions, with group-aware dry-run suggestions.
- Optional lightweight frontend price-match box in Norwegian and coupon-discount exclusion for real actively price-matched products. Dry-run sessions do not trigger customer-facing display or coupon exclusion.
- Guarded real WooCommerce price update foundation using WooCommerce CRUD APIs only. Real updates remain blocked by default.

## Architecture Principles

- Admin-only behavior for monitoring, checks, suggestions, approvals, imports, exports, and updates.
- Optional frontend behavior is limited to the price-match box and coupon exclusion when enabled.
- No competitor checks, external HTTP requests, heavy product queries, product scans, suggestion creation, or scheduled work on normal frontend requests.
- No automatic full-catalog scanning.
- Custom tables instead of storing monitor state in `wp_postmeta`.
- Paginated and indexed admin queries.
- Bounded batch sizes for any background or manual batch work.
- Shared locks, retry backoff, and explicit limits for production operations.
- WooCommerce CRUD APIs for any real product price updates.
- Dry-run mode remains central and visible.

## Data Model

The plugin creates these custom tables with the active WordPress table prefix:

- `lpm_monitored_products`: selected WooCommerce product IDs, SKU snapshots, enabled state, strategy, priority, check cadence, and timestamps.
- `lpm_competitors`: global competitor profiles, domains, request delay/timeout settings, extraction rules, selector settings, stock text, JavaScript requirement, and notes.
- `lpm_competitor_links`: direct competitor URLs attached to monitored products, optional `competitor_id`, last detected price/stock data, check timestamps, errors, consecutive failure count, and next eligible check time.
- `lpm_product_groups`: named groups for monitored products that can share pricing decisions.
- `lpm_product_group_members`: enabled/disabled group members, primary/member role, and monitored product mapping.
- `lpm_price_observations`: historical check rows for trust, debugging, recovery behavior, and future reports.
- `lpm_price_suggestions`: dry-run and real-update workflow suggestions, suggestion type, status, reason, rule details, warnings, margin snapshot, reviewer, and timestamps.
- `lpm_price_match_sessions`: original price state and recovery context for price-match sessions, including dry-run sessions.
- `lpm_approval_tokens`: hashed, expiring, one-time dry-run approval/rejection tokens for webhook workflows.
- `lpm_logs`: audit trail for admin actions, checks, suggestions, notifications, jobs, and guarded price update attempts.

Schema creation and migrations live in `src/Database/Schema.php`. Repository helpers live in `src/Database/Repository.php`.

## Non-Goals

This project currently does not implement:

- Frontend competitor checks, frontend scraping, frontend product scans, or frontend price calculations.
- Full crawling or full catalog scanning.
- Automatic price checks on normal frontend requests.
- Direct WhatsApp, SMS, or email provider integrations.
- Automatic or bulk WooCommerce price updates.
- Direct SQL updates to `_price`, `_regular_price`, or `_sale_price`.
- Heavy reporting over all products or all orders.
- JavaScript/browser scraping, anti-bot bypassing, or external scraper workers.
- Unauthenticated real WooCommerce price update links. Token links can only record dry-run match/approve actions or reject suggestions.

## Development

Run PHP syntax checks with:

```sh
bash tools/lint-php.sh
```

Run lightweight local service tests with:

```sh
bash tools/run-local-tests.sh
```

Composer is optional and has no production dependencies:

```sh
composer run lint:php
composer run test:local
composer run qa
```

The local tests cover pure service behavior for `PriceParser`, `PricingRuleService`, `PriceRecoveryService`, and `ApprovalTokenService` using minimal WordPress function stubs. They do not load WordPress, WooCommerce, custom database tables, admin screens, Action Scheduler, WP-CLI commands, HTTP requests, or webhook delivery.

See `AGENTS.md` for coding rules, `docs/ARCHITECTURE.md` for module details, `docs/MVP.md` for staged work, `docs/SAFETY_REVIEW.md` for current guardrails, and `docs/MANUAL_TEST_PLAN.md` for manual QA.
