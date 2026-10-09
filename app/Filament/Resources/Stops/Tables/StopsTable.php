<?php

namespace App\Filament\Resources\Stops\Tables;

use App\Filament\Resources\Stops\StopResource;
use App\Models\Stop;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class StopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Stop'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('ownership')
                    ->label(__('Scope'))
                    ->state(
                        fn (Stop $record): string => $record->company_id === null
                            ? __('Shared')
                            : __('Company'),
                    )
                    ->badge(),

                TextColumn::make('latitude')
                    ->label(__('Latitude'))
                    ->fontFamily(FontFamily::Mono),

                TextColumn::make('longitude')
                    ->label(__('Longitude'))
                    ->fontFamily(FontFamily::Mono),
            ])
            ->defaultSort('name')
            ->filters([
                TrashedFilter::make()
                    ->label(__('Archive Status'))
                    ->placeholder(__('Not Archived'))
                    ->trueLabel(__('All Stops'))
                    ->falseLabel(__('Archived Only')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(
                        fn (Stop $record): bool => ! $record->trashed()
                            && ! StopResource::canEdit($record),
                    )
                    ->color('info'),

                EditAction::make()
                    ->visible(
                        fn (Stop $record): bool => ! $record->trashed()
                            && StopResource::canEdit($record),
                    )
                    ->color('primary'),

                DeleteAction::make()
                    ->label(__('Archive'))
                    ->color('danger')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->modalHeading(__('Archive Stop'))
                    ->modalDescription(__(
                        'The stop will be hidden from the active catalog. Its data will be preserved and it can be restored.',
                    ))
                    ->modalIcon(Heroicon::OutlinedArchiveBox)
                    ->modalIconColor('danger')
                    ->modalAlignment(Alignment::Start)
                    ->modalWidth(Width::Large)
                    ->modalSubmitActionLabel(__('Archive'))
                    ->successNotificationTitle(__('Stop Archived'))
                    ->using(function (Stop $record, DeleteAction $action): bool {
                        try {
                            return (bool) $record->delete();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title(__('Could Not Archive Stop'))
                                ->body(
                                    collect($exception->errors())
                                        ->flatten()
                                        ->first(),
                                )
                                ->send();

                            $action->halt();
                        }
                    }),

                RestoreAction::make()
                    ->label(__('Restore'))
                    ->color('success')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->requiresConfirmation()
                    ->modalHeading(__('Restore Stop'))
                    ->modalDescription(__(
                        'The stop will become available in the active catalog again.',
                    ))
                    ->modalIcon(Heroicon::OutlinedArrowUturnLeft)
                    ->modalIconColor('success')
                    ->modalAlignment(Alignment::Start)
                    ->modalWidth(Width::Large)
                    ->modalSubmitActionLabel(__('Restore'))
                    ->successNotificationTitle(__('Stop Restored')),
            ])
            ->toolbarActions([])
            ->emptyStateHeading(__('No Stops Available'))
            ->emptyStateDescription(__(
                'Company and shared stops will appear here.',
            ))
            ->recordUrl(function (Stop $record): ?string {
                if ($record->trashed()) {
                    return null;
                }

                if (StopResource::canEdit($record)) {
                    return StopResource::getUrl('edit', [
                        'record' => $record->getRouteKey(),
                    ]);
                }

                if (StopResource::canView($record)) {
                    return StopResource::getUrl('view', [
                        'record' => $record->getRouteKey(),
                    ]);
                }

                return null;
            });
    }
}
