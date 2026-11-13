<?php

namespace App\Filament\Resources\TripAssignments\Schemas;

use App\Enums\DriverStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\RouteSchedule;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class TripAssignmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Recurring Operation'))->icon(Heroicon::OutlinedArrowPath)->schema([
                Select::make('route_schedule_ids')
                    ->label(__('Scheduled Departures'))
                    ->options(fn (): array => static::scheduleOptions())
                    ->multiple()->searchable()->required()->visibleOn('create'),
                Select::make('route_schedule_id')
                    ->label(__('Scheduled Departure'))
                    ->options(fn (): array => static::scheduleOptions())
                    ->searchable()->required()->visibleOn('edit'),
                Select::make('driver_id')->label(__('Driver'))
                    ->options(fn (string $operation): array => static::driverOptions($operation === 'edit'))->searchable()->required(),
                Select::make('bus_id')->label(__('Bus'))
                    ->options(fn (string $operation): array => static::busOptions($operation === 'edit'))->searchable()->required(),
                TextInput::make('planned_duration_minutes')->label(__('Planned Duration (Minutes)'))
                    ->placeholder('90')
                    ->helperText(__('The duration is applied to each selected departure.'))
                    ->integer()->minValue(1)->maxValue(1440)->required()->visibleOn('create'),
                TimePicker::make('planned_arrival_time')->label(__('Planned Arrival'))
                    ->seconds()->required()->visibleOn('edit'),
                Toggle::make('ends_next_day')->label(__('Ends Next Day'))->default(false)->visibleOn('edit'),
                DatePicker::make('valid_from')->label(__('Valid From'))
                    ->helperText(__('Leave blank when there is no starting boundary.')),
                DatePicker::make('valid_until')->label(__('Valid Until'))
                    ->helperText(__('Leave blank when there is no ending boundary.'))
                    ->afterOrEqual(fn (Get $get): string => filled($get('valid_from')) ? 'valid_from' : '')
                    ->validationMessages(['after_or_equal' => __('The validity end date must be on or after the start date.')]),
            ])->columns(2)->columnSpanFull(),
        ]);
    }

    /** @return array<string, string> */
    public static function scheduleOptions(): array
    {
        $company = Filament::getTenant();
        if (! $company instanceof Company) {
            return [];
        }

        return RouteSchedule::query()->with('pattern.route')
            ->whereHas('pattern.route', fn ($query) => $query->where('company_id', $company->id))
            ->orderBy('day_of_week')->orderBy('departure_time')->get()
            ->mapWithKeys(fn (RouteSchedule $schedule): array => [$schedule->id => static::scheduleLabel($schedule)])->all();
    }

    /** @return array<string, string> */
    public static function driverOptions(bool $includeHistorical = false): array
    {
        $company = Filament::getTenant();

        return $company instanceof Company ? Driver::query()->when($includeHistorical, fn ($query) => $query->withTrashed())
            ->with('user')->where('company_id', $company->id)
            ->when(! $includeHistorical, fn ($query) => $query->where('status', DriverStatus::ACTIVE))
            ->orderBy('license')->get()->mapWithKeys(fn (Driver $driver): array => [
                $driver->id => trim(($driver->user?->name ?? $driver->license).($driver->trashed() ? ' ('.__('Archived').')' : '')),
            ])->all() : [];
    }

    /** @return array<string, string> */
    public static function busOptions(bool $includeHistorical = false): array
    {
        $company = Filament::getTenant();

        return $company instanceof Company ? Bus::query()->when($includeHistorical, fn ($query) => $query->withTrashed())
            ->where('company_id', $company->id)->when(! $includeHistorical, fn ($query) => $query->where('is_active', true))
            ->orderBy('unit_number')->get()->mapWithKeys(fn (Bus $bus): array => [
                $bus->id => $bus->unit_number.($bus->trashed() ? ' ('.__('Archived').')' : ''),
            ])->all() : [];
    }

    public static function scheduleLabel(RouteSchedule $schedule): string
    {
        $direction = $schedule->pattern->headsign ?: ($schedule->pattern->name ?: $schedule->pattern->code);

        return static::weekdayLabel($schedule->day_of_week).' · '.substr($schedule->departure_time, 0, 5)
            ." · {$schedule->pattern->route->code} · {$direction}";
    }

    public static function weekdayLabel(int $dayOfWeek): string
    {
        return CarbonImmutable::create(2026, 11, 1)->addDays($dayOfWeek)
            ->locale(app()->getLocale())->translatedFormat('l');
    }
}
