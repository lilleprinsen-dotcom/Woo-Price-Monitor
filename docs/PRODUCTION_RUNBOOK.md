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
