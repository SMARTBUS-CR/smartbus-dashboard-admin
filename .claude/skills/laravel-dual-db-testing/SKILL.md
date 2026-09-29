---
name: laravel-dual-db-testing
description: >
  Set up an isolated, dual-database (PostgreSQL + MySQL, or any two Laravel
  connections) automated test suite for a Laravel + Pest application,
  including separate _testing databases, a safety guard against wiping
  development data, a reusable Pest helper for the secondary connection's
  fixture tables, Docker/Dockerfile services for both test databases, and
  a CI/CD workflow that runs it all. Use this whenever the user asks to
  set up testing, configure phpunit for two databases, add CI for Laravel
  tests, test a multi-database or multi-tenant Laravel app, or mentions
  RefreshDatabase failing or wiping the wrong database, cross-database
  Eloquent relations, or wanting GitHub Actions services for Postgres and
  MySQL together. Model- and domain-agnostic — works regardless of what
  Eloquent models, tenancy scheme, or auth guards the project actually has.
---

# Laravel dual-database testing setup

Configures a Laravel + Pest project so tests run safely against **two real
databases** (not SQLite-in-memory), fully isolated from development data,
both locally and in CI/CD. The pattern in this skill was built and verified
end-to-end on a real multi-database Laravel app (PostgreSQL as the primary
connection, MySQL as a secondary "external" connection for an
Authentication API), but every piece below is written to be connection- and
model-agnostic. Substitute your own connection names and table names.

**Do not adapt this to the project's specific models before reading the
whole file.** The value of this skill is the wiring (config, guard rails,
fixtures, CI), not any particular model's tests.

## When NOT to use this

- Single-database Laravel apps with no cross-connection concerns: plain
  `RefreshDatabase` + SQLite or one real DB is simpler and this skill is
  overkill. Only bring in the secondary-connection fixture pattern
  (Steps 4–5) if the app genuinely queries a second database connection.
- If the project already has a working, isolated test-DB setup, don't
  redo it blindly — diff against this skill's checklist and only fix gaps.

## Overview of the pieces

| Piece | Purpose |
|---|---|
| Two `*_testing` databases | Real engines (Postgres, MySQL/MariaDB, etc.), never touched by dev data |
| `phpunit.xml` `<env>` overrides | Point each connection's *database name* at the testing DB |
| `TestCase::setUpTraits()` guard | Hard-abort if any connection's DB name doesn't end in `_testing`, so a misconfigured run can't wipe development data |
| `tests/Pest.php` helpers | Shared, reusable helpers (auth-as, fixture builders) so test files don't repeat setup logic |
| `tests/Support/*` traits | Encapsulate per-test fixture tables for the secondary connection, hooked into PHPUnit's lifecycle correctly |
| Docker services | Both databases available identically in local dev and CI containers |
| CI workflow | Spins up both databases as services with the same names phpunit.xml expects |

Read this file top to bottom once, then execute the steps against the
target project, substituting real names. Reference templates are in
`references/` — read the ones relevant to the current step before writing
files, they contain the exact syntax and known gotchas.

---

## Step 0 — Discover the project's actual shape

Before writing anything, inspect the target project and answer:

1. What are the connection names in `config/database.php` (e.g. `pgsql`,
   `mysql`, `mariadb`, custom names)? Which env vars back each one's
   `database` key (e.g. `DB_PGSQL_DATABASE`, `DB_DATABASE`)?
2. Is there already a `tests/TestCase.php`? A `tests/Pest.php`? Don't
   overwrite blindly — merge in additively.
3. Does `phpunit.xml` currently force `DB_CONNECTION=sqlite` /
   `DB_DATABASE=:memory:`? That has to go.
4. Is there a Docker Compose file and/or `Dockerfile` already defining
   database services for local dev? Reuse their image versions/credentials
   conventions rather than inventing new ones.
5. Is there an existing CI workflow (e.g. `.github/workflows/*.yml`)? If
   the user supplies one, treat it as the base to extend, not replace —
   preserve its lint/build steps and only touch the test job's services
   and env.
6. Does any model query a second connection directly (e.g. an auth guard
   backed by a different database, a cross-database pivot)? If yes, Steps
   4–5 (secondary-connection fixtures) apply. If every model lives on one
   connection, skip those steps — a single-connection guard and
   `RefreshDatabase` is enough.

Never invent table/column names for the secondary connection's fixture —
ask the user or inspect the real model/migration for the columns actually
used by the code under test.

---

## Step 1 — Separate testing databases

Two databases per environment (dev vs. testing), for **every** connection
the app uses, following a strict naming convention: the testing database
name must end in `_testing`. This convention is what the guard in Step 3
checks, so don't deviate from it.

```bash
# Example for Postgres + MySQL — adapt names/tools to the real connections
psql -U postgres -c "CREATE DATABASE app_testing;"
mysql -u root -e "CREATE DATABASE app_users_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

If any connection needs extensions (e.g. PostGIS for geospatial columns),
enable them on the testing database too:

```bash
psql -U postgres -d app_testing -c "CREATE EXTENSION IF NOT EXISTS postgis;"
```

## Step 2 — `phpunit.xml`

Read `references/phpunit.xml.template` before editing. Key points:

- Remove any `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` `<env>` lines.
- Set `DB_CONNECTION` to whichever connection is the app's default.
- Add one `<env>` per connection, overriding **only the database name**
  env var for that connection (e.g. `DB_PGSQL_DATABASE=app_testing`,
  `DB_MYSQL_DATABASE=app_users_testing`). Do **not** hardcode host/user/
  password here — those come from `.env` locally and from the CI job's
  `env:` block in CI, so the same `phpunit.xml` works in both places.
  `<env>` values in `phpunit.xml` are overridden by real environment
  variables already present when PHPUnit boots, and take precedence over
  `.env` — that's exactly the layering this design relies on.
- Confirm with the user which connection is the Eloquent default; that's
  the one `RefreshDatabase` will migrate and refresh automatically. Any
  other connection needs the fixture-trait pattern (Step 4) because
  `RefreshDatabase` does not touch it.

## Step 3 — The "don't wipe dev data" guard

This is the single most important safety piece. `RefreshDatabase` runs
`migrate:fresh` as part of `parent::setUp()`. If `phpunit.xml`'s `<env>`
overrides silently fail to apply (wrong var name, typo, `.env.testing` not
loaded, etc.), tests will happily migrate-fresh the **development**
database with zero warning. The guard aborts loudly instead.

Read `references/TestCase.php.snippet` and add the method to the
project's real `tests/TestCase.php` (don't replace the whole file — most
projects already have one).

```php
protected function setUpTraits()
{
    foreach (['pgsql', 'mysql'] as $connection) { // <- real connection names
        $database = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_testing')) {
            throw new \RuntimeException(
                "Connection '{$connection}' points at '{$database}', which does not end in '_testing'. Aborting to avoid wiping development data."
            );
        }
    }

    return parent::setUpTraits();
}
```

Why `setUpTraits()` and not `setUp()`: `setUpTraits()` runs *before*
`RefreshDatabase`'s own setup fires (`RefreshDatabase` hooks in via
`setUpTraits` itself), so the guard trips before any migration runs. Put it
in `setUp()` and the damage is already done by the time you'd check.

If the project uses more than two connections, list all of them. If some
connections are never migrated in tests (e.g. a cache-only DB), it's still
safe to require the `_testing` suffix — dev data on any connection
shouldn't be touched by a test run.

## Step 4 — `tests/Pest.php`: shared helpers only

Read `references/Pest.php.template`. Keep `tests/Pest.php` to **generic,
reusable** helpers — not per-model logic:

- A "make an unpersisted model instance with a valid primary key" helper,
  if the app uses UUID/ULID primary keys and something (like a pivot
  attach method) needs a model instance without hitting the database.
- An `actingAsXxx()` helper if the app has a non-trivial auth flow (custom
  guard, session-based external auth, roles/permissions not stored on the
  `users` row itself). Model it on how the real login code populates
  session/auth state — read the actual guard/provider classes, don't
  guess. If the app's auth is a plain Eloquent guard, Laravel's own
  `actingAs()` is enough and this helper isn't needed.
- Do **not** put per-feature fixture setup (like creating a secondary
  connection's tables) directly in `Pest.php` as bare functions called
  from `beforeEach()` — see Step 5 for why, and where it actually goes.

## Step 5 — `tests/Support/*`: secondary-connection fixtures as PHPUnit-lifecycle traits

**Only needed if a second connection is queried directly in tests**
(e.g. an auth guard on a different database, a manually-joined pivot
across connections). Skip this step for single-connection apps.

### The trap this avoids

A tempting first approach is:

```php
beforeEach(fn () => Schema::connection('mysql')->create('users', ...));
```

This throws `Call to a member function connection() on null` (or similar),
because Pest's `beforeEach()` runs before Laravel's testing bootstrap has
attached the application container to the `Schema` facade. `Schema::` (and
most facades) are only safe to use once the real PHPUnit/Laravel lifecycle
methods have fired.

### The fix: a trait with `setUp{TraitName}()` / `tearDown{TraitName}()`

Laravel's `TestCase` automatically calls `setUp{TraitName}()` and
`tearDown{TraitName}()` for every trait a test class `uses()`, at the
correct point in the real lifecycle (after the app is booted). Put the
fixture logic there instead of in a bare Pest closure.

Read `references/WithSecondaryConnectionFixture.php.template` for the full
pattern:

```php
namespace Tests\Support;

trait WithSecondaryConnectionFixture
{
    protected function setUpWithSecondaryConnectionFixture(): void
    {
        Schema::connection('mysql')->dropIfExists('users');
        Schema::connection('mysql')->create('users', function (Blueprint $table) {
            // Only the columns the code under test actually reads/writes.
            // This is a test fixture, not a copy of the real service's migrations.
        });
    }

    protected function tearDownWithSecondaryConnectionFixture(): void
    {
        Schema::connection('mysql')->dropIfExists('users');
    }
}
```

Then in a test file:

```php
uses(RefreshDatabase::class, \Tests\Support\WithSecondaryConnectionFixture::class);
```

Notes:
- `RefreshDatabase` only refreshes the **default** connection. The
  secondary connection's fixture table is created/dropped per test by this
  trait because DDL on most databases (MySQL included) commits implicitly
  and can't be rolled back by a transaction.
- Name the fixture table's columns after what the real code actually
  touches — inspect the real model class, don't invent a schema.
- If the app has more than one secondary connection needing fixtures, make
  one trait per fixture and combine them in `uses()`.

---

## Step 6 — Docker services for both test databases

Both databases need to be reachable the same way in local Docker dev and
in CI. Read `references/docker-compose.additions.yml` for the concrete
snippet shape, then:

1. **Inspect the existing `docker/` directory and `Dockerfile`(s) first.**
   Reuse the project's existing image tags, credential conventions, and
   volume/network naming — don't introduce a parallel, inconsistent set of
   services.
2. If there's a `docker-compose.yml` (or `compose.yaml`) already defining
   the app's databases, extend it to also provision the `_testing`
   databases — either as a second database on the same service (simplest:
   most images support creating multiple DBs via init scripts) or as a
   dedicated `*-testing` service on a different port, matching whatever
   pattern the project already uses for other environments.
3. For Postgres, if the app needs PostGIS or another extension, make sure
   the testing database service uses an image that includes it (e.g.
   `postgis/postgis:<version>` instead of plain `postgres:<version>`), and
   that any init script also runs `CREATE EXTENSION IF NOT EXISTS ...` on
   the testing database.
4. If the `Dockerfile` installs PHP extensions, confirm both `pdo_pgsql`
   and `pdo_mysql` (or whichever drivers the two connections need) are
   present — testing against a database whose PDO driver isn't compiled in
   fails opaquely.
5. Document in the PR/commit which `.env` / `.env.testing` variables need
   to point at the new services' host/port so a developer running tests
   inside the Docker network picks them up automatically.

Do not hardcode passwords into the Dockerfile or compose file if the
project already sources them from `.env`; keep the same secrets flow the
project uses elsewhere.

## Step 7 — CI/CD workflow

The user's existing pipeline is the base — extend it, don't replace it.
Read `references/ci-cd.yml.updated` for a complete worked example (adapted
from a real two-job "lint then test" pipeline with sqlite) and diff it
against the project's real workflow file before writing:

Key changes over a naive/SQLite-based test job:
1. Add two `services:` (one per database), each with a name matching
   `_testing` and pinned image versions. Health checks so the test job
   waits for both to accept connections before running.
2. Set connection **host/port/user/password** via the job's `env:` (these
   are real environment variables and take precedence over `phpunit.xml`'s
   `<env>` block, matching the local-dev layering from Step 2). Leave the
   **database names** to `phpunit.xml`, so there is exactly one place that
   decides what the testing databases are called.
3. Remove any `DB_CONNECTION: sqlite` / `DB_DATABASE: ...sqlite` job-level
   env — that silently defeats everything above.
4. Keep existing steps (checkout, setup-php, composer install, node/asset
   build, permissions, lint) in place; only the database-related env and
   the new `services:` block are additions.
5. If the pipeline already uses `--parallel` for Pest, flag it to the user
   explicitly: Pest's parallel runner creates suffixed databases (e.g.
   `app_testing_test_1`), which will fail the Step 3 guard's exact
   `str_ends_with(..., '_testing')` check. Either don't parallelize the
   database-touching suite, or relax the guard to `str_contains(...,
   '_testing')` — get the user's explicit choice, don't silently pick one.
6. If the project mounts a `.env.example` for CI (`cp .env.example .env`),
   confirm it contains no real secrets and that its DB connection vars are
   safe/placeholder — CI supplies the real values via `env:`, not the
   file.

---

## Step 8 — Verification checklist

Before declaring the setup done, confirm all of these — each one maps to a
failure mode this skill was built to catch:

- [ ] `php artisan test` locally hits the `_testing` databases, not dev —
      verify by temporarily breaking `phpunit.xml`'s override and
      confirming the Step 3 guard throws instead of silently proceeding.
- [ ] Running the suite twice in a row doesn't fail on unique-constraint
      violations from leftover data (RefreshDatabase should handle the
      default connection; the fixture traits should handle the secondary
      one every test, not just the first).
- [ ] Any factory used in tests produces values that satisfy the schema's
      real `UNIQUE`/`NOT NULL` constraints without exhausting `fake()->
      unique()`'s pool on large runs (prefer random suffixes over small
      bounded ranges).
- [ ] CI green on a fresh PR, and — deliberately — CI **red** if a test is
      temporarily edited to violate a constraint, proving the pipeline
      actually executes the suite rather than passing trivially.
- [ ] Local Docker services and CI services use matching database/user
      naming so a developer's `.env.testing` mirrors CI's `env:` block.

---

## Common pitfalls (read before debugging blind)

- **`Call to a member function connection() on null`** → facade used
  inside `beforeEach()`/bare Pest closures before Laravel boots. Fix: move
  to a `setUp{Trait}()` lifecycle trait (Step 5).
- **Guard throws "does not end in `_testing`" unexpectedly** →
  `phpunit.xml`'s `<env>` for that connection's database var is missing,
  misspelled, or a real environment variable of the same name is set
  ambient in the shell/CI and winning by precedence. Check both.
- **Tests pass locally, fail in CI (or vice versa) on the secondary
  connection** → local Docker service and CI service likely have
  different fixture tables/columns, or the CI job's health check isn't
  actually waiting for the service to be ready before tests start.
- **Parallel test runs fail the `_testing` guard** → see Step 7, point 5.
- **A resource/query unexpectedly returns rows/records across
  tenants/owners after adding a scoping fix** → that's very likely test
  data created without the ownership/tenancy relationship the fix now
  enforces; update the test's fixtures to attach ownership, don't loosen
  the scope back.
