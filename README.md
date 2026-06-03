# Lilleprinsen Price Monitor

Lilleprinsen Price Monitor is an admin-only WordPress/WooCommerce plugin for selected competitor price monitoring. It lets store admins add specific WooCommerce products to a monitored list, attach direct competitor product URLs, run bounded manual price checks, create dry-run price suggestions, and approve or reject suggestions in an admin pricing inbox.

The plugin is built for a high-traffic WooCommerce store with around 100k products and 100k orders. It is conservative by default: it does not add frontend hooks, does not scan the full catalog, and keeps WooCommerce price updates blocked unless several explicit safety settings are changed and a single-product confirmation flow is used.

## Current Scope

Implemented foundation:

- Admin page under WooCommerce: Dashboard, Products, Approvals, Competitors, Settings, and Logs.
- Product search by ID, SKU, or bounded title query, limited to 20 results.
- Custom database tables for monitoring rows, competitor links, suggestions, price match sessions, and logs.
- Competitor link management with manual "Test check" action.
- Price parsing MVP with JSON-LD, price meta tags, and NOK/kr visible text fallback.
- Dry-run suggestion creation for price-match-down, price-up/recovery, restore, manual-review, and blocked scenarios.
- Approval inbox with dry-run approve, reject, suggested-price adjustment, product links, and competitor links.
- Price recovery service that suggests safe recovery actions from price match session data.
- Background job skeleton using Action Scheduler when available, disabled by default.
- Notification abstraction with a log-only channel. No real WhatsApp calls are made.
- Guarded real WooCommerce price update foundation using WooCommerce CRUD APIs only. Real updates remain blocked by default.

## Architecture Principles

- Admin-only behavior unless explicitly changed in a future PR.
- No competitor checks, external HTTP requests, heavy product queries, or scheduled work on normal frontend requests.
- No automatic full-catalog scanning.
- Custom tables instead of storing monitor state in `wp_postmeta`.
- Paginated and indexed admin queries.
- Bounded batch sizes for any background or manual batch work.
- WooCommerce CRUD APIs for any real product price updates.
- Dry-run mode remains central and visible.

## Data Model

The plugin creates these custom tables with the active WordPress table prefix:

- `lpm_monitored_products`: selected WooCommerce product IDs, SKU snapshots, enabled state, strategy, priority, check cadence, and timestamps.
- `lpm_competitor_links`: direct competitor URLs attached to monitored products, last detected price data, check timestamps, and errors.
- `lpm_price_suggestions`: dry-run and real-update workflow suggestions, suggestion type, status, reason, reviewer, and timestamps.
- `lpm_price_match_sessions`: original price state and recovery context for price-match sessions, including dry-run sessions.
- `lpm_logs`: audit trail for admin actions, checks, suggestions, notifications, jobs, and guarded price update attempts.

Schema creation and migrations live in `src/Database/Schema.php`. Repository helpers live in `src/Database/Repository.php`.

## Non-Goals

This project currently does not implement:

- Frontend widgets, storefront notices, cart hooks, checkout hooks, or customer-facing behavior.
- Full crawling or full catalog scanning.
- Automatic price checks on normal frontend requests.
- Real WhatsApp, SMS, webhook, or email provider integrations.
- Automatic or bulk WooCommerce price updates.
- Direct SQL updates to `_price`, `_regular_price`, or `_sale_price`.
- Heavy reporting over all products or all orders.

## Development

Run PHP syntax checks with:

```sh
bash tools/lint-php.sh
```

Composer is optional and has no production dependencies:

```sh
composer run lint:php
```

See `AGENTS.md` for coding rules, `docs/ARCHITECTURE.md` for module details, `docs/MVP.md` for staged work, `docs/SAFETY_REVIEW.md` for current guardrails, and `docs/MANUAL_TEST_PLAN.md` for manual QA.
