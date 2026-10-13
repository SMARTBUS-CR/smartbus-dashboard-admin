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

describe('Route Pattern Adjustment Move Browser Flow', function (): void {
    test('moves an adjustment marker while preserving its segment and leaving changes unsaved', function (): void {
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

        $adjustments = [
            [
                'from_occurrence_id' => $occurrenceIds[0],
                'to_occurrence_id' => $occurrenceIds[1],
                'points' => [
                    ['lat' => 10.454, 'lng' => -84.014],
                ],
            ],
        ];

        $pattern->update([
            'routing_adjustments' => $adjustments,
        ]);

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
            ]));

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

            const entry = [...window.Alpine.raw(layers).entries()]
                .find(([id]) => id.startsWith('adjustment:'));

            return Boolean(entry)
                && window.Alpine.raw(entry[1].layer).dragging.enabled();
        }
        JS);
        });

        $page->drag(
            '.leaflet-marker-icon[data-layer-id^="adjustment:"]',
            '.leaflet-container',
        );

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

            const adjustments = component.$wire.get('data.routing_adjustments');
            const pendingPoint = adjustments?.[0]?.points?.[0];

            if (adjustmentEntries.length !== 1 || !pendingPoint) {
                return false;
            }

            const position = window.Alpine.raw(
                adjustmentEntries[0][1].layer
            ).getLatLng();

            const moved =
                Math.abs(pendingPoint.lat - 10.454) > 0.0000001
                || Math.abs(pendingPoint.lng + 84.014) > 0.0000001;

            return moved
                && Math.abs(position.lat - pendingPoint.lat) < 0.0000001
                && Math.abs(position.lng - pendingPoint.lng) < 0.0000001
                && entries.has('origin')
                && entries.has('destination');
        }
        JS);
        });

        $page->assertNoJavaScriptErrors();

        expect($pattern->fresh()->routing_adjustments)->toEqual($adjustments)
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
