<?php

use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

describe('Route Pattern Ownership', function (): void {
    test('stores independent outbound and inbound patterns for the same route', function (): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $otherRoute = Route::factory()->for($company)->create();

        $outbound = RoutePattern::factory()
            ->for($route)
            ->create([
                'code' => 'OUTBOUND',
                'name' => 'Outbound',
                'headsign' => 'Heredia',
            ]);

        $inbound = RoutePattern::factory()
            ->for($route)
            ->create([
                'code' => 'INBOUND',
                'name' => 'Inbound',
                'headsign' => 'San Jose',
            ]);

        expect(Str::isUuid($outbound->getKey()))->toBeTrue()
            ->and($outbound->fresh()->route->is($route))->toBeTrue()
            ->and($outbound->fresh()->route->company->is($company))->toBeTrue()
            ->and($route->patterns()->pluck('id')->all())
            ->toEqualCanonicalizing([
                $outbound->getKey(),
                $inbound->getKey(),
            ])
            ->and($otherRoute->patterns()->exists())->toBeFalse();

        $outbound->update(['headsign' => 'Heredia Terminal']);

        expect($outbound->fresh()->headsign)->toBe('Heredia Terminal')
            ->and($inbound->fresh()->headsign)->toBe('San Jose');

        $this->assertDatabaseHas(RoutePattern::class, [
            'id' => $outbound->getKey(),
            'route_id' => $route->getKey(),
            'code' => 'OUTBOUND',
            'name' => 'Outbound',
            'headsign' => 'Heredia Terminal',
        ]);
    });
});

describe('Route Pattern Code Constraints', function (): void {
    test('allows different routes to use the same pattern code', function (): void {
        $company = createCompany();

        $firstRoute = Route::factory()->for($company)->create();
        $secondRoute = Route::factory()->for($company)->create();

        $firstPattern = RoutePattern::factory()
            ->for($firstRoute)
            ->create(['code' => 'OUTBOUND']);

        $secondPattern = RoutePattern::factory()
            ->for($secondRoute)
            ->create(['code' => 'OUTBOUND']);

        expect($firstPattern->fresh()->code)->toBe('OUTBOUND')
            ->and($secondPattern->fresh()->code)->toBe('OUTBOUND')
            ->and($firstPattern->route_id)
            ->not->toBe($secondPattern->route_id);
    });

    test('rejects duplicate pattern codes within the same route', function (): void {
        $route = Route::factory()->create();

        RoutePattern::factory()
            ->for($route)
            ->create(['code' => 'OUTBOUND']);

        $duplicate = RoutePattern::factory()
            ->for($route)
            ->make(['code' => 'OUTBOUND']);

        expect(fn () => $duplicate->save())
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('Route Pattern Archival', function (): void {
    test('archives one pattern without archiving its route or another pattern', function (): void {
        $route = Route::factory()->create();

        $outbound = RoutePattern::factory()
            ->for($route)
            ->create(['code' => 'OUTBOUND']);

        $inbound = RoutePattern::factory()
            ->for($route)
            ->create(['code' => 'INBOUND']);

        $outbound->delete();

        $this->assertSoftDeleted($outbound);
        $this->assertNotSoftDeleted($route);
        $this->assertNotSoftDeleted($inbound);

        expect($route->patterns()->pluck('id')->all())
            ->toBe([$inbound->getKey()]);

        $outbound->restore();

        expect($route->patterns()->pluck('id')->all())
            ->toEqualCanonicalizing([
                $outbound->getKey(),
                $inbound->getKey(),
            ]);
    });

    test('reserves the pattern code after archival', function (): void {
        $route = Route::factory()->create();

        $pattern = RoutePattern::factory()
            ->for($route)
            ->create(['code' => 'OUTBOUND']);

        $pattern->delete();

        $this->assertSoftDeleted($pattern);

        $replacement = RoutePattern::factory()
            ->for($route)
            ->make(['code' => 'OUTBOUND']);

        expect(fn () => $replacement->save())
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('Route Pattern Route Changes', function (): void {
    test('rejects moving a pattern with private stops to another company route', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $originalRoute = Route::factory()->for($company)->create();
        $destinationRoute = Route::factory()->for($otherCompany)->create();

        $pattern = RoutePattern::factory()
            ->for($originalRoute)
            ->create();

        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        $pattern->load('route', 'stopOccurrences');

        expect(fn () => $pattern->update([
            'route_id' => $destinationRoute->getKey(),
        ]))->toThrow(ValidationException::class)
            ->and($pattern->fresh()->route_id)->toBe($originalRoute->getKey());

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
        ]);
    });

    test('allows moving a pattern to another route of the same company', function (): void {
        $company = createCompany();

        $originalRoute = Route::factory()->for($company)->create();
        $destinationRoute = Route::factory()->for($company)->create();

        $pattern = RoutePattern::factory()
            ->for($originalRoute)
            ->create();

        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        $pattern->update([
            'route_id' => $destinationRoute->getKey(),
        ]);

        expect($pattern->fresh()->route_id)
            ->toBe($destinationRoute->getKey())
            ->and($originalRoute->patterns()->exists())->toBeFalse()
            ->and($destinationRoute->patterns()
                ->whereKey($pattern->getKey())
                ->exists())->toBeTrue();

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
        ]);
    });

    test('allows moving a pattern with shared stops to another company route', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $originalRoute = Route::factory()->for($company)->create();
        $destinationRoute = Route::factory()->for($otherCompany)->create();

        $pattern = RoutePattern::factory()
            ->for($originalRoute)
            ->create();

        $stop = Stop::factory()->shared()->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        $pattern->update([
            'route_id' => $destinationRoute->getKey(),
        ]);

        expect($pattern->fresh()->route_id)
            ->toBe($destinationRoute->getKey());

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
        ]);
    });

    test('rejects moving a pattern to an archived route', function (): void {
        $company = createCompany();

        $originalRoute = Route::factory()->for($company)->create();
        $destinationRoute = Route::factory()->for($company)->create();

        $pattern = RoutePattern::factory()
            ->for($originalRoute)
            ->create();

        $destinationRoute->delete();

        expect(fn () => $pattern->update([
            'route_id' => $destinationRoute->getKey(),
        ]))->toThrow(ValidationException::class)
            ->and($pattern->fresh()->route_id)->toBe($originalRoute->getKey());
    });
});

describe('Route Pattern Archived Route Creation', function (): void {
    test('rejects creating a pattern for an archived route', function (): void {
        $route = Route::factory()->create();
        $route->delete();

        $pattern = RoutePattern::factory()
            ->for($route)
            ->make();

        expect(fn () => $pattern->save())
            ->toThrow(ValidationException::class)
            ->and(RoutePattern::query()
                ->where('route_id', $route->getKey())->exists())->toBeFalse();

        $this->assertSoftDeleted($route);
    });
});
