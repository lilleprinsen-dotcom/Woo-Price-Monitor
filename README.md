# Woo Price Monitor

Woo Price Monitor is a planned WordPress/WooCommerce plugin for admin-only competitor price monitoring.

The plugin will let store admins choose specific WooCommerce products to monitor, attach direct competitor product URLs, record competitor price observations later, generate price suggestions, and approve or reject proposed changes before anything affects WooCommerce product pricing.

## Purpose

The goal is to support safe pricing decisions for a high-traffic WooCommerce store with around 100k products and 100k orders. The plugin must be conservative by default: no frontend impact, no automatic catalog-wide scanning, and no automatic price writes in the first version.

## MVP Scope

The first version should provide:

- Admin-only plugin shell and settings.
- Custom database tables for monitored products, competitor URLs, price checks, suggestions, and logs.
- A paginated admin list of monitored WooCommerce products.
- Product search/add flows that only add selected products.
- Competitor URL management for each monitored product.
- A dry-run price observation and suggestion workflow.
- Manual approval or rejection of suggestions.
- Audit logging for admin actions and suggestion decisions.

## Architecture Principles

Woo Price Monitor should be designed around bounded admin and background work:

- No frontend hooks or customer-facing features unless explicitly requested later.
- No competitor checks, scraping, external HTTP calls, or heavy queries during frontend requests.
- No automatic scan of all WooCommerce products.
- Custom database tables instead of storing monitor state in `wp_postmeta`.
- Paginated and indexed queries for all admin lists and background jobs.
- WooCommerce CRUD APIs for any future product price updates.
- Dry-run behavior until price updates are intentionally added in a later milestone.

## Planned Data Model

Initial custom tables are expected to include:

- `wp_wpm_monitored_products` for selected product IDs and monitor status.
- `wp_wpm_competitor_urls` for direct competitor product URLs attached to monitored products.
- `wp_wpm_price_checks` for observed competitor prices and check metadata.
- `wp_wpm_price_suggestions` for proposed price changes and approval state.
- `wp_wpm_action_logs` for audit events and admin decisions.

Exact table names should use the WordPress table prefix and be created on plugin activation with indexed columns for product IDs, statuses, timestamps, and foreign-key-like references.

## Non-Goals

The MVP should not include:

- Frontend widgets, storefront notices, or customer-facing behavior.
- Full scraping or crawling.
- WhatsApp, SMS, email campaign, or messaging integrations.
- Automatic price updates.
- Bulk scanning all products.
- Direct SQL writes to WooCommerce price metadata.
- Heavy reporting queries over all products or all orders.

## Future Direction

Later versions may add controlled competitor check jobs, rate limits, richer suggestion rules, and opt-in price updates. Those features should be developed in separate small pull requests and should keep frontend requests isolated from monitoring work.
