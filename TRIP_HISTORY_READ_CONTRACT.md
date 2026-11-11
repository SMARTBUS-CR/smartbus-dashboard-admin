# Trip History Read Contract — HU #40

Status: implemented. Isaac confirmed the inclusive service-date range and the
response projection for `listFor()` and `findFor()`, as reported by Melanie.

## Entry Points

```php
TripReadService::listFor(
    User $actor,
    Company $company,
    string $fromDate,
    string $untilDate,
): Illuminate\Support\Collection;

TripReadService::findFor(
    User $actor,
    Company $company,
    string $tripId,
): array;
```

`listFor()` returns a collection of the arrays defined below, including an empty
collection when no permitted trip matches. `findFor()` returns one array with
the same projection. Neither method returns an Eloquent model.

Both entry points take an explicit actor and company and work without initializing
Filament or selecting its tenant. They perform reads without acquiring reservation
locks or changing persisted history. This contract supplies backend queries;
it does not add UI, endpoints or workforce summaries.

## Authorization and Scope

Authorization runs before input validation or trip lookup. The permission team
is temporarily set to the selected company, then restored on success and every
exception. Cached actor roles and permissions are cleared at both boundaries.

| Actor | List | Detail |
| --- | --- | --- |
| Current SuperAdmin | Selected company's trips | Selected company's trip |
| Current company administrator/custom administrative role | Requires `ViewAny:Trip` | Requires `View:Trip` |
| Current company Driver | Only trips whose assigned Driver profile references that User in the selected company | Only that User's assigned trips in the selected company |
| Passenger, deleted identity, revoked membership or missing qualifying role | Denied | Denied |

Non-SuperAdmin actors require an unarchived CompanyUser membership and a current
role assignment for that company. Read permissions granted to a Driver do not
expand their assignment scope. Passenger roles do not grant administrative reads.

All trip states and soft-deleted trips are included. An inactive or archived
company, bus or Driver profile does not hide authorized history. This does not
restore deleted identities, memberships or role assignments, or make resources
eligible for planning or execution.

`TripPermissionsSeeder`, already called by `DatabaseSeeder`, creates the two read
permissions with guard `web`. Repeated runs preserve permission IDs, existing
roles, grants and permission context. The seeder does not assign these permissions
automatically; administrative access requires explicit role grants.

## Date Range and Ordering

`fromDate` and `untilDate` are mandatory, valid local calendar dates in exact
`YYYY-MM-DD` format. Both endpoints are inclusive, `fromDate <= untilDate`, and
equal endpoints select one service day. A range is mandatory; this entry point
does not introduce pagination or additional filters.

Filtering uses persisted `trips.service_date`, not `created_at`, planned UTC dates
or actual departure dates. It does not reinterpret past service dates after a
company timezone change. The saved snapshot carries the original company timezone.

Ordering is `service_date ASC`, `scheduled_departure_at ASC`, then `id ASC`.

## Exact Trip Projection

Every result contains only these root fields:

| Field | Value |
| --- | --- |
| `id` | Trip UUID |
| `status` | `scheduled`, `in_progress`, `completed` or `cancelled` |
| `service_date` | Local `YYYY-MM-DD` string |
| `scheduled_departure_at`, `scheduled_arrival_at` | ISO-8601 UTC strings with six fractional digits and `Z` |
| `actual_departure_at`, `actual_arrival_at`, `cancelled_at`, `deleted_at` | Same UTC format, or `null` |
| `cancellation_reason` | Persisted string, or `null` |
| `route_pattern_id`, `route_schedule_id` | UUIDs |
| `company` | `{id, name, deleted_at}`; `name` maps to current `Company.legal_name` |
| `bus` | `{id, country_code, plate_number, unit_number, deleted_at}` |
| `driver` | `{id, user_id, deleted_at}` |
| `planned_route_snapshot` | Saved schema-v1 projection below |

Nested `deleted_at` values use the same nullable UTC format. Company, bus and
Driver metadata are minimal current identifiers, including archived records;
historical route and stop data come from the saved snapshot. No User relation is
loaded or serialized. Related columns are selected explicitly and eager loaded.

## Saved Snapshot Projection

The snapshot exposes only these fields:

| Fields | Value |
| --- | --- |
| `schema_version` | `1` |
| `pattern_id`, `pattern_code`, `pattern_name`, `headsign` | Saved pattern identity and labels |
| `route_id`, `route_code`, `route_name` | Saved route identity and labels |
| `company_timezone` | Saved IANA timezone |
| `route_geometry` | GeoJSON `{type, coordinates}` from the saved plan |
| `distance_meters`, `driving_duration_seconds` | Saved decimal strings |
| `routing_points_hash`, `revision_hash` | Saved hashes |
| `routing_leg_distances` | Saved numeric list, or `null` |
| `occurrences` | Ordered list of the objects below |

Each occurrence exposes only `occurrence_id`, `stop_id`, `name`, `stop_sequence`,
`lat`, `lng` and `minutes_from_start`. UUIDs and coordinate strings retain their
saved values; the sequence is an integer and the timing estimate is an integer
or `null`. Repeated stop IDs remain separate occurrences in travel order.

Reads do not reconstruct the route, recalculate its revision, query live stops
for snapshot content, or call OSRM/Photon. Changes or archival in the catalogue
do not rewrite the stored snapshot or planned/actual times. Extra object fields
outside this projection are omitted, including nested User/Driver metadata.

Salaries, license details, email addresses, passwords, credential hashes and tokens
are excluded from the response. Privacy coverage checks actual returned keys and
values, including extra metadata in snapshot, geometry and occurrence objects.

## Errors and Localization

| Condition | Result |
| --- | --- |
| Actor cannot perform the read | `AuthorizationException`, before validation or lookup |
| Missing/invalid range endpoint | `ValidationException` keyed by `from_date` or `until_date` |
| Reversed range | `ValidationException` keyed by `until_date` |
| Missing/malformed UUID | `ValidationException` keyed by `trip_id`, before a UUID lookup |
| Missing, foreign or unassigned trip | The same `ModelNotFoundException` outcome |

Validation messages and field labels use the application locale (`en`/`es`).
The service retains framework authorization and not-found exception types;
consumers must not expose private database or related-record diagnostics.

## Verification

The persisted tests cover current authorization, explicit context, inclusive and
single-day ranges, service-date/UTC differences, archived parents, catalogue edits,
uniform not-found outcomes, nested privacy, repeated stops, nullable values,
unchanged snapshots, bilingual validation and repeatable permission setup.

```bat
php artisan test --compact tests/Feature/Policies/TripReadPolicyTest.php tests/Feature/Services/TripReadTest.php tests/Feature/Database/Seeders/TripPermissionsSeederTest.php tests/Feature/Database/Seeders/DatabaseSeederTest.php
```
