<?php

use App\Enums\DriverStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Drivers\Pages\EditDriver;
use App\Filament\Resources\Drivers\Pages\ListDrivers;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Driver Resource Lifecycle', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany();
        $this->actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->actor->getKey(),
        ]);
        grantShield($this->actor, [
            'ViewAny:Driver', 'View:Driver', 'Update:Driver', 'Delete:Driver', 'Restore:Driver',
        ], $this->company);
        actingAsInCompany($this->actor, $this->company);
    });

    test('updates employment fields without allowing identity or status injection', function (): void {
        $driver = Driver::factory()->for($this->company)->active()->create();
        $originalUserId = $driver->user_id;
        $otherCompany = createCompany();

        Livewire::test(EditDriver::class, ['record' => $driver->getKey()])
            ->fillForm([
                'license' => ' updated-license ',
                'license_expires_at' => '2031-12-31',
                'employment_starts_on' => '2026-01-01',
                'employment_ends_on' => '2030-12-31',
            ])
            ->set('data.company_id', $otherCompany->getKey())
            ->set('data.user_id', User::factory()->create()->getKey())
            ->set('data.status', DriverStatus::INACTIVE->value)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $updated = $driver->fresh();
        expect($updated->company_id)->toBe($this->company->getKey())
            ->and($updated->user_id)->toBe($originalUserId)
            ->and($updated->status)->toBe(DriverStatus::ACTIVE)
            ->and($updated->license)->toBe('UPDATED-LICENSE')
            ->and($updated->employment_ends_on->toDateString())->toBe('2030-12-31');
    });

    test('deactivates and reactivates employment through explicit actions', function (): void {
        $driver = Driver::factory()->for($this->company)->active()->create();

        Livewire::test(ListDrivers::class)
            ->callAction(TestAction::make('deactivate')->table($driver))
            ->assertNotified();

        expect($driver->fresh()->status)->toBe(DriverStatus::INACTIVE);

        Livewire::test(ListDrivers::class)
            ->callAction(TestAction::make('activate')->table($driver->fresh()))
            ->assertNotified();

        expect($driver->fresh()->status)->toBe(DriverStatus::ACTIVE);
    });

    test('archives and restores employment using the original uuid', function (): void {
        $driver = Driver::factory()->for($this->company)->create();
        $driverId = $driver->getKey();

        Livewire::test(ListDrivers::class)
            ->callAction(TestAction::make('delete')->table($driver))
            ->assertNotified();

        $this->assertSoftDeleted($driver);

        Livewire::test(ListDrivers::class)
            ->filterTable('trashed', false)
            ->callAction(TestAction::make('restore')->table($driver))
            ->assertNotified();

        expect($driver->fresh()->getKey())->toBe($driverId);
        $this->assertNotSoftDeleted($driver);
        $this->assertDatabaseCount('drivers', 1);
    });

    test('preserves active employment when a committed trip blocks deactivation', function (): void {
        $driver = Driver::factory()->for($this->company)->active()->create();
        Trip::factory()->create([
            'company_id' => $this->company->getKey(),
            'driver_id' => $driver->getKey(),
        ]);

        Livewire::test(ListDrivers::class)
            ->callAction(TestAction::make('deactivate')->table($driver))
            ->assertNotified();

        expect($driver->fresh()->status)->toBe(DriverStatus::ACTIVE);
        $this->assertNotSoftDeleted($driver);
    });
});
