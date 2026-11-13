<?php

use App\Enums\UserRole;
use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Resources\Drivers\Pages\EditDriver;
use App\Filament\Resources\Drivers\Pages\ListDrivers;
use App\Filament\Resources\Drivers\Pages\ViewDriver;
use App\Models\CompanyUser;
use App\Models\Driver;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Driver Resource Access', function (): void {
    test('shows only employment profiles from the selected company', function (string $role): void {
        [$company, $otherCompany] = createTenantPair();
        $ownDriver = Driver::factory()->for($company)->create();
        $foreignDriver = Driver::factory()->for($otherCompany)->create();

        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $company);
            CompanyUser::create([
                'company_id' => $company->getKey(),
                'user_id' => $actor->getKey(),
            ]);
            grantShield($actor, ['ViewAny:Driver', 'View:Driver'], $company);
        }

        actingAsInCompany($actor, $company);

        Livewire::test(ListDrivers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$ownDriver])
            ->assertCanNotSeeTableRecords([$foreignDriver]);
    })->with([
        'system administrator' => UserRole::SuperAdmin->value,
        'company administrator' => UserRole::Admin->value,
        'custom workforce viewer' => 'workforce-viewer',
    ]);

    test('denies listing employment without the required permission', function (): void {
        $company = createCompany();
        $actor = createUserWithRole('workforce-viewer', $company);
        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        actingAsInCompany($actor, $company);

        Livewire::test(ListDrivers::class)->assertForbidden();
    });

    test('denies employment administration to employee roles even with permissions', function (UserRole $role): void {
        $company = createCompany();
        $actor = createUserWithRole($role, $company);
        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);
        grantShield($actor, ['ViewAny:Driver', 'View:Driver', 'Update:Driver'], $company);

        actingAsInCompany($actor, $company);

        Livewire::test(ListDrivers::class)->assertForbidden();
    })->with([
        'driver' => UserRole::Driver,
        'passenger' => UserRole::Passenger,
    ]);

    test('does not resolve another company employment even for a system administrator', function (): void {
        [$company, $otherCompany] = createTenantPair();
        $foreignDriver = Driver::factory()->for($otherCompany)->create();

        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        Livewire::test(EditDriver::class, ['record' => $foreignDriver->getKey()])
            ->assertStatus(404);
    });

    test('rejects a forged lifecycle action without its permission', function (): void {
        $company = createCompany();
        $driver = Driver::factory()->for($company)->active()->create();
        $actor = createUserWithRole('workforce-viewer', $company);
        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);
        grantShield($actor, ['ViewAny:Driver', 'View:Driver'], $company);
        actingAsInCompany($actor, $company);

        Livewire::test(ListDrivers::class)
            ->call('mountAction', 'deactivate', [], [
                'table' => true,
                'recordKey' => $driver->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect($driver->fresh()->status->value)->toBe('active');
    });

    test('allows authorized historical viewing of archived employment', function (): void {
        $company = createCompany();
        $driver = Driver::factory()->for($company)->archived()->create();
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);
        grantShield($actor, ['ViewAny:Driver', 'View:Driver'], $company);
        actingAsInCompany($actor, $company);

        Livewire::test(ViewDriver::class, ['record' => $driver->getKey()])
            ->assertSuccessful();

        $this->assertSoftDeleted($driver);
    });

    test('does not load salary columns for ordinary employment reads', function (): void {
        $company = createCompany();
        $driver = Driver::factory()->for($company)->create(['salary_amount' => '987654.32']);
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);
        grantShield($actor, ['ViewAny:Driver', 'View:Driver'], $company);
        actingAsInCompany($actor, $company);

        $record = DriverResource::getEloquentQuery()->findOrFail($driver->getKey());

        expect($record->getAttributes())
            ->not->toHaveKeys(['salary_amount', 'salary_currency', 'salary_period']);

        Livewire::test(ViewDriver::class, ['record' => $driver->getKey()])
            ->assertDontSee('987654.32');
    });

    test('renders native record actions for an authorized workforce administrator', function (): void {
        $company = createCompany();
        $driver = Driver::factory()->for($company)->create();
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);
        grantShield($actor, ['ViewAny:Driver', 'View:Driver', 'Update:Driver'], $company);
        actingAsInCompany($actor, $company);

        Livewire::test(ListDrivers::class)
            ->assertActionVisible(TestAction::make('edit')->table($driver));
    });
});
