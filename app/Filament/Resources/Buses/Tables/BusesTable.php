<?php

namespace App\Filament\Resources\Buses\Tables;

use App\Filament\Resources\Buses\BusResource;
use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use App\Services\BusManagementService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class BusesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('unit_number')
                    ->label(__('Unit Number'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('plate_number')
                    ->label(__('Plate Number'))
                    ->formatStateUsing(fn (string $state, Bus $record): string => "{$record->country_code} - {$state}")
                    ->searchable()
                    ->sortable(),

                TextColumn::make('vehicle')
                    ->label(__('Vehicle'))
                    ->state(fn (Bus $record): string => trim("{$record->brand} {$record->model}"))
                    ->placeholder(__('Not Specified')),

                TextColumn::make('year')
                    ->label(__('Year'))
                    ->placeholder(__('Not Specified'))
                    ->sortable(),

                TextColumn::make('capacity')
                    ->label(__('Passenger Capacity'))
                    ->numeric()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('unit_number')
            ->filters([
                TrashedFilter::make()
                    ->label(__('Archive Status'))
                    ->placeholder(__('Not Archived'))
                    ->trueLabel(__('All Buses'))
                    ->falseLabel(__('Archived Only')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (Bus $record): bool => ! $record->trashed() && ! BusResource::canEdit($record)),

                EditAction::make()
                    ->visible(fn (Bus $record): bool => ! $record->trashed() && BusResource::canEdit($record)),

                Action::make('deactivate')
                    ->label(__('Deactivate'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize(fn (Bus $record): bool => BusResource::canEdit($record))
                    ->visible(fn (Bus $record): bool => ! $record->trashed() && $record->is_active)
                    ->action(fn (Bus $record, Action $action): bool => static::run(
                        $action,
                        fn (User $actor, Company $company): Bus => app(BusManagementService::class)
                            ->setActive($actor, $company, $record->getKey(), false),
                        __('Bus Deactivated'),
                    )),

                Action::make('activate')
                    ->label(__('Activate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (Bus $record): bool => BusResource::canEdit($record))
                    ->visible(fn (Bus $record): bool => ! $record->trashed() && ! $record->is_active)
                    ->action(fn (Bus $record, Action $action): bool => static::run(
                        $action,
                        fn (User $actor, Company $company): Bus => app(BusManagementService::class)
                            ->setActive($actor, $company, $record->getKey(), true),
                        __('Bus Activated'),
                    )),

                DeleteAction::make()
                    ->label(__('Archive'))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->modalHeading(__('Archive Bus'))
                    ->modalDescription(__('The bus will be archived without deleting its operational history.'))
                    ->modalSubmitActionLabel(__('Archive'))
                    ->successNotificationTitle(__('Bus Archived'))
                    ->using(fn (Bus $record, DeleteAction $action): bool => static::run(
                        $action,
                        fn (User $actor, Company $company): Bus => app(BusManagementService::class)
                            ->archive($actor, $company, $record->getKey()),
                    )),

                RestoreAction::make()
                    ->label(__('Restore'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->modalHeading(__('Restore Bus'))
                    ->modalDescription(__('The bus will become available in the company fleet again.'))
                    ->modalSubmitActionLabel(__('Restore'))
                    ->successNotificationTitle(__('Bus Restored'))
                    ->using(fn (Bus $record, RestoreAction $action): bool => static::run(
                        $action,
                        fn (User $actor, Company $company): Bus => app(BusManagementService::class)
                            ->restore($actor, $company, $record->getKey()),
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading(__('No Buses Available'))
            ->emptyStateDescription(__('Company fleet units will appear here.'));
    }

    /** @param Closure(User, Company): Bus $operation */
    private static function run(Action $action, Closure $operation, ?string $successTitle = null): bool
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();

        abort_unless($actor instanceof User && $company instanceof Company, 403);

        try {
            $operation($actor, $company);

            if (filled($successTitle)) {
                Notification::make()->success()->title($successTitle)->send();
            }

            return true;
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title(__('Fleet Change Rejected'))
                ->body(collect($exception->errors())->flatten()->first())
                ->send();

            $action->halt();

            return false;
        }
    }
}
