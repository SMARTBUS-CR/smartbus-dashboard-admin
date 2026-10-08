<?php

use App\Enums\LucideIcon;
use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Livewire\Livewire;

describe('Route Pattern Map', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany();
        $this->route = Route::factory()->for($this->company)->create();
        $this->pattern = RoutePattern::factory()->for($this->route)->create();

        $this->originStop = Stop::factory()->for($this->company)->create([
            'latitude' => '10.4000000',
            'longitude' => '-84.0000000',
        ]);

        $this->middleStop = Stop::factory()->for($this->company)->create([
            'latitude' => '10.4500000',
            'longitude' => '-84.0500000',
        ]);

        $this->destinationStop = Stop::factory()->for($this->company)->create([
            'latitude' => '10.5000000',
            'longitude' => '-84.1000000',
        ]);

        foreach ([
            $this->originStop,
            $this->middleStop,
            $this->destinationStop,
        ] as $index => $stop) {
            RoutePatternStop::factory()->create([
                'route_pattern_id' => $this->pattern->getKey(),
                'stop_id' => $stop->getKey(),
                'stop_sequence' => $index + 1,
                'minutes_from_start' => null,
            ]);
        }

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );
    });

    test('shows the stored stop locations in their travel order', function (): void {
        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->assertSchemaComponentExists(
                'route_map',
                checkComponentUsing: function (MapPicker $component): bool {
                    $coordinates = collect(
                        $component->getMapData()['layersData'],
                    )
                        ->where('type', 'marker')
                        ->pluck('coords')
                        ->values()
                        ->all();

                    expect($coordinates)->toBe([
                        [10.4, -84.0],
                        [10.45, -84.05],
                        [10.5, -84.1],
                    ]);

                    return true;
                },
            );
    });

    test('previews selected endpoints while preserving the intermediate stop without saving', function (): void {
        $stopsBefore = Stop::withTrashed()->count();

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->set('data.origin_search', json_encode([
                'coordinate' => [
                    'lat' => 10.4523456,
                    'lng' => -84.0123456,
                ],
                'name' => 'Selected Origin',
                'display_name' => 'Selected Origin, Costa Rica',
            ]))
            ->set('data.destination_search', json_encode([
                'coordinate' => [
                    'lat' => 10.4623456,
                    'lng' => -84.0223456,
                ],
                'name' => 'Selected Destination',
                'display_name' => 'Selected Destination, Costa Rica',
            ]))
            ->assertSchemaComponentExists(
                'route_map',
                checkComponentUsing: function (MapPicker $component): bool {
                    $coordinates = collect(
                        $component->getMapData()['layersData'],
                    )
                        ->where('type', 'marker')
                        ->pluck('coords')
                        ->values()
                        ->all();

                    expect($coordinates)->toBe([
                        [10.4523456, -84.0123456],
                        [10.45, -84.05],
                        [10.4623456, -84.0223456],
                    ]);

                    return true;
                },
            );

        expect(Stop::withTrashed()->count())->toBe($stopsBefore)
            ->and($this->pattern->stopOccurrences()->pluck('stop_id')->all())
            ->toBe([
                $this->originStop->getKey(),
                $this->middleStop->getKey(),
                $this->destinationStop->getKey(),
            ]);
    });

    test('distinguishes origin and destination using their marker icons', function (): void {
        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->assertSchemaComponentExists(
                'route_map',
                checkComponentUsing: function (MapPicker $component): bool {
                    $markers = collect(
                        $component->getMapData()['layersData'],
                    )
                        ->where('type', 'marker')
                        ->keyBy('id');

                    $originIcon = svg(
                        Heroicon::PlayCircle->getIconForSize(IconSize::Small),
                    )->toHtml();

                    $destinationIcon = svg(
                        Heroicon::StopCircle->getIconForSize(IconSize::Small),
                    )->toHtml();

                    expect(data_get($markers->get('origin'), 'icon.heroicon'))
                        ->toBe($originIcon)
                        ->and(
                            data_get(
                                $markers->get('destination'),
                                'icon.heroicon',
                            ),
                        )
                        ->toBe($destinationIcon);

                    return true;
                },
            );
    });

    test('identifies intermediate stops with a bus icon', function (): void {
        $occurrence = $this->pattern->stopOccurrences()
            ->where('stop_id', $this->middleStop->getKey())
            ->sole();

        $markerId = 'stop-'.$occurrence->getKey();

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->assertSchemaComponentExists(
                'route_map',
                checkComponentUsing: function (MapPicker $component) use ($markerId): bool {
                    $marker = collect(
                        $component->getMapData()['layersData'],
                    )
                        ->where('type', 'marker')
                        ->firstWhere('id', $markerId);

                    expect($marker)->not->toBeNull();

                    expect(data_get($marker, 'icon.heroicon'))
                        ->toBe(
                            svg(LucideIcon::BusFront->value)->toHtml(),
                        );

                    return true;
                },
            );
    });

    test('shows the calculated route with a thicker line and no fill', function (): void {
        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->set('data.calculated_geometry', [
                'type' => 'LineString',
                'coordinates' => [
                    [-84.0, 10.4],
                    [-84.05, 10.45],
                    [-84.1, 10.5],
                ],
            ])
            ->assertSchemaComponentExists(
                'route_map',
                checkComponentUsing: function (MapPicker $component): bool {
                    $line = collect(
                        $component->getMapData()['layersData'],
                    )->firstWhere('type', 'polyline');

                    expect($line)->not->toBeNull();

                    expect(data_get($line, 'options.weight'))->toBe(4)
                        ->and(data_get($line, 'options.fill'))->toBeFalse()
                        ->and(data_get($line, 'options.fillOpacity'))
                        ->toEqual(0);

                    return true;
                },
            );
    });
});
