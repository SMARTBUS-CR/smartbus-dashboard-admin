<?php

namespace App\Filament\Resources\Routes\Schemas;

use App\Services\RoutingService;
use EduardoRibeiroDev\FilamentLeaflet\Enums\GeoSearchProvider;
use EduardoRibeiroDev\FilamentLeaflet\Fields\GeoSearchInput;
use EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\Polyline;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\Coordinate;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\GeoSearchResult;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;

use function count;
use function is_array;
use function is_string;
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

                Section::make('Trazado de la Ruta')
                    ->schema([
                        Grid::make(1)
                            ->schema([
                                Radio::make('active_point')
                                    ->label('Al hacer clic en el mapa, definir:')
                                    ->options([
                                        'origin' => 'Origen',
                                        'destination' => 'Destino',
                                        'waypoint' => 'Punto Intermedio',
                                    ])
                                    ->default('origin')
                                    ->inline()
                                    ->live()
                                    ->columnSpanFull(),

                                GeoSearchInput::make('origin_search')
                                    ->label('Buscar Origen')
                                    ->hint(new HtmlString(
                                        '<span wire:loading wire:target="callSchemaComponentMethod" class="text-sm text-gray-500">
                                            Actualizando ubicación...
                                        </span>'
                                    ))
                                    ->provider(GeoSearchProvider::Nominatim)
                                    ->live()
                                    ->language(app()->getLocale())
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
                                    ->columnSpan(1),

                                GeoSearchInput::make('destination_search')
                                    ->label('Buscar Destino')
                                    ->hint(new HtmlString(
                                        '<span wire:loading wire:target="callSchemaComponentMethod" class="text-sm text-gray-500">
                                            Actualizando ubicación...
                                        </span>'
                                    ))
                                    ->provider(GeoSearchProvider::Nominatim)
                                    ->live()
                                    ->language(app()->getLocale())
                                    ->afterStateHydrated(function ($record, callable $set) {
                                        if ($record?->destination) {
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
                                    ->columnSpan(1),

                                Actions::make([
                                    Action::make('calcularRuta')
                                        ->label('Calcular Ruta')
                                        ->icon('heroicon-o-map')
                                        ->disabled(function (Get $get) {
                                            return blank($get('origin')['lat'] ?? null)
                                                || blank($get('destination')['lat'] ?? null);
                                        })
                                        ->action(function (Get $get, Set $set) {
                                            $polyline = app(RoutingService::class)->calculateRoutePolyline(
                                                origin: $get('origin'),
                                                destination: $get('destination'),
                                                waypoints: $get('waypoints') ?? [],
                                            );

                                            if (! $polyline) {
                                                Notification::make()
                                                    ->title('No se pudo calcular la ruta')
                                                    ->danger()
                                                    ->send();

                                                return;
                                            }

                                            $set('overview_polyline', $polyline);

                                            Notification::make()
                                                ->title('Ruta calculada correctamente')
                                                ->success()
                                                ->send();
                                        }),
                                ])->alignment(Alignment::End)->columnSpan(1)
                            ]),

                        static::buildMapPicker()->columnSpan(1),

                        Hidden::make('origin')
                            ->afterStateHydrated(function ($state, Set $set) {
                                $set('origin', static::normalizePoint($state));
                            }),
                        Hidden::make('destination')
                            ->afterStateHydrated(function ($state, Set $set) {
                                $set('destination', static::normalizePoint($state));
                            }),
                        Hidden::make('waypoints')
                            ->default([])
                            ->afterStateHydrated(function ($state, Set $set) {
                                $set('waypoints', static::normalizePoint($state));
                            }),
                        Hidden::make('overview_polyline'),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 1,
                        'lg' => 1,
                        'xl' => 2,
                    ])
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('Ruta Activa')
                    ->default(true)
                    ->columnSpanFull(),
            ]);
    }

    protected static function buildMapPicker(): MapPicker
    {
        return MapPicker::make('route_map')
            ->label('Mapa de la Ruta')
            ->height(450)
            ->key(fn (Get $get) => md5(json_encode([
                $get('origin'),
                $get('destination'),
                $get('waypoints'),
                $get('overview_polyline'),
            ])))
            ->autoCenter(function (Get $get, $record) {
                return blank($record) 
                    && blank($get('origin')) 
                    && blank($get('overview_polyline'));
            })
            ->center(function (Get $get, $record) {
                $encodedPolyline = $get('overview_polyline') ?? $record?->overview_polyline;

                if (filled($encodedPolyline)) {
                    $points = app(RoutingService::class)
                        ->decodePolyline($encodedPolyline);

                    if (! empty($points)) {
                        $latSum = 0;
                        $lngSum = 0;
                        $totalPoints = count($points);

                        foreach ($points as $point) {
                            $latSum += (float) ($point[0] ?? $point['lat']);
                            $lngSum += (float) ($point[1] ?? $point['lng']);
                        }

                        return [
                            'lat' => $latSum / $totalPoints,
                            'lng' => $lngSum / $totalPoints,
                        ];
                    }
                }

                $origin = static::normalizePoint($get('origin') ?? $record?->origin);
                $destination = static::normalizePoint($get('destination') ?? $record?->destination);
                
                if (! empty($origin['lat']) && ! empty($destination['lat'])) {
                    return [
                        'lat' => ((float) $origin['lat'] + (float) $destination['lat']) / 2,
                        'lng' => ((float) $origin['lng'] + (float) $destination['lng']) / 2,
                    ];
                }

                if (! empty($origin['lat'])) {
                    return [(float) $origin['lat'], (float) $origin['lng']];
                }

                return [9.9281, -84.0907]; // Default to San José, Costa Rica
            })
            ->zoom(function (Get $get, $record) {
                $origin = static::normalizePoint($get('origin'));
                $destination = static::normalizePoint($get('destination'));

                if (empty($origin['lat']) && $record?->origin) {
                    $origin = static::normalizePoint($record->origin);
                }

                if (empty($destination['lat']) && $record?->destination) {
                    $destination = static::normalizePoint($record->destination);
                }

                return (! empty($origin['lat']) && ! empty($destination['lat'])) ? 12 : 7;
            })
            ->markers(function (Get $get) {
                $markers = [];

                if (filled($origin = $get('origin')) && filled($origin['lat'] ?? null)) {
                    $markers[] = Marker::make($origin['lat'], $origin['lng'])
                        ->id('origin-marker')
                        ->green()
                        ->title('Origen');
                }

                if (filled($destination = $get('destination')) && filled($destination['lat'] ?? null)) {
                    $markers[] = Marker::make($destination['lat'], $destination['lng'])
                        ->id('destination-marker')
                        ->red()
                        ->title('Destino');
                }

                foreach (($get('waypoints') ?? []) as $index => $waypoint) {
                    $markers[] = Marker::make($waypoint['lat'], $waypoint['lng'])
                        ->id("waypoint-{$index}")
                        ->orange()
                        ->title('Punto intermedio (clic para quitar)');
                }

                return $markers;
            })
            ->shapes(function (Get $get) {
                $encoded = $get('overview_polyline');

                if (filled($encoded)) {
                    $points = app(RoutingService::class)->decodePolyline($encoded);

                    return [
                        Polyline::make(...$points)
                            ->blue()
                            ->weight(4)
                            ->fill(false)
                            ->fillOpacity(0),
                    ];
                }

                $origin = $get('origin');
                $destination = $get('destination');

                if (blank($origin['lat'] ?? null) || blank($destination['lat'] ?? null)) {
                    return [];
                }

                $points = [
                    [$origin['lat'], $origin['lng']],
                    ...collect($get('waypoints') ?? [])->map(fn ($w) => [$w['lat'], $w['lng']])->all(),
                    [$destination['lat'], $destination['lng']],
                ];

                return [
                    Polyline::make(...$points)
                        ->gray()
                        ->weight(2)
                        ->dashArray('8, 8'),
                ];
            })
            ->onMapClick(function (float $latitude, float $longitude, Get $get, Set $set) {
                $point = ['lat' => $latitude, 'lng' => $longitude];

                match ($get('active_point')) {
                    'origin' => $set('origin', $point),
                    'destination' => $set('destination', $point),
                    'waypoint' => $set('waypoints', [...($get('waypoints') ?? []), $point]),
                    default => null,
                };

                if ($get('active_point') === 'origin') {
                    $set('origin_search', static::reverseGeocode($latitude, $longitude));
                } elseif ($get('active_point') === 'destination') {
                    $set('destination_search', static::reverseGeocode($latitude, $longitude));
                }
            })
            ->onLayerClick(function ($layer, Get $get, Set $set) {
                $id = $layer?->getId();

                if (! $id || ! str_starts_with($id, 'waypoint-')) {
                    return;
                }

                $index = (int) str_replace('waypoint-', '', $id);
                $waypoints = $get('waypoints') ?? [];

                unset($waypoints[$index]);
                $set('waypoints', array_values($waypoints));
            });
    }

    protected static function normalizePoint(mixed $state): array
    {
        if ($state instanceof Coordinate) {
            return $state->toArray();
        }

        if (is_string($state)) {
            $state = json_decode($state, true);
        }

        return is_array($state) ? $state : [];
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
