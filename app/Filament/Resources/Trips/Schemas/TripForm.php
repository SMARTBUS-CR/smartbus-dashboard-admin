<?php

namespace App\Filament\Resources\Trips\Schemas;

use App\Enums\DriverStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class TripForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Trip Plan'))
                ->description(__('Select a company route departure and the resources required for the complete planned interval.'))
                ->schema([
                    Select::make('route_pattern_id')
                        ->label(__('Route Pattern'))
                        ->options(fn (): array => static::routePatternOptions())
                        ->searchable()
                        ->live()
                        ->required(),

                    Select::make('route_schedule_id')
                        ->label(__('Route Schedule'))
                        ->options(fn (Get $get): array => static::routeScheduleOptions($get('route_pattern_id')))
                        ->searchable()
                        ->required(),

                    DatePicker::make('service_date')
                        ->label(__('Service Date'))
                        ->required()
                        ->rules(['date_format:Y-m-d']),

                    TextInput::make('departure_utc_offset')
                        ->label(__('Departure UTC Offset'))
                        ->placeholder('-06:00')
                        ->helperText(__('Required only when the local departure time is ambiguous because of a daylight-saving transition.'))
                        ->dehydrated(fn (?string $state): bool => filled($state)),

                    DateTimePicker::make('arrival_at')
                        ->label(__('Scheduled Arrival'))
                        ->timezone(fn (): string => static::company()?->timezone ?? config('app.timezone'))
                        ->seconds()
                        ->required()
                        ->dehydrateStateUsing(fn (mixed $state): mixed => static::arrivalWithOffset($state)),

                    Select::make('bus_id')
                        ->label(__('Bus'))
                        ->options(fn (): array => static::busOptions())
                        ->searchable()
                        ->required(),

                    Select::make('driver_id')
                        ->label(__('Driver'))
                        ->options(fn (): array => static::driverOptions())
                        ->searchable()
                        ->required(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    /** @return array<string, string> */
    private static function routePatternOptions(): array
    {
        $company = static::company();

        if ($company === null) {
            return [];
        }

        return RoutePattern::query()
            ->whereHas('route', fn (Builder $query): Builder => $query->where('company_id', $company->getKey()))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (RoutePattern $pattern): array => [
                $pattern->getKey() => "{$pattern->code} — {$pattern->name}",
            ])->all();
    }

    /** @return array<string, string> */
    private static function routeScheduleOptions(mixed $patternId): array
    {
        if (! is_string($patternId) || $patternId === '') {
            return [];
        }

        return RouteSchedule::query()
            ->where('route_pattern_id', $patternId)
            ->orderBy('day_of_week')
            ->orderBy('departure_time')
            ->get(['id', 'day_of_week', 'departure_time'])
            ->mapWithKeys(fn (RouteSchedule $schedule): array => [
                $schedule->getKey() => __('Day :day at :time', [
                    'day' => static::weekdayLabel($schedule->day_of_week),
                    'time' => substr($schedule->departure_time, 0, 5),
                ]),
            ])->all();
    }

    /** @return array<string, string> */
    private static function busOptions(): array
    {
        $company = static::company();

        if ($company === null) {
            return [];
        }

        return Bus::query()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->orderBy('unit_number')
            ->get(['id', 'unit_number', 'plate_number'])
            ->mapWithKeys(fn (Bus $bus): array => [
                $bus->getKey() => "{$bus->unit_number} — {$bus->plate_number}",
            ])->all();
    }

    /** @return array<string, string> */
    private static function driverOptions(): array
    {
        $company = static::company();

        if ($company === null) {
            return [];
        }

        return Driver::query()
            ->where('company_id', $company->getKey())
            ->where('status', DriverStatus::ACTIVE)
            ->orderBy('license')
            ->pluck('license', 'id')
            ->all();
    }

    private static function arrivalWithOffset(mixed $state): mixed
    {
        $company = static::company();

        if (! is_string($state) || $state === '' || $company === null) {
            return $state;
        }

        return CarbonImmutable::parse($state, config('app.timezone'))
            ->setTimezone($company->timezone)
            ->format('Y-m-d\TH:i:sP');
    }

    private static function company(): ?Company
    {
        $company = Filament::getTenant();

        return $company instanceof Company ? $company : null;
    }

    private static function weekdayLabel(int $day): string
    {
        return match ($day) {
            0 => __('Sunday'),
            1 => __('Monday'),
            2 => __('Tuesday'),
            3 => __('Wednesday'),
            4 => __('Thursday'),
            5 => __('Friday'),
            6 => __('Saturday'),
        };
    }
}
