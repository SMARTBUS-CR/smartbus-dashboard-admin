# Recurring Trip Materialization Runbook

## Command

Normal recovery for every active Company, starting from each Company's current local date:

```bash
php artisan trips:materialize-recurring
```

Recover a wider window after downtime:

```bash
php artisan trips:materialize-recurring --days=7
```

Recover one Company from an explicit Company-local date:

```bash
php artisan trips:materialize-recurring --company=<company-uuid> --date=2026-11-12 --days=7
```

The default window remains today and tomorrow. Repeated commands are safe: existing scheduled, in-progress, completed, or cancelled Trips are not modified or regenerated.

## Scheduler and Atomic Locks

Laravel registers the command hourly with `onOneServer()` and `withoutOverlapping(120)`. The 120-minute expiration prevents an abandoned scheduler lock from blocking later recovery indefinitely.

All scheduler instances must use the same shared atomic lock store. This repository defaults to `CACHE_STORE=database`, backed by the shared PostgreSQL `cache` and `cache_locks` tables. Do not deploy scheduler instances with `array` or per-instance `file` cache stores. After changing scheduler definitions, stale locks can be cleared with:

```bash
php artisan schedule:clear-cache
```

## Render Deployment

For an environment with a persistent scheduler process, run:

```bash
php artisan schedule:work
```

Render free web services can sleep while idle, and a sleeping process cannot execute Laravel's hourly Scheduler. Render Cron Jobs may require paid infrastructure. The `-dev` free-plan recovery strategy is therefore:

1. Run `php artisan trips:materialize-recurring --days=7` in the deploy/start command before starting the web process.
2. Keep the normal hourly Scheduler registration for environments where a persistent scheduler is available.
3. Run the explicit recovery command after any known outage longer than the generated window.

Example start-command prefix:

```bash
php artisan trips:materialize-recurring --days=7 || echo "Recurring Trip materialization reported operational issues; continuing startup"
exec <existing-web-start-command>
```

The web start command is deliberately unconditional. Exit code `1` reports ineligible assignments, conflicts, or isolated failures that require attention, but it must not prevent the Dashboard from starting. The command output and application log retain the aggregate result for diagnosis.

This does not send keepalive traffic, expose a public trigger endpoint, require an artificial system User, or depend on external HTTP requests. A scheduled GitHub Actions request is intentionally not included because it would require either a public mutation endpoint or remote shell credentials.

## Exit Codes and Output

- `0`: processing completed without ineligible, conflicting, or failed assignments.
- `1`: processing completed, but one or more assignments require operational attention.
- `2`: invalid command options or Company selection.

Output contains aggregate counts only: created, existing, suspended, ineligible, conflicting, and failed. It excludes identity credentials, salary, licenses, and assignment details.

The same aggregate counts are written to the application log after every completed run, including hourly Scheduler executions whose console table is otherwise discarded.

