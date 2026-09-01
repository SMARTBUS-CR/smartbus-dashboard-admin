<?php

namespace App\Filament\Resources\Companies\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Empresa'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('Identificador'))
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('legal_id')
                    ->label(__('RUT / Fiscal'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('phone')
                    ->label(__('Teléfono'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label(__('Activa'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Creada el'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
