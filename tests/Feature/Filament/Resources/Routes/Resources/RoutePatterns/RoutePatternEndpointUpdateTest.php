<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Livewire\Livewire;

describe('Route Pattern Endpoint Update', function (): void {
    test('replaces endpoint references while preserving intermediate appearances and catalog stops', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $originalOrigin = Stop::factory()->for($company)->create([
            'name' => 'Original Origin',
            'latitude' => '10.4000000',
            'longitude' => '-84.0000000',
        ]);

        $intermediateStop = Stop::factory()->for($company)->create();

        $originalDestination = Stop::factory()->for($company)->create([
            'name' => 'Original Destination',
            'latitude' => '10.5000000',
            'longitude' => '-84.1000000',
        ]);

        $first = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $originalOrigin->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $middle = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $intermediateStop->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 15,
        ]);

        $last = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $originalDestination->getKey(),
            'stop_sequence' => 3,
            'minutes_from_start' => 30,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(EditRoutePattern::class, [
            'record' => $pattern->getRouteKey(),
            'parentRecord' => $route,
        ])
            ->set('data.origin_search', json_encode([
                'coordinate' => [
                    'lat' => 10.4523456,
                    'lng' => -84.0123456,
                ],
                'name' => 'Central Terminal',
                'display_name' => 'Central Terminal, Costa Rica',
            ]))
            ->set('data.destination_search', json_encode([
                'coordinate' => [
                    'lat' => 10.4623456,
                    'lng' => -84.0223456,
                ],
                'name' => 'North Terminal',
                'display_name' => 'North Terminal, Costa Rica',
            ]))
            ->call('save')
            ->assertHasNoFormErrors();

        $appearances = $pattern->stopOccurrences()->with('stop')->get();

        expect($appearances->modelKeys())->toBe([
            $first->getKey(),
            $middle->getKey(),
            $last->getKey(),
        ])
            ->and($appearances->pluck('stop_sequence')->all())->toBe([1, 2, 3])
            ->and($appearances->pluck('minutes_from_start')->all())->toBe([0, null, null])
            ->and($appearances->first()->stop->name)->toBe('Central Terminal')
            ->and($appearances->last()->stop->name)->toBe('North Terminal');

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $middle->getKey(),
            'stop_id' => $intermediateStop->getKey(),
            'stop_sequence' => 2,
        ]);

        $this->assertDatabaseHas(Stop::class, [
            'id' => $originalOrigin->getKey(),
            'name' => 'Original Origin',
            'latitude' => '10.4000000',
            'longitude' => '-84.0000000',
            'deleted_at' => null,
        ]);

        $this->assertDatabaseHas(Stop::class, [
            'id' => $originalDestination->getKey(),
            'name' => 'Original Destination',
            'latitude' => '10.5000000',
            'longitude' => '-84.1000000',
            'deleted_at' => null,
        ]);
    });

    test('preserves stop appearances and estimates when saving without endpoint changes', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $originStop = Stop::factory()->for($company)->create();
        $intermediateStop = Stop::factory()->for($company)->create();
        $destinationStop = Stop::factory()->for($company)->create();

        $first = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $originStop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $middle = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $intermediateStop->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 15,
        ]);

        $last = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $destinationStop->getKey(),
            'stop_sequence' => 3,
            'minutes_from_start' => 30,
        ]);

        $stopsBefore = Stop::withTrashed()->count();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(EditRoutePattern::class, [
            'record' => $pattern->getRouteKey(),
            'parentRecord' => $route,
        ])
            ->set('data.name', 'Updated Pattern Name')
            ->call('save')
            ->assertHasNoFormErrors();

        expect($pattern->fresh()->name)->toBe('Updated Pattern Name')
            ->and(Stop::withTrashed()->count())->toBe($stopsBefore);

        $appearances = $pattern->stopOccurrences()->get();

        expect($appearances->modelKeys())->toBe([
            $first->getKey(),
            $middle->getKey(),
            $last->getKey(),
        ])
            ->and($appearances->pluck('stop_id')->all())->toBe([
                $originStop->getKey(),
                $intermediateStop->getKey(),
                $destinationStop->getKey(),
            ])
            ->and($appearances->pluck('stop_sequence')->all())->toBe([1, 2, 3])
            ->and($appearances->pluck('minutes_from_start')->all())->toBe([0, 15, 30]);
    });
});
