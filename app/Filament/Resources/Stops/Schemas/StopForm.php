<?php

namespace App\Filament\Resources\Stops\Schemas;

use App\Enums\LucideIcon;
use App\Filament\Forms\Components\LocationSearchInput;
use App\Filament\Maps\Layers\LucideMarker;
use App\Models\Stop;
use App\Services\PhotonGeocodingService;
use EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\Coordinate;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\GeoSearchResult;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class StopForm
{
    public static function configure(Schema $schema, bool $compact = false): Schema
    {
        $informationFields = [
            Toggle::make('is_shared')
                ->label(__('Shared Stop'))
                ->helperText(__(
                    'Shared stops can be used by multiple companies. Only system admins can manage them.',
                ))
                ->default(false)
                ->visible(
                    fn (string $operation): bool => $operation === 'create'
                        && (Filament::auth()->user()?->isSuperAdmin() ?? false),
                )
                ->dehydrated(
                    fn (string $operation): bool => $operation === 'create'
                        && (Filament::auth()->user()?->isSuperAdmin() ?? false),
                )
                ->rules(['boolean']),

            TextInput::make('name')
                ->label(__('Stop Name'))
                ->prefixIcon(LucideIcon::BusFront)
                ->required()
                ->maxLength(255),

            Textarea::make('description')
                ->label(__('Description'))
                ->rows(3),
        ];

        return $schema
            ->columns(1)
            ->components([
                ...($compact
                    ? $informationFields
                    : [
                        Section::make(__('Stop Information'))
                            ->description(__('The name and details of the boarding stop.'))
                            ->icon(LucideIcon::BusFront)
                            ->schema($informationFields)
                            ->columnSpanFull(),
                    ]),

                Section::make(__('Location'))
                    ->description(__('Search for a place or select the boarding point on the map.'))
                    ->icon(Heroicon::OutlinedMapPin)
                    ->schema([
                        LocationSearchInput::make('location_search')
                            ->label(__('Search Location'))
                            ->prefixIcon(Heroicon::OutlinedMagnifyingGlass)
                            ->extraAttributes(['data-testid' => 'location-search'])
                            ->helperText(__(
                                'Search for a place or select a point on the map. This sets the location without changing the stop name.',
                            ))
                            ->live()
                            ->dehydrated(false)
                            ->coordinateLabelUsing(
                                fn (): string => __('Selected Location'),
                            )
                            ->afterStateHydrated(function (LocationSearchInput $component, ?Stop $record): void {
                                if (
                                    ! $record
                                    || $record->latitude === null
                                    || $record->longitude === null
                                ) {
                                    $component->state(null);

                                    return;
                                }

                                $component->state(new Coordinate(
                                    (float) $record->latitude,
                                    (float) $record->longitude,
                                ));
                            })
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $coordinate = match (true) {
                                    $state instanceof GeoSearchResult => $state->coordinate,
                                    $state instanceof Coordinate => $state,
                                    default => null,
                                };

                                if ($coordinate === null) {
                                    return;
                                }

                                $set('latitude', sprintf('%.7f', $coordinate->lat));
                                $set('longitude', sprintf('%.7f', $coordinate->lng));
                                $set('location', $coordinate->toArray());
                            })
                            ->columnSpanFull(),

                        MapPicker::make('location')
                            ->view('filament.forms.components.stop-map')
                            ->label(__('Boarding Point'))
                            ->helperText(__('Search for a place or select the boarding point on the map.'))
                            ->height(400)
                            ->center([9.9281, -84.0907])
                            ->zoom(13)
                            ->defaultPickMarker(
                                fn (): LucideMarker => LucideMarker::make()
                                    ->lucideIcon(LucideIcon::BusFront),
                            )
                            ->autoCenter(false)
                            ->zoomControl()
                            ->scaleControl()
                            ->fullscreenControl()
                            ->live()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (MapPicker $component, ?Stop $record): void {
                                if (
                                    ! $record
                                    || $record->latitude === null
                                    || $record->longitude === null
                                ) {
                                    $component->state(null);

                                    return;
                                }

                                $component->state([
                                    'lat' => (float) $record->latitude,
                                    'lng' => (float) $record->longitude,
                                ]);
                            })
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                if (! $state instanceof Coordinate) {
                                    return;
                                }

                                $set('latitude', sprintf('%.7f', $state->lat));
                                $set('longitude', sprintf('%.7f', $state->lng));
                            })
                            ->onMapClick(
                                fn (
                                    Set $set,
                                    float $latitude,
                                    float $longitude,
                                ) => self::selectMapLocation(
                                    $set,
                                    $latitude,
                                    $longitude,
                                ),
                            )
                            ->columnSpanFull(),

                        Section::make(__('Advanced Coordinates'))
                            ->description(__(
                                'Optional manual adjustment. You can select the location using search or the map.',
                            ))
                            ->icon(Heroicon::OutlinedCodeBracket)
                            ->schema([
                                TextInput::make('latitude')
                                    ->label(__('Latitude'))
                                    ->prefixIcon(Heroicon::OutlinedArrowsUpDown)
                                    ->numeric()
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(
                                        fn (TextInput $component, Set $set) => self::syncMapLocation($component, $set),
                                    )
                                    ->rules([
                                        'bail',
                                        'numeric',
                                        'between:-90,90',
                                    ]),

                                TextInput::make('longitude')
                                    ->label(__('Longitude'))
                                    ->prefixIcon(Heroicon::OutlinedArrowsRightLeft)
                                    ->numeric()
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(
                                        fn (TextInput $component, Set $set) => self::syncMapLocation($component, $set),
                                    )
                                    ->rules([
                                        'bail',
                                        'numeric',
                                        'between:-180,180',
                                    ]),
                            ])
                            ->columns($compact ? 1 : 2)
                            ->collapsible()
                            ->collapsed()
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible($compact)
                    ->collapsed($compact)
                    ->columnSpanFull(),
            ]);
    }

    private static function selectMapLocation(
        Set $set,
        float $latitude,
        float $longitude,
    ): void {
        if (
            ! is_finite($latitude)
            || ! is_finite($longitude)
            || $latitude < -90
            || $latitude > 90
            || $longitude < -180
            || $longitude > 180
        ) {
            return;
        }

        $set('latitude', sprintf('%.7f', $latitude));
        $set('longitude', sprintf('%.7f', $longitude));
        $set('location', [
            'lat' => $latitude,
            'lng' => $longitude,
        ]);

        try {
            $result = app(PhotonGeocodingService::class)->reverse(
                $latitude,
                $longitude,
            );
        } catch (RuntimeException) {
            $result = null;
        }

        $set(
            'location_search',
            $result ?? GeoSearchResult::fromArray([
                'coordinate' => [
                    'lat' => $latitude,
                    'lng' => $longitude,
                ],
                'name' => __('Selected Location'),
                'display_name' => __('Selected Location (:latitude, :longitude)', [
                    'latitude' => sprintf('%.7f', $latitude),
                    'longitude' => sprintf('%.7f', $longitude),
                ]),
            ]),
        );
    }

    private static function syncMapLocation(
        TextInput $component,
        Set $set,
    ): void {
        $livewire = $component->getLivewire();

        $latitude = data_get(
            $livewire,
            $component->resolveRelativeStatePath('latitude'),
        );

        $longitude = data_get(
            $livewire,
            $component->resolveRelativeStatePath('longitude'),
        );

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            $set('location', null);

            return;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        if (
            ! is_finite($latitude)
            || ! is_finite($longitude)
            || $latitude < -90
            || $latitude > 90
            || $longitude < -180
            || $longitude > 180
        ) {
            $set('location', null);

            return;
        }

        $set('location', [
            'lat' => $latitude,
            'lng' => $longitude,
        ]);
    }
}
