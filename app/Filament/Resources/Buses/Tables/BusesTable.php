<?php

namespace App\Filament\Resources\Buses\Tables;

use App\Filament\Resources\Buses\BusResource;
use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use App\Services\BusManagementService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                    ->badge()->color('primary')->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('plate_number')
                    ->label(__('Plate Number'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('country_code')
                    ->label(__('Country'))->badge()->color('info')->toggleable(),

                TextColumn::make('brand')
                    ->label(__('Vehicle'))->weight(FontWeight::Bold)
                    ->placeholder(__('Not Specified'))
                    ->description(fn (Bus $record): string => collect([$record->model, $record->year])->filter()->implode(' · '))
                    ->toggleable(),

                TextColumn::make('capacity')
                    ->label(__('Capacity'))
                    ->formatStateUsing(fn (int $state): string => static::capacityLabel($state))
                    ->sortable()->toggleable(),
            ])
            ->defaultSort('unit_number')
            ->filters([
                SelectFilter::make('brand')->label(__('Brand'))->options(fn (): array => static::vehicleOptions('brand')),
                SelectFilter::make('model')->label(__('Model'))->options(fn (): array => static::vehicleOptions('model')),
                SelectFilter::make('capacity')->label(__('Capacity'))->options(fn (): array => static::vehicleOptions('capacity')),
                TrashedFilter::make()
                    ->label(__('Archive Status'))
                    ->placeholder(__('Not Archived'))
                    ->trueLabel(__('All Buses'))
                    ->falseLabel(__('Archived Only')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema(fn (Schema $schema): Schema => BusResource::infolist($schema))
                    ->visible(fn (Bus $record): bool => BusResource::canView($record) && ! BusResource::canEdit($record)),

                EditAction::make()
                    ->visible(fn (Bus $record): bool => BusResource::canEdit($record)),

                ActionGroup::make([
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
                ])->label(__('More Actions'))->icon(Heroicon::OutlinedEllipsisVertical)->color('gray'),
            ])
            ->toolbarActions([])
            ->emptyStateHeading(__('No Buses Available'))
            ->emptyStateDescription(__('Company fleet units will appear here.'));
    }

    /** @return array<int|string, string> */
    private static function vehicleOptions(string $column): array
    {
        return BusResource::getEloquentQuery()->withTrashed()->whereNotNull($column)
            ->distinct()->orderBy($column)->pluck($column)
            ->reject(fn ($value): bool => blank($value))
            ->mapWithKeys(fn ($value): array => [$value => $column === 'capacity' ? static::capacityLabel((int) $value) : $value])
            ->all();
    }

    private static function capacityLabel(int $capacity): string
    {
        return $capacity === 0 ? __('Not Applicable') : trans_choice(':count passenger|:count passengers', $capacity);
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
