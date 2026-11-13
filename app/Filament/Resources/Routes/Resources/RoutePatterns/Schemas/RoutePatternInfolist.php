<?php

namespace App\Filament\Resources\Routes\Resources\RoutePatterns\Schemas;

use App\Enums\LucideIcon;
use App\Filament\Maps\Layers\LucideMarker;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use EduardoRibeiroDev\FilamentLeaflet\Infolists\MapEntry;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\Polyline;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class RoutePatternInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('General Information'))
                ->schema([
                    TextEntry::make('code')
                        ->label(__('Code')),

                    TextEntry::make('name')
                        ->label(__('Pattern Name')),

                    TextEntry::make('headsign')
                        ->label(__('Destination')),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make(__('Route Layout'))
                ->schema([
                    TextEntry::make('distance_preview')
                        ->label(__('Total Distance'))
                        ->state(
                            fn (RoutePattern $record): ?string => $record->distance_meters !== null
                                ? number_format(
                                    (float) $record->distance_meters / 1000,
                                    2,
                                )
                                : null,
                        )
                        ->suffix(' km')
                        ->placeholder(__('Not Calculated')),

                    TextEntry::make('driving_duration_preview')
                        ->label(__('Estimated Driving Time'))
                        ->state(
                            fn (RoutePattern $record): ?int => $record->driving_duration_seconds !== null
                                ? (int) ceil(
                                    (float) $record->driving_duration_seconds / 60,
                                )
                                : null,
                        )
                        ->suffix(' '.__('min'))
                        ->placeholder(__('Not Calculated')),

                    TextEntry::make('routing_notice')
                        ->hiddenLabel()
                        ->state(__(
                            'Review the suggested road route for bus operation. Driving time does not include boarding time or replace scheduled times.',
                        ))
                        ->columnSpanFull(),

                    MapEntry::make('route_map')
                        ->label(__('Pattern Map'))
                        ->height(450)
                        ->center([9.9281, -84.0907])
                        ->zoom(12)
                        ->autoCenter(false)
                        ->fitBounds()
                        ->zoomControl()
                        ->scaleControl()
                        ->fullscreenControl()
                        ->markers(function (RoutePattern $record): array {
                            $occurrences = $record->stopOccurrences()
                                ->with('stop')
                                ->orderBy('stop_sequence')
                                ->get();

                            $lastIndex = $occurrences->count() - 1;

                            return $occurrences
                                ->map(function (RoutePatternStop $occurrence, int $index) use ($lastIndex): ?Marker {
                                    $stop = $occurrence->stop;

                                    if ($stop === null) {
                                        return null;
                                    }

                                    $marker = LucideMarker::make(
                                        (float) $stop->latitude,
                                        (float) $stop->longitude,
                                    )
                                        ->id('stop-'.$occurrence->getKey())
                                        ->title($stop->name);

                                    if ($index === 0) {
                                        return $marker
                                            ->heroicon(Heroicon::PlayCircle)
                                            ->green();
                                    }

                                    if ($index === $lastIndex) {
                                        return $marker
                                            ->heroicon(Heroicon::StopCircle)
                                            ->red();
                                    }

                                    return $marker->lucideIcon(LucideIcon::BusFront);
                                })
                                ->filter()
                                ->values()
                                ->all();
                        })
                        ->shapes(function (RoutePattern $record): array {
                            $geometry = $record->route_geometry;

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
                                    ->title(__('Calculated Route'))
                                    ->blue()
                                    ->weight(4)
                                    ->fill(false)
                                    ->fillOpacity(0),
                            ];
                        })
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
