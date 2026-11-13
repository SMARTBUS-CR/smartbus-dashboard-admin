# Simplified Trip Reservation Concurrency Contract — HU #38

Status: implemented in the planning, bus lifecycle and workforce services, with
real-process race coverage. The shared protocol is confirmed by B and Isaac,
as reported by Melanie. Complete-suite regression verification is required when
integrating these services before operational enablement.

## Reuse the Existing Schema

Keep `trips` as the reservation record and reuse its bus/Driver exclusions and
non-cancelled schedule/date unique index. Do not introduce `trip_allocations`,
duplicated times/statuses, buffers, triggers or new indexes.

The alternative allocation table would still need coordination with bus and
workforce edits: its exclusion cannot prevent a separate transaction from
making a bus inactive or removing availability. Exclusions also use locks
internally and do not eliminate every possible deadlock.

## Coordinate Only Affected Resources

Retain existing transactions and row locks. Add the missing person lock because
multiple Driver profiles can reference the same MySQL User across companies.
Locking one Driver profile does not protect that shared identity.

Use a PostgreSQL transaction advisory lock per persisted `drivers.user_id`,
without company in the key. Compute `hashtextextended` from
`smartbus:trip-user:v1:<canonical-user-uuid>` and acquire the resulting key with
`pg_advisory_xact_lock`. For multiple people, deduplicate and acquire signed
integer keys in ascending PostgreSQL order, without PHP float conversion.
Hash collisions only serialize unrelated people; they never skip checks.

Order, only where applicable:

1. Existing Company shared lock, then existing Trip row lock for replan/start/complete/cancel.
2. Affected person locks, then Bus rows, then Driver rows.
3. Existing target DriverSchedule row lock for update/delete.

Sort multiple rows by UUID within each type. Replan includes old and new
resources, reading the old assignment from the locked Trip. Driver identity
remains immutable. Replace planning's exclusive Company lock with a shared
lock so unrelated reservations in one company can proceed concurrently.

Under READ COMMITTED, requery final state after locking and write in the same
transaction. No network calls or manual commits. Do not add MySQL writes to
this PostgreSQL reservation transaction.

## Required Final Checks

| Operation | Check under resource locks |
| --- | --- |
| Schedule/replan | Active company/bus, Driver eligibility, full `covers()` and no overlapping Trip across any profile of that User. |
| Start | Current company/bus and Driver eligibility, effective departure suspension, availability at the actual start instant, and no other in-progress Trip for the bus or any profile of that User. |
| Complete | Authorized assigned driver or administrator; in-progress state and server arrival at or after actual departure. Company inactivity does not prevent authorized closure. |
| Cancel | Authorized administrator, scheduled state and trimmed nonempty reason of at most 500 characters. |
| Bus deactivate/archive | No scheduled/in-progress Trip for the bus. |
| Driver deactivate/archive | No scheduled/in-progress Trip for that profile. |
| Employment/license edit | Proposed values preserve assigned-trip eligibility. |
| Availability update/delete | Proposed change preserves assigned-trip coverage or rolls back. |

Workforce mutations acquire the person lock before their existing Driver lock.
Their guards read Trips without locking them, avoiding an inversion against
replan. Salary-only changes require no reservation check. MySQL identity and
membership changes are not made atomic by this protocol; workflows requiring
coordination must explicitly participate.

Intervals are UTC [departure, arrival): existing start < proposed end AND
existing end > proposed start. Allow adjacency; replan excludes its own Trip.
Only scheduled/in-progress Trips reserve intervals. Completed departures still
occupy schedule/date identity; cancelled history can be replaced without deletion.
At start, another in-progress Trip blocks the bus/person even after its planned
interval ends. Start checks the actual instant, not full planned-window coverage.
Complete and cancel use the same resource-lock order before writing, releasing
the reservation through the persisted state change.

Scope and authorize before resolving retries. Equivalent retries preserve the
first server timestamps and cancellation reason; cancellation retries still
validate the supplied reason. Replan cannot change started or terminal Trips.
Normal and quiet direct model history writes and permanent deletion remain
prohibited; only the trusted service transitions may modify execution history.
Soft deletion does not cancel or complete a Trip: scheduled/in-progress Trips
retain their reservations and in-progress occupancy even when soft deleted.
Restoration may clear `deleted_at` without changing any other historical field.
Factory/seeder
execution examples are explicit development fixtures, not operational transitions.

Preserve private bilingual field errors for known constraint conflicts.
Person conflicts use `driver_id` without exposing foreign details. Unexpected
errors propagate; failures preserve originals. Restore permission context in finally.

## Verification and Performance

Reuse independent CLI workers, barriers, committed fixtures and `_testing`
guards. Do not introduce POSIX FIFOs or `pcntl_fork`: the existing process
harness supports Windows development.

Test actual service outcomes and persisted invariants for overlap, adjacency,
cross-company person conflicts, duplicate departures, replan and competing
bus/workforce edits. Control the contested point using barriers; simultaneous
start alone does not prove overlapping execution. `pg_blocking_pids()` is an
optional diagnostic, not a required assertion in every race test.
Exercise both winning orders and clean only owned fixtures after workers stop.

Keep transactions short, batch key computation and avoid redundant queries.
Do not claim cloud performance gains without measuring query count and
transaction duration. No global lock or exclusive company-wide planning lock.

Coordination confirmed: B and Isaac agreed on the shared person key, resource
order and transaction boundaries, as reported by Melanie. Snapshot and trip
history response agreements are separate contracts.

References: [PostgreSQL locking](https://www.postgresql.org/docs/16/explicit-locking.html)
and [constraints](https://www.postgresql.org/docs/16/ddl-constraints.html).
