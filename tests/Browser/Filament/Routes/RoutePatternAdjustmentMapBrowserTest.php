<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RoutePatternResource;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Execution;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('Route Pattern Adjustment Map Browser Flow', function (): void {
    test('adds and removes an adjustment point through map clicks without saving or calculating', function (): void {
        config([
            'app.locale' => 'en',
            'services.osrm.url' => 'https://osrm.test',
        ]);

        app()->setLocale('en');

        $pattern = RoutePattern::factory()->create();
        $route = $pattern->route;
        $company = $route->company;

        $points = [
            [10.4523456, -84.0123456],
            [10.4623456, -84.0223456],
        ];

        $occurrenceIds = [];

        foreach ($points as $index => [$latitude, $longitude]) {
            $stop = Stop::factory()->for($company)->create([
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);

            $occurrenceIds[] = RoutePatternStop::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'stop_id' => $stop->getKey(),
                'stop_sequence' => $index + 1,
                'minutes_from_start' => $index * 20,
            ])->getKey();
        }

        BrowserSession::start(
            createUserWithRole(UserRole::SuperAdmin),
        );

        $path = parse_url(
            RoutePatternResource::getUrl(
                'edit',
                [
                    'route' => $route->getRouteKey(),
                    'record' => $pattern->getRouteKey(),
                ],
                panel: 'admin',
                tenant: $company,
            ),
            PHP_URL_PATH,
        );

        $page = visit($path)
            ->assertVisible('.leaflet-container')
            ->click(Selector::getByRoleSelector('switch', [
                'name' => 'Adjust Route',
                'exact' => true,
            ]))
            ->assertSee('Route Segment')
            ->select(
                Selector::getByLabelSelector('Route Segment', false),
                $occurrenceIds[0],
            )
            ->assertValue(
                Selector::getByLabelSelector('Route Segment', false),
                $occurrenceIds[0],
            )
            ->click('.leaflet-container');

        Execution::instance()->waitForExpectation(function () use ($page): void {
            $page->assertScript(<<<'JS'
        () => {
            const element = document.querySelector('.leaflet-container');
            const root = element?.closest('[x-data]');
            const component = root ? window.Alpine.$data(root) : null;
            const layers = component?.mapCore?.layers;

            if (!layers) {
                return false;
            }

            const entries = window.Alpine.raw(layers);
            const adjustmentEntries = [...entries.entries()]
                .filter(([id]) => id.startsWith('adjustment:'));

            return adjustmentEntries.length === 1
                && entries.has('origin')
                && entries.has('destination');
        }
        JS);
        });

        expect($pattern->fresh()->routing_adjustments)->toBe([])
            ->and($pattern->stopOccurrences()->pluck('id')->all())
            ->toBe($occurrenceIds);

        $page->click('.leaflet-marker-icon[data-layer-id^="adjustment:"]');

        Execution::instance()->waitForExpectation(function () use ($page): void {
            $page->assertScript(<<<'JS'
        () => {
            const element = document.querySelector('.leaflet-container');
            const root = element?.closest('[x-data]');
            const component = root ? window.Alpine.$data(root) : null;
            const layers = component?.mapCore?.layers;

            if (!layers) {
                return false;
            }

            const entries = window.Alpine.raw(layers);

            return ![...entries.keys()]
                .some(id => id.startsWith('adjustment:'))
                && entries.has('origin')
                && entries.has('destination');
        }
        JS);
        });

        $page->assertNoJavaScriptErrors();

        expect($pattern->fresh()->routing_adjustments)->toBe([])
            ->and($pattern->fresh()->route_geometry)->toBeNull()
            ->and($pattern->stopOccurrences()->pluck('id')->all())
            ->toBe($occurrenceIds)
            ->and($pattern->stopOccurrences()->pluck('minutes_from_start')->all())
            ->toBe([0, 20]);

        Http::assertNotSent(
            fn ($request): bool => parse_url($request->url(), PHP_URL_HOST) === 'osrm.test',
        );

        BrowserSession::assertTokenWasValidated();
    });
});
