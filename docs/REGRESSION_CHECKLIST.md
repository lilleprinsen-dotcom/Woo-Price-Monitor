# Regression Checklist

Use this checklist after admin refactors and before enabling new workflows on staging.

## Core Setup

- [ ] Activate the plugin without a PHP fatal error.
- [ ] Confirm schema upgrade runs and expected custom tables exist.
- [ ] Confirm WooCommerce inactive notice appears when WooCommerce is disabled.
- [ ] Confirm Dashboard loads and health cards render.
- [ ] Confirm Settings loads, saves, and redirects with a notice.
- [ ] Confirm Logs loads with schema status and pagination.

## Product Monitoring

- [ ] Search products by ID.
- [ ] Search products by SKU.
- [ ] Search products by bounded title query.
- [ ] Add a monitored product.
- [ ] Edit monitored product rules.
- [ ] Enable and disable a monitored product.
- [ ] Confirm product lists remain paginated.

## Competitors And Checks

- [ ] Add a competitor profile.
- [ ] Edit a competitor profile.
- [ ] Add a competitor link.
- [ ] Edit a competitor link.
- [ ] Run a manual Test check.
- [ ] Confirm the competitor link latest price/error fields update.
- [ ] Confirm a price observation row is created.
- [ ] Confirm History filters and pagination work.

## Suggestions And Approvals

- [ ] Create a dry-run suggestion from a checked competitor link.
- [ ] Confirm duplicate pending suggestion prevention still works.
- [ ] Approve a suggestion as dry-run.
- [ ] Reject a suggestion.
- [ ] Edit suggested price before approval.
- [ ] Confirm WooCommerce product price is unchanged by default.
- [ ] Confirm real update remains blocked by default.

## Import / Export

- [ ] Download the CSV template.
- [ ] Preview a valid CSV import.
- [ ] Confirm a valid CSV import.
- [ ] Preview invalid rows and confirm errors/warnings are clear.
- [ ] Export monitored products and links.
- [ ] Export pending suggestions.
- [ ] Export recent failed checks.
- [ ] Export price observations.

## Notifications And Operations

- [ ] Send test notification and confirm it logs only.
- [ ] Send test webhook with a staging webhook URL.
- [ ] Run one small batch check from Dashboard.
- [ ] Confirm batch lock skips overlapping batch attempts.
- [ ] Run `wp lpm status`.
- [ ] Run `wp lpm check-batch --limit=1`.
- [ ] Run `wp lpm cleanup`.
- [ ] Confirm retention cleanup preserves approval/update audit logs.
- [ ] Confirm no frontend hook, direct WhatsApp, external scraper worker, automatic price update, or bulk price update behavior is introduced.
