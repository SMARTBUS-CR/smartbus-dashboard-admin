<?php

namespace App\Filament\Resources\Buses\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BusInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Fleet Identification'))
                ->schema([
                    TextEntry::make('country_code')->label(__('Country')),
                    TextEntry::make('plate_number')->label(__('Plate Number')),
                    TextEntry::make('unit_number')->label(__('Unit Number')),
                    IconEntry::make('is_active')->label(__('Active'))->boolean(),
                ])
                ->columns(4)
                ->columnSpanFull(),

            Section::make(__('Vehicle Details'))
                ->schema([
                    TextEntry::make('brand')->label(__('Brand'))->placeholder(__('Not Specified')),
                    TextEntry::make('model')->label(__('Model'))->placeholder(__('Not Specified')),
                    TextEntry::make('year')->label(__('Year'))->placeholder(__('Not Specified')),
                    TextEntry::make('capacity')->label(__('Passenger Capacity'))->numeric(),
                ])
                ->columns(4)
                ->columnSpanFull(),
        ]);
    }
}
