<?php

namespace App\Filament\Resources\TripAssignments\Schemas;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\RouteSchedule;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TripAssignmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Recurring Operation'))->schema([
                Select::make('route_schedule_ids')
                    ->label(__('Scheduled Departures'))
                    ->options(fn (): array => static::scheduleOptions())
                    ->multiple()->searchable()->required()->visibleOn('create'),
                Select::make('route_schedule_id')
                    ->label(__('Scheduled Departure'))
                    ->options(fn (): array => static::scheduleOptions())
                    ->searchable()->required()->visibleOn('edit'),
                Select::make('driver_id')->label(__('Driver'))
                    ->options(fn (): array => static::driverOptions())->searchable()->required(),
                Select::make('bus_id')->label(__('Bus'))
                    ->options(fn (): array => static::busOptions())->searchable()->required(),
                TimePicker::make('planned_arrival_time')->label(__('Planned Arrival'))
                    ->seconds()->required(),
                Toggle::make('ends_next_day')->label(__('Ends Next Day'))->default(false),
                DatePicker::make('valid_from')->label(__('Valid From')),
                DatePicker::make('valid_until')->label(__('Valid Until')),
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
            ->mapWithKeys(fn (RouteSchedule $schedule): array => [
                $schedule->id => __('Day :day', ['day' => $schedule->day_of_week])." · {$schedule->departure_time} · {$schedule->pattern->code}",
            ])->all();
    }

    /** @return array<string, string> */
    public static function driverOptions(): array
    {
        $company = Filament::getTenant();

        return $company instanceof Company ? Driver::withTrashed()->with('user')->where('company_id', $company->id)
            ->orderBy('license')->get()->mapWithKeys(fn (Driver $driver): array => [
                $driver->id => trim(($driver->user?->name ?? $driver->license).($driver->trashed() ? ' ('.__('Archived').')' : '')),
            ])->all() : [];
    }

    /** @return array<string, string> */
    public static function busOptions(): array
    {
        $company = Filament::getTenant();

        return $company instanceof Company ? Bus::withTrashed()->where('company_id', $company->id)
            ->orderBy('unit_number')->get()->mapWithKeys(fn (Bus $bus): array => [
                $bus->id => $bus->unit_number.($bus->trashed() ? ' ('.__('Archived').')' : ''),
            ])->all() : [];
    }
}
