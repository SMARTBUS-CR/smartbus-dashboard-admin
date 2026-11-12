<?php

namespace App\Filament\Resources\Trips\Tables;

use App\Enums\TripStatus;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\RouteSchedule;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripManagementService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class TripsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('service_date')
                    ->label(__('Service Date'))
                    ->date()
                    ->sortable(),

                TextColumn::make('planned_route_snapshot.pattern_name')
                    ->label(__('Route Pattern'))
                    ->formatStateUsing(fn (string $state, Trip $record): string => "{$record->planned_route_snapshot['pattern_code']} — {$state}"),

                TextColumn::make('scheduled_departure_at')
                    ->label(__('Scheduled Departure'))
                    ->dateTime()
                    ->timezone(fn (Trip $record): string => $record->company->timezone)
                    ->sortable(),

                TextColumn::make('scheduled_arrival_at')
                    ->label(__('Scheduled Arrival'))
                    ->dateTime()
                    ->timezone(fn (Trip $record): string => $record->company->timezone)
                    ->sortable(),

                TextColumn::make('bus.unit_number')
                    ->label(__('Bus')),

                TextColumn::make('driver.license')
                    ->label(__('Driver')),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
            ])
            ->defaultSort('scheduled_departure_at')
            ->filters([
                Filter::make('service_date')
                    ->label(__('Service Date'))
                    ->schema([
                        DatePicker::make('from_date')->label(__('From Date')),
                        DatePicker::make('until_date')->label(__('Until Date')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => static::applyServiceDateFilter($query, $data)),

                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(TripStatus::class),

                SelectFilter::make('driver_id')
                    ->label(__('Driver'))
                    ->options(fn (): array => static::driverOptions()),

                SelectFilter::make('bus_id')
                    ->label(__('Bus'))
                    ->options(fn (): array => static::busOptions()),

                SelectFilter::make('route_schedule_id')
                    ->label(__('Route Schedule'))
                    ->options(fn (): array => static::routeScheduleOptions()),
            ])
            ->recordActions([
                ViewAction::make(),

                EditAction::make()
                    ->label(__('Replan'))
                    ->visible(fn (Trip $record): bool => $record->status === TripStatus::SCHEDULED
                        && TripResource::canEdit($record)),

                Action::make('start')
                    ->label(__('Start Trip'))
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Start Trip'))
                    ->modalDescription(__('The actual departure time will be recorded by the server.'))
                    ->authorize(fn (Trip $record): bool => TripResource::canStart($record))
                    ->visible(fn (Trip $record): bool => $record->status === TripStatus::SCHEDULED)
                    ->action(fn (Trip $record, Action $action): bool => static::run(
                        $action,
                        fn (User $actor, Company $company): Trip => app(TripManagementService::class)
                            ->start($actor, $company, $record->getKey()),
                        __('Trip Started'),
                    )),

                Action::make('complete')
                    ->label(__('Complete Trip'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Complete Trip'))
                    ->modalDescription(__('The actual arrival time will be recorded by the server.'))
                    ->authorize(fn (Trip $record): bool => TripResource::canComplete($record))
                    ->visible(fn (Trip $record): bool => $record->status === TripStatus::IN_PROGRESS)
                    ->action(fn (Trip $record, Action $action): bool => static::run(
                        $action,
                        fn (User $actor, Company $company): Trip => app(TripManagementService::class)
                            ->complete($actor, $company, $record->getKey()),
                        __('Trip Completed'),
                    )),

                Action::make('cancel')
                    ->label(__('Cancel Trip'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Cancel Trip'))
                    ->modalDescription(__('The Trip will remain available as historical operational data.'))
                    ->schema([
                        Textarea::make('cancellation_reason')
                            ->label(__('Cancellation Reason'))
                            ->required()
                            ->maxLength(500),
                    ])
                    ->authorize(fn (Trip $record): bool => TripResource::canCancel($record))
                    ->visible(fn (Trip $record): bool => $record->status === TripStatus::SCHEDULED)
                    ->action(fn (Trip $record, array $data, Action $action): bool => static::run(
                        $action,
                        fn (User $actor, Company $company): Trip => app(TripManagementService::class)
                            ->cancel($actor, $company, $record->getKey(), $data['cancellation_reason']),
                        __('Trip Cancelled'),
                    )),

                Action::make('replace')
                    ->label(__('Create Replacement'))
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->color('info')
                    ->authorize(fn (): bool => TripResource::canCreate())
                    ->visible(fn (Trip $record): bool => $record->status === TripStatus::CANCELLED)
                    ->url(fn (Trip $record): string => TripResource::getUrl('create', [
                        'replacement' => $record->getKey(),
                    ], tenant: Filament::getTenant())),
            ])
            ->toolbarActions([])
            ->emptyStateHeading(__('No Trips Planned'))
            ->emptyStateDescription(__('Scheduled company trips will appear here.'));
    }

    /**
     * @param  Builder<Trip>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<Trip>
     */
    private static function applyServiceDateFilter(Builder $query, array $data): Builder
    {
        $fromDate = $data['from_date'] ?? null;
        $untilDate = $data['until_date'] ?? null;

        if (blank($fromDate) && blank($untilDate)) {
            return $query;
        }

        if ((filled($fromDate) && ! static::isValidDate($fromDate))
            || (filled($untilDate) && ! static::isValidDate($untilDate))) {
            return $query->whereRaw('1 = 0');
        }

        $fromDate = filled($fromDate) ? $fromDate : $untilDate;
        $untilDate = filled($untilDate) ? $untilDate : $fromDate;

        if ($fromDate > $untilDate) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereBetween('service_date', [$fromDate, $untilDate]);
    }

    private static function isValidDate(mixed $date): bool
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return false;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /** @return array<string, string> */
    private static function driverOptions(): array
    {
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return [];
        }

        return Driver::withTrashed()
            ->where('company_id', $company->getKey())
            ->orderBy('license')
            ->pluck('license', 'id')
            ->all();
    }

    /** @return array<string, string> */
    private static function busOptions(): array
    {
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return [];
        }

        return Bus::withTrashed()
            ->where('company_id', $company->getKey())
            ->orderBy('unit_number')
            ->get(['id', 'unit_number', 'plate_number'])
            ->mapWithKeys(fn (Bus $bus): array => [
                $bus->getKey() => "{$bus->unit_number} — {$bus->plate_number}",
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function routeScheduleOptions(): array
    {
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return [];
        }

        return RouteSchedule::query()
            ->join('route_patterns', 'route_patterns.id', '=', 'route_schedules.route_pattern_id')
            ->join('routes', 'routes.id', '=', 'route_patterns.route_id')
            ->where('routes.company_id', $company->getKey())
            ->orderBy('route_patterns.code')
            ->orderBy('route_schedules.departure_time')
            ->get([
                'route_schedules.id',
                'route_schedules.departure_time',
                'route_patterns.code as pattern_code',
            ])
            ->mapWithKeys(fn (RouteSchedule $schedule): array => [
                $schedule->getKey() => "{$schedule->pattern_code} — {$schedule->departure_time}",
            ])
            ->all();
    }

    /** @param Closure(User, Company): Trip $operation */
    private static function run(Action $action, Closure $operation, string $successTitle): bool
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();

        abort_unless($actor instanceof User && $company instanceof Company, 403);

        try {
            $operation($actor, $company);

            Notification::make()->success()->title($successTitle)->send();

            return true;
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title(__('Trip Change Rejected'))
                ->body(collect($exception->errors())->flatten()->first())
                ->send();

            $action->halt();

            return false;
        }
    }
}
