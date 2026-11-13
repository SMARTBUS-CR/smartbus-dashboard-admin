<?php

use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Illuminate\Validation\ValidationException;

describe('Stop Archival', function (): void {
    test('archives an unused stop while preserving its data', function (): void {
        $company = createCompany();

        $stop = Stop::factory()->for($company)->create([
            'name' => 'Unused Boarding Point',
        ]);

        $stop->delete();

        expect($stop->fresh()->trashed())->toBeTrue();

        $this->assertDatabaseHas(Stop::class, [
            'id' => $stop->getKey(),
            'company_id' => $company->getKey(),
            'name' => 'Unused Boarding Point',
        ]);
    });

    test('rejects archiving a stop referenced by a pattern', function (bool $archivedPattern): void {
        $company = createCompany();
        $route = Route::factory()->for($company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        if ($archivedPattern) {
            $pattern->delete();
        }

        expect(fn () => $stop->delete())
            ->toThrow(ValidationException::class)
            ->and($stop->fresh()->trashed())->toBeFalse();

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);
    })->with([
        'active pattern' => false,
        'archived pattern' => true,
    ]);
});
