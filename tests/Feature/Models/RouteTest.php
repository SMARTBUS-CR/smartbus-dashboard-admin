<?php

use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

describe('Route Ownership', function (): void {
    test('persists a route with a uuid and associates it with its company', function (): void {
        $company = createCompany();
        $otherCompany = createCompany();

        $route = Route::factory()
            ->for($company)
            ->create([
                'code' => 'R-101',
                'name' => 'San Jose - Heredia',
            ]);

        $storedRoute = $route->fresh();

        expect(Str::isUuid($storedRoute->getKey()))->toBeTrue()
            ->and($storedRoute->company->is($company))->toBeTrue()
            ->and($company->routes()->whereKey($route->getKey())->exists())->toBeTrue()
            ->and($otherCompany->routes()->whereKey($route->getKey())->exists())->toBeFalse();

        $this->assertDatabaseHas(Route::class, [
            'id' => $route->getKey(),
            'company_id' => $company->getKey(),
            'code' => 'R-101',
            'name' => 'San Jose - Heredia',
        ]);
    });
});

describe('Route Code Constraints', function (): void {
    test('allows different companies to use the same route code', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $firstRoute = Route::factory()
            ->for($company)
            ->create(['code' => 'R-101']);

        $secondRoute = Route::factory()
            ->for($otherCompany)
            ->create(['code' => 'R-101']);

        expect($firstRoute->fresh()->code)->toBe('R-101')
            ->and($secondRoute->fresh()->code)->toBe('R-101')
            ->and($firstRoute->company_id)->not->toBe($secondRoute->company_id);
    });

    test('rejects duplicate route codes within the same company', function (): void {
        $company = createCompany();

        Route::factory()
            ->for($company)
            ->create(['code' => 'R-101']);

        $duplicate = Route::factory()
            ->for($company)
            ->make(['code' => 'R-101']);

        expect(fn () => $duplicate->save())
            ->toThrow(UniqueConstraintViolationException::class);
    });

    test('reserves the route code after the route is archived', function (): void {
        $company = createCompany();

        $route = Route::factory()
            ->for($company)
            ->create(['code' => 'R-101']);

        $route->delete();

        $this->assertSoftDeleted($route);

        expect($company->routes()->whereKey($route->getKey())->exists())
            ->toBeFalse();

        $replacement = Route::factory()
            ->for($company)
            ->make(['code' => 'R-101']);

        expect(fn () => $replacement->save())
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('Route Ownership Changes', function (): void {
    test('rejects changing company when a pattern uses a private stop from the original company', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        expect(fn () => $route->update([
            'company_id' => $otherCompany->getKey(),
        ]))->toThrow(ValidationException::class)
            ->and($route->fresh()->company_id)->toBe($company->getKey());

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
        ]);
    });

    test('allows changing company when all associated stops are shared', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->shared()->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        $route->update([
            'company_id' => $otherCompany->getKey(),
        ]);

        expect($route->fresh()->company_id)->toBe($otherCompany->getKey());

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
        ]);
    });
});
