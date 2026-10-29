<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\DriverStatus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\DriverSchedule;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DriverAvailabilityService
{
    /**
     * Check complete half-open UTC coverage using persisted active employment.
     * Endpoints must have zero UTC offset and end must be strictly after start.
     * Repeated local clock times count in both occurrences; nonexistent clock
     * times contribute no real instants. No local boundary is silently shifted.
     * This read-only result is not a reservation or a Trip assignment guard.
     *
     * @throws ValidationException Invalid endpoints or company timezone.
     */
    public function covers(Driver $driver, CarbonImmutable $startUtc, CarbonImmutable $endUtc): bool
    {
        $errors = [];
        foreach (['start_utc' => $startUtc, 'end_utc' => $endUtc] as $field => $endpoint) {
            if ($endpoint->getOffset() !== 0) {
                $errors[$field] = __('Availability interval endpoints must use UTC.');
            }
        }
        if ($endUtc <= $startUtc) {
            $errors['end_utc'] = __('The end of the availability interval must be after its start.');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $startUtc = $startUtc->setTimezone('UTC');
        $endUtc = $endUtc->setTimezone('UTC');
        $employment = Driver::query()->whereKey($driver->getKey())->where('status', DriverStatus::ACTIVE)->first(['id', 'company_id']);
        if ($employment === null) {
            return false;
        }
        $company = Company::query()->whereKey($employment->company_id)->where('status', CompanyStatus::ACTIVE)->first(['id', 'timezone']);
        if ($company === null) {
            return false;
        }
        Validator::make(['timezone' => $company->timezone], ['timezone' => ['required', 'timezone']])->validate();
        $schedules = DriverSchedule::query()->where('driver_id', $employment->id)->orderBy('id')->get()->groupBy('day_of_week');
        if ($schedules->isEmpty()) {
            return false;
        }
        $timezone = new DateTimeZone($company->timezone);
        $transitions = $timezone->getTransitions($startUtc->getTimestamp(), $endUtc->getTimestamp() + 1);

        foreach ($transitions as $index => $transition) {
            $segmentStart = CarbonImmutable::createFromTimestampUTC($transition['ts']);
            $segmentEnd = isset($transitions[$index + 1])
                ? CarbonImmutable::createFromTimestampUTC($transitions[$index + 1]['ts']) : $endUtc;
            $segmentStart = $segmentStart < $startUtc ? $startUtc : $segmentStart;
            $segmentEnd = $segmentEnd > $endUtc ? $endUtc : $segmentEnd;
            if ($segmentStart < $segmentEnd && ! $this->segmentCovered($schedules, $segmentStart, $segmentEnd, $transition['offset'])) {
                return false;
            }
        }

        return true;
    }

    /** @param Collection<int, Collection<int, DriverSchedule>> $schedules */
    private function segmentCovered(Collection $schedules, CarbonImmutable $startUtc, CarbonImmutable $endUtc, int $offset): bool
    {
        // UTC is used as a neutral calendar for wall-clock arithmetic. Applying
        // each real offset segment explicitly avoids PHP's ambiguous-time choice.
        for ($day = $startUtc->addSeconds($offset)->startOfDay(); ; $day = $day->addDay()) {
            $dayStartUtc = $day->subSeconds($offset);
            if ($dayStartUtc >= $endUtc) {
                return true;
            }
            $dayEndUtc = $day->addDay()->subSeconds($offset);
            $windowStart = $dayStartUtc > $startUtc ? $dayStartUtc : $startUtc;
            $windowEnd = $dayEndUtc < $endUtc ? $dayEndUtc : $endUtc;
            if (! $this->dayCovered($schedules, $day, $offset, $windowStart, $windowEnd)) {
                return false;
            }
        }
    }

    /** @param Collection<int, Collection<int, DriverSchedule>> $schedules */
    private function dayCovered(Collection $schedules, CarbonImmutable $day, int $offset, CarbonImmutable $startUtc, CarbonImmutable $endUtc): bool
    {
        $intervals = [];
        foreach ([$day->subDay(), $day] as $startingDay) {
            $date = $startingDay->toDateString();
            foreach ($schedules->get($startingDay->dayOfWeek, collect()) as $schedule) {
                $validFrom = $schedule->getRawOriginal('valid_from');
                $validUntil = $schedule->getRawOriginal('valid_until');
                if (($validFrom !== null && $date < $validFrom) || ($validUntil !== null && $date > $validUntil)) {
                    continue;
                }
                $localEndDay = $schedule->ends_next_day ? $startingDay->addDay() : $startingDay;
                $slotStart = CarbonImmutable::parse($date.' '.$schedule->start_time, 'UTC')->subSeconds($offset);
                $slotEnd = CarbonImmutable::parse($localEndDay->toDateString().' '.$schedule->end_time, 'UTC')->subSeconds($offset);
                $slotStart = $slotStart < $startUtc ? $startUtc : $slotStart;
                $slotEnd = $slotEnd > $endUtc ? $endUtc : $slotEnd;
                if ($slotStart < $slotEnd) {
                    $intervals[] = [$slotStart, $slotEnd];
                }
            }
        }
        usort($intervals, static fn (array $first, array $second): int => $first[0] <=> $second[0]);
        $cursor = $startUtc;
        foreach ($intervals as [$slotStart, $slotEnd]) {
            if ($slotStart > $cursor) {
                return false;
            }
            $cursor = $slotEnd > $cursor ? $slotEnd : $cursor;
            if ($cursor >= $endUtc) {
                return true;
            }
        }

        return false;
    }
}
