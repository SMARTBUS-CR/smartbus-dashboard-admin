<?php

namespace App\Filament\Resources\Trips\Tables;

use App\Enums\TripStatus;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
            ])
            ->toolbarActions([])
            ->emptyStateHeading(__('No Trips Planned'))
            ->emptyStateDescription(__('Scheduled company trips will appear here.'));
    }
}
