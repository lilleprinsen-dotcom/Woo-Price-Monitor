# MVP Plan

Woo Price Monitor should start as a safe, admin-only, mostly dry-run plugin. Each milestone should be small enough for a focused pull request.

## Milestone 0: Project Documentation

- Add development rules for future Codex agents.
- Document the plugin purpose, scope, architecture, and staged plan.
- Keep the repository ready for a minimal plugin scaffold.

## Milestone 1: Plugin Shell And Custom Tables

- Add the main plugin file and admin-only bootstrap.
- Add activation logic for custom tables.
- Add schema version storage.
- Add basic uninstall or retention policy notes.
- Run PHP syntax checks if PHP is available.

Expected tables:

- Monitored products.
- Competitor URLs.
- Price checks.
- Price suggestions.
- Action logs.

## Milestone 2: Monitored Product List

- Add a WooCommerce admin page for monitored products.
- Support paginated listing, status filtering, and indexed lookup.
- Add selected products by product ID, SKU, or bounded search.
- Prevent duplicate monitored product entries.
- Do not scan the full product catalog.

## Milestone 3: Competitor URL Management

- Add a product monitor detail screen.
- Allow admins to add, edit, disable, and delete competitor URLs.
- Validate and sanitize URLs.
- Store competitor URLs in the custom table.
- Log admin actions.

## Milestone 4: Dry-Run Price Observations

- Add manual competitor price observation entry or placeholder check records.
- Store observed price, currency, URL reference, status, and timestamps.
- Keep all checks admin-triggered and bounded.
- Do not add scraping or external HTTP requests yet.
- Do not run any checks on frontend requests.

## Milestone 5: Suggestion Engine

- Generate price suggestions from current WooCommerce price snapshots and recent observations.
- Keep suggestion rules simple and explainable.
- Store suggested price, reason, source observation IDs, and status.
- Do not update WooCommerce product prices.

## Milestone 6: Approval And Rejection Workflow

- Add an admin approval queue for suggestions.
- Allow approve and reject decisions with optional notes.
- Log reviewer, decision, timestamps, and price snapshots.
- Keep approval dry-run in the MVP.
- Clearly separate approval state from actual WooCommerce price updates.

## Milestone 7: Hardening

- Add retention limits for old observations and logs.
- Add more validation around prices, currencies, and product state.
- Add simple tests for suggestion calculations.
- Add PHP syntax checks to the development workflow.
- Review indexes against expected admin filters.

## Later, Explicitly Opt-In Work

These should not be included in the MVP unless explicitly requested:

- Real competitor HTTP checks.
- Scraping or parsing competitor pages.
- Automatic WooCommerce price updates.
- WhatsApp, SMS, or email messaging.
- Bulk import jobs.
- Advanced reports across the whole product or order catalog.

If real price updates are added later, they must use WooCommerce CRUD APIs, run only from admin-approved flows or controlled background jobs, and write a full audit log entry for every attempted update.
