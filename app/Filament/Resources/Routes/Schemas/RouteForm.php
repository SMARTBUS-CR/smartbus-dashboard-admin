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
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;

use function is_array;
use function is_object;
use function is_string;
use function sprintf;

class RouteForm
{
    /**
     * Default geographic coordinates for San José, Costa Rica (fallback center point).
     */
    private const DEFAULT_CENTER = [9.9281, -84.0907];

    /**
     * Cache storage duration for Nominatim reverse geocoding API queries.
     */
    private const REVERSE_GEOCODE_CACHE_HOURS = 24;

    /**
     * Configures and returns the complete Filament form schema.
     *
     * @param Schema $schema The incoming Filament schema instance.
     * @return Schema The configured Filament schema.
     */
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

                        Toggle::make('is_active')
                            ->label('Ruta Activa')
                            ->default(true)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Trazado de la Ruta')
                    ->schema([
                        Grid::make(1)
                            ->schema([
                                static::buildGeoSearchInput('origin_search', 'Buscar Origen', 'origin'),
                                static::buildGeoSearchInput('destination_search', 'Buscar Destino', 'destination'),

                                TextInput::make('distance_km')
                                    ->label('Distancia Total (km)')
                                    ->numeric()
                                    ->readOnly()
                                    ->suffix('km')
                                    ->dehydrated(),

                                TextInput::make('duration_formatted')
                                    ->label('Tiempo Estimado')
                                    ->readOnly()
                                    ->suffix('aprox.')
                                    ->dehydrated(false)
                                    ->afterStateHydrated(function (TextInput $component, $record) {
                                        if ($record?->duration_minutes !== null) {
                                            $component->state(static::formatDuration($record->duration_minutes * 60));
                                        }
                                    }),

                                Actions::make([
                                    Action::make('clear_all')
                                        ->label('Resetear Ruta')
                                        ->color('danger')
                                        ->icon('heroicon-m-arrow-path')
                                        ->action(static::resetRouteState(...)),

                                    Action::make('calcularRuta')
                                        ->label('Calcular Ruta')
                                        ->icon('heroicon-o-map')
                                        ->disabled(function (Get $get) {
                                            $origin = static::normalizePoint($get('origin'));
                                            $destination = static::normalizePoint($get('destination'));

                                            // Disable button if either origin or destination coordinates are missing
                                            return blank($origin['lat']) || blank($destination['lat']);
                                        })
                                        ->action(function (Get $get, Set $set) {
                                            $routeData = app(RoutingService::class)->calculateRoute(
                                                origin: $get('origin'),
                                                destination: $get('destination'),
                                                waypoints: $get('waypoints') ?? [],
                                            );

                                            if (! $routeData) {
                                                Notification::make()
                                                    ->title('No se pudo calcular la ruta')
                                                    ->danger()
                                                    ->send();

                                                return;
                                            }

                                            $km = round($routeData['distance_meters'] / 1000, 2);
                                            $minutes = (int) round($routeData['duration_seconds'] / 60);
                                            $formattedDuration = static::formatDuration($routeData['duration_seconds']);
                                            
                                            $set('overview_polyline', $routeData['polyline']);
                                            $set('distance_km', $km);
                                            $set('duration_minutes', $minutes);
                                            $set('duration_formatted', $formattedDuration);

                                            Notification::make()
                                                ->title("Ruta calculada: {$km} km ({$formattedDuration})")
                                                ->success()
                                                ->send();
                                        }),
                                ])
                                ->alignment(Alignment::Between)
                                ->columnSpan(1),
                            ]),

                        static::buildMapPicker()->columnSpan(1),

                        Hidden::make('origin')
                            ->live()
                            ->dehydrated()
                            ->afterStateHydrated(fn ($state, Set $set) => $set('origin', static::normalizePoint($state))),

                        Hidden::make('destination')
                            ->live()
                            ->dehydrated()
                            ->afterStateHydrated(fn ($state, Set $set) => $set('destination', static::normalizePoint($state))),

                        Hidden::make('waypoints')
                            ->live()
                            ->default([])
                            ->afterStateHydrated(fn ($state, Set $set) => $set('waypoints', static::parseWaypoints($state))),

                        Hidden::make('overview_polyline')
                            ->live()
                            ->dehydrated()
                            ->afterStateHydrated(fn ($state, Set $set) => $set('overview_polyline', $state)),

                        Hidden::make('duration_minutes')
                            ->dehydrated(),
                    ])
                    ->columns(['xl' => 2])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Builds a GeoSearchInput component with reverse geocoding capabilities.
     *
     * @param string $name Component state key name.
     * @param string $label Component UI label.
     * @param string $targetField Hidden field target storing coordinate state.
     * @return GeoSearchInput The initialized search input instance.
     */
    protected static function buildGeoSearchInput(string $name, string $label, string $targetField): GeoSearchInput
    {
        return GeoSearchInput::make($name)
            ->label($label)
            ->hint(new HtmlString(
                '<div wire:loading wire:target="callSchemaComponentMethod" class="flex items-center gap-x-2 text-sm text-gray-500 dark:text-gray-400">
                    <x-filament::loading-indicator class="h-4 w-4 text-primary-600 dark:text-primary-400" />
                    <span>Actualizando ubicación...</span>
                </div>'
            ))
            ->provider(GeoSearchProvider::Nominatim)
            ->live()
            ->language(app()->getLocale())
            ->afterStateHydrated(static function ($record, Set $set) use ($name, $targetField): void {
                $point = $record?->{$targetField};

                if ($point && isset($point->lat, $point->lng)) {
                    $set($name, static::reverseGeocode((float) $point->lat, (float) $point->lng));
                }
            })
            ->afterStateUpdated(static function (mixed $state, Set $set) use ($targetField): void {
                if ($state instanceof GeoSearchResult) {
                    $set($targetField, $state->coordinate->toArray());
                } elseif (empty($state)) {
                    $set($targetField, null);
                }
                $set('overview_polyline', null);
            })
            ->columnSpan(1);
    }

    /**
     * Builds and configures the interactive Leaflet MapPicker component.
     *
     * @return MapPicker The map picker schema element.
     */
    protected static function buildMapPicker(): MapPicker
    {
        return MapPicker::make('route_map')
            ->label('Mapa de la Ruta')
            ->height(450)
            ->reactive()
            ->zoomControl()
            ->scaleControl()
            ->fullscreenControl()
            ->key(fn (Get $get, $record): string => md5(json_encode([
                static::normalizePoint($get('origin') ?? $record?->origin),
                static::normalizePoint($get('destination') ?? $record?->destination),
                static::parseWaypoints($get('waypoints') ?? $record?->waypoints),
                $get('overview_polyline') ?? $record?->overview_polyline,
            ])))
            ->autoCenter(static function (Get $get, $record): bool {
                $origin = static::normalizePoint($get('origin') ?? $record?->origin);

                return blank($record)
                    && blank($origin['lat'])
                    && blank($get('overview_polyline'));
            })
            ->center(static function (Get $get, $record): array {
                $encodedPolyline = $get('overview_polyline') ?? $record?->overview_polyline;

                // Calculate map center based on route polyline average coordinates
                if (filled($encodedPolyline)) {
                    $points = app(RoutingService::class)->decodePolyline($encodedPolyline);

                    if (! empty($points)) {
                        /** @var Collection<int, array> $collection */
                        $collection = collect($points);

                        return [
                            'lat' => $collection->avg(fn ($p) => (float) ($p[0] ?? $p['lat'])),
                            'lng' => $collection->avg(fn ($p) => (float) ($p[1] ?? $p['lng'])),
                        ];
                    }
                }

                $origin = static::normalizePoint($get('origin') ?? $record?->origin);
                $destination = static::normalizePoint($get('destination') ?? $record?->destination);

                // Midpoint center if both origin and destination exist
                if (! empty($origin['lat']) && ! empty($destination['lat'])) {
                    return [
                        'lat' => ((float) $origin['lat'] + (float) $destination['lat']) / 2,
                        'lng' => ((float) $origin['lng'] + (float) $destination['lng']) / 2,
                    ];
                }

                // Center directly on origin if available
                if (! empty($origin['lat'])) {
                    return [(float) $origin['lat'], (float) $origin['lng']];
                }

                // Default fallback location
                return self::DEFAULT_CENTER;
            })
            ->zoom(static function (Get $get, $record): int {
                $origin = static::normalizePoint($get('origin') ?? $record?->origin);
                $destination = static::normalizePoint($get('destination') ?? $record?->destination);

                return (! empty($origin['lat']) && ! empty($destination['lat'])) ? 9 : 7;
            })
            ->markers(static function (Get $get, $record): array {
                $markers = [];

                // Resolve origin point
                $originState = $get('origin');
                $hasOriginState = is_array($originState) && isset($originState['lat']) && ($originState['lat']) !== null;
                $origin = static::normalizePoint($hasOriginState ? $originState : $record?->origin);

                if (! empty($originState) && ! empty($origin['lat']) && ! empty($origin['lng'])) {
                    $markers[] = Marker::make((float) $origin['lat'], (float) $origin['lng'])
                        ->id('origin-marker')
                        ->green()
                        ->title('Origen');
                }

                // Resolve destination point
                $destinationState = $get('destination');
                $hasDestinationState = is_array($destinationState) && isset($destinationState['lat']) && ($destinationState['lat']) !== null;
                $destination = static::normalizePoint($hasDestinationState ? $destinationState : $record?->destination);

                if (! empty($destinationState) && ! empty($destination['lat']) && ! empty($destination['lng'])) {
                    $markers[] = Marker::make((float) $destination['lat'], (float) $destination['lng'])
                        ->id('destination-marker')
                        ->red()
                        ->title('Destino');
                }

                // Resolve waypoints
                $waypoints = static::parseWaypoints($get('waypoints') ?? $record?->waypoints);

                foreach ($waypoints as $index => $point) {
                    if (($point['lat']) !== null && ($point['lng']) !== null) {
                        $markers[] = Marker::make((float) $point['lat'], (float) $point['lng'])
                            ->id("waypoint-{$index}")
                            ->orange()
                            ->title('Parada de la Ruta (clic para eliminar)');
                    }
                }

                return $markers;
            })
            ->shapes(static function (Get $get, $record): array {
                $polylineState = $get('overview_polyline');
                $encoded = filled($polylineState) ? $polylineState : $record?->overview_polyline;
                // $encoded = ($polylineState === null || $polylineState === '') ? null : ($polylineState ?? $record?->overview_polyline);

                // Render calculated polyline shape if present
                if (filled($encoded)) {
                    $linePoints = app(RoutingService::class)->decodePolyline($encoded);

                    return [
                        Polyline::make(...$linePoints)
                            ->blue()
                            ->weight(4)
                            ->fill(false)
                            ->fillOpacity(0),
                    ];
                }

                // Fallback: Render straight dashed connection lines between points
                $originState = $get('origin');
                $destinationState = $get('destination');

                if (empty($originState) || empty($destinationState)) {
                    return [];
                }

                $origin = static::normalizePoint($originState);
                $destination = static::normalizePoint($destinationState);

                if (empty($origin['lat']) || empty($destination['lat'])) {
                    return [];
                }

                $waypoints = static::parseWaypoints($get('waypoints') ?? []);

                $linePoints = [
                    [(float) $origin['lat'], (float) $origin['lng']],
                    ...array_filter(
                        array_map(static fn ($p) => ($p['lat'] !== null && $p['lng'] !== null) ? [(float) $p['lat'], (float) $p['lng']] : null, $waypoints)
                    ),
                    [(float) $destination['lat'], (float) $destination['lng']],
                ];

                return [
                    Polyline::make(...$linePoints)
                        ->gray()
                        ->weight(2)
                        ->dashArray('8, 8'),
                ];
            })
            ->onMapClick(null)
            ->onLayerClick(static function (mixed $layer, Get $get, Set $set): void {
                $id = $layer?->getId();
                if (! $id) return;

                if ($id === 'origin-marker') {
                    $set('origin', null);
                    $set('origin_search', null);
                    $set('overview_polyline', null);
                    return;
                }

                if ($id === 'destination-marker') {
                    $set('destination', null);
                    $set('destination_search', null);
                    $set('overview_polyline', null);
                    return;
                }

                if (str_starts_with($id, 'waypoint-')) {
                    $index = (int) str_replace('waypoint-', '', $id);
                    $waypoints = $get('waypoints') ?? [];

                    unset($waypoints[$index]);
                    $set('waypoints', array_values($waypoints));
                    $set('overview_polyline', null);
                }
            });
    }

    /**
     * Resets calculated metric states in the form.
     *
     * @param Set $set Filament state setter callback.
     */
    protected static function resetCalculatedValues(Set $set): void
    {
        $set('overview_polyline', null);
        $set('distance_km', null);
        $set('duration_minutes', null);
        $set('duration_formatted', null);
    }

    /**
     * Resets all route configuration and calculated states.
     *
     * @param Set $set Filament state setter callback.
     */
    protected static function resetRouteState(Set $set): void
    {
        $set('origin', null);
        $set('origin_search', null);
        $set('destination', null);
        $set('destination_search', null);
        $set('waypoints', []);
        static::resetCalculatedValues($set);
    }

    /**
     * Formats raw duration seconds into a human-readable string.
     *
     * @param float $seconds Total duration in seconds.
     * @return string Formatted string representation (e.g., "1 h 15 min").
     */
    protected static function formatDuration(float $seconds): string
    {
        $minutes = (int) round($seconds / 60);

        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes === 0
            ? "{$hours} h"
            : "{$hours} h {$remainingMinutes} min";
    }

    /**
     * Parses and normalizes waypoint data from array or JSON string formats.
     *
     * @param mixed $waypoints Raw waypoint input.
     * @return array<int, array{lat: float|null, lng: float|null}> Normalized collection of points.
     */
    protected static function parseWaypoints(mixed $waypoints): array
    {
        if (is_string($waypoints)) {
            $waypoints = json_decode($waypoints, true) ?? [];
        }

        if (! is_array($waypoints)) {
            return [];
        }

        return array_map(static::normalizePoint(...), $waypoints);
    }

    /**
     * Normalizes various coordinate inputs into a uniform array structure.
     *
     * @param mixed $point Coordinate payload (Coordinate object, standard object, array, or null).
     * @return array{lat: float|null, lng: float|null} Uniform spatial coordinate array.
     */
    protected static function normalizePoint(mixed $point): array
    {
        if (empty($point)) {
            return ['lat' => null, 'lng' => null];
        }

        if ($point instanceof Coordinate) {
            return ['lat' => $point->lat, 'lng' => $point->lng];
        }

        if (is_object($point)) {
            return [
                'lat' => $point->lat ?? $point->latitude ?? null,
                'lng' => $point->lng ?? $point->longitude ?? null,
            ];
        }

        if (is_array($point)) {
            return [
                'lat' => $point['lat'] ?? $point['latitude'] ?? null,
                'lng' => $point['lng'] ?? $point['longitude'] ?? null,
            ];
        }

        return ['lat' => null, 'lng' => null];
    }

    /**
     * Reversely geocodes coordinates into a human-readable display address with caching.
     *
     * @param float $lat Latitude coordinate.
     * @param float $lng Longitude coordinate.
     * @return string|null Resolved display name or null on failure.
     */
    protected static function reverseGeocode(float $lat, float $lng): ?string
    {
        $key = sprintf('reverse-geocode:%.6f,%.6f', $lat, $lng);

        return Cache::remember($key, now()->addHours(self::REVERSE_GEOCODE_CACHE_HOURS), static function () use ($lat, $lng): ?string {
            $response = Http::withHeaders(['User-Agent' => 'SmartBusApp/1.0'])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'json',
                ]);

            return $response->successful() ? $response->json('display_name') : null;
        });
    }
}
