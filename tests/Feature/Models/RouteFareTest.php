<?php

use App\Models\Route;
use App\Models\RouteFare;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

describe('Route Fare Ownership', function (): void {
    test('stores a monetary amount and validity associated with its route', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $otherRoute = Route::factory()->for($company)->create();

        $fare = RouteFare::factory()->create([
            'route_id' => $route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-12-31',
        ]);

        $storedFare = $fare->fresh();

        expect(Str::isUuid($storedFare->getKey()))->toBeTrue()
            ->and($storedFare->route->is($route))->toBeTrue()
            ->and($storedFare->amount)->toBe('500.00')
            ->and($storedFare->currency)->toBe('CRC')
            ->and($storedFare->valid_from->format('Y-m-d'))
            ->toBe('2026-11-01')
            ->and($storedFare->valid_until->format('Y-m-d'))
            ->toBe('2026-12-31')
            ->and($route->fares()->whereKey($fare->getKey())->exists())
            ->toBeTrue()
            ->and($otherRoute->fares()->exists())->toBeFalse();

        $this->assertDatabaseHas(RouteFare::class, [
            'id' => $fare->getKey(),
            'route_id' => $route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-12-31',
        ]);
    });
});

describe('Route Fare Values', function (): void {
    test('allows a fare without validity boundaries', function (): void {
        $fare = RouteFare::factory()->create([
            'amount' => '1.25',
            'currency' => 'USD',
            'valid_from' => null,
            'valid_until' => null,
        ]);

        expect($fare->fresh()->amount)->toBe('1.25')
            ->and($fare->fresh()->currency)->toBe('USD');

        $this->assertDatabaseHas(RouteFare::class, [
            'id' => $fare->getKey(),
            'valid_from' => null,
            'valid_until' => null,
        ]);
    });

    test('allows a zero fare for a free service', function (): void {
        $fare = RouteFare::factory()->create([
            'amount' => '0.00',
            'currency' => 'CRC',
        ]);

        expect($fare->fresh()->amount)->toBe('0.00');

        $this->assertDatabaseHas(RouteFare::class, [
            'id' => $fare->getKey(),
            'amount' => '0.00',
            'currency' => 'CRC',
        ]);
    });
});

describe('Route Fare Validation', function (): void {
    test('rejects invalid fare values', function (array $changes): void {
        $route = Route::factory()->create();

        $fare = RouteFare::factory()->make([
            'route_id' => $route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-12-31',
            ...$changes,
        ]);

        expect(fn () => $fare->save())
            ->toThrow(ValidationException::class)
            ->and($route->fares()->exists())->toBeFalse();
    })->with([
        'negative amount' => [['amount' => '-1.00']],
        'more than two decimal places' => [['amount' => '1.005']],
        'non-numeric amount' => [['amount' => 'free']],
        'unsupported currency' => [['currency' => 'ZZZ']],
        'incomplete currency code' => [['currency' => 'US']],
        'ending before starting' => [[
            'valid_from' => '2026-12-01',
            'valid_until' => '2026-11-30',
        ]],
    ]);

    test('preserves the amount when an invalid update is rejected', function (): void {
        $fare = RouteFare::factory()->create([
            'amount' => '500.00',
            'currency' => 'CRC',
        ]);

        expect(fn () => $fare->update([
            'amount' => '-1.00',
        ]))->toThrow(ValidationException::class)
            ->and($fare->fresh()->amount)->toBe('500.00');

        $this->assertDatabaseHas(RouteFare::class, [
            'id' => $fare->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
        ]);
    });
});

describe('Route Fare Archived References', function (): void {
    test('rejects creating a fare for an archived route', function (): void {
        $route = Route::factory()->create();
        $route->delete();

        $fare = RouteFare::factory()->make([
            'route_id' => $route->getKey(),
        ]);

        expect(fn () => $fare->save())
            ->toThrow(ValidationException::class)
            ->and(RouteFare::query()
                ->where('route_id', $route->getKey())->exists())->toBeFalse();
    });
});

describe('Route Fare Validity Overlap', function (): void {
    test('allows consecutive fare periods for the same route and currency', function (): void {
        $route = Route::factory()->create();

        $currentFare = RouteFare::factory()->create([
            'route_id' => $route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => null,
            'valid_until' => '2026-11-30',
        ]);

        $futureFare = RouteFare::factory()->create([
            'route_id' => $route->getKey(),
            'amount' => '550.00',
            'currency' => 'CRC',
            'valid_from' => '2026-12-01',
            'valid_until' => null,
        ]);

        expect($route->fares()->count())->toBe(2)
            ->and($currentFare->fresh()->amount)->toBe('500.00')
            ->and($futureFare->fresh()->amount)->toBe('550.00');
    });

    test('allows overlapping dates for different currencies', function (): void {
        $route = Route::factory()->create();

        foreach ([
            'CRC' => '500.00',
            'USD' => '1.00',
        ] as $currency => $amount) {
            RouteFare::factory()->create([
                'route_id' => $route->getKey(),
                'amount' => $amount,
                'currency' => $currency,
                'valid_from' => '2026-11-01',
                'valid_until' => '2026-11-30',
            ]);
        }

        expect($route->fares()->count())->toBe(2);
    });

    test('allows different routes to have fares in the same period and currency', function (): void {
        $company = createCompany();

        $firstRoute = Route::factory()->for($company)->create();
        $secondRoute = Route::factory()->for($company)->create();

        foreach ([$firstRoute, $secondRoute] as $route) {
            RouteFare::factory()->create([
                'route_id' => $route->getKey(),
                'amount' => '500.00',
                'currency' => 'CRC',
                'valid_from' => '2026-11-01',
                'valid_until' => '2026-11-30',
            ]);
        }

        expect($firstRoute->fares()->count())->toBe(1)
            ->and($secondRoute->fares()->count())->toBe(1);
    });

    test('rejects overlapping fare periods for the same route and currency', function (?string $from, ?string $until): void {
        $route = Route::factory()->create();

        RouteFare::factory()->create([
            'route_id' => $route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-11-30',
        ]);

        $conflictingFare = RouteFare::factory()->make([
            'route_id' => $route->getKey(),
            'amount' => '550.00',
            'currency' => 'CRC',
            'valid_from' => $from,
            'valid_until' => $until,
        ]);

        expect(fn () => $conflictingFare->save())
            ->toThrow(QueryException::class);
    })->with([
        'partial overlap' => ['2026-11-15', '2026-12-15'],
        'shared inclusive boundary' => ['2026-11-30', '2026-12-15'],
        'unbounded period' => [null, null],
    ]);
});

describe('Route Fare Calendar Selection', function (): void {
    test('selects a fare using inclusive validity boundaries', function (string $date, bool $expected): void {
        $company = createCompany([
            'country_code' => 'CR',
            'timezone' => 'America/Costa_Rica',
        ]);

        $route = Route::factory()->for($company)->create();
        $otherRoute = Route::factory()->for($company)->create();

        $fare = RouteFare::factory()->create([
            'route_id' => $route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-11-30',
        ]);

        RouteFare::factory()->create([
            'route_id' => $otherRoute->getKey(),
            'amount' => '600.00',
            'currency' => 'CRC',
        ]);

        $localDate = CarbonImmutable::parse($date, $company->timezone);

        expect($route->fares()
            ->applicableOn($localDate, 'CRC')
            ->pluck('id')
            ->all())->toBe($expected ? [$fare->getKey()] : []);
    })->with([
        'first valid day' => ['2026-11-01', true],
        'inside validity' => ['2026-11-15', true],
        'last valid day' => ['2026-11-30', true],
        'before validity' => ['2026-10-31', false],
        'after validity' => ['2026-12-01', false],
    ]);

    test('selects the applicable period and currency', function (string $date, string $currency, string $expectedFare): void {
        $route = Route::factory()->create();

        $fares = [
            'previous' => RouteFare::factory()->create([
                'route_id' => $route->getKey(),
                'amount' => '500.00',
                'currency' => 'CRC',
                'valid_from' => null,
                'valid_until' => '2026-11-30',
            ]),
            'next' => RouteFare::factory()->create([
                'route_id' => $route->getKey(),
                'amount' => '550.00',
                'currency' => 'CRC',
                'valid_from' => '2026-12-01',
                'valid_until' => null,
            ]),
            'dollars' => RouteFare::factory()->create([
                'route_id' => $route->getKey(),
                'amount' => '1.00',
                'currency' => 'USD',
                'valid_from' => null,
                'valid_until' => null,
            ]),
        ];

        $localDate = CarbonImmutable::parse(
            $date,
            $route->company->timezone,
        );

        expect($route->fares()
            ->applicableOn($localDate, $currency)
            ->pluck('id')
            ->all())->toBe([$fares[$expectedFare]->getKey()]);
    })->with([
        'previous price' => ['2026-11-30', 'CRC', 'previous'],
        'next price' => ['2026-12-01', 'CRC', 'next'],
        'different currency' => ['2026-12-01', 'USD', 'dollars'],
    ]);

    test('returns no fare when the requested currency has no configured price', function (): void {
        $route = Route::factory()->create();

        RouteFare::factory()->create([
            'route_id' => $route->getKey(),
            'amount' => '500.00',
            'currency' => 'CRC',
        ]);

        $localDate = CarbonImmutable::parse(
            '2026-11-15',
            $route->company->timezone,
        );

        expect($route->fares()
            ->applicableOn($localDate, 'USD')
            ->exists())->toBeFalse();
    });
});
