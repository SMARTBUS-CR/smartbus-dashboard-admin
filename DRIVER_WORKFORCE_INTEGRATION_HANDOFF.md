# Driver + Workforce Backend Integration Handoff

## Scope

This handoff covers the integrated Driver + Workforce backend delivered by subtasks 01–10 and its contract with the real Trip implementation. It does not introduce Filament Resources, public APIs, attendance tables, payroll behavior, GPS/ETA behavior, or replacement Trip structures.

## Data ownership and migration order

PostgreSQL owns operational and company-scoped data: companies, company memberships, drivers, driver schedules, buses, and trips. MySQL owns shared User identities and Spatie roles, permissions, and assignments. `drivers.user_id` is a UUID reference value without a cross-database foreign key. Identity data is resolved separately through the User model; operational queries do not use cross-database joins or `whereHas` clauses.

Relevant PostgreSQL migrations execute in this order:

1. `2026_10_13_213237_create_buses_table.php`
2. `2026_10_13_213240_create_drivers_table.php`
3. `2026_10_13_213242_create_driver_schedules_table.php`
4. `2026_10_13_213245_create_trips_table.php`
5. `2026_10_21_233122_prevent_driver_identity_reassignment.php`
6. `2026_11_04_164520_add_deleted_at_to_trips_table.php`

Driver UUIDs, company/user uniqueness, normalized company/license uniqueness, salary constraints, soft deletion, immutable identity fields, and RESTRICT relationships are enforced by the PostgreSQL schema. Archived Driver rows continue to reserve their company/user and company/license combinations.

## Service contracts

### DriverManagementService

- `register(User $actor, Company $company, array $identity, array $employment): Driver`
- `createForExistingUser(User $actor, Company $company, User $user, array $employment): Driver`
- `updateEmployment(User $actor, Company $company, Driver $driver, array $data): Driver`
- `setActive(User $actor, Company $company, Driver $driver, bool $active): Driver`
- `archive(User $actor, Company $company, Driver $driver): void`
- `restore(User $actor, Company $company, Driver $driver): Driver`

Registration provisions only the required company membership and company-scoped `driver` role. Existing credentials, unrelated memberships, and unrelated roles remain unchanged. Lifecycle changes use explicit actor/company context and reject changes that invalidate scheduled or in-progress Trip assignments.

### DriverScheduleService

- `create(User $actor, Company $company, Driver $driver, array $data): DriverSchedule`
- `update(User $actor, Company $company, Driver $driver, DriverSchedule $schedule, array $data): DriverSchedule`
- `delete(User $actor, Company $company, Driver $driver, DriverSchedule $schedule): void`

The service validates local weekly availability, overnight intervals, inclusive validity dates, and overlaps. Mutations are serialized and rejected atomically when persisted coverage would invalidate a committed Trip.

### DriverAvailabilityService

- `covers(Driver $driver, CarbonImmutable $startUtc, CarbonImmutable $endUtc): bool`

The service accepts a positive half-open UTC interval and evaluates the complete interval in `Company.timezone`. It merges adjacent local availability, includes prior-day overnight spillover, respects effective dates, and uses real instants across daylight-saving transitions. See `DRIVER_AVAILABILITY_CONTRACT.md` for the detailed ambiguity policy.

### DriverAttendanceService

- `summarize(User $actor, Company $company, Driver $driver, CarbonImmutable $fromDate, CarbonImmutable $untilDate): array`

The read-only report groups actual Trip records by `service_date`, includes empty local dates, uses immutable planned snapshots, and returns real UTC timestamps with company timezone context. Counters describe recorded operations only; they do not prove physical attendance, absence, punctuality, payroll eligibility, penalties, or wage deductions.

### DriverReadService

Normal profile projections exclude salary and identity credentials. `salaryFor()` is the deliberate salary projection and requires `ViewSalary:Driver` in the explicit company context.

## Cross-database compensation

Registration opens connection-specific transactions and records only the deltas created by the current operation: new User, Driver, membership creation or restoration, role creation, and role assignment. PostgreSQL employment commits before MySQL identity changes. On failure, open transactions are rolled back and `DriverRegistrationOperation` compensates only recorded deltas. Existing Users, memberships, roles, and unrelated employment are never deleted. Incomplete compensation is surfaced as `DriverRegistrationCompensationException`; it is not reported as a successful registration.

## Identity locking and Trip concurrency

Workforce and Trip mutations use the shared person identity rather than only `driver_id`:

1. Enter a PostgreSQL transaction and lock the trusted Company context.
2. Resolve the persisted Driver and collect its shared `user_id`.
3. Acquire ordered transaction-scoped PostgreSQL advisory locks derived from `user_id`.
4. Lock the affected Driver, schedule, Trip, and other operational rows in the operation's documented order.
5. Revalidate eligibility and complete interval coverage before commit.

This contract serializes one person across Driver profiles in multiple companies. Conflicting workforce changes and Trip reservations cannot both commit, while unrelated identities remain independent. See `TRIP_RESERVATION_LOCK_CONTRACT.md` for the shared reservation protocol.

## Authorization and confidentiality

- Driver and DriverSchedule operations use the existing Laravel Gate, policies, Spatie teams, and active CompanyUser membership.
- A global `super-admin` may manage valid company records without membership, but record ownership is still verified.
- Company `admin`, `driver`, and `passenger` role names alone do not grant workforce administration permissions.
- Every service receives trusted actor/company/model context and does not rely on a Filament tenant.
- Ordinary Driver arrays and JSON hide salary fields and the User relation.
- Attendance reports omit salary, identity credentials, and other-company Trips.
- Spatie team context and loaded permission relations are restored or cleared on success and failure paths.

## Factories, permissions, and seeders

`DriverFactory` and `DriverScheduleFactory` provide deterministic active, inactive, archived, same-day, overnight, bounded, and unbounded fixtures without external requests.

The coordinated development order is:

1. `WorkforcePermissionsSeeder`
2. `DriverSeeder`
3. `BusSeeder`
4. `TripSeeder`

`DriverSeeder` remains an explicit development/testing action and is intentionally not called by `DatabaseSeeder`. This prevents automatic DEMO identity creation while preserving the agreed `DriverSeeder -> BusSeeder -> TripSeeder` dependency order. It is idempotent, skips production, does not restore archived employment, and preserves manual salary/schedule edits. `DatabaseSeeder` continues to own the existing catalogue, Bus, and Trip seed flow.

## Test-case evidence map

| Cases | Primary implementation evidence |
| --- | --- |
| DW-TC01–03 | `DriverNewIdentityRegistrationTest`, `DriverExistingUserRegistrationTest`, `DriverRegistrationGuardTest` |
| DW-TC04–05 | `DriverValuesTest`, `DriverEmploymentUpdateTest`, `DriverIdentityTest`, `DriverRegistrationDateValidationTest` |
| DW-TC06–07 | `DriverRegistrationAuthorizationTest`, `DriverProvisioningContextTest` |
| DW-TC08–09 | `DriverRegistrationCompensationTest`, `DriverRegistrationConcurrencyTest` |
| DW-TC10–11 | `DriverPolicyTest`, `DriverSchedulePolicyTest`, `DriverReadServiceTest`, `DriverSerializationTest` |
| DW-TC12 | `DriverEmploymentLifecycleTest`, `DriverTripLifecycleTest` |
| DW-TC13–16 | `DriverScheduleServiceTest`, `DriverScheduleOverlapTest`, `DriverScheduleConcurrencyTest` |
| DW-TC17–19 | `DriverAvailabilityServiceTest`, `DriverAvailabilityTimezoneTest` |
| DW-TC20–21 | `DriverTripAvailabilityTest`, `DriverTripExecutionTest`, `DriverTripConcurrencyTest` |
| DW-TC22–27 | `DriverAttendanceServiceTest` |
| DW-TC28–29 | `DriverFactoryTest`, `DriverScheduleFactoryTest`, `DriverSeederTest`, `WorkforcePermissionsSeederTest` |
| DW-TC30 | `DriverWorkforceIntegrationTest` |

## Verification evidence

Executed locally on PHP 8.5.12 with isolated PostgreSQL `smartbus_testing` and MySQL `smartbus_users_testing` databases:

- `php artisan test --compact tests/Feature/Services/Drivers/DriverWorkforceIntegrationTest.php`
  - Passed: 1 test, 38 assertions.
- Grouped regression and full-suite attempts did not produce a final Pest result. The complete run was interrupted after the local workstation suspended while the MySQL fixture was still creating per-test isolated tables. No assertion failure was reported, but these attempts are not counted as passes.

PHP 8.4 compatibility is configured in `.github/workflows/ci-cd.yml`, which installs PHP 8.4 and runs the complete suite against PostgreSQL and MySQL services. The complete remote CI result must be attached to the pull request before merge.

## Remaining integration responsibilities

- Run and record the complete CI suite on PHP 8.4 for this branch.
- Keep final merge and deployment decisions with Isaac.
- Future Filament Resources and transportation consumers must call these explicit services and must not duplicate their authorization, salary, availability, compensation, or locking rules.
- Physical attendance, payroll, GPS/ETA, public API, Gateway, and Flutter integrations remain outside this backend scope.
