# Production Audit

## Verdict

- Ready for staging testing.
- Ready for limited live dry-run testing with selected products.
- Not ready for broad automatic price updates.
- Not ready for direct WhatsApp provider delivery or an external scraper worker.

The plugin is designed to start as an admin-only, dry-run price-control tool. Real WooCommerce price updates exist only behind explicit safety settings and logged-in admin confirmation. Scheduled checks are bounded and disabled by default.

## Safety Checklist

- No frontend scraping.
- No frontend competitor checks.
- No frontend external HTTP requests.
- No full product catalog scans.
- No automatic WooCommerce price updates by default.
- Dry-run mode defaults on.
- Emergency price update disable defaults on.
- Manual approval is required for real updates.
- Token links are dry-run only and cannot call real price updates.
- Real updates require logged-in admin confirmation and the existing safety settings.
- Group real updates require complete group safety validation and explicit admin confirmation.
- Partial group updates are disabled by default.
- Coupon exclusion applies only to real active price matches.
- Frontend prismatch box displays only for real active price matches.
- Dry-run sessions are admin-only workflow state and must not set customer-facing active match flags.

## Launch Plan

1. Run staging with WooCommerce enabled and a small monitored product set.
2. Test manual checks against a few simple competitor pages and review observations/history.
3. Test live dry-run with 20-50 selected products only.
4. Review parser accuracy, extraction methods, failed checks, and false positives.
5. Review webhook payloads in Make/Zapier before forwarding to WhatsApp.
6. Review suggestion reasons, pricing rules, group warnings, and recovery outcomes.
7. Keep real updates disabled until the team trusts parser and rule outputs.
8. Try limited manual real updates on staging only.
9. Enable limited manual real updates in production only after several weeks of stable dry-run data.
10. Do not enable automatic price updates until a separate audited release.

## Emergency Plan

1. Disable scheduled checks.
2. Enable `dry_run_mode`.
3. Enable `disable_all_price_updates`.
4. Disable `allow_real_price_updates`.
5. Disable the frontend prismatch box.
6. Disable coupon exclusion if cart behavior looks wrong.
7. Disable webhook notifications and token action links.
8. Review Logs, History, active sessions, and recent suggestions.
9. End dry-run sessions from History if they are confusing admin review.
10. Use normal WooCommerce admin tools for any manual product price correction.

## Recommended Initial Live Settings

- `dry_run_mode = yes`
- `allow_real_price_updates = no`
- `disable_all_price_updates = yes`
- `require_manual_approval = yes`
- `require_confirmation_for_real_updates = yes`
- `scheduled_checks_enabled = no`
- `create_suggestions_from_scheduled_checks = no`
- `max_urls_per_batch = 10` or lower during first live tests
- `webhook_notifications_enabled = no` until a test webhook succeeds
- `whatsapp_action_links_enabled = no` until dry-run token behavior is tested
- `allow_unauthenticated_real_price_update_from_token = no`
- `allow_partial_group_price_updates = no`
- `price_match_box_enabled = no` until real matches exist and have been checked on staging
- `disable_coupons_for_price_matched_products = yes` only after real match coupon behavior is tested

## Current Production Risks

- Parser behavior is still MVP-level and should be trusted only after reviewing observation history.
- JavaScript-heavy competitor pages require a future external worker; the internal checker does not render JavaScript.
- Group real updates are guarded but should be tested on staging with real WooCommerce products before production use.
- Mid-update rollback is not implemented for group updates; the plugin logs exactly which products succeeded and failed.
- Cost/margin behavior depends on store-specific cost metadata and should be verified before strict margin blocking.
- Webhook delivery depends on the configured third-party provider and should be tested before WhatsApp forwarding.

## Audit Notes

- `lpm_price_suggestions` schema was reviewed for duplicate `product_id` index definitions; no duplicate was present.
- Local QA covers pure service logic and does not replace staging tests with WordPress, WooCommerce, Action Scheduler, and real products.
- Frontend hooks are limited to the optional prismatch box and coupon discount filtering. They do not perform competitor checks, suggestions, scans, or external requests.
- `AdminPage.php` remains large. Low-risk logic has already been moved into services such as `TokenActionHandler`, `GroupSuggestionService`, `PriceMatchDisplayService`, and notification builders; a broader tab-renderer refactor should be a separate PR after staging validation.
