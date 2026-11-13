<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TripPlanPreparationService
{
    public function __construct(private readonly RouteRoutingPointsService $routingPoints) {}

    /**
     * Prepare catalogue and time data only; authorization and driver eligibility belong to the management service.
     *
     * @param array{
     *     route_pattern_id: string, route_schedule_id: string, bus_id: string,
     *     service_date: string, arrival_at: string, departure_utc_offset?: string
     * } $data
     * @return array{
     *     company: Company, pattern: RoutePattern, schedule: RouteSchedule, bus: Bus,
     *     occurrences: Collection<int, RoutePatternStop>, service_date: string,
     *     departure_at: CarbonImmutable, arrival_at: CarbonImmutable, local_dates: list<string>
     * }
     */
    public function prepare(Company $company, array $data): array
    {
        $company = Company::query()->find($company->getKey());
        if (! $company || $company->status !== CompanyStatus::ACTIVE) {
            $this->reject('company', 'An active company context is required.');
        }

        $allowed = ['route_pattern_id', 'route_schedule_id', 'bus_id', 'service_date', 'arrival_at', 'departure_utc_offset'];
        foreach (array_diff(array_keys($data), $allowed) as $field) {
            $this->reject($field, 'This field cannot be supplied when preparing a trip plan.');
        }
        Validator::make($data, [
            'route_pattern_id' => ['required', 'uuid'],
            'route_schedule_id' => ['required', 'uuid'],
            'bus_id' => ['required', 'uuid'],
            'service_date' => ['required', 'string', 'date_format:Y-m-d'],
            'arrival_at' => ['required', 'string'],
            'departure_utc_offset' => ['sometimes', 'required', 'string', 'regex:/\A[+-](?:0\d|1[0-4]):[0-5]\d\z/'],
        ])->validate();

        $pattern = RoutePattern::query()->whereHas('route', fn (Builder $query) => $query->where('company_id', $company->getKey()))
            ->find($data['route_pattern_id']);
        if (! $pattern) {
            $this->reject('route_pattern_id', 'The selected route pattern is unavailable.');
        }
        $schedule = $pattern->schedules()->find($data['route_schedule_id']);
        if (! $schedule) {
            $this->reject('route_schedule_id', 'The selected schedule does not belong to the available route pattern.');
        }
        $bus = Bus::query()->where('company_id', $company->getKey())->where('is_active', true)->find($data['bus_id']);
        if (! $bus) {
            $this->reject('bus_id', 'The selected bus is unavailable.');
        }

        $zone = new DateTimeZone($company->timezone);
        $departure = $this->departure($data['service_date'].' '.$schedule->departure_time, $zone, $data['departure_utc_offset'] ?? null);
        if (! $pattern->schedules()->whereKey($schedule->getKey())->scheduledOn($departure->setTimezone($zone))->exists()) {
            $this->reject('service_date', 'The selected schedule does not operate on this local service date.');
        }
        $arrival = $this->arrival($data['arrival_at'], $zone);
        if ($arrival <= $departure) {
            $this->reject('arrival_at', 'Arrival must be explicitly later than departure.');
        }
        $occurrences = $this->savedPlan($pattern, $company);

        $dates = [];
        for ($day = $departure->setTimezone($zone)->startOfDay(); $day < $arrival; $day = $day->addDay()->startOfDay()) {
            $dates[] = $day->toDateString();
        }

        return [
            'company' => $company, 'pattern' => $pattern, 'schedule' => $schedule, 'bus' => $bus,
            'occurrences' => $occurrences, 'service_date' => $data['service_date'],
            'departure_at' => $departure, 'arrival_at' => $arrival, 'local_dates' => $dates,
        ];
    }

    private function departure(string $local, DateTimeZone $zone, ?string $offset): CarbonImmutable
    {
        $wall = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $local, 'UTC');
        $transitions = $zone->getTransitions($wall->getTimestamp() - 172800, $wall->getTimestamp() + 172800);
        $offsets = array_unique(array_column($transitions ?: [], 'offset'));
        $candidates = [];
        foreach ($offsets as $seconds) {
            $candidate = $wall->subSeconds($seconds);
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i:s') === $local) {
                $candidates[] = $candidate;
            }
        }
        if ($candidates === []) {
            $this->reject('service_date', 'The scheduled departure does not exist in the company timezone.');
        }
        if (count($candidates) > 1 && $offset === null) {
            $this->reject('departure_utc_offset', 'An explicit valid UTC offset is required for this departure.');
        }
        foreach ($candidates as $candidate) {
            if ($offset === null || $candidate->setTimezone($zone)->format('P') === $offset) {
                return $candidate;
            }
        }
        $this->reject('departure_utc_offset', 'An explicit valid UTC offset is required for this departure.');
    }

    private function arrival(string $value, DateTimeZone $zone): CarbonImmutable
    {
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $value)) {
            $this->reject('arrival_at', 'Arrival must include a valid local date, time and UTC offset for the company timezone.');
        }
        $normalized = str_replace('Z', '+00:00', $value);
        $format = str_contains($normalized, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        $parsed = DateTimeImmutable::createFromFormat($format, $normalized);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            $this->reject('arrival_at', 'Arrival must include a valid local date, time and UTC offset for the company timezone.');
        }
        $arrival = CarbonImmutable::instance($parsed)->setTimezone($zone);
        if ($arrival->format('Y-m-d\TH:i:s') !== substr($value, 0, 19) || $arrival->format('P') !== substr($normalized, -6)) {
            $this->reject('arrival_at', 'Arrival must include a valid local date, time and UTC offset for the company timezone.');
        }

        return $arrival->utc();
    }

    /** @return Collection<int, RoutePatternStop> */
    private function savedPlan(RoutePattern $pattern, Company $company): Collection
    {
        $occurrences = $pattern->stopOccurrences()->with('stop')->get();
        if ($occurrences->count() < 2 || $occurrences->contains(fn (RoutePatternStop $occurrence): bool => $occurrence->stop === null || ($occurrence->stop->company_id !== null && (string) $occurrence->stop->company_id !== (string) $company->getKey())
        )) {
            $this->reject('route_pattern_id', 'The saved route plan is incomplete or outdated. Calculate and save the route before planning.');
        }

        $values = $pattern->only(['route_geometry', 'distance_meters', 'driving_duration_seconds', 'routing_points_hash', 'routing_leg_distances']);
        $validator = Validator::make($values, [
            'route_geometry' => ['required', 'array'],
            'route_geometry.type' => ['required', 'in:LineString'],
            'route_geometry.coordinates' => ['required', 'array', 'list', 'min:2'],
            'route_geometry.coordinates.*' => ['required', 'array', 'list', 'size:2'],
            'route_geometry.coordinates.*.0' => ['required', 'numeric', 'between:-180,180'],
            'route_geometry.coordinates.*.1' => ['required', 'numeric', 'between:-90,90'],
            'distance_meters' => ['required', 'numeric', 'min:0'],
            'driving_duration_seconds' => ['required', 'numeric', 'min:0'],
            'routing_points_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'routing_leg_distances' => ['nullable', 'array', 'list'],
            'routing_leg_distances.*' => ['required', 'numeric', 'min:0'],
        ]);
        if ($validator->fails()) {
            $this->reject('route_pattern_id', 'The saved route plan is incomplete or outdated. Calculate and save the route before planning.');
        }
        try {
            $points = $this->routingPoints->build($occurrences->map(fn (RoutePatternStop $occurrence): array => [
                'occurrence_id' => $occurrence->getKey(), 'lat' => $occurrence->stop->latitude, 'lng' => $occurrence->stop->longitude,
            ])->all(), $pattern->routing_adjustments ?? []);
        } catch (InvalidArgumentException $exception) {
            $this->reject('route_pattern_id', 'The saved route plan is incomplete or outdated. Calculate and save the route before planning.');
        }
        $coordinates = implode(';', array_map(fn (array $point): string => sprintf('%.7F,%.7F', $point['lng'], $point['lat']), $points));
        if (
            ! hash_equals($pattern->routing_points_hash, hash('sha256', $coordinates))
            || ($pattern->routing_leg_distances !== null && count($pattern->routing_leg_distances) !== count($points) - 1)
        ) {
            $this->reject('route_pattern_id', 'The saved route plan is incomplete or outdated. Calculate and save the route before planning.');
        }

        return $occurrences;
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
