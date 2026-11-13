<?php

use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

describe('Route Pattern Stop Sequence', function (): void {
    test('returns stop occurrences in sequence order', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $firstStop = Stop::factory()->for($company)->create();
        $secondStop = Stop::factory()->for($company)->create();

        $secondOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $secondStop->getKey(),
            'stop_sequence' => 2,
        ]);

        $firstOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $firstStop->getKey(),
            'stop_sequence' => 1,
        ]);

        expect(Str::isUuid($firstOccurrence->getKey()))->toBeTrue()
            ->and($firstOccurrence->fresh()->pattern->is($pattern))->toBeTrue()
            ->and($firstOccurrence->fresh()->stop->is($firstStop))->toBeTrue()
            ->and($pattern->stopOccurrences()->pluck('id')->all())
            ->toBe([
                $firstOccurrence->getKey(),
                $secondOccurrence->getKey(),
            ]);
    });

    test('allows the same stop to appear at different positions', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $firstOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        $lastOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 3,
        ]);

        expect($firstOccurrence->getKey())
            ->not->toBe($lastOccurrence->getKey())
            ->and($pattern->stopOccurrences()->pluck('stop_id')->all())
            ->toBe([$stop->getKey(), $stop->getKey()]);
    });

    test('rejects duplicate positions within the same pattern', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $firstStop = Stop::factory()->for($company)->create();
        $secondStop = Stop::factory()->for($company)->create();

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $firstStop->getKey(),
            'stop_sequence' => 1,
        ]);

        $duplicate = RoutePatternStop::factory()->make([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $secondStop->getKey(),
            'stop_sequence' => 1,
        ]);

        expect(fn () => $duplicate->save())
            ->toThrow(UniqueConstraintViolationException::class);
    });

    test('rejects non-positive positions', function (int $sequence): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->make([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => $sequence,
        ]);

        expect(fn () => $occurrence->save())
            ->toThrow(ValidationException::class)
            ->and($pattern->stopOccurrences()->exists())->toBeFalse();
    })->with([
        'zero' => 0,
        'negative' => -1,
    ]);
});

describe('Route Pattern Stop Ownership', function (): void {
    test('allows a shared stop in a company pattern', function (): void {
        $route = Route::factory()->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->shared()->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);
    });

    test('rejects a private stop owned by another company', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($otherCompany)->create();

        $occurrence = RoutePatternStop::factory()->make([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        expect(fn () => $occurrence->save())
            ->toThrow(ValidationException::class)
            ->and($pattern->stopOccurrences()->exists())->toBeFalse();
    });

    test('preserves the occurrence when an update assigns a stop from another company', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $originalStop = Stop::factory()->for($company)->create();
        $foreignStop = Stop::factory()->for($otherCompany)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $originalStop->getKey(),
            'stop_sequence' => 1,
        ]);

        expect(fn () => $occurrence->update([
            'stop_id' => $foreignStop->getKey(),
        ]))->toThrow(ValidationException::class)
            ->and($occurrence->fresh()->stop_id)->toBe($originalStop->getKey());
    });
});

describe('Route Pattern Stop Reassignment', function (): void {
    test('rejects moving a private stop occurrence to another company pattern', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $originalRoute = Route::factory()->for($company)->create();
        $originalPattern = RoutePattern::factory()
            ->for($originalRoute)
            ->create();

        $foreignRoute = Route::factory()->for($otherCompany)->create();
        $foreignPattern = RoutePattern::factory()
            ->for($foreignRoute)
            ->create();

        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $originalPattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        $occurrence->load('pattern', 'stop');

        expect(fn () => $occurrence->update([
            'route_pattern_id' => $foreignPattern->getKey(),
        ]))->toThrow(ValidationException::class)
            ->and($occurrence->fresh()->route_pattern_id)->toBe($originalPattern->getKey());
    });

    test('allows moving an occurrence to another pattern of the same company', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();

        $originalPattern = RoutePattern::factory()->for($route)->create();
        $destinationPattern = RoutePattern::factory()->for($route)->create();

        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $originalPattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        $occurrence->update([
            'route_pattern_id' => $destinationPattern->getKey(),
        ]);

        expect($occurrence->fresh()->route_pattern_id)
            ->toBe($destinationPattern->getKey())
            ->and($originalPattern->stopOccurrences()->exists())->toBeFalse()
            ->and($destinationPattern->stopOccurrences()
                ->whereKey($occurrence->getKey())
                ->exists())->toBeTrue();
    });
});

describe('Route Pattern Stop Archived References', function (): void {
    test('rejects creating an occurrence with an archived reference', function (string $reference): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        match ($reference) {
            'route' => $route->delete(),
            'pattern' => $pattern->delete(),
            'stop' => $stop->delete(),
        };

        $occurrence = RoutePatternStop::factory()->make([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        expect(fn () => $occurrence->save())
            ->toThrow(ValidationException::class);

        $this->assertDatabaseMissing(RoutePatternStop::class, [
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
        ]);
    })->with([
        'archived route' => 'route',
        'archived pattern' => 'pattern',
        'archived stop' => 'stop',
    ]);

    test('preserves an existing occurrence when an archived reference prevents an update', function (string $reference): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);

        $occurrence->load('pattern.route', 'stop');

        // Simulate legacy data containing an archived stop still referenced by a pattern.
        match ($reference) {
            'route' => $route->delete(),
            'pattern' => $pattern->delete(),
            'stop' => $stop->deleteQuietly(),
        };

        expect(fn () => $occurrence->update([
            'stop_sequence' => 2,
        ]))->toThrow(ValidationException::class);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
        ]);
    })->with([
        'archived route' => 'route',
        'archived pattern' => 'pattern',
        'archived stop' => 'stop',
    ]);
});

describe('Route Pattern Stop Estimated Times', function (): void {
    test('stores different estimated times for repeated appearances of the same stop', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $firstOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        $lastOccurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 3,
            'minutes_from_start' => 40,
        ]);

        expect($firstOccurrence->fresh()->minutes_from_start)->toBe(0)
            ->and($lastOccurrence->fresh()->minutes_from_start)->toBe(40);
    });

    test('allows an unknown estimated time', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => null,
        ]);

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'minutes_from_start' => null,
        ]);
    });

    test('rejects invalid estimated times', function (mixed $minutes): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->make([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => $minutes,
        ]);

        expect(fn () => $occurrence->save())
            ->toThrow(ValidationException::class)
            ->and($pattern->stopOccurrences()->exists())->toBeFalse();
    })->with([
        'negative minutes' => -1,
        'fractional minutes' => 1.5,
        'non-numeric minutes' => 'unknown',
    ]);

    test('preserves the estimated time when an invalid update is rejected', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 15,
        ]);

        expect(fn () => $occurrence->update([
            'minutes_from_start' => -1,
        ]))->toThrow(ValidationException::class)
            ->and($occurrence->fresh()->minutes_from_start)->toBe(15);
    });
});

describe('Route Pattern Stop Time Ordering', function (): void {
    test('rejects an estimated time that conflicts with an existing neighbor', function (int $sequence, int $minutes): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        foreach ([1 => 10, 3 => 30] as $position => $estimate) {
            RoutePatternStop::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'stop_id' => Stop::factory()->for($company)->create()->getKey(),
                'stop_sequence' => $position,
                'minutes_from_start' => $estimate,
            ]);
        }

        $candidate = RoutePatternStop::factory()->make([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => Stop::factory()->for($company)->create()->getKey(),
            'stop_sequence' => $sequence,
            'minutes_from_start' => $minutes,
        ]);

        expect(fn () => $candidate->save())
            ->toThrow(ValidationException::class)
            ->and($pattern->stopOccurrences()->count())->toBe(2);
    })->with([
        'earlier than previous stop' => [2, 5],
        'later than following stop' => [2, 35],
    ]);

    test('allows equal estimates and unknown times between known estimates', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        foreach ([1 => 10, 2 => null, 3 => 10, 4 => 25] as $position => $estimate) {
            RoutePatternStop::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'stop_id' => Stop::factory()->for($company)->create()->getKey(),
                'stop_sequence' => $position,
                'minutes_from_start' => $estimate,
            ]);
        }

        expect($pattern->stopOccurrences()
            ->pluck('minutes_from_start')
            ->all())->toBe([10, null, 10, 25]);
    });

    test('rejects changing an estimate when it would precede an earlier known estimate', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $occurrences = [];

        foreach ([1 => 10, 2 => null, 3 => 30] as $position => $estimate) {
            $occurrences[$position] = RoutePatternStop::factory()->create([
                'route_pattern_id' => $pattern->getKey(),
                'stop_id' => Stop::factory()->for($company)->create()->getKey(),
                'stop_sequence' => $position,
                'minutes_from_start' => $estimate,
            ]);
        }

        expect(fn () => $occurrences[3]->update([
            'minutes_from_start' => 5,
        ]))->toThrow(ValidationException::class)
            ->and($occurrences[3]->fresh()->minutes_from_start)->toBe(30);
    });

    test('rejects moving an occurrence before a stop with a lower estimate', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => Stop::factory()->for($company)->create()->getKey(),
            'stop_sequence' => 2,
            'minutes_from_start' => 10,
        ]);

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => Stop::factory()->for($company)->create()->getKey(),
            'stop_sequence' => 3,
            'minutes_from_start' => 30,
        ]);

        expect(fn () => $occurrence->update([
            'stop_sequence' => 1,
        ]))->toThrow(ValidationException::class)
            ->and($occurrence->fresh()->stop_sequence)->toBe(3);
    });

    test('allows updating an estimate without comparing the occurrence against itself', function (): void {
        $company = createCompany();

        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => Stop::factory()->for($company)->create()->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 10,
        ]);

        $occurrence->update([
            'minutes_from_start' => 5,
        ]);

        expect($occurrence->fresh()->minutes_from_start)->toBe(5);
    });
});
