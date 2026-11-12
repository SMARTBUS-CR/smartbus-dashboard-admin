<?php

namespace App\Filament\Resources\TripAssignments\Tables;

use App\Enums\TripAssignmentStatus;
use App\Filament\Resources\TripAssignments\Schemas\TripAssignmentForm;
use App\Filament\Resources\TripAssignments\TripAssignmentResource;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Company;
use App\Models\RoutePattern;
use App\Models\TripAssignment;
use App\Models\User;
use App\Services\TripAssignmentManagementService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class TripAssignmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('schedule.day_of_week')->label(__('Weekday'))
                    ->formatStateUsing(fn (int $state): string => TripAssignmentForm::weekdayLabel($state))->sortable(),
                TextColumn::make('schedule.departure_time')->label(__('Departure'))->sortable(),
                TextColumn::make('schedule.pattern.code')->label(__('Route Pattern'))->searchable(),
                TextColumn::make('driver.user.name')->label(__('Driver'))
                    ->formatStateUsing(fn (?string $state, TripAssignment $record): string => ($state ?? $record->driver->license).($record->driver->trashed() ? ' ('.__('Archived').')' : '')),
                TextColumn::make('bus.unit_number')->label(__('Bus'))
                    ->formatStateUsing(fn (string $state, TripAssignment $record): string => $state.($record->bus->trashed() ? ' ('.__('Archived').')' : '')),
                TextColumn::make('planned_arrival_time')->label(__('Planned Arrival'))->toggleable(),
                IconColumn::make('ends_next_day')->label(__('Overnight'))->boolean()->toggleable(),
                TextColumn::make('valid_from')->label(__('Valid From'))->date()->toggleable(),
                TextColumn::make('valid_until')->label(__('Valid Until'))->date()->toggleable(),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options(TripAssignmentStatus::class),
                SelectFilter::make('route_schedule_id')->label(__('Scheduled Departure'))->options(fn (): array => TripAssignmentForm::scheduleOptions()),
                SelectFilter::make('driver_id')->label(__('Driver'))->options(fn (): array => TripAssignmentForm::driverOptions(true)),
                SelectFilter::make('bus_id')->label(__('Bus'))->options(fn (): array => TripAssignmentForm::busOptions(true)),
                Filter::make('route_pattern')->schema([Select::make('id')->options(fn (): array => static::patternOptions())])
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['id'] ?? null, fn (Builder $query, string $id): Builder => $query->whereHas('schedule', fn (Builder $query): Builder => $query->where('route_pattern_id', $id)))),
                Filter::make('validity')->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->where(fn (Builder $query): Builder => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $date)))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->where(fn (Builder $query): Builder => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $date)))),
                TrashedFilter::make()->label(__('Archive Status')),
            ])
            ->recordActions([
                ViewAction::make()->visible(fn (TripAssignment $record): bool => ! TripAssignmentResource::canEdit($record)),
                EditAction::make(),
                ActionGroup::make([
                    Action::make('dailyTrips')->label(__('Daily Trips'))->icon(Heroicon::OutlinedListBullet)
                        ->url(fn (TripAssignment $record): string => TripResource::getUrl('index', [
                            'filters' => [
                                'route_schedule_id' => ['value' => $record->route_schedule_id],
                                'service_date' => ['period' => 'all'],
                            ],
                        ], tenant: Filament::getTenant())),
                    Action::make('deactivate')->label(__('Deactivate'))->icon(Heroicon::OutlinedNoSymbol)
                        ->visible(fn (TripAssignment $record): bool => ! $record->trashed() && $record->status === TripAssignmentStatus::ACTIVE && TripAssignmentResource::canEdit($record))
                        ->action(fn (TripAssignment $record, Action $action): bool => static::lifecycle($record, $action, 'setActive', false)),
                    Action::make('activate')->label(__('Activate'))->icon(Heroicon::OutlinedCheckCircle)
                        ->visible(fn (TripAssignment $record): bool => ! $record->trashed() && $record->status === TripAssignmentStatus::INACTIVE && TripAssignmentResource::canEdit($record))
                        ->action(fn (TripAssignment $record, Action $action): bool => static::lifecycle($record, $action, 'setActive', true)),
                    Action::make('archive')->label(__('Archive'))->icon(Heroicon::OutlinedArchiveBox)
                        ->requiresConfirmation()->visible(fn (TripAssignment $record): bool => ! $record->trashed() && TripAssignmentResource::canDelete($record))
                        ->action(fn (TripAssignment $record, Action $action): bool => static::lifecycle($record, $action, 'archive')),
                    Action::make('restore')->label(__('Restore'))->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->requiresConfirmation()->visible(fn (TripAssignment $record): bool => $record->trashed() && TripAssignmentResource::canRestore($record))
                        ->action(fn (TripAssignment $record, Action $action): bool => static::lifecycle($record, $action, 'restore')),
                ])->label(__('More Actions'))->icon(Heroicon::OutlinedEllipsisVertical)->color('gray'),
            ])
            ->recordUrl(function (TripAssignment $record): ?string {
                $page = match (true) {
                    TripAssignmentResource::canEdit($record) => 'edit',
                    TripAssignmentResource::canView($record) => 'view',
                    default => null,
                };

                return $page === null ? null : TripAssignmentResource::getUrl($page, ['record' => $record], tenant: Filament::getTenant());
            })
            ->toolbarActions([]);
    }

    /** @return array<string, string> */
    private static function patternOptions(): array
    {
        $company = Filament::getTenant();

        return $company instanceof Company ? RoutePattern::query()->whereHas('route', fn (Builder $query): Builder => $query->where('company_id', $company->id))
            ->orderBy('code')->pluck('code', 'id')->all() : [];
    }

    private static function lifecycle(TripAssignment $record, Action $action, string $operation, ?bool $active = null): bool
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();
        abort_unless($actor instanceof User && $company instanceof Company, 403);

        try {
            $service = app(TripAssignmentManagementService::class);
            $operation === 'setActive' ? $service->setActive($actor, $company, $record, (bool) $active) : $service->{$operation}($actor, $company, $record);
            Notification::make()->success()->title(__('Recurring Assignment Updated'))->send();

            return true;
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title(__('Assignment Change Rejected'))->body(collect($exception->errors())->flatten()->first())->send();
            $action->halt();

            return false;
        }
    }
}
