# AGENTS.md

Development rules for Codex agents working on Woo Price Monitor.

## Pull Request Discipline

- For every code edit, create a new pull request instead of updating an old one.
- Keep changes small, focused, and reviewable.
- Do not mix documentation, schema, UI, and pricing behavior changes unless the user explicitly asks for a combined PR.

## Scope And Safety

- Keep code admin-only unless explicitly asked otherwise.
- Avoid frontend hooks, shortcodes, template filters, storefront assets, cart hooks, checkout hooks, and customer-facing behavior.
- No scraping, competitor checks, external HTTP requests, or heavy queries may run on frontend requests.
- Do not scan all products automatically. Only operate on products that an admin explicitly adds to the monitor list.
- Assume the store has around 100k products and 100k orders. Design for pagination, indexes, and bounded work.
- First versions should be mostly dry-run: collect monitor data, store observations, generate suggestions, and require explicit approval before any real price change.

## WooCommerce Data Rules

- Use custom database tables for monitoring records, competitor URLs, price observations, suggestions, and action logs.
- Do not store monitor state primarily in `wp_postmeta`.
- Avoid direct SQL updates to WooCommerce product prices.
- When price updates are eventually added, use WooCommerce CRUD APIs such as `wc_get_product()` and `$product->set_regular_price()`, `$product->set_sale_price()`, and `$product->save()`.
- Do not update `_price`, `_regular_price`, or `_sale_price` meta directly.

## Query And Performance Rules

- Keep all admin lists paginated.
- Add indexes before relying on filters, status columns, timestamps, or joins at scale.
- Avoid unbounded `WP_Query`, `wc_get_products()`, or direct SQL queries.
- Prefer targeted lookups by monitored product IDs, competitor URL IDs, status, and date ranges.
- Batch background work with explicit limits, locks, and retry state.

## Admin UX And Security

- Gate admin screens and actions behind appropriate capabilities, usually `manage_woocommerce`.
- Use nonces for form submissions and AJAX/REST mutations.
- Sanitize and validate all product IDs, URLs, prices, currencies, statuses, and notes.
- Escape output in admin screens.
- Treat competitor URLs and observed values as untrusted input.

## Validation

- Add PHP syntax checks or simple tests where possible.
- Run `php -l` on changed PHP files when PHP is available.
- Prefer small unit tests for pure services such as suggestion calculations once code exists.
- If checks cannot be run, explain why in the PR or final summary.
