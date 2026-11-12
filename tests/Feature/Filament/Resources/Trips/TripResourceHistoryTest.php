<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Trips\Pages\ViewTrip;
use App\Models\CompanyUser;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

describe('Trip Resource History', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany(['timezone' => 'America/Costa_Rica']);
        $this->actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::factory()->for($this->company)->for($this->actor, 'user')->create();
        grantShield($this->actor, ['ViewAny:Trip', 'View:Trip'], $this->company);
    });

    test('renders each trip lifecycle state without manufacturing execution timestamps', function (
        string $state,
        TripStatus $status,
        string $expectedText,
    ): void {
        $factory = Trip::factory()->for($this->company)->withEligibleDriver();
        $factory = match ($state) {
            'in_progress' => $factory->inProgress(),
            'completed' => $factory->completed(),
            'cancelled' => $factory->cancelled(),
            default => $factory,
        };
        $trip = $factory->create();
        actingAsInCompany($this->actor, $this->company);

        $component = Livewire::test(ViewTrip::class, ['record' => $trip->getKey()])
            ->assertSuccessful()
            ->assertSee($trip->getKey())
            ->assertSee($trip->route_schedule_id)
            ->assertSee($trip->bus_id)
            ->assertSee($trip->driver_id)
            ->assertSee($status->getLabel())
            ->assertSee($expectedText)
            ->assertSee($trip->scheduled_departure_at->toJSON())
            ->assertSee($trip->scheduled_arrival_at->toJSON())
            ->assertSee('Not recorded')
            ->assertDontSee('Start Trip')
            ->assertDontSee('Complete Trip')
            ->assertDontSee('Cancel Trip');

        if ($status === TripStatus::CANCELLED) {
            $component
                ->assertSee($trip->cancelled_at->toJSON())
                ->assertSee('Demo cancellation');
        }

        if ($trip->actual_departure_at !== null) {
            $component->assertSee($trip->actual_departure_at->toJSON());
        }

        if ($trip->actual_arrival_at !== null) {
            $component->assertSee($trip->actual_arrival_at->toJSON());
        }
    })->with([
        'scheduled' => ['scheduled', TripStatus::SCHEDULED, 'Planned Execution'],
        'in progress' => ['in_progress', TripStatus::IN_PROGRESS, 'Actual Execution'],
        'completed' => ['completed', TripStatus::COMPLETED, 'Actual Execution'],
        'cancelled' => ['cancelled', TripStatus::CANCELLED, 'Cancellation'],
    ]);

    test('uses the immutable snapshot after catalogue changes and keeps archived references private', function (): void {
        $trip = Trip::factory()->for($this->company)->withEligibleDriver()->completed()->create([
            'service_date' => '2026-10-26',
            'scheduled_departure_at' => '2026-10-27T05:00:00Z',
            'scheduled_arrival_at' => '2026-10-27T07:00:00Z',
        ]);
        $snapshot = $trip->planned_route_snapshot;
        $originalPatternName = $snapshot['pattern_name'];
        $originalFirstStop = $snapshot['occurrences'][0]['name'];
        $trip->pattern->update(['name' => 'Changed Current Pattern']);
        $trip->pattern->stopOccurrences()->firstOrFail()->stop->update(['name' => 'Changed Current Stop']);
        $trip->driver->forceFill(['salary_amount' => '987654.32'])->save();
        $driverEmail = $trip->driver->user->email;
        $trip->driver->delete();
        $trip->bus->delete();
        actingAsInCompany($this->actor, $this->company);

        Livewire::test(ViewTrip::class, ['record' => $trip->getKey()])
            ->assertSuccessful()
            ->assertSee('2026-10-26')
            ->assertSee($originalPatternName)
            ->assertSee($originalFirstStop)
            ->assertSee($snapshot['company_timezone'])
            ->assertSee($snapshot['route_geometry']['type'])
            ->assertDontSee('Changed Current Pattern')
            ->assertDontSee('Changed Current Stop')
            ->assertDontSee('987654.32')
            ->assertDontSee($driverEmail);
    });

    test('does not resolve another company trip', function (): void {
        $foreignTrip = Trip::factory()->withEligibleDriver()->create();
        actingAsInCompany($this->actor, $this->company);

        Livewire::test(ViewTrip::class, ['record' => $foreignTrip->getKey()])
            ->assertStatus(404);
    });

    test('keeps query growth bounded when the saved snapshot contains more stops', function (): void {
        $trip = Trip::factory()->for($this->company)->withEligibleDriver()->create();
        actingAsInCompany($this->actor, $this->company);
        $connection = DB::connection('pgsql');
        $connection->enableQueryLog();

        Livewire::test(ViewTrip::class, ['record' => $trip->getKey()])->assertSuccessful();
        $smallQueryCount = count($connection->getQueryLog());

        $snapshot = $trip->planned_route_snapshot;
        $template = $snapshot['occurrences'][0];
        $snapshot['occurrences'] = collect(range(1, 30))->map(fn (int $sequence): array => [
            ...$template,
            'occurrence_id' => fake()->uuid(),
            'stop_id' => fake()->uuid(),
            'name' => "Historical Stop {$sequence}",
            'stop_sequence' => $sequence,
        ])->all();
        Trip::query()->whereKey($trip->getKey())->update(['planned_route_snapshot' => $snapshot]);
        $connection->flushQueryLog();

        Livewire::test(ViewTrip::class, ['record' => $trip->getKey()])->assertSuccessful();

        expect(count($connection->getQueryLog()))->toBeLessThanOrEqual($smallQueryCount + 1);
        $connection->disableQueryLog();
    });
});
