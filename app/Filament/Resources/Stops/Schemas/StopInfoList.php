<?php

namespace App\Filament\Resources\Stops\Schemas;

use App\Models\Stop;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StopInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Stop Information'))
                ->schema([
                    TextEntry::make('name')
                        ->label(__('Stop Name')),

                    TextEntry::make('ownership')
                        ->label(__('Scope'))
                        ->state(
                            fn (Stop $record): string => $record->company_id === null
                                ? __('Shared')
                                : __('Company'),
                        )
                        ->badge(),

                    TextEntry::make('description')
                        ->label(__('Description'))
                        ->placeholder(__('No Description'))
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make(__('Location'))
                ->schema([
                    TextEntry::make('latitude')
                        ->label(__('Latitude')),

                    TextEntry::make('longitude')
                        ->label(__('Longitude')),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
