<?php

use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

describe('Stop Ownership', function (): void {
    test('persists a private stop associated with its company', function (): void {
        $company = createCompany();
        $otherCompany = createCompany();

        $stop = Stop::factory()
            ->for($company)
            ->create([
                'name' => 'Central Market',
                'description' => 'Across from the main entrance.',
                'latitude' => 9.934739,
                'longitude' => -84.084051,
            ]);

        $storedStop = $stop->fresh();

        expect(Str::isUuid($storedStop->getKey()))->toBeTrue()
            ->and($storedStop->company->is($company))->toBeTrue()
            ->and($company->stops()->whereKey($stop->getKey())->exists())->toBeTrue()
            ->and($otherCompany->stops()->whereKey($stop->getKey())->exists())->toBeFalse();

        $this->assertDatabaseHas(Stop::class, [
            'id' => $stop->getKey(),
            'company_id' => $company->getKey(),
            'name' => 'Central Market',
            'description' => 'Across from the main entrance.',
            'latitude' => 9.934739,
            'longitude' => -84.084051,
        ]);
    });

    test('persists a shared stop without a company owner', function (): void {
        $company = createCompany();

        $stop = Stop::factory()->create([
            'company_id' => null,
            'name' => 'Public Terminal',
        ]);

        expect($stop->fresh()->company_id)->toBeNull()
            ->and($stop->fresh()->company)->toBeNull()
            ->and($company->stops()->whereKey($stop->getKey())->exists())->toBeFalse();

        $this->assertDatabaseHas(Stop::class, [
            'id' => $stop->getKey(),
            'company_id' => null,
        ]);
    });

    test('allows different physical stops to have the same name', function (): void {
        $company = createCompany();

        $firstStop = Stop::factory()
            ->for($company)
            ->create([
                'name' => 'Central Market',
                'latitude' => 9.934739,
                'longitude' => -84.084051,
            ]);

        $secondStop = Stop::factory()
            ->for($company)
            ->create([
                'name' => 'Central Market',
                'latitude' => 9.935100,
                'longitude' => -84.083800,
            ]);

        expect($firstStop->getKey())->not->toBe($secondStop->getKey())
            ->and($company->stops()->where('name', 'Central Market')->count())
            ->toBe(2);
    });
});

describe('Stop Coordinates', function (): void {
    test('preserves zero latitude and longitude as valid coordinates', function (): void {
        $stop = Stop::factory()->create([
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $storedStop = $stop->fresh();

        expect((float) $storedStop->latitude)->toBe(0.0)
            ->and((float) $storedStop->longitude)->toBe(0.0);
    });
});

describe('Stop Archival', function (): void {
    test('archives and restores a stop without archiving its company', function (): void {
        $company = createCompany();

        $stop = Stop::factory()
            ->for($company)
            ->create();

        $stop->delete();

        $this->assertSoftDeleted($stop);
        $this->assertNotSoftDeleted($company);

        expect($company->stops()->whereKey($stop->getKey())->exists())
            ->toBeFalse();

        $stop->restore();

        $this->assertNotSoftDeleted($stop);

        expect($company->stops()->whereKey($stop->getKey())->exists())
            ->toBeTrue();
    });
});

describe('Stop Coordinate Constraints', function (): void {
    test('accepts coordinates at the geographic boundaries', function (float $latitude, float $longitude): void {
        $stop = Stop::factory()->create([
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);

        $storedStop = $stop->fresh();

        expect((float) $storedStop->latitude)->toBe($latitude)
            ->and((float) $storedStop->longitude)->toBe($longitude);
    })->with([
        'southwest boundary' => [-90.0, -180.0],
        'northeast boundary' => [90.0, 180.0],
    ]);

    test('rejects coordinates outside the geographic boundaries', function (string $field, float $value): void {
        $stop = Stop::factory()->make([
            'latitude' => 9.934739,
            'longitude' => -84.084051,
            $field => $value,
        ]);

        expect(fn () => $stop->save())
            ->toThrow(QueryException::class);
    })->with([
        'latitude below minimum' => ['latitude', -90.0000001],
        'latitude above maximum' => ['latitude', 90.0000001],
        'longitude below minimum' => ['longitude', -180.0000001],
        'longitude above maximum' => ['longitude', 180.0000001],
    ]);
});

describe('Stop Ownership Changes', function (): void {
    test('rejects changing company when a private stop belongs to an existing pattern', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        expect(fn () => $stop->update([
            'company_id' => $otherCompany->getKey(),
        ]))->toThrow(ValidationException::class)
            ->and($stop->fresh()->company_id)->toBe($company->getKey());

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'stop_id' => $stop->getKey(),
        ]);
    });

    test('rejects making a shared stop private when another company uses it', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $firstRoute = Route::factory()->for($company)->create();
        $secondRoute = Route::factory()->for($otherCompany)->create();

        $firstPattern = RoutePattern::factory()->for($firstRoute)->create();
        $secondPattern = RoutePattern::factory()->for($secondRoute)->create();

        $stop = Stop::factory()->shared()->create();

        foreach ([$firstPattern, $secondPattern] as $pattern) {
            RoutePatternStop::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'stop_id' => $stop->getKey(),
                'stop_sequence' => 1,
            ]);
        }

        expect(fn () => $stop->update([
            'company_id' => $company->getKey(),
        ]))->toThrow(ValidationException::class)
            ->and($stop->fresh()->company_id)->toBeNull()
            ->and(RoutePatternStop::query()
                ->where('stop_id', $stop->getKey())->count())->toBe(2);
    });

    test('allows making a shared stop private when only that company uses it', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->shared()->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        $stop->update([
            'company_id' => $company->getKey(),
        ]);

        expect($stop->fresh()->company_id)->toBe($company->getKey());

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'stop_id' => $stop->getKey(),
        ]);
    });
});
