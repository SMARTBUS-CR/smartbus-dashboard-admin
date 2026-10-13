<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker;
use Livewire\Livewire;

describe('Route Pattern Stop Synchronization', function (): void {
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

    test('refreshes routing data after a stop change without discarding unsaved general information', function (): void {
        $occurrences = $this->pattern->stopOccurrences()->get();

        $this->pattern->update([
            'routing_adjustments' => [
                [
                    'from_occurrence_id' => $occurrences[0]->getKey(),
                    'to_occurrence_id' => $occurrences[1]->getKey(),
                    'points' => [
                        ['lat' => 10.425, 'lng' => -84.025],
                    ],
                ],
            ],
        ]);

        $geometry = [
            'type' => 'LineString',
            'coordinates' => [
                [-84.0, 10.4],
                [-84.025, 10.425],
                [-84.05, 10.45],
                [-84.1, 10.5],
            ],
        ];

        $this->pattern->update([
            'route_geometry' => $geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => hash(
                'sha256',
                '-84.0000000,10.4000000;'
                .'-84.0250000,10.4250000;'
                .'-84.0500000,10.4500000;'
                .'-84.1000000,10.5000000',
            ),
        ]);

        $page = Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->assertSet('data.calculated_geometry', $geometry)
            ->set('data.name', 'Unsaved Pattern Name');

        $occurrences[1]->delete();

        $page
            ->dispatch(
                'route-pattern-stops-changed.'.$this->pattern->getKey(),
            )
            ->assertHasNoErrors()
            ->assertSchemaStateSet([
                'name' => 'Unsaved Pattern Name',
                'routing_adjustments' => [],
                'calculated_geometry' => null,
                'distance_meters' => null,
                'driving_duration_seconds' => null,
            ])
            ->assertSchemaComponentExists(
                'route_map',
                checkComponentUsing: function (MapPicker $component): bool {
                    $layers = collect(
                        $component->getMapData()['layersData'],
                    );

                    expect($layers->where('type', 'marker')->pluck('coords')->values()->all())
                        ->toEqual([
                            [10.4, -84.0],
                            [10.5, -84.1],
                        ])
                        ->and($layers->where('type', 'polyline')->count())->toBe(0);

                    return true;
                },
            );

        expect($this->pattern->fresh()->name)
            ->not->toBe('Unsaved Pattern Name');
    });
});
