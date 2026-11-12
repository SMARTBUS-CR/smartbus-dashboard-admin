<?php

use App\Models\Company;
use App\Services\TripAssignmentMaterializationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schedule;

use function Pest\Laravel\mock;

describe('Materialize Recurring Trips Command', function (): void {
    test('registers hourly single-server execution with a bounded overlap lock', function (): void {
        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'trips:materialize-recurring'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('0 * * * *')
            ->and($event->onOneServer)->toBeTrue()
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBe(120);
    });

    test('processes today and tomorrow in every company local timezone', function (): void {
        $costaRica = createCompany(['timezone' => 'America/Costa_Rica']);
        $tokyo = createCompany(['timezone' => 'Asia/Tokyo']);
        $this->travelTo(CarbonImmutable::parse('2026-11-12 16:30:00', 'UTC'));
        $service = mock(TripAssignmentMaterializationService::class);
        $empty = ['created' => [], 'skipped' => [], 'failed' => []];
        $service->shouldReceive('materialize')->once()->withArgs(fn (Company $company, CarbonImmutable $date): bool => $company->is($costaRica) && $date->toDateString() === '2026-11-12')->andReturn($empty);
        $service->shouldReceive('materialize')->once()->withArgs(fn (Company $company, CarbonImmutable $date): bool => $company->is($costaRica) && $date->toDateString() === '2026-11-13')->andReturn($empty);
        $service->shouldReceive('materialize')->once()->withArgs(fn (Company $company, CarbonImmutable $date): bool => $company->is($tokyo) && $date->toDateString() === '2026-11-13')->andReturn($empty);
        $service->shouldReceive('materialize')->once()->withArgs(fn (Company $company, CarbonImmutable $date): bool => $company->is($tokyo) && $date->toDateString() === '2026-11-14')->andReturn($empty);

        $this->artisan('trips:materialize-recurring')->assertSuccessful();
    });

    test('supports explicit company date and recovery window with safe aggregate output', function (): void {
        $company = createCompany();
        mock(TripAssignmentMaterializationService::class)
            ->shouldReceive('materialize')->times(3)->andReturn([
                'created' => [['assignment_id' => fake()->uuid(), 'trip_id' => fake()->uuid()]],
                'skipped' => [['assignment_id' => fake()->uuid(), 'reason' => 'already_materialized']],
                'failed' => [],
            ]);

        $this->artisan('trips:materialize-recurring', [
            '--company' => $company->id, '--date' => '2026-11-12', '--days' => 3,
        ])->expectsOutputToContain('Created')->doesntExpectOutputToContain($company->id)->assertSuccessful();
    });

    test('rejects invalid options with a meaningful exit code', function (array $options): void {
        $this->artisan('trips:materialize-recurring', $options)->assertExitCode(Command::INVALID);
    })->with([
        'invalid date' => [['--date' => '2026-02-30']],
        'invalid days' => [['--days' => 0]],
        'malformed company uuid' => [['--company' => 'missing-company']],
        'unknown company uuid' => [['--company' => '019aa8e7-7c83-7145-83e5-c27f643df098']],
    ]);

    test('continues after assignment failures and returns failure', function (): void {
        createCompany();
        mock(TripAssignmentMaterializationService::class)->shouldReceive('materialize')->twice()->andReturn([
            'created' => [],
            'skipped' => [],
            'failed' => [['assignment_id' => fake()->uuid(), 'reason' => 'ineligible', 'errors' => ['driver_id' => ['Unavailable']]]],
        ]);

        $this->artisan('trips:materialize-recurring')->assertFailed();
    });
});
