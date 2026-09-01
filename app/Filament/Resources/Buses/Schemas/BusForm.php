<?php

namespace App\Filament\Resources\Buses\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BusForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('plate_number')
                    ->label(__('Número de Placa'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(20),
                TextInput::make('unit_number')
                    ->label(__('Número de Unidad / Interno'))
                    ->required()
                    ->maxLength(20),
                TextInput::make('brand')
                    ->label(__('Marca'))
                    ->placeholder('Mercedes-Benz, Scania, etc.')
                    ->maxLength(50),
                TextInput::make('model')
                    ->label(__('Modelo'))
                    ->maxLength(50),
                TextInput::make('year')
                    ->label(__('Año de Fabricación'))
                    ->numeric()
                    ->minValue(1980)
                    ->maxValue(2050),
                TextInput::make('capacity')
                    ->label(__('Capacidad de Pasajeros'))
                    ->numeric()
                    ->default(40)
                    ->required(),
                Toggle::make('is_active')
                    ->label(__('Activo para Operación'))
                    ->default(true),
            ]);
    }
}
