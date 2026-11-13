<?php

use App\Enums\LucideIcon;
use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\ViewRoutePattern;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use EduardoRibeiroDev\FilamentLeaflet\Infolists\MapEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

describe('Route Pattern View Map', function (): void {
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

    test('shows the saved route and ordered stop markers without contacting the routing provider', function (string $role): void {
        if ($role !== UserRole::SuperAdmin->value) {
            $actor = createUserWithRole($role, $this->company);

            CompanyUser::create([
                'company_id' => $this->company->getKey(),
                'user_id' => $actor->getKey(),
            ]);

            grantShield($actor, [
                'ViewAny:Route',
                'View:Route',
                'ViewAny:RoutePattern',
                'View:RoutePattern',
            ], $this->company);

            actingAsInCompany($actor, $this->company);
        }

        Http::fake();
        Http::preventStrayRequests();

        $geometry = [
            'type' => 'LineString',
            'coordinates' => [
                [-84.0000000, 10.4000000],
                [-84.0500000, 10.4500000],
                [-84.1000000, 10.5000000],
            ],
        ];

        $this->pattern->update([
            'route_geometry' => $geometry,
            'distance_meters' => '1200.50',
            'driving_duration_seconds' => '180.00',
            'routing_points_hash' => hash(
                'sha256',
                '-84.0000000,10.4000000;'
                .'-84.0500000,10.4500000;'
                .'-84.1000000,10.5000000',
            ),
        ]);

        Livewire::test(ViewRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->assertSuccessful()
            ->assertSchemaComponentExists(
                'route_map',
                checkComponentUsing: function ($component): bool {
                    $layers = collect(
                        $component->getMapData()['layersData'],
                    );

                    expect(
                        $layers->where('type', 'marker')
                            ->pluck('coords')
                            ->values()
                            ->all(),
                    )->toEqual([
                        [10.4, -84.0],
                        [10.45, -84.05],
                        [10.5, -84.1],
                    ])
                        ->and($layers->where('type', 'polyline')
                            ->values()->all())->toHaveCount(1)
                        ->and($layers->firstWhere('type', 'polyline')['points'])->toEqual([
                            [10.4, -84.0],
                            [10.45, -84.05],
                            [10.5, -84.1],
                        ]);

                    $line = $layers->firstWhere('type', 'polyline');

                    expect(data_get($line, 'options.weight'))->toBe(4)
                        ->and(data_get($line, 'options.fill'))->toBeFalse()
                        ->and(data_get($line, 'options.fillOpacity'))
                        ->toEqual(0);

                    return true;
                },
            );

        Http::assertNothingSent();
    })->with([
        'system administrator' => UserRole::SuperAdmin->value,
        'company administrator' => UserRole::Admin->value,
        'custom viewer role' => 'route-viewer',
    ]);

    test('allows a viewer to inspect the map while denying access to editing', function (): void {
        $actor = createUserWithRole('route-viewer', $this->company);

        CompanyUser::create([
            'company_id' => $this->company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Route',
            'View:Route',
            'ViewAny:RoutePattern',
            'View:RoutePattern',
        ], $this->company);

        actingAsInCompany($actor, $this->company);

        Livewire::test(ViewRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->assertSuccessful()
            ->assertSchemaComponentExists('route_map')
            ->assertActionHidden('edit');

        Livewire::test(EditRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->assertForbidden();
    });

    test('shows saved routing metrics or indicates that the route has not been calculated', function (bool $calculated): void {
        app()->setLocale('en');

        if ($calculated) {
            $this->pattern->update([
                'route_geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [
                        [-84.0000000, 10.4000000],
                        [-84.0500000, 10.4500000],
                        [-84.1000000, 10.5000000],
                    ],
                ],
                'distance_meters' => '1200.50',
                'driving_duration_seconds' => '181.00',
                'routing_points_hash' => hash(
                    'sha256',
                    '-84.0000000,10.4000000;'
                    .'-84.0500000,10.4500000;'
                    .'-84.1000000,10.5000000',
                ),
            ]);
        }

        $page = Livewire::test(ViewRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->assertSuccessful()
            ->assertSchemaComponentExists(
                'distance_preview',
                checkComponentUsing: function (TextEntry $component) use ($calculated): bool {
                    expect($component->getState())
                        ->toBe($calculated ? '1.20' : null);

                    return true;
                },
            )
            ->assertSchemaComponentExists(
                'driving_duration_preview',
                checkComponentUsing: function (TextEntry $component) use ($calculated): bool {
                    expect($component->getState())
                        ->toBe($calculated ? 4 : null);

                    return true;
                },
            );

        if (! $calculated) {
            $page->assertSee('Not Calculated');
        }
    })->with([
        'saved calculation' => true,
        'no calculation' => false,
    ]);

    test('distinguishes endpoints and intermediate stops using their icons', function (): void {
        Livewire::test(ViewRoutePattern::class, [
            'record' => $this->pattern->getRouteKey(),
            'parentRecord' => $this->route,
        ])
            ->assertSchemaComponentExists(
                'route_map',
                checkComponentUsing: function (MapEntry $component): bool {
                    $icons = collect(
                        $component->getMapData()['layersData'],
                    )
                        ->where('type', 'marker')
                        ->map(
                            fn (array $marker): ?string => data_get(
                                $marker,
                                'icon.heroicon',
                            ),
                        )
                        ->values()
                        ->all();

                    expect($icons)->toBe([
                        svg(
                            Heroicon::PlayCircle->getIconForSize(
                                IconSize::Small,
                            ),
                        )->toHtml(),
                        svg(LucideIcon::BusFront->value)->toHtml(),
                        svg(
                            Heroicon::StopCircle->getIconForSize(
                                IconSize::Small,
                            ),
                        )->toHtml(),
                    ]);

                    return true;
                },
            );
    });
});
