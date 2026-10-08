<?php

namespace App\Filament\Resources\Stops\Tables;

use App\Filament\Resources\Stops\StopResource;
use App\Models\Stop;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->description(__(
                'Shared stops can be used by multiple companies.',
            ))
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
                    ->label(__('Latitude')),

                TextColumn::make('longitude')
                    ->label(__('Longitude')),
            ])
            ->defaultSort('name')
            ->recordActions([
                ViewAction::make()
                    ->visible(
                        fn (Stop $record): bool =>
                            ! StopResource::canEdit($record),
                    )
                    ->color('info'),

                EditAction::make()
                    ->color('primary'),
            ])
            ->toolbarActions([])
            ->emptyStateHeading(__('No Stops Available'))
            ->emptyStateDescription(__(
                'Company and shared stops will appear here.',
            ))
            ->recordUrl(function (Stop $record): ?string {
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
