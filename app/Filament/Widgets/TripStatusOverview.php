<?php

namespace App\Filament\Widgets;

use App\Enums\TripStatus;
use App\Models\Company;
use App\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

class TripStatusOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    public static function canView(): bool
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();

        return $actor instanceof User
            && $company instanceof Company
            && $actor->canAccessTenant($company)
            && Gate::forUser($actor)->allows('viewAny', [Trip::class, $company]);
    }

    protected function getHeading(): string
    {
        return __('Trip Operations');
    }

    protected function getDescription(): string
    {
        return __('Persisted Trip statuses for the selected local service dates.');
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $counts = $this->statusCounts();

        return [
            Stat::make(TripStatus::SCHEDULED->getLabel(), $counts[TripStatus::SCHEDULED->value])
                ->color(TripStatus::SCHEDULED->getColor()),
            Stat::make(TripStatus::IN_PROGRESS->getLabel(), $counts[TripStatus::IN_PROGRESS->value])
                ->color(TripStatus::IN_PROGRESS->getColor()),
            Stat::make(TripStatus::COMPLETED->getLabel(), $counts[TripStatus::COMPLETED->value])
                ->color(TripStatus::COMPLETED->getColor()),
            Stat::make(TripStatus::CANCELLED->getLabel(), $counts[TripStatus::CANCELLED->value])
                ->color(TripStatus::CANCELLED->getColor()),
        ];
    }

    /** @return array<string, int> */
    private function statusCounts(): array
    {
        $counts = array_fill_keys(array_column(TripStatus::cases(), 'value'), 0);
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return $counts;
        }

        $range = $this->dateRange($company);
        if ($range === null) {
            return $counts;
        }

        $persisted = Trip::query()
            ->where('company_id', $company->getKey())
            ->whereBetween('service_date', $range)
            ->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        foreach ($persisted as $status => $total) {
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $total;
            }
        }

        return $counts;
    }

    /** @return array{string, string}|null */
    private function dateRange(Company $company): ?array
    {
        $fromDate = $this->pageFilters['from_date'] ?? null;
        $untilDate = $this->pageFilters['until_date'] ?? null;
        $today = CarbonImmutable::today($company->timezone);
        $period = $this->pageFilters['period'] ?? (filled($fromDate) || filled($untilDate) ? 'custom' : 'today');

        if ($period !== 'custom') {
            $daysBack = match ($period) {
                'today' => 0,
                'last_7_days' => 6,
                'last_30_days' => 29,
                default => null,
            };

            return $daysBack === null ? null : [$today->subDays($daysBack)->toDateString(), $today->toDateString()];
        }

        if (blank($fromDate) && blank($untilDate)) {
            return [$today->toDateString(), $today->toDateString()];
        }

        if (! static::isValidDate($fromDate) && ! blank($fromDate)) {
            return null;
        }

        if (! static::isValidDate($untilDate) && ! blank($untilDate)) {
            return null;
        }

        $fromDate = filled($fromDate) ? $fromDate : $untilDate;
        $untilDate = filled($untilDate) ? $untilDate : $fromDate;

        if ($fromDate > $untilDate) {
            return null;
        }

        return [$fromDate, $untilDate];
    }

    private static function isValidDate(mixed $date): bool
    {
        if (! is_string($date)) {
            return false;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
