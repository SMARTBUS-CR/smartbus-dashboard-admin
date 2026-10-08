<?php

use App\Enums\UserRole;
use App\Filament\Resources\Stops\Pages\ListStops;
use App\Filament\Resources\Stops\StopResource;
use App\Models\CompanyUser;
use App\Models\Stop;
use Filament\Facades\Filament;
use Livewire\Livewire;

describe('Stop Resource Access', function (): void {
    test('lists company and shared stops while excluding foreign and archived stops', function (string $role): void {
        $company = createCompany();
        $otherCompany = createCompany();

        $companyStop = Stop::factory()->for($company)->create();

        $sharedStop = Stop::factory()->create([
            'company_id' => null,
        ]);

        $foreignStop = Stop::factory()->for($otherCompany)->create();

        $archivedStop = Stop::factory()->for($company)->create();
        $archivedStop->delete();

        $archivedSharedStop = Stop::factory()->create([
            'company_id' => null,
        ]);
        $archivedSharedStop->delete();

        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $company);

            CompanyUser::create([
                'company_id' => $company->getKey(),
                'user_id' => $actor->getKey(),
            ]);

            grantShield($actor, [
                'ViewAny:Stop',
                'View:Stop',
            ], $company);
        }

        actingAsInCompany($actor, $company);

        Livewire::test(ListStops::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([
                $companyStop,
                $sharedStop,
            ])
            ->assertCanNotSeeTableRecords([
                $foreignStop,
                $archivedStop,
                $archivedSharedStop,
            ]);
    })->with([
        'system admin' => [UserRole::SuperAdmin->value],
        'company admin' => [UserRole::Admin->value],
        'custom role' => ['stop-viewer'],
    ]);

    test('rejects access without permission to list stops', function (): void {
        $company = createCompany();
        $actor = createUserWithRole('stop-viewer', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['View:Stop'], $company);

        actingAsInCompany($actor, $company);

        Livewire::test(ListStops::class)
            ->assertForbidden();
    });

    test('returns no stops when no company is selected', function (): void {
        $company = createCompany();

        Stop::factory()->for($company)->create();
        Stop::factory()->create(['company_id' => null]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Filament::setTenant(null, isQuiet: true);

        expect(StopResource::getEloquentQuery()->exists())
            ->toBeFalse();
    });
});
