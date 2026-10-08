<?php

namespace App\Filament\Resources\Stops\Schemas;

use App\Filament\Forms\Components\LocationSearchInput;
use App\Filament\Maps\Layers\LucideMarker;
use App\Models\Stop;
use App\Enums\LucideIcon;
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

class StopForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Stop Information'))
                ->schema([
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
                        ->required()
                        ->maxLength(255),

                    Textarea::make('description')
                        ->label(__('Description'))
                        ->rows(3),
                ])
                ->columnSpanFull(),

            Section::make(__('Location'))
                ->description(__('Search for a place or select the boarding point on the map.'))
                ->schema([
                    LocationSearchInput::make('location_search')
                        ->label(__('Search Location'))
                        ->extraAttributes(['data-testid' => 'location-search'])
                        ->helperText(__(
                            'Search for a place, select a result, then adjust the boarding point on the map.',
                        ))
                        ->live()
                        ->dehydrated(false)
                        ->coordinateLabelUsing(
                            fn (?Stop $record): string => $record?->name
                                ?? __('Selected Location'),
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
                        ->columnSpanFull(),

                    Section::make(__('Advanced Coordinates'))
                        ->description(__(
                            'Optional manual adjustment. You can select the location using search or the map.',
                        ))
                        ->schema([
                            TextInput::make('latitude')
                                ->label(__('Latitude'))
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
                        ->columns(2)
                        ->collapsible()
                        ->collapsed()
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
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
