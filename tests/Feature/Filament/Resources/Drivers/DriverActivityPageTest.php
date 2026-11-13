<?php

use App\Enums\UserRole;
use App\Filament\Resources\Drivers\Pages\DriverActivity;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\Trip;
use Livewire\Livewire;

describe('Driver Activity Page', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany(['timezone' => 'America/Costa_Rica']);
        $this->driver = Driver::factory()->for($this->company)->create([
            'salary_amount' => '987654.32',
        ]);
        $this->actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->actor->getKey(),
        ]);
        grantShield($this->actor, [
            'ViewAny:Driver', 'View:Driver', 'ViewAttendance:Driver',
        ], $this->company);
    });

    test('renders inclusive daily activity with real lifecycle timestamps and empty dates', function (): void {
        $trip = Trip::factory()->for($this->company)->for($this->driver, 'driver')->completed()->create([
            'service_date' => '2026-10-26',
            'scheduled_departure_at' => '2026-10-27T05:00:00Z',
            'scheduled_arrival_at' => '2026-10-27T07:00:00Z',
            'planned_route_snapshot' => [
                'schema_version' => 1,
                'pattern_code' => 'NIGHT-01',
                'pattern_name' => 'Night Service',
            ],
        ]);
        actingAsInCompany($this->actor, $this->company);

        Livewire::test(DriverActivity::class, ['record' => $this->driver->getKey()])
            ->set('data.from_date', '2026-10-26')
            ->set('data.until_date', '2026-10-27')
            ->call('generateReport')
            ->assertHasNoErrors()
            ->assertSee('2026-10-26')
            ->assertSee('2026-10-27')
            ->assertSee('NIGHT-01')
            ->assertSee('2026-10-27T05:00:00.000000Z')
            ->assertSee('2026-10-27T07:00:00.000000Z')
            ->assertSee($trip->getKey())
            ->assertDontSee('987654.32')
            ->assertDontSee($this->actor->email)
            ->assertSee('This report describes recorded Trip activity only. It is not proof of physical attendance or payroll eligibility.');
    });

    test('keeps archived history available to authorized administrators', function (): void {
        $this->driver->delete();
        actingAsInCompany($this->actor, $this->company);

        Livewire::test(DriverActivity::class, ['record' => $this->driver->getKey()])
            ->set('data.from_date', '2026-10-26')
            ->set('data.until_date', '2026-10-26')
            ->call('generateReport')
            ->assertSuccessful()
            ->assertSee('2026-10-26');
    });

    test('rejects reversed ranges without replacing the current report', function (): void {
        actingAsInCompany($this->actor, $this->company);
        $component = Livewire::test(DriverActivity::class, ['record' => $this->driver->getKey()])
            ->set('data.from_date', '2026-10-26')
            ->set('data.until_date', '2026-10-26')
            ->call('generateReport')
            ->assertSee('2026-10-26');

        $component->set('data.from_date', '2026-10-27')
            ->set('data.until_date', '2026-10-26')
            ->call('generateReport')
            ->assertHasErrors(['data.until_date'])
            ->assertSet('report.from_date', '2026-10-26');
    });

    test('denies the report without attendance permission and hides another company record', function (): void {
        $otherCompany = createCompany();
        $otherDriver = Driver::factory()->for($otherCompany)->create();
        $unauthorized = createUserWithRole('workforce-viewer', $this->company);
        CompanyUser::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $unauthorized->getKey(),
        ]);
        grantShield($unauthorized, ['ViewAny:Driver', 'View:Driver'], $this->company);
        actingAsInCompany($unauthorized, $this->company);

        Livewire::test(DriverActivity::class, ['record' => $this->driver->getKey()])
            ->assertForbidden();

        actingAsInCompany($this->actor, $this->company);

        Livewire::test(DriverActivity::class, ['record' => $otherDriver->getKey()])
            ->assertStatus(404);
    });
});
