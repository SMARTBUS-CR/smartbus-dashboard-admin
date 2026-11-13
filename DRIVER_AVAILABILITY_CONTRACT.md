# Driver Availability Contract for Contributor A

## Entry Point

```php
use App\Models\Driver;
use App\Services\DriverAvailabilityService;
use Carbon\CarbonImmutable;

public function covers(
    Driver $driver,
    CarbonImmutable $startUtc,
    CarbonImmutable $endUtc,
): bool;
```

Resolve the service through Laravel's container, or inject it into the Trip planning service. There are no constructor dependencies, UI requirements, external API calls, or Trip model dependencies.

```php
$covered = $availability->covers(
    $driver,
    CarbonImmutable::parse('2026-10-19T14:00:00Z'),
    CarbonImmutable::parse('2026-10-19T18:00:00Z'),
);
```

## Inputs and Validation

- Both endpoints must be CarbonImmutable instances with UTC offset zero. `UTC`, `GMT`, `Etc/UTC`, and `Z` representations with zero offset are accepted; the service normalizes immutable copies to UTC.
- `endUtc` must be strictly later than `startUtc`. The comparison preserves microseconds.
- Parse untrusted timestamp strings at the calling boundary. The typed contract receives date objects, not raw strings.
- Invalid endpoints throw Laravel `ValidationException`. Nonzero-offset endpoints use `start_utc` and/or `end_utc` errors; empty or reversed intervals use `end_utc`.
- An active company's missing or invalid IANA timezone throws `ValidationException` with a `timezone` field error. `UTC` is supported.
- Interval validation happens before database eligibility lookup. For valid intervals, a missing, archived, or inactive persisted Driver or Company returns `false`.

## Meaning of the Result

`true` means every real instant in the half-open UTC interval `[startUtc, endUtc)` is covered by this employment profile's effective weekly availability. The start is included and the end is excluded. A valid interval with any uncovered portion returns `false`; an eligible departure by itself does not establish coverage.

The service reloads the Driver and its actual Company, then reads that Driver's schedules from PostgreSQL. It ignores dirty caller attributes and cached relationships. Other drivers' schedules, including another employment profile for the same MySQL User, cannot fill a gap.

No result cache or current-clock dependency is used. The same persisted employment state, Company timezone, schedules, UTC interval, and runtime timezone rules produce the same result. Input date objects and stored records are not modified.

## Weekly Rules and Local Calendar

- `day_of_week` uses the Company's local calendar: 0 is Sunday and 6 is Saturday.
- `start_time` and `end_time` are local wall-clock values in the persisted `Company.timezone`.
- Null `valid_from` or `valid_until` means no lower or upper starting-date limit, respectively.
- Both date bounds are inclusive and apply to the local day on which a shift starts.
- An overnight shift may contribute coverage on the following day even when that following date is after `valid_until`.
- With `ends_next_day = true`, equal start/end times mean the same local clock time on the following calendar date. That local-day shift can span 23, 24, or 25 real hours during ordinary daylight transitions.
- Adjacent and overlapping real intervals are merged. Any real temporal gap prevents complete coverage, including a subsecond requested portion of a gap.
- Requests may cross midnight, week/month boundaries, and multiple local dates.

Example: Sunday 20:00–23:00 in `America/Costa_Rica` covers Monday 02:00–05:00 UTC. Comparing the request's UTC weekday directly with `day_of_week` would give the wrong answer.

## Daylight-Saving and Ambiguous Local-Time Policy

Availability is evaluated point by point on the real UTC timeline using the runtime IANA timezone rules. The service splits the request into constant-offset timezone segments and maps local clock windows into each segment. It never relies on PHP choosing a single ambiguous local endpoint or silently moving an invalid local endpoint.

| Local-clock situation | Contract |
| --- | --- |
| A repeated local time during a backward transition | Both real occurrences are covered while their local clock values fall inside the configured interval. |
| A nonexistent local time during a forward transition | It contributes no UTC instants. Surviving portions of the configured window still contribute normally. |
| A backward transition creates a real gap between two occurrences of a narrow window | The gap remains uncovered. The service does not join the earliest start to the latest end. |
| Two configured windows become adjacent on the real UTC timeline | They can combine into complete coverage. |
| The server timezone differs from the Company timezone | Results use the Company timezone and explicit UTC arithmetic. |

Concrete `America/New_York` examples:

- On 2026-11-01, local Sunday 01:15–01:45 covers `[05:15,05:45)` UTC and `[06:15,06:45)` UTC. It does **not** cover `[05:30,06:30)` UTC because that interval includes uncovered local clock values between the two occurrences.
- On 2026-03-08, local Sunday 02:15–03:15 contributes `[07:00,07:15)` UTC. Local 02:15 does not exist that morning, so it is not normalized into a later artificial start.
- A full local Sunday on those transition dates spans 25 and 23 real hours, respectively.

Tests also cover `Australia/Lord_Howe`, where the repeated-time transition is thirty minutes rather than one hour.

## Integration Responsibilities

This service evaluates configured schedule coverage for active employment and Company records. It does not resolve MySQL identities or memberships, check permissions, validate employment/license dates, or inspect Trips, GPS, route departures, or buses. Those additional planning and authorization rules belong to their respective domain boundaries.

The answer is a read-only availability check, not a reservation. When committing a Trip assignment, the caller must coordinate the final check and assignment write with the agreed Driver locking protocol and the committed-assignment safeguards delivered in Subtask 09. A prior planning read alone does not prevent schedules or employment state from changing.

Use DriverScheduleService for availability mutations. The complete Driver registration, authorization, and availability foundations must be integrated before publishing the shared planning workflow.
