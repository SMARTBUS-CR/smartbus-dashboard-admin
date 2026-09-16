<?php

namespace App\Filament\Resources\Routes\Tables;

use App\Models\Route;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class RoutesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('Code'))
                    ->badge()
                    ->fontFamily(FontFamily::Mono)
                    ->color(Color::Gray)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('Route Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('distance_km')
                    ->label(__('Distance'))
                    ->numeric(2, ',', '.')
                    ->suffix(' km')
                    ->badge()
                    ->color(Color::Blue)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('duration_minutes')
                    ->label(__('Estimated Time (ETA)'))
                    ->formatStateUsing(fn (?int $state): ?string => $state !== null ? Route::formatDuration($state * 60) : null)
                    ->badge()
                    ->color(Color::Yellow)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('is_active')
                    ->badge()
                    ->label(__('Status'))
                    ->formatStateUsing(fn (?bool $state): string => match ($state) {
                        true => __('Activa'),
                        false => __('Inactiva'),
                        default => __('Unknown'),
                    })
                    ->color(fn (?bool $state): string => match ($state) {
                        true => 'success',
                        false => 'danger',
                        default => 'secondary',
                    }),

                TextColumn::make('created_at')
                    ->label(__('Creation Date'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->filters([
                TrashedFilter::make(),
                TernaryFilter::make('is_active')
                    ->label(__('Status'))
                    ->trueLabel(__('Active'))
                    ->falseLabel(__('Inactive')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
