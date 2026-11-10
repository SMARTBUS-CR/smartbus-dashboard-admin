<?php

namespace App\Filament\Resources\Buses\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BusForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Fleet Identification'))
                ->description(__('Identifiers used to distinguish the unit within its company and country.'))
                ->schema([
                    Select::make('country_code')
                        ->label(__('Country'))
                        ->options(config('countries'))
                        ->searchable()
                        ->required(),

                    TextInput::make('plate_number')
                        ->label(__('Plate Number'))
                        ->required()
                        ->maxLength(30),

                    TextInput::make('unit_number')
                        ->label(__('Unit Number'))
                        ->required()
                        ->maxLength(50),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make(__('Vehicle Details'))
                ->schema([
                    TextInput::make('brand')
                        ->label(__('Brand'))
                        ->maxLength(100),

                    TextInput::make('model')
                        ->label(__('Model'))
                        ->maxLength(100),

                    TextInput::make('year')
                        ->label(__('Year'))
                        ->integer()
                        ->minValue(1900)
                        ->maxValue(now()->year + 1),

                    TextInput::make('capacity')
                        ->label(__('Passenger Capacity'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(32767)
                        ->required(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
