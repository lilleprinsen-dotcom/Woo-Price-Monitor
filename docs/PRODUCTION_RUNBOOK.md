# Production Runbook

Lilleprinsen Price Monitor should be launched as an admin-only, dry-run monitoring tool first. Treat every production operation as opt-in, bounded, and reversible.

## Recommended Dry-Run Launch

1. Activate the plugin with WooCommerce active.
2. Confirm Settings keep `dry_run_mode = yes`, `disable_all_price_updates = yes`, and `allow_real_price_updates = no`.
3. Keep `scheduled_checks_enabled = no` during initial setup.
4. Add a small set of monitored products manually or by CSV import.
5. Add direct competitor links for those selected products only.
6. Run manual "Test check" actions and review History, Logs, and Approvals.
7. Keep approved suggestions as dry-run records until the team trusts parser results, margins, and recovery behavior.
8. Keep product groups in dry-run review until group member safety rules and the explicit group confirmation page have been checked on staging.
9. Keep the frontend price-match box disabled until active match state and coupon behavior have been verified on staging.
10. Keep webhook/WhatsApp action links disabled until token expiry, one-time use, and dry-run-only behavior have been tested.

## Server Cron With WP-CLI

Scheduled checks are disabled by default. For production cron, prefer a small WP-CLI batch from server cron after staging validation:

```sh
wp lpm check-batch --limit=10
```

Operational notes:

- The command respects the shared `lpm_check_batch_lock` transient.
- The limit is capped at 100 even if a larger value is passed.
- Links with `next_check_after` in the future are skipped.
- Competitor profile `request_delay_seconds` is respected.
- The command does not update WooCommerce prices.

Use `wp lpm status` before and after cron setup:

```sh
wp lpm status
```

Use cleanup manually or from a carefully reviewed admin cron only:

```sh
wp lpm cleanup
```

Cleanup deletes old debug/operational logs and old price observations. It preserves approval and real-update audit logs.

## Monitor Health

Check the Dashboard health cards for:

- Last successful check time
- Checks and failed checks in the last 24 hours
- Current batch lock status
- Scheduled checks enabled/disabled
- Pending and blocked suggestions
- Active price match sessions
- Real price updates possible/impossible
- Webhook notifications enabled/disabled

Review warnings before increasing batch size. Large batches, many failed checks, disabled dry-run mode, disabled emergency update protection, or missing recent observations should be treated as operational warnings.

## Pause Updates

Real updates are blocked by default. To pause all possible real update behavior:

1. Set `dry_run_mode = yes`.
2. Set `disable_all_price_updates = yes`.
3. Set `allow_real_price_updates = no`.
4. Keep `require_confirmation_for_real_updates = yes`.

Approvals can still be recorded as dry-run workflow state. WooCommerce prices are not changed while the guards above are active.

Webhook action links also remain dry-run-only. They can adjust the stored suggestion price, approve dry-run, or reject depending on settings, but they do not perform WooCommerce price updates. Match-price actions are blocked before changing the suggestion if the requested price violates positive-price, max drop/increase, monitored min-price, or group member min-price checks.

## Group Price Updates

Group real updates are opt-in and require the same real-update safety settings as single-product updates plus explicit admin confirmation. Before enabling on production:

1. Keep `allow_partial_group_price_updates = no` until the team has reviewed staging behavior.
2. Confirm every group member shows old price, new price, and safety status on the confirmation page.
3. Confirm blocked members stop the whole update while partial updates are disabled.
4. Confirm successful group updates create real active sessions only for products actually updated.
5. Review logs for per-product old/new state after every staging group update.

## Frontend Price-Match Box

The frontend box is optional and disabled by default. When enabled, it should only display for products with stored real active match state. Dry-run sessions are workflow records only and must not show the customer-facing box or exclude coupons. The storefront path must not run competitor checks, external HTTP requests, product scans, or price calculations.

Before enabling on production:

1. Verify `_lpm_price_matched_active = real` state is set and cleared correctly for real staging sessions.
2. Confirm normal products do not show the box.
3. Confirm dry-run-only sessions do not show the box and do not exclude coupon discounts.
4. Confirm CSS is lightweight and only enqueued when the box setting is enabled.
5. Confirm coupon discounts are removed from real price-matched cart lines only when `disable_coupons_for_price_matched_products` is enabled.

## Webhook Action Links

Make/Zapier can forward webhook payload fields to WhatsApp messages:

- `action_match_price_url`
- `action_match_price_minus_1_url`
- `action_reject_url`
- `competitor_url`
- `review_url`

These links are disabled by default, expire, are one-time use, and store only token hashes. In this version they do not perform real WooCommerce price updates. Real updates still require logged-in admin confirmation through the normal admin approval flow.

## Disable Scheduled Checks

1. Open WooCommerce > Price Monitor > Settings.
2. Turn off Scheduled checks.
3. Save settings.
4. Run `wp lpm status` and confirm scheduled checks are disabled.

If Action Scheduler was in use, the plugin clears its scheduled action when scheduled checks are disabled.

## Recover If Something Looks Wrong

1. Disable scheduled checks.
2. Keep dry-run mode enabled and emergency update disable enabled.
3. Check Dashboard warnings.
4. Review Logs filtered by `competitor_check_failed`, `check_batch_skipped`, `webhook_notification_failed`, or `real_price_update_failed`.
5. Review History for recent failed observations.
6. Lower `max_urls_per_batch`.
7. Increase competitor profile `request_delay_seconds` for noisy competitors.
8. Fix or disable problematic competitor links.
9. Run `wp lpm status`.
10. Re-enable small batches only after errors are understood.

## Why Frontend Requests Must Not Process Checks

The production store has around 100k products and 100k orders. Frontend requests must stay focused on shoppers. Competitor checks involve external HTTP requests, parsing, logging, and suggestion decisions; running that work on storefront traffic would add latency, risk lock contention, and make failures customer-visible.

All check processing should stay in explicit admin actions, Action Scheduler cron, or bounded WP-CLI commands.
