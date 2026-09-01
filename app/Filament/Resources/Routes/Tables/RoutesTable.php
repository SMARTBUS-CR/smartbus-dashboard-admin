<?php

namespace App\Filament\Resources\Routes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RoutesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('Código'))
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('Nombre'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('origin')
                    ->label(__('Origen'))
                    ->searchable(),
                TextColumn::make('destination')
                    ->label(__('Destino'))
                    ->searchable(),
                TextColumn::make('distance_km')
                    ->label(__('Distancia'))
                    ->suffix(' km')
                    ->sortable(),
                TextColumn::make('estimated_duration_minutes')
                    ->label(__('Duración'))
                    ->suffix(' min')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('Activa'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
