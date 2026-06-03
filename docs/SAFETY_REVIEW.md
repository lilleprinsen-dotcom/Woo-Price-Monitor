# Safety Review

This review records the current safety assumptions for Lilleprinsen Price Monitor before adding more product features.

## Current Guardrails

- The heavy plugin coordinator initializes only in admin, cron, or WP-CLI contexts.
- Optional frontend hooks exist only for the settings-gated price-match box and coupon exclusion. They do not run competitor checks, external HTTP requests, product scans, observation queries, suggestion creation, or price calculations.
- Frontend display uses cached product flags first and only a simple indexed active-session lookup as fallback on single product pages.
- Admin assets load only when `page=lilleprinsen-price-monitor`.
- WooCommerce product search runs only after an admin submits a search query.
- Product search is bounded to 20 results and uses exact ID lookup, SKU lookup, or a limited title query.
- No full product catalog or order catalog scan is performed.
- Admin lists are paginated and use repository methods with explicit limits.
- Monitoring data is stored in custom `lpm_*` tables instead of `wp_postmeta`.
- Product groups are stored in `lpm_product_groups` and `lpm_product_group_members`; group-aware suggestion metadata is stored in `lpm_price_suggestions`.
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
- Tokenized links can approve dry-run suggestions, reject suggestions, or record webhook action-link choices that adjust the stored suggested price before dry-run approval.
- Match-price token actions validate positive price, max drop/increase limits, monitored minimum price, and enabled group member minimum prices before changing the stored suggestion price.
- Tokenized action links cannot approve real WooCommerce price updates.
- Real WooCommerce price updates are blocked by default.
- Real updates require dry-run mode off, emergency disable off, explicit allow setting on, manual approval, explicit confirmation, allowed suggestion type, positive price, unchanged product snapshot, and max-drop validation.
- Unauthenticated links cannot perform real WooCommerce price updates.
- Real updates use WooCommerce CRUD APIs, not direct SQL price metadata writes.
- Retention cleanup is manual/admin-only or WP-CLI-invoked. It deletes old debug/operational logs, observations, and old used/expired token rows while preserving approval/update audit logs.
- Coupon exclusion for price-matched products filters coupon discount amounts only; it does not change product prices in the cart.
- Frontend price-match display and coupon exclusion use real active match state only. Dry-run sessions are ignored and must not set the cached real-match product flags.

## Review Notes

The current code registers normal WordPress admin hooks, an Action Scheduler action, an `admin_init` scheduling check, and bounded WP-CLI commands. The main plugin file prevents the coordinator from loading during normal frontend requests. The job action can run in cron, and scheduled processing exits when scheduled checks are disabled.

`PriceCheckService` uses external HTTP only for manual checks or bounded job batches. There is no crawler, link discovery, or automatic all-product scan.

`PriceUpdateService` contains real update code, but it is guarded by settings and confirmation. The default settings keep it unavailable. Group suggestions are excluded from the real-update button path in this version; dry-run group approval logs affected members only.

## Known Risks And TODOs

- Product title search depends on WooCommerce query behavior and should be tested on the production-like catalog before relying on it.
- Action Scheduler duplicate scheduling should be reviewed before scheduled checks are enabled in production at high volume; batch execution now has a shared transient lock.
- Parser behavior is MVP-level and can misread complex competitor pages; suggestions should stay manual-review/dry-run until parsing confidence improves.
- Selector rules support only simple `.class`, `#id`, and `[attr="value"]` patterns for now.
- Token dry-run action validation currently uses a conservative subset of full pricing rules. Full group cost/margin validation should be centralized before any future real group action flow.
- JavaScript-rendered competitor pages require a future, explicit external worker design; the internal checker does not render JavaScript.
- Pricing rules depend on optional cost metadata when configured; cost meta keys and margin rules should be verified on staging before enabling strict cost blocking.
- Competitor links are currently deleted from the link table when the delete action is used. Historical suggestions/logs are preserved, but link audit retention may need a soft-delete model later.
- Direct WhatsApp delivery remains future work; webhook payloads can be forwarded by Make/Zapier.
- Real multi-product group update confirmation is future work. Any future implementation must validate every member, use WooCommerce CRUD, and keep partial updates disabled by default.
- Price-match frontend display depends on cached product flags or active session rows. Staging should confirm cache state is set/cleared as expected before enabling the customer-facing box.
- More automated tests are needed for parsing, suggestion safety rules, recovery decisions, and guarded update validation.
