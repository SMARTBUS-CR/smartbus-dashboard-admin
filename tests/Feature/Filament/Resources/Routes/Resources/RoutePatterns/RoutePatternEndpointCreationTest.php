<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Livewire\Livewire;

describe('Route Pattern Endpoint Creation', function (): void {
    test('creates the first and last stop appearances from selected origin and destination locations', function (string $role): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $pattern = RoutePattern::factory()->for($route)->create([
            'code' => 'OUTBOUND',
            'name' => 'Outbound Pattern',
            'headsign' => 'North Terminal',
        ]);

        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $company);

            CompanyUser::create([
                'company_id' => $company->getKey(),
                'user_id' => $actor->getKey(),
            ]);

            grantShield($actor, [
                'ViewAny:Route',
                'View:Route',
                'ViewAny:RoutePattern',
                'View:RoutePattern',
                'Update:RoutePattern',
                'Create:Stop',
            ], $company);
        }

        actingAsInCompany($actor, $company);

        $origin = [
            'coordinate' => [
                'lat' => 10.4523456,
                'lng' => -84.0123456,
            ],
            'name' => 'Central Terminal',
            'display_name' => 'Central Terminal, Sarapiquí, Costa Rica',
            'type' => 'bus_station',
            'addresstype' => 'amenity',
            'address' => [],
            'boundingbox' => [],
        ];

        $destination = [
            'coordinate' => [
                'lat' => 10.4623456,
                'lng' => -84.0223456,
            ],
            'name' => 'North Terminal',
            'display_name' => 'North Terminal, Sarapiquí, Costa Rica',
            'type' => 'bus_station',
            'addresstype' => 'amenity',
            'address' => [],
            'boundingbox' => [],
        ];

        Livewire::test(EditRoutePattern::class, [
            'record' => $pattern->getRouteKey(),
            'parentRecord' => $route,
        ])
            ->assertSchemaComponentExists('origin_search')
            ->assertSchemaComponentExists('destination_search')
            ->set('data.origin_search', json_encode($origin))
            ->set('data.destination_search', json_encode($destination))
            ->call('save')
            ->assertHasNoFormErrors();

        $appearances = $pattern->stopOccurrences()
            ->with('stop')
            ->get();

        expect($appearances)->toHaveCount(2)
            ->and($appearances->pluck('stop_sequence')->all())
            ->toBe([1, 2])
            ->and($appearances->pluck('minutes_from_start')->all())
            ->toBe([0, null]);

        $originStop = $appearances->first()->stop;
        $destinationStop = $appearances->last()->stop;

        $this->assertDatabaseHas(Stop::class, [
            'id' => $originStop->getKey(),
            'company_id' => $company->getKey(),
            'name' => 'Central Terminal',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        $this->assertDatabaseHas(Stop::class, [
            'id' => $destinationStop->getKey(),
            'company_id' => $company->getKey(),
            'name' => 'North Terminal',
            'latitude' => '10.4623456',
            'longitude' => '-84.0223456',
        ]);

        expect(
            RoutePatternStop::query()
                ->where('route_pattern_id', $pattern->getKey())
                ->count(),
        )->toBe(2);
    })->with([
        'system admin' => [UserRole::SuperAdmin->value],
        'company admin' => [UserRole::Admin->value],
        'custom role' => ['route-editor'],
    ]);

    test('reuses matching company and shared stops without creating duplicates', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $originStop = Stop::factory()->for($company)->create([
            'name' => 'Central Terminal',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        $destinationStop = Stop::factory()->create([
            'company_id' => null,
            'name' => 'North Terminal',
            'latitude' => '10.4623456',
            'longitude' => '-84.0223456',
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

        expect($pattern->stopOccurrences()->pluck('stop_id')->all())
            ->toBe([
                $originStop->getKey(),
                $destinationStop->getKey(),
            ])
            ->and(Stop::withTrashed()->count())->toBe($stopsBefore);

        $this->assertDatabaseHas(Stop::class, [
            'id' => $destinationStop->getKey(),
            'company_id' => null,
        ]);
    });

    test('requires both endpoints when only one location is selected', function (string $selectedField, string $missingField): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(EditRoutePattern::class, [
            'record' => $pattern->getRouteKey(),
            'parentRecord' => $route,
        ])
            ->set("data.{$selectedField}", json_encode([
                'coordinate' => [
                    'lat' => 10.4523456,
                    'lng' => -84.0123456,
                ],
                'name' => 'Central Terminal',
                'display_name' => 'Central Terminal, Costa Rica',
            ]))
            ->call('save')
            ->assertHasFormErrors([$missingField => 'required']);

        expect($pattern->stopOccurrences()->count())->toBe(0)
            ->and(Stop::query()->count())->toBe(0);
    })->with([
        'missing destination' => ['origin_search', 'destination_search'],
        'missing origin' => ['destination_search', 'origin_search'],
    ]);
});
