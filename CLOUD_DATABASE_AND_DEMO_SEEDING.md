# Cloud Database and Development Demo Seeding

## Database ownership

| Connection | Owner | Dashboard responsibility |
| --- | --- | --- |
| PostgreSQL (`pgsql`) | Dashboard operational backend | Run this repository's migrations for companies, routes, buses, Drivers, schedules, Trips, and related operational tables. |
| MySQL (`mysql`) | Shared authentication infrastructure | Provide the User and Spatie schema and create the administrator identity before Dashboard demo seeding. This repository must not run its PostgreSQL migrations against MySQL. |

The application container does not run migrations at startup. Configure the cloud platform to execute the PostgreSQL migration as a release/deployment command so multiple application replicas do not compete during boot.

## Cloud release command

Run on every deployment after both database connections and secrets are available:

```bash
php artisan migrate --database=pgsql --force --no-interaction
```

Confirm the applied PostgreSQL migrations when diagnosing an environment:

```bash
php artisan migrate:status --database=pgsql
```

Never use `migrate:fresh` against a shared cloud database. MySQL schema deployment remains the responsibility of the authentication service or its infrastructure workflow.

## One-time development demo preparation

Demo data is allowed only when `APP_ENV` is `local`, `development`, or `testing`.

1. Provision and migrate the external MySQL authentication database.
2. Create the active MySQL identity `admin@company.com` through the approved authentication infrastructure.
3. Configure the Dashboard `DB_PGSQL_*` and `DB_MYSQL_*` environment variables.
4. Run the PostgreSQL migration command above.
5. Run the coordinated demo seeding command:

```bash
php artisan db:seed --class=DatabaseSeeder --force --no-interaction
```

If `admin@company.com` is missing, `DatabaseSeeder` fails before creating dependent Driver, Bus, or Trip demo records. It does not create a second authentication workflow.

## Coordinated seeder order

`DatabaseSeeder` now executes development data in this order:

1. Bus, Trip, and Driver permission definitions.
2. External administrator identity precondition.
3. `CompanySeeder`.
4. `SarapiquiRoutesSeeder` for the existing `grupo-caribenos` catalogue.
5. `SmartbusDemoRouteSeeder` for the offline `smartbus-demo` plan.
6. `WorkforcePermissionsSeeder`.
7. `DriverSeeder`.
8. `BusSeeder`.
9. `TripSeeder`.

The SmartBus demo plan contains one fictional route and pattern, two ordered stops, saved offline routing data, and one unbounded Monday departure at 09:00 in `America/Costa_Rica`. `TripSeeder` selects this domain key explicitly instead of selecting the first Monday schedule by database ID.

Repeated seeding preserves existing demo identities, employment, availability, buses, planning records, and Trip history. Demo operational records are not created in production, staging, or preview environments; only permission definitions are synchronized there.

## Recovery and verification

Seeders are safe to run again after their prerequisites are corrected. They do not restore archived records or overwrite manual operational edits.

After development seeding, verify that `smartbus-demo` has:

- one `DEMO-CITY` route with the `OUTBOUND` saved plan;
- one Monday 09:00 route schedule;
- three active demo Drivers with weekday 08:00–16:00 availability;
- three active demo buses;
- four deterministic Trip lifecycle examples.

Do not repair missing cloud data with manual SQL. Correct the owning migration or seeder, verify it in isolated databases, and rerun the supported command.
