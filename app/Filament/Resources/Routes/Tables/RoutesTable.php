<?php

namespace App\Filament\Resources\Routes\Tables;

use App\Filament\Resources\Routes\RouteResource;
use App\Models\Route;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;

class RoutesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('Code'))
                    ->fontFamily(FontFamily::Mono)
                    ->color(Color::Gray)
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('Route Name'))
                    ->searchable()
                    ->sortable(),
            ])
            ->defaultSort('code')
            ->filters([
                TrashedFilter::make()
                    ->label(__('Archive Status'))
                    ->placeholder(__('Not Archived'))
                    ->trueLabel(__('All Routes'))
                    ->falseLabel(__('Archived Only')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(
                        fn (Route $record): bool =>
                            ! RouteResource::canEdit($record),
                    )
                    ->color('info'),

                EditAction::make()
                    ->color('primary'),

                ActionGroup::make([
                    DeleteAction::make()
                        ->label(__('Archive'))
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Archive Route'))
                        ->modalDescription(__(
                            'Archiving this route hides it from the current list and preserves its configuration.'
                        ))
                        ->modalSubmitActionLabel(__('Archive'))
                        ->successNotificationTitle(__('Route Archived')),
                    RestoreAction::make()
                        ->label(__('Restore'))
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading(__('Restore Route'))
                        ->modalSubmitActionLabel(__('Restore'))
                        ->successNotificationTitle(__('Route Restored')),
                ])
                    ->label(__('More Actions'))
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->color('gray'),
            ])
            ->recordUrl(function (Route $record): ?string {
                if (RouteResource::canEdit($record)) {
                    return RouteResource::getUrl('edit', [
                        'record' => $record->getRouteKey(),
                    ]);
                }

                if (RouteResource::canView($record)) {
                    return RouteResource::getUrl('view', [
                        'record' => $record->getRouteKey(),
                    ]);
                }

                return null;
            });
    }
}
