<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Models\Company;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;
use Ysfkaya\FilamentPhoneInput\Tables\PhoneColumn;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('legal_id')
                    ->label(__('Legal ID'))
                    ->fontFamily(FontFamily::Mono)
                    ->color(Color::Gray)
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('legal_name')
                    ->label(__('Company'))
                    ->weight(FontWeight::Bold)
                    ->description(fn (Company $record): ?string => $record->trade_name)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('operator_number')
                    ->label(__('Operator'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('email')
                    ->label(__('Email'))
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('country_code')
                    ->label(__('Country'))
                    ->badge()
                    ->searchable()
                    ->sortable(),

                PhoneColumn::make('phone')
                    ->label(__('Phone'))
                    ->displayFormat(PhoneInputNumberType::INTERNATIONAL)
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime()
                    ->timezone(fn (Company $record): string => $record->timezone)
                    ->dateTimeTooltip('Y-m-d H:i:s T', fn (Company $record): string => $record->timezone)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
