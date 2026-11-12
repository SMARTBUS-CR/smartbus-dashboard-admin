<?php

namespace App\Filament\Resources\Trips\Tables;

use App\Enums\TripStatus;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Company;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripManagementService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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

                TextColumn::make('pattern.name')
                    ->label(__('Route Pattern'))
                    ->formatStateUsing(fn (string $state, Trip $record): string => "{$record->pattern->code} — {$state}"),

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
            ->filters([])
            ->recordActions([
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
