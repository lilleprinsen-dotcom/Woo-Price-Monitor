# Safety Review

This review records the current safety assumptions for Lilleprinsen Price Monitor before adding more product features.

## Current Guardrails

- No frontend hooks are registered. The plugin coordinator initializes only in admin, cron, or WP-CLI contexts.
- Admin assets load only when `page=lilleprinsen-price-monitor`.
- WooCommerce product search runs only after an admin submits a search query.
- Product search is bounded to 20 results and uses exact ID lookup, SKU lookup, or a limited title query.
- No full product catalog or order catalog scan is performed.
- Admin lists are paginated and use repository methods with explicit limits.
- Monitoring data is stored in custom `lpm_*` tables instead of `wp_postmeta`.
- Manual competitor checks fetch one admin-selected competitor URL at a time.
- Competitor profiles can configure extraction rules, but selector support is limited and dependency-free.
- Profiles marked as requiring JavaScript return a clear warning; no browser scraper or anti-bot bypass is implemented.
- Price observation history stores check metadata only. Raw HTML and full response bodies are not stored.
- Scheduled checks are disabled by default.
- Scheduled checks use Action Scheduler only when available and enabled.
- Scheduled batches are capped by `max_urls_per_batch`.
- Manual, scheduled, and WP-CLI batches share a transient lock and retry/backoff skips future-due failed links.
- Scheduled checks never update WooCommerce prices.
- Scheduled suggestion creation is disabled by default.
- Pricing rules create dry-run suggestions only and store explainable rule metadata; they do not update WooCommerce prices.
- Notifications are disabled by default.
- Webhook notifications are also disabled by default and require a valid admin-configured webhook URL.
- Webhook payloads are JSON only, may include an HMAC signature, and failures are logged without blocking admin workflows.
- WhatsApp settings are placeholders only. No direct Meta/Twilio WhatsApp provider call is implemented.
- Notification review links point to normal authenticated WordPress admin URLs.
- Tokenized approve/reject links are disabled by default, expire, are one-time use, and store only token hashes.
- Tokenized links can only approve dry-run suggestions or reject suggestions. They cannot approve real WooCommerce price updates.
- Real WooCommerce price updates are blocked by default.
- Real updates require dry-run mode off, emergency disable off, explicit allow setting on, manual approval, explicit confirmation, allowed suggestion type, positive price, unchanged product snapshot, and max-drop validation.
- Unauthenticated links cannot perform real WooCommerce price updates.
- Real updates use WooCommerce CRUD APIs, not direct SQL price metadata writes.
- Retention cleanup is manual/admin-only or WP-CLI-invoked. It deletes old debug/operational logs, observations, and old used/expired token rows while preserving approval/update audit logs.

## Review Notes

The current code registers normal WordPress admin hooks, an Action Scheduler action, an `admin_init` scheduling check, and bounded WP-CLI commands. The main plugin file prevents the coordinator from loading during normal frontend requests. The job action can run in cron, and scheduled processing exits when scheduled checks are disabled.

`PriceCheckService` uses external HTTP only for manual checks or bounded job batches. There is no crawler, link discovery, or automatic all-product scan.

`PriceUpdateService` contains real update code, but it is guarded by settings and confirmation. The default settings keep it unavailable.

## Known Risks And TODOs

- Product title search depends on WooCommerce query behavior and should be tested on the production-like catalog before relying on it.
- Action Scheduler duplicate scheduling should be reviewed before scheduled checks are enabled in production at high volume; batch execution now has a shared transient lock.
- Parser behavior is MVP-level and can misread complex competitor pages; suggestions should stay manual-review/dry-run until parsing confidence improves.
- Selector rules support only simple `.class`, `#id`, and `[attr="value"]` patterns for now.
- JavaScript-rendered competitor pages require a future, explicit external worker design; the internal checker does not render JavaScript.
- Pricing rules depend on optional cost metadata when configured; cost meta keys and margin rules should be verified on staging before enabling strict cost blocking.
- Competitor links are currently deleted from the link table when the delete action is used. Historical suggestions/logs are preserved, but link audit retention may need a soft-delete model later.
- Direct WhatsApp delivery remains future work; webhook payloads can be forwarded by Make/Zapier.
- More automated tests are needed for parsing, suggestion safety rules, recovery decisions, and guarded update validation.
