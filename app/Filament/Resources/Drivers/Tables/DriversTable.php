<?php

namespace App\Filament\Resources\Drivers\Tables;

use App\Enums\DriverStatus;
use App\Filament\Resources\Drivers\DriverResource;
use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use App\Services\DriverManagementService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class DriversTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('Driver'))
                    ->placeholder(__('Unavailable Identity')),

                TextColumn::make('user.email')
                    ->label(__('Email'))
                    ->placeholder(__('Unavailable Identity')),

                TextColumn::make('license')
                    ->label(__('License'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('license_expires_at')
                    ->label(__('License Expiration'))
                    ->date()
                    ->sortable(),

                TextColumn::make('employment_starts_on')
                    ->label(__('Employment Start'))
                    ->date()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (DriverStatus $state): string => __(ucfirst($state->value)))
                    ->color(fn (DriverStatus $state): string => $state === DriverStatus::ACTIVE ? 'success' : 'gray'),
            ])
            ->defaultSort('license')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([
                        DriverStatus::ACTIVE->value => __('Active'),
                        DriverStatus::INACTIVE->value => __('Inactive'),
                    ]),

                TrashedFilter::make()
                    ->label(__('Archive Status'))
                    ->placeholder(__('Not Archived'))
                    ->trueLabel(__('All Drivers'))
                    ->falseLabel(__('Archived Only')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (Driver $record): bool => DriverResource::canView($record)
                        && ($record->trashed() || ! DriverResource::canEdit($record))),

                EditAction::make()
                    ->visible(fn (Driver $record): bool => ! $record->trashed() && DriverResource::canEdit($record)),

                Action::make('deactivate')
                    ->label(__('Deactivate'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize(fn (Driver $record): bool => DriverResource::canEdit($record))
                    ->visible(fn (Driver $record): bool => ! $record->trashed()
                        && $record->status === DriverStatus::ACTIVE)
                    ->action(fn (Driver $record, Action $action): bool => static::run(
                        $action,
                        fn (User $actor, Company $company): Driver => app(DriverManagementService::class)
                            ->setActive($actor, $company, $record, false),
                        __('Driver Deactivated'),
                    )),

                Action::make('activate')
                    ->label(__('Activate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (Driver $record): bool => DriverResource::canEdit($record))
                    ->visible(fn (Driver $record): bool => ! $record->trashed()
                        && $record->status === DriverStatus::INACTIVE)
                    ->action(fn (Driver $record, Action $action): bool => static::run(
                        $action,
                        fn (User $actor, Company $company): Driver => app(DriverManagementService::class)
                            ->setActive($actor, $company, $record, true),
                        __('Driver Activated'),
                    )),

                DeleteAction::make()
                    ->label(__('Archive'))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->modalHeading(__('Archive Driver Employment'))
                    ->modalDescription(__('Employment will be archived without changing the shared user identity or company memberships.'))
                    ->modalSubmitActionLabel(__('Archive'))
                    ->successNotificationTitle(__('Driver Employment Archived'))
                    ->using(fn (Driver $record, DeleteAction $action): bool => static::run(
                        $action,
                        function (User $actor, Company $company) use ($record): Driver {
                            app(DriverManagementService::class)->archive($actor, $company, $record);

                            return $record;
                        },
                    )),

                RestoreAction::make()
                    ->label(__('Restore'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->modalHeading(__('Restore Driver Employment'))
                    ->modalDescription(__('Employment will be restored with its original identity and UUID.'))
                    ->modalSubmitActionLabel(__('Restore'))
                    ->successNotificationTitle(__('Driver Employment Restored'))
                    ->using(fn (Driver $record, RestoreAction $action): bool => static::run(
                        $action,
                        fn (User $actor, Company $company): Driver => app(DriverManagementService::class)
                            ->restore($actor, $company, $record),
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading(__('No Driver Employment Available'))
            ->emptyStateDescription(__('Driver employment profiles for the selected company will appear here.'));
    }

    /** @param Closure(User, Company): Driver $operation */
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
                ->title(__('Employment Change Rejected'))
                ->body(collect($exception->errors())->flatten()->first())
                ->send();

            $action->halt();

            return false;
        }
    }
}
