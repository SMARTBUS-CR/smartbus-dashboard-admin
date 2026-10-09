<?php

namespace App\Filament\Resources\Routes\Resources\RoutePatterns\Schemas;

use App\Enums\LucideIcon;
use App\Filament\Forms\Components\LocationSearchInput;
use App\Filament\Forms\Components\RouteMapPicker;
use App\Filament\Maps\Layers\LucideMarker;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RoutePatternResource;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Services\OsrmRoutingService;
use App\Services\RouteDetourAnalyzer;
use App\Services\RouteRoutingPointsService;
use EduardoRibeiroDev\FilamentLeaflet\Layers\BaseLayer;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\Polyline;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\GeoSearchResult;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component as LivewireComponent;
use RuntimeException;

use function count;

class RoutePatternForm
{
    public static function configure(
        Schema $schema,
        ?Route $ownerRoute = null,
    ): Schema {
        $generalFields = [
            TextInput::make('code')
                ->label(__('Code'))
                ->prefixIcon(LucideIcon::Barcode)
                ->required()
                ->maxLength(50)
                ->rules(fn (?RoutePattern $record): array => [
                    Rule::unique(RoutePattern::class, 'code')
                        ->where(
                            'route_id',
                            $ownerRoute?->getKey() ?? $record?->route_id,
                        )
                        ->ignore($record?->getKey()),
                ]),

            TextInput::make('name')
                ->label(__('Pattern Name'))
                ->prefixIcon(LucideIcon::Route)
                ->required()
                ->maxLength(255),

            TextInput::make('headsign')
                ->label(__('Destination'))
                ->prefixIcon(Heroicon::OutlinedMapPin)
                ->helperText(__('Destination displayed to passengers.'))
                ->required()
                ->maxLength(255),
        ];

        return $schema
            ->columns($ownerRoute !== null ? 1 : 2)
            ->components([
                ...($ownerRoute !== null
                    ? $generalFields
                    : [
                        Section::make(__('General Information'))
                            ->description(__('The code, name and passenger destination of the pattern.'))
                            ->icon(LucideIcon::Route)
                            ->schema($generalFields)
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),

                Section::make(__('Route Layout'))
                    ->icon(Heroicon::OutlinedMap)
                    ->description(__(
                        'Search for the origin and destination. Changing endpoints clears the estimated stop times.',
                    ))
                    ->visible(
                        fn (string $operation): bool => $operation === 'edit'
                            && $ownerRoute === null,
                    )
                    ->schema([
                        Callout::make(__('Review the suggested route'))
                            ->key('routing_notice')
                            ->description(__(
                                'Review the suggested road route for bus operation. Driving time does not include boarding time or replace scheduled times.',
                            ))
                            ->info()
                            ->columnSpanFull(),

                        Grid::make(1)
                            ->schema([
                                self::endpointSearch(
                                    'origin_search',
                                    __('Search Origin'),
                                    true,
                                )
                                    ->required(
                                        fn (Get $get): bool => filled($get('destination_search')),
                                    ),

                                self::endpointSearch(
                                    'destination_search',
                                    __('Search Destination'),
                                    false,
                                )
                                    ->required(
                                        fn (Get $get): bool => filled($get('origin_search')),
                                    ),

                                Hidden::make('routing_adjustments')
                                    ->default([])
                                    ->live()
                                    ->afterStateHydrated(
                                        function (Hidden $component, ?RoutePattern $record): void {
                                            $component->state($record?->routing_adjustments ?? []);
                                        },
                                    )
                                    ->afterStateUpdated(
                                        fn (Set $set) => self::clearCalculatedRoute($set),
                                    )
                                    ->dehydrated(true),

                                Hidden::make('calculated_geometry')
                                    ->default(null)
                                    ->afterStateHydrated(
                                        function (Hidden $component, ?RoutePattern $record): void {
                                            $component->state($record?->route_geometry);
                                        },
                                    )
                                    ->dehydrated(false),

                                Hidden::make('distance_meters')
                                    ->default(null)
                                    ->afterStateHydrated(
                                        function (Hidden $component, ?RoutePattern $record): void {
                                            $component->state($record?->distance_meters);
                                        },
                                    )
                                    ->dehydrated(false),

                                Hidden::make('driving_duration_seconds')
                                    ->default(null)
                                    ->afterStateHydrated(
                                        function (Hidden $component, ?RoutePattern $record): void {
                                            $component->state($record?->driving_duration_seconds);
                                        },
                                    )
                                    ->dehydrated(false),

                                Hidden::make('route_detour_warnings')
                                    ->default([])
                                    ->dehydrated(false),

                                Hidden::make('route_detour_analysis_available')
                                    ->default(null)
                                    ->dehydrated(false)
                                    ->afterStateHydrated(function (Hidden $component, Get $get, Set $set, ?RoutePattern $record): void {
                                        $set('route_detour_warnings', []);

                                        if ($record?->route_geometry === null) {
                                            $component->state(null);

                                            return;
                                        }

                                        $component->state(false);

                                        if ($record->routing_leg_distances === null) {
                                            return;
                                        }

                                        try {
                                            $warnings = self::routeDetourWarnings(
                                                $get,
                                                $record,
                                                self::routePoints($get, $record),
                                                $record->routing_leg_distances,
                                            );
                                        } catch (InvalidArgumentException|ValidationException) {
                                            return;
                                        }

                                        $set('route_detour_warnings', $warnings ?? []);
                                        $component->state($warnings !== null);
                                    }),

                                TextInput::make('distance_preview')
                                    ->label(__('Total Distance'))
                                    ->prefixIcon(LucideIcon::Route)
                                    ->suffix('km')
                                    ->placeholder(__('Not Calculated'))
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->afterStateHydrated(
                                        function (TextInput $component, ?RoutePattern $record): void {
                                            $component->state(
                                                $record?->distance_meters !== null
                                                ? number_format(
                                                    (float) $record->distance_meters / 1000,
                                                    2,
                                                    '.',
                                                    '',
                                                )
                                                : null,
                                            );
                                        },
                                    ),

                                TextInput::make('driving_duration_preview')
                                    ->label(__('Estimated Driving Time'))
                                    ->prefixIcon(Heroicon::Clock)
                                    ->suffix(__('min'))
                                    ->placeholder(__('Not Calculated'))
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->afterStateHydrated(
                                        function (TextInput $component, ?RoutePattern $record): void {
                                            $component->state(
                                                $record?->driving_duration_seconds !== null
                                                ? (int) ceil(
                                                    (float) $record->driving_duration_seconds / 60,
                                                )
                                                : null,
                                            );
                                        },
                                    ),

                                Toggle::make('adjustment_mode')
                                    ->label(__('Adjust Route'))
                                    ->helperText(__(
                                        'Orange points guide the calculated road route; they are not boarding stops. Select a segment and click the map to add points in travel order. Drag a point to move it, or click it to remove it.',
                                    ))
                                    ->default(false)
                                    ->live()
                                    ->dehydrated(false),

                                Grid::make(2)
                                    ->schema([
                                        Select::make('adjustment_segment')
                                            ->label(__('Route Segment'))
                                            ->options(
                                                fn (?RoutePattern $record): array => self::routeSegmentOptions($record),
                                            )
                                            ->visible(
                                                fn (Get $get): bool => (bool) $get('adjustment_mode'),
                                            )
                                            ->live()
                                            ->dehydrated(false),

                                        Actions::make([
                                            Action::make('calculateRoute')
                                                ->label(__('Calculate Route'))
                                                ->authorize(
                                                    fn (?RoutePattern $record): bool => $record !== null
                                                        && $record->route()->exists()
                                                        && RoutePatternResource::canEdit($record),
                                                )
                                                ->disabled(function (Get $get): bool {
                                                    $origin = $get('origin_search');
                                                    $destination = $get('destination_search');

                                                    return ! $origin instanceof GeoSearchResult
                                                        || $origin->coordinate === null
                                                        || ! $destination instanceof GeoSearchResult
                                                        || $destination->coordinate === null;
                                                })
                                                ->action(
                                                    fn (Get $get, Set $set, ?RoutePattern $record) => self::calculateRoute(
                                                        $get,
                                                        $set,
                                                        $record,
                                                    ),
                                                ),
                                        ])
                                            ->alignment(Alignment::End)
                                            ->verticalAlignment(VerticalAlignment::End)
                                            ->columnStart(2)
                                            ->columnSpan(1)
                                            ->key('routing_actions'),
                                    ]),
                            ])
                            ->columnSpan(1),

                        RouteMapPicker::make('route_map')
                            ->label(__('Pattern Map'))
                            ->helperText(__('Stop locations are shown in travel order.'))
                            ->view('filament.forms.components.route-map')
                            ->height(450)
                            ->center([9.9281, -84.0907])
                            ->zoom(12)
                            ->autoCenter(false)
                            ->fitBounds()
                            ->zoomControl()
                            ->scaleControl()
                            ->fullscreenControl()
                            ->disabled()
                            ->dehydrated(false)
                            ->markers(
                                fn (Get $get, ?RoutePattern $record): array => [
                                    ...self::routeMarkers($get, $record),
                                    ...self::adjustmentMarkers($get, $record),
                                ],
                            )
                            ->shapes(function (Get $get): array {
                                $geometry = $get('calculated_geometry');

                                if (
                                    ! is_array($geometry)
                                    || ($geometry['type'] ?? null) !== 'LineString'
                                    || empty($geometry['coordinates'])
                                ) {
                                    return [];
                                }

                                $points = array_map(
                                    static fn (array $coordinate): array => [
                                        (float) $coordinate[1],
                                        (float) $coordinate[0],
                                    ],
                                    $geometry['coordinates'],
                                );

                                return [
                                    Polyline::make($points)
                                        ->id('calculated-route')
                                        ->title(__('Calculated Road Route'))
                                        ->blue()
                                        ->weight(4)
                                        ->fill(false)
                                        ->fillOpacity(0),
                                ];
                            })
                            ->onMapClick(
                                fn (
                                    Get $get,
                                    Set $set,
                                    ?RoutePattern $record,
                                    float $latitude,
                                    float $longitude,
                                ) => self::addAdjustmentPoint(
                                    $get,
                                    $set,
                                    $record,
                                    $latitude,
                                    $longitude,
                                ),
                            )
                            ->onLayerClick(
                                fn (
                                    Get $get,
                                    Set $set,
                                    ?RoutePattern $record,
                                    ?BaseLayer $layer,
                                ) => self::removeAdjustmentPoint(
                                    $get,
                                    $set,
                                    $record,
                                    $layer,
                                ),
                            )
                            ->onAdjustmentPointMove(
                                fn (
                                    Get $get,
                                    Set $set,
                                    ?RoutePattern $record,
                                    string $layerId,
                                    float $latitude,
                                    float $longitude,
                                ) => self::moveAdjustmentPoint(
                                    $get,
                                    $set,
                                    $record,
                                    $layerId,
                                    $latitude,
                                    $longitude,
                                ),
                            )
                            ->columnSpan(1),

                        Callout::make(__('Review the calculated route'))
                            ->key('routing_detour_warning')
                            ->warning()
                            ->extraAttributes(['role' => 'status'])
                            ->visible(
                                fn (Get $get): bool => filled($get('calculated_geometry'))
                                    && filled($get('route_detour_warnings')),
                            )
                            ->description(function (Get $get): string {
                                $messages = array_map(
                                    static fn (array $warning): string => __(
                                        'The segment from :from to :to has a calculated distance of :distance km and may include a considerable detour.',
                                        [
                                            'from' => $warning['from_name'],
                                            'to' => $warning['to_name'],
                                            'distance' => number_format(
                                                $warning['distance_meters'] / 1000,
                                                2,
                                                '.',
                                                '',
                                            ),
                                        ],
                                    ),
                                    $get('route_detour_warnings') ?? [],
                                );

                                $messages[] = __(
                                    'This may be caused by traffic restrictions, map connections or selected points. Check that the route matches the road used by the bus.',
                                );

                                return implode(' ', $messages);
                            })
                            ->actions([
                                function (Get $get, ?RoutePattern $record): array {
                                    $actions = [];

                                    foreach ($get('route_detour_warnings') ?? [] as $index => $warning) {
                                        $actions[] = Action::make('locateDetourSegment'.$index)
                                            ->label(__('Locate segment'))
                                            ->icon(Heroicon::OutlinedMapPin)
                                            ->tooltip($warning['from_name'].' → '.$warning['to_name'])
                                            ->action(function (LivewireComponent $livewire) use ($record, $warning): void {
                                                if ($record === null) {
                                                    return;
                                                }

                                                $map = $livewire->form->getComponentByStatePath(
                                                    'route_map',
                                                    withHidden: true,
                                                );

                                                if (! $map instanceof RouteMapPicker) {
                                                    return;
                                                }

                                                $ids = $record->stopOccurrences()
                                                    ->orderBy('stop_sequence')
                                                    ->pluck('id')
                                                    ->all();

                                                $segmentIndex = $warning['segment_index'];
                                                $lastIndex = count($ids) - 1;

                                                if (
                                                    count($ids) >= 2
                                                    && ! isset($ids[$segmentIndex + 1])
                                                ) {
                                                    return;
                                                }

                                                $fromLayerId = $segmentIndex === 0
                                                    ? 'origin'
                                                    : 'stop-'.$ids[$segmentIndex];

                                                $toLayerId = count($ids) < 2
                                                    || $segmentIndex + 1 === $lastIndex
                                                    ? 'destination'
                                                    : 'stop-'.$ids[$segmentIndex + 1];

                                                $livewire->dispatch(
                                                    'route-detour-segment-focus',
                                                    mapId: $map->getMapData()['mapId'],
                                                    fromLayerId: $fromLayerId,
                                                    toLayerId: $toLayerId,
                                                    fromOccurrenceId: $ids[$segmentIndex] ?? 'draft-origin',
                                                    toOccurrenceId: $ids[$segmentIndex + 1] ?? 'draft-destination',
                                                );
                                            });
                                    }

                                    return $actions;
                                }])
                            ->columnSpanFull(),

                        Callout::make(__('Route review unavailable'))
                            ->key('routing_detour_unavailable')
                            ->info()
                            ->visible(
                                fn (Get $get): bool => filled($get('calculated_geometry'))
                                    && $get('route_detour_analysis_available') === false,
                            )
                            ->description(__(
                                'The calculation does not include the segment details needed to detect considerable detours. Review the suggested route on the map.',
                            ))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    private static function endpointSearch(
        string $name,
        string $label,
        bool $isOrigin,
    ): LocationSearchInput {
        return LocationSearchInput::make($name)
            ->label($label)
            ->live()
            ->afterStateUpdated(
                fn (Set $set) => self::clearCalculatedRoute($set),
            )
            ->extraAttributes(['data-testid' => $name])
            ->afterStateHydrated(function (LocationSearchInput $component, ?RoutePattern $record) use ($isOrigin): void {
                if (! $record?->exists) {
                    $component->state(null);

                    return;
                }

                $stop = $record->stopOccurrences()
                    ->reorder('stop_sequence', $isOrigin ? 'asc' : 'desc')
                    ->with('stop')
                    ->first()
                    ?->stop;

                if ($stop === null) {
                    $component->state(null);

                    return;
                }

                $component->state(GeoSearchResult::fromArray([
                    'coordinate' => [
                        'lat' => (float) $stop->latitude,
                        'lng' => (float) $stop->longitude,
                    ],
                    'name' => $stop->name,
                    'display_name' => $stop->name,
                ]));
            });
    }

    /**
     * @return list<Marker>
     */
    private static function routeMarkers(
        Get $get,
        ?RoutePattern $record,
    ): array {
        $markers = [];

        $origin = $get('origin_search');
        $destination = $get('destination_search');

        if (
            $origin instanceof GeoSearchResult
            && $origin->coordinate !== null
        ) {
            $markers[] = Marker::make(
                $origin->coordinate->lat,
                $origin->coordinate->lng,
            )
                ->id('origin')
                ->title($origin->name)
                ->heroicon(Heroicon::PlayCircle)
                ->green();
        }

        $occurrences = $record?->stopOccurrences()
            ->with('stop')
            ->get();

        if ($occurrences !== null && $occurrences->count() > 2) {
            foreach ($occurrences->slice(1, $occurrences->count() - 2) as $occurrence) {
                $stop = $occurrence->stop;

                if ($stop === null) {
                    continue;
                }

                $markers[] = LucideMarker::make(
                    (float) $stop->latitude,
                    (float) $stop->longitude,
                )
                    ->id('stop-'.$occurrence->getKey())
                    ->title($stop->name)
                    ->lucideIcon(LucideIcon::BusFront);
            }
        }

        if (
            $destination instanceof GeoSearchResult
            && $destination->coordinate !== null
        ) {
            $markers[] = Marker::make(
                $destination->coordinate->lat,
                $destination->coordinate->lng,
            )
                ->id('destination')
                ->title($destination->name)
                ->heroicon(Heroicon::StopCircle)
                ->red();
        }

        return $markers;
    }

    /**
     * @return list<array{lat: float, lng: float}>
     */
    private static function routePoints(
        Get $get,
        ?RoutePattern $record,
        ?array $adjustments = null,
    ): array {
        $origin = $get('origin_search');
        $destination = $get('destination_search');

        if (
            ! $origin instanceof GeoSearchResult
            || $origin->coordinate === null
            || ! $destination instanceof GeoSearchResult
            || $destination->coordinate === null
        ) {
            return [];
        }

        $occurrences = $record?->stopOccurrences()
            ->with('stop')
            ->orderBy('stop_sequence')
            ->get()
            ->all() ?? [];

        $count = count($occurrences);

        $stops = [
            [
                'occurrence_id' => $count > 0
                    ? $occurrences[0]->getKey()
                    : 'draft-origin',
                'lat' => $origin->coordinate->lat,
                'lng' => $origin->coordinate->lng,
            ],
        ];

        for ($index = 1; $index < $count - 1; $index++) {
            $occurrence = $occurrences[$index];
            $stop = $occurrence->stop;

            if ($stop === null) {
                throw new InvalidArgumentException(
                    'The route contains an unavailable intermediate stop.',
                );
            }

            $stops[] = [
                'occurrence_id' => $occurrence->getKey(),
                'lat' => (float) $stop->latitude,
                'lng' => (float) $stop->longitude,
            ];
        }

        $stops[] = [
            'occurrence_id' => $count >= 2
                ? $occurrences[$count - 1]->getKey()
                : 'draft-destination',
            'lat' => $destination->coordinate->lat,
            'lng' => $destination->coordinate->lng,
        ];

        return app(RouteRoutingPointsService::class)->build(
            $stops,
            $adjustments ?? $get('routing_adjustments') ?? [],
        );
    }

    private static function clearCalculatedRoute(Set $set): void
    {
        $set('calculated_geometry', null);
        $set('distance_meters', null);
        $set('driving_duration_seconds', null);
        $set('distance_preview', null);
        $set('driving_duration_preview', null);
        $set('route_detour_warnings', []);
        $set('route_detour_analysis_available', null);
    }

    private static function calculateRoute(
        Get $get,
        Set $set,
        ?RoutePattern $record,
    ): void {
        abort_unless(
            $record !== null && $record->route()->exists(),
            403,
        );

        Gate::authorize('update', $record);

        self::clearCalculatedRoute($set);

        try {
            $points = self::routePoints($get, $record);

            $result = app(OsrmRoutingService::class)->calculate($points);

            $warnings = self::routeDetourWarnings(
                $get,
                $record,
                $points,
                $result['leg_distances_meters'] ?? [],
            );
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->danger()
                ->title(__('Could Not Calculate Route'))
                ->body(__(
                    'Review the route adjustment points and available stops before calculating.',
                ))
                ->send();

            return;
        } catch (RuntimeException $exception) {
            Notification::make()
                ->danger()
                ->title(__('Could Not Calculate Route'))
                ->body($exception->getMessage())
                ->send();

            return;
        }

        $set('route_detour_warnings', $warnings ?? []);
        $set('route_detour_analysis_available', $warnings !== null);
        $set('calculated_geometry', $result['geometry']);
        $set('distance_meters', $result['distance_meters']);
        $set('driving_duration_seconds', $result['duration_seconds']);

        $set(
            'distance_preview',
            number_format(
                (float) $result['distance_meters'] / 1000,
                2,
                '.',
                '',
            ),
        );

        $set(
            'driving_duration_preview',
            (int) ceil((float) $result['duration_seconds'] / 60),
        );

        Notification::make()
            ->success()
            ->title(__('Route Calculated'))
            ->send();
    }

    /**
     * @return array<string, string>
     */
    private static function routeSegmentOptions(?RoutePattern $record): array
    {
        $occurrences = $record?->stopOccurrences()
            ->with('stop')
            ->orderBy('stop_sequence')
            ->get()
            ->all() ?? [];

        $options = [];

        for ($index = 0; $index < count($occurrences) - 1; $index++) {
            $origin = $occurrences[$index]->stop;
            $destination = $occurrences[$index + 1]->stop;

            if ($origin === null || $destination === null) {
                continue;
            }

            $options[$occurrences[$index]->getKey()] =
                $origin->name.' → '.$destination->name;
        }

        return $options;
    }

    private static function addAdjustmentPoint(
        Get $get,
        Set $set,
        ?RoutePattern $record,
        float $latitude,
        float $longitude,
    ): void {
        abort_unless(
            $record !== null && $record->route()->exists(),
            403,
        );

        Gate::authorize('update', $record);

        if (! $get('adjustment_mode')) {
            return;
        }

        $selectedOrigin = $get('adjustment_segment');

        $occurrenceIds = $record->stopOccurrences()
            ->pluck('id')
            ->all();

        $index = array_search($selectedOrigin, $occurrenceIds, true);

        if (
            $index === false
            || ! isset($occurrenceIds[$index + 1])
        ) {
            Notification::make()
                ->warning()
                ->title(__('Select a route segment before adding adjustment points.'))
                ->send();

            return;
        }

        $adjustments = $get('routing_adjustments') ?? [];
        $adjustmentIndex = null;

        foreach ($adjustments as $key => $adjustment) {
            if (
                $adjustment['from_occurrence_id'] === $selectedOrigin
                && $adjustment['to_occurrence_id'] === $occurrenceIds[$index + 1]
            ) {
                $adjustmentIndex = $key;

                break;
            }
        }

        if ($adjustmentIndex === null) {
            $adjustments[] = [
                'from_occurrence_id' => $selectedOrigin,
                'to_occurrence_id' => $occurrenceIds[$index + 1],
                'points' => [],
            ];

            $adjustmentIndex = array_key_last($adjustments);
        }

        $adjustments[$adjustmentIndex]['points'][] = [
            'lat' => $latitude,
            'lng' => $longitude,
        ];

        try {
            if (count(self::routePoints($get, $record, $adjustments)) < 2) {
                throw new InvalidArgumentException(
                    'Route adjustments require available endpoints.',
                );
            }
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->danger()
                ->title(__('Could Not Add Adjustment Point'))
                ->body(__(
                    'Review the route adjustment points and available stops before calculating.',
                ))
                ->send();

            return;
        }

        $set('routing_adjustments', $adjustments);

        self::clearCalculatedRoute($set);
    }

    /**
     * @return list<Marker>
     */
    private static function adjustmentMarkers(Get $get, ?RoutePattern $record): array
    {
        $markers = [];
        $segments = self::routeSegmentOptions($record);

        foreach ($get('routing_adjustments') ?? [] as $adjustment) {
            foreach ($adjustment['points'] as $index => $point) {
                $label = __('Route Adjustment :number: :segment', [
                    'number' => $index + 1,
                    'segment' => $segments[$adjustment['from_occurrence_id']]
                        ?? __('Unavailable Segment'),
                ]);

                $markers[] = LucideMarker::make(
                    (float) $point['lat'],
                    (float) $point['lng'],
                )
                    ->id(
                        'adjustment:'
                        .$adjustment['from_occurrence_id'].':'
                        .$adjustment['to_occurrence_id'].':'
                        .$index,
                    )
                    ->orange()
                    ->draggable((bool) $get('adjustment_mode'))
                    ->lucideIcon(LucideIcon::Route)
                    ->title($label)
                    ->tooltip($label);
            }
        }

        return $markers;
    }

    private static function removeAdjustmentPoint(
        Get $get,
        Set $set,
        ?RoutePattern $record,
        ?BaseLayer $layer,
    ): void {
        abort_unless(
            $record !== null && $record->route()->exists(),
            403,
        );

        Gate::authorize('update', $record);

        if (! $get('adjustment_mode') || $layer === null) {
            return;
        }

        $layerId = $layer->getId();

        if (
            $layerId === null
            || ! str_starts_with($layerId, 'adjustment:')
        ) {
            return;
        }

        $adjustments = $get('routing_adjustments') ?? [];

        foreach ($adjustments as $adjustmentIndex => $adjustment) {
            foreach ($adjustment['points'] as $pointIndex => $point) {
                $expectedId = 'adjustment:'
                    .$adjustment['from_occurrence_id'].':'
                    .$adjustment['to_occurrence_id'].':'
                    .$pointIndex;

                if ($expectedId !== $layerId) {
                    continue;
                }

                unset($adjustments[$adjustmentIndex]['points'][$pointIndex]);

                $remainingPoints = array_values(
                    $adjustments[$adjustmentIndex]['points'],
                );

                if ($remainingPoints === []) {
                    unset($adjustments[$adjustmentIndex]);
                } else {
                    $adjustments[$adjustmentIndex]['points'] = $remainingPoints;
                }

                $set('routing_adjustments', array_values($adjustments));

                self::clearCalculatedRoute($set);

                return;
            }
        }
    }

    private static function moveAdjustmentPoint(
        Get $get,
        Set $set,
        ?RoutePattern $record,
        string $layerId,
        float $latitude,
        float $longitude,
    ): void {
        abort_unless(
            $record !== null && $record->route()->exists(),
            403,
        );

        Gate::authorize('update', $record);

        if (
            ! $get('adjustment_mode')
            || ! str_starts_with($layerId, 'adjustment:')
        ) {
            return;
        }

        $adjustments = $get('routing_adjustments') ?? [];

        foreach ($adjustments as $adjustmentIndex => $adjustment) {
            foreach ($adjustment['points'] as $pointIndex => $point) {
                $expectedId = 'adjustment:'
                    .$adjustment['from_occurrence_id'].':'
                    .$adjustment['to_occurrence_id'].':'
                    .$pointIndex;

                if ($expectedId !== $layerId) {
                    continue;
                }

                $adjustments[$adjustmentIndex]['points'][$pointIndex] = [
                    'lat' => $latitude,
                    'lng' => $longitude,
                ];

                try {
                    if (count(self::routePoints($get, $record, $adjustments)) < 2) {
                        throw new InvalidArgumentException(
                            'Route adjustments require available endpoints.',
                        );
                    }
                } catch (InvalidArgumentException $exception) {
                    Notification::make()
                        ->danger()
                        ->title(__('Could Not Move Adjustment Point'))
                        ->body(__(
                            'Review the route adjustment points and available stops before calculating.',
                        ))
                        ->send();

                    return;
                }

                $set('routing_adjustments', $adjustments);

                self::clearCalculatedRoute($set);

                return;
            }
        }
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $points
     * @param  list<float>  $legDistances
     * @return list<array{
     *     from_name: string,
     *     to_name: string,
     *     distance_meters: float,
     *     segment_index: int,
     * }>|null
     */
    private static function routeDetourWarnings(
        Get $get,
        RoutePattern $record,
        array $points,
        array $legDistances,
    ): ?array {
        if (
            count($points) < 2
            || count($legDistances) !== count($points) - 1
        ) {
            return null;
        }

        $occurrences = $record->stopOccurrences()
            ->with('stop')
            ->orderBy('stop_sequence')
            ->get()
            ->all();

        $count = count($occurrences);

        $stops = [
            [
                'id' => $count > 0
                    ? $occurrences[0]->getKey()
                    : 'draft-origin',
                'name' => $get('origin_search')->name,
            ],
        ];

        for ($index = 1; $index < $count - 1; $index++) {
            $stops[] = [
                'id' => $occurrences[$index]->getKey(),
                'name' => $occurrences[$index]->stop->name,
            ];
        }

        $stops[] = [
            'id' => $count >= 2
                ? $occurrences[$count - 1]->getKey()
                : 'draft-destination',
            'name' => $get('destination_search')->name,
        ];

        $adjustments = $get('routing_adjustments') ?? [];
        $segments = [];
        $pointIndex = 0;

        for ($index = 0; $index < count($stops) - 1; $index++) {
            $adjustmentCount = 0;

            foreach ($adjustments as $adjustment) {
                if (
                    $adjustment['from_occurrence_id'] === $stops[$index]['id']
                    && $adjustment['to_occurrence_id'] === $stops[$index + 1]['id']
                ) {
                    $adjustmentCount = count($adjustment['points']);

                    break;
                }
            }

            $nextPointIndex = $pointIndex + $adjustmentCount + 1;

            $segments[] = [
                'from_point_index' => $pointIndex,
                'to_point_index' => $nextPointIndex,
            ];

            $pointIndex = $nextPointIndex;
        }

        // Inconsistent metadata must not be presented as a successful review.
        if ($pointIndex !== count($points) - 1) {
            return null;
        }

        $warnings = app(RouteDetourAnalyzer::class)->analyze(
            $points,
            $legDistances,
            $segments,
        );

        return array_map(
            static fn (array $warning): array => [
                'segment_index' => $warning['segment_index'],
                'from_name' => $stops[$warning['segment_index']]['name'],
                'to_name' => $stops[$warning['segment_index'] + 1]['name'],
                'distance_meters' => $warning['distance_meters'],
            ],
            $warnings,
        );
    }
}
