<?php

namespace App\Filament\Resources\Routes\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class RouteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label(__('Código de Ruta'))
                    ->required()
                    ->placeholder('R-101')
                    ->maxLength(20),
                TextInput::make('name')
                    ->label(__('Nombre de la Ruta'))
                    ->required()
                    ->placeholder('Línea 1 - Centro / Terminal')
                    ->maxLength(255),
                TextInput::make('origin')
                    ->label(__('Origen / Cabecera'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('destination')
                    ->label(__('Destino / Fin de Línea'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('distance_km')
                    ->label(__('Distancia Estimada (KM)'))
                    ->numeric()
                    ->step(0.1),
                TextInput::make('estimated_duration_minutes')
                    ->label(__('Duración Estimada (Minutos)'))
                    ->numeric(),
                Textarea::make('description')
                    ->label(__('Descripción / Puntos Clave'))
                    ->rows(3)
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label(__('Ruta Habilitada'))
                    ->default(true),
            ]);
    }
}
