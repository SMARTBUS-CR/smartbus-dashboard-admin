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

    test('reuses the company timezone across departure tooltips', function (): void {
        app()->setLocale('en');

        $schedules = [$this->schedule];

        foreach (['07:30:00', '08:30:00'] as $departure) {
            $schedules[] = RouteSchedule::factory()->create([
                'route_pattern_id' => $this->pattern->getKey(),
                'day_of_week' => 1,
                'departure_time' => $departure,
                'valid_from' => '2026-10-12',
                'valid_until' => '2026-12-31',
            ]);
        }

        $component = Livewire::test(SchedulesRelationManager::class, [
            'ownerRecord' => $this->pattern,
            'pageClass' => EditRoutePattern::class,
        ]);

        $page = $component->instance();
        $column = $page->getTable()->getColumn('departure_time');

        $records = array_map(
            fn (RouteSchedule $schedule) => $page->getTableRecord($schedule->getKey()),
            $schedules,
        );

        $connection = $this->company->getConnection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            foreach ($records as $record) {
                expect((string) $column->record($record)->getTooltip())
                    ->toBe(
                        'Company timezone: '.$this->company->timezone,
                    );
            }

            $queries = collect($connection->getQueryLog())
                ->filter(
                    fn (array $query): bool => str_contains(
                        $query['query'],
                        'from "companies"',
                    ),
                );

            expect($queries->count())->toBeLessThanOrEqual(1);
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    });
});
