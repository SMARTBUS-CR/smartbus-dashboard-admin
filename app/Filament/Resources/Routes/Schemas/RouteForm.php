<?php

namespace App\Filament\Resources\Routes\Schemas;

use EduardoRibeiroDev\FilamentLeaflet\Enums\GeoSearchProvider;
use EduardoRibeiroDev\FilamentLeaflet\Fields\GeoSearchInput;
use EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\GeoSearchResult;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use Livewire\Component;

use function sprintf;

class RouteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información Básica')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre de la Ruta')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label('Código')
                            ->required()
                            ->maxLength(50),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Grid::make()
                    ->columns([
                        'default' => 1,
                        'lg' => 1,
                        'xl' => 2,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Section::make('Punto de Origen')
                            ->schema([
                                GeoSearchInput::make('origin_search')
                                    ->label('Buscar Origen')
                                    ->hint(new HtmlString(
                                        '<span wire:loading wire:target="callSchemaComponentMethod" class="text-sm text-gray-500">
                                            Actualizando ubicación...
                                        </span>'
                                    ))
                                    ->provider(GeoSearchProvider::Nominatim)
                                    ->language(app()->getLocale())
                                    ->reactive()
                                    ->afterStateHydrated(function ($record, callable $set) {
                                        if ($record?->origin) {
                                            $set('origin_search', static::reverseGeocode(
                                                $record->origin->lat,
                                                $record->origin->lng
                                            ));
                                        }
                                    })
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        if ($state instanceof GeoSearchResult) {
                                            $set('origin', $state->coordinate->toArray());
                                        }
                                    })
                                    ->suffixAction(
                                        Action::make('clearOrigin')
                                            ->icon('heroicon-o-x-mark')
                                            ->tooltip('Quitar marcador')
                                            ->action(function (Set $set) {
                                                $set('origin', null);
                                                $set('origin_search', null);
                                            })
                                    ),

                                MapPicker::make('origin')
                                    ->label('Ubicación en Mapa')
                                    ->height(300)
                                    ->pickMarker(fn ($marker) => $marker->blue()->title('Origen'))
                                    ->onMapClick(function (float $latitude, float $longitude, callable $set) {
                                        $set('origin_search', static::reverseGeocode($latitude, $longitude));
                                    }),
                            ]),

                        Section::make('Punto de Destino')
                            ->schema([
                                GeoSearchInput::make('destination_search')
                                    ->label('Buscar Destino')
                                    ->hint(new HtmlString(
                                        '<span wire:loading wire:target="callSchemaComponentMethod" class="text-sm text-gray-500">
                                            Actualizando ubicación...
                                        </span>'
                                    ))
                                    ->provider(GeoSearchProvider::Nominatim)
                                    ->language(app()->getLocale())
                                    ->reactive()
                                    ->afterStateHydrated(function ($record, callable $set) {
                                        if ($record?->origin) {
                                            $set('destination_search', static::reverseGeocode(
                                                $record->destination->lat,
                                                $record->destination->lng
                                            ));
                                        }
                                    })
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        if ($state instanceof GeoSearchResult) {
                                            $set('destination', $state->coordinate->toArray());
                                        }
                                    })
                                    ->suffixAction(
                                        Action::make('clearDestination')
                                            ->icon('heroicon-o-x-mark')
                                            ->tooltip('Quitar marcador')
                                            ->action(function (Set $set, Component $livewire) {
                                                $set('destination', null);
                                                $set('destination_search', null);
                                            })
                                    ),

                                MapPicker::make('destination')
                                    ->label('Ubicación en Mapa')
                                    ->height(300)
                                    ->pickMarker(fn ($marker) => $marker->blue()->title('Destino'))
                                    ->onMapClick(function (float $latitude, float $longitude, callable $set) {
                                        $set('destination_search', static::reverseGeocode($latitude, $longitude));
                                    }),
                            ]),
                    ]),

                Toggle::make('is_active')
                    ->label('Ruta Activa')
                    ->default(true)
                    ->columnSpanFull(),
            ]);
    }

    protected static function reverseGeocode(float $lat, float $lng): ?string
    {
        $key = sprintf('reverse-geocode:%.6f,%.6f', $lat, $lng);

        return Cache::remember($key, now()->addDay(), function () use ($lat, $lng) {
            $response = Http::withHeaders(['User-Agent' => 'SmartBusApp/1.0'])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $lat, 'lon' => $lng, 'format' => 'json',
                ]);

            return $response->successful() ? $response->json('display_name') : null;
        });
    }
}
