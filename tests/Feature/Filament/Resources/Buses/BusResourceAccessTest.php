<?php

use App\Enums\UserRole;
use App\Filament\Resources\Buses\Pages\EditBus;
use App\Filament\Resources\Buses\Pages\ListBuses;
use App\Models\Bus;
use App\Models\CompanyUser;
use Livewire\Livewire;

describe('Bus Resource Access', function (): void {
    test('shows only buses from the selected company to an authorized user', function (string $role): void {
        [$company, $otherCompany] = createTenantPair();
        $ownBus = Bus::factory()->for($company)->create();
        $foreignBus = Bus::factory()->for($otherCompany)->create();

        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $company);
            CompanyUser::create([
                'company_id' => $company->getKey(),
                'user_id' => $actor->getKey(),
            ]);
            grantShield($actor, ['ViewAny:Bus', 'View:Bus'], $company);
        }

        actingAsInCompany($actor, $company);

        Livewire::test(ListBuses::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$ownBus])
            ->assertCanNotSeeTableRecords([$foreignBus]);
    })->with([
        'system administrator' => UserRole::SuperAdmin->value,
        'company administrator' => UserRole::Admin->value,
        'custom fleet viewer' => 'fleet-viewer',
    ]);

    test('denies listing buses without the required permission', function (): void {
        $company = createCompany();
        $actor = createUserWithRole('fleet-viewer', $company);
        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        actingAsInCompany($actor, $company);

        Livewire::test(ListBuses::class)->assertForbidden();
    });

    test('denies fleet access to employee roles even when permissions are assigned', function (UserRole $role): void {
        $company = createCompany();
        $actor = createUserWithRole($role, $company);
        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);
        grantShield($actor, ['ViewAny:Bus', 'View:Bus', 'Update:Bus'], $company);

        actingAsInCompany($actor, $company);

        Livewire::test(ListBuses::class)->assertForbidden();
    })->with([
        'driver' => UserRole::Driver,
        'passenger' => UserRole::Passenger,
    ]);

    test('does not resolve another company bus even for a system administrator', function (): void {
        [$company, $otherCompany] = createTenantPair();
        $foreignBus = Bus::factory()->for($otherCompany)->create();

        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        Livewire::test(EditBus::class, ['record' => $foreignBus->getKey()])
            ->assertStatus(404);
    });
});
