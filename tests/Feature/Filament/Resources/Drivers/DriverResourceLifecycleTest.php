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
use Filament\Facades\Filament;
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

    test('rejects an end date before the employment start in the form', function (): void {
        $driver = Driver::factory()->for($this->company)->create();
        $originalEnd = $driver->employment_ends_on?->toDateString();

        Livewire::test(EditDriver::class, ['record' => $driver->getKey()])
            ->fillForm(['employment_starts_on' => '2026-10-28', 'employment_ends_on' => '2026-10-27'])
            ->call('save')
            ->assertHasFormErrors(['employment_ends_on' => 'after_or_equal'])
            ->assertNotNotified();

        expect($driver->fresh()->employment_ends_on?->toDateString())->toBe($originalEnd);
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

        $list = Livewire::test(ListDrivers::class)
            ->set('activeTab', 'active')
            ->callAction(TestAction::make('deactivate')->table($driver))
            ->assertNotified()
            ->assertCanNotSeeTableRecords([$driver]);

        expect($driver->fresh()->status)->toBe(DriverStatus::INACTIVE)
            ->and($list->instance()->getCachedTabs()['inactive']->getBadge())->toBe('1')
            ->and($list->instance()->getTable()->getAction('deactivate')->getGroup())->not->toBeNull();

        Livewire::test(ListDrivers::class)
            ->callAction(TestAction::make('activate')->table($driver->fresh()))
            ->assertNotified();

        expect($driver->fresh()->status)->toBe(DriverStatus::ACTIVE);
    });

    test('filters employment tabs and counts within the selected archive scope', function (): void {
        Filament::setTenant(null, isQuiet: true);
        $foreign = Driver::factory()->active()->create();
        actingAsInCompany($this->actor, $this->company);
        $active = Driver::factory()->for($this->company)->active()->create();
        $inactive = Driver::factory()->for($this->company)->inactive()->create();
        $archived = Driver::factory()->for($this->company)->inactive()->create();
        $archived->delete();

        $list = Livewire::test(ListDrivers::class)
            ->assertSet('activeTab', 'all')
            ->assertCanSeeTableRecords([$active, $inactive])
            ->assertCanNotSeeTableRecords([$archived, $foreign])
            ->set('activeTab', 'active')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive, $archived, $foreign])
            ->set('activeTab', 'inactive')
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$archived])
            ->assertCanNotSeeTableRecords([$active, $inactive, $foreign]);

        $tabs = $list->instance()->getCachedTabs();
        expect($tabs['all']->getBadge())->toBe('1')
            ->and($tabs['active']->getBadge())->toBe('0')
            ->and($tabs['inactive']->getBadge())->toBe('1');
    });

    test('shows the driver email and license expiration without separate columns', function (): void {
        $driver = Driver::factory()->for($this->company)->create(['license_expires_at' => '2031-12-31']);

        Livewire::test(ListDrivers::class)
            ->assertTableColumnStateSet('user.name', $driver->user->name, $driver)
            ->assertTableColumnDoesNotExist('user.email')
            ->assertTableColumnDoesNotExist('license_expires_at')
            ->assertSee($driver->user->email)
            ->assertSee(__('Expires: :date', ['date' => '31/12/2031']));
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
