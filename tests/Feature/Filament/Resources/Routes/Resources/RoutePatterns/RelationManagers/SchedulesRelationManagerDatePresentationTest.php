<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\ViewRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RelationManagers\SchedulesRelationManager;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\RouteScheduleException;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

describe('Route Schedule Date Presentation', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany();
        $route = Route::factory()->for($this->company)->create();

        $this->pattern = RoutePattern::factory()->for($route)->create();

        $this->schedule = RouteSchedule::factory()->create([
            'route_pattern_id' => $this->pattern->getKey(),
            'day_of_week' => 1,
            'departure_time' => '06:30:00',
            'valid_from' => '2026-10-12',
            'valid_until' => '2026-12-31',
        ]);

        $this->suspension = RouteScheduleException::factory()->create([
            'route_schedule_id' => $this->schedule->getKey(),
            'service_date' => '2026-10-19',
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );
    });

    test('shows suspension dates in the interface language', function (string $locale, string $expectedDate): void {
        app()->setLocale($locale);

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => ViewRoutePattern::class,
        ])
            ->mountAction(
                TestAction::make('viewSuspensions')->table($this->schedule),
            )
            ->assertMountedActionModalSee($expectedDate);

        $this->assertDatabaseHas(RouteScheduleException::class, [
            'id' => $this->suspension->getKey(),
            'service_date' => '2026-10-19',
        ]);
    })->with([
        'English' => ['en', 'October 19, 2026'],
        'Spanish' => ['es', '19 de octubre de 2026'],
    ]);

    test('uses readable dates as resumption labels while preserving suspension identifiers', function (string $locale, string $expectedDate): void {
        app()->setLocale($locale);

        Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ])
            ->mountAction(
                TestAction::make('resume')->table($this->schedule),
            )
            ->assertSchemaComponentExists(
                'exception_id',
                checkComponentUsing: function (Select $component) use ($expectedDate): bool {
                    expect($component->getOptions())->toBe([
                        $this->suspension->getKey() => $expectedDate,
                    ]);

                    return true;
                },
            );

        $this->assertDatabaseHas(RouteScheduleException::class, [
            'id' => $this->suspension->getKey(),
            'service_date' => '2026-10-19',
        ]);
    })->with([
        'English' => ['en', 'October 19, 2026'],
        'Spanish' => ['es', '19 de octubre de 2026'],
    ]);
});
