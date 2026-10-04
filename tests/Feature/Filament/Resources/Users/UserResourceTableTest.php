<?php

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\CompanyUser;
use Livewire\Livewire;

describe('Company Admin Resource Table', function (): void {
    test('shows the administrators name and email', function (): void {
        $company = createCompany();

        $admin = createUserWithRole(UserRole::Admin, $company);
        $admin->update([
            'name' => 'Administrador Principal',
            'email' => 'principal@example.test',
        ]);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$admin])
            ->assertCanRenderTableColumn('name')
            ->assertCanRenderTableColumn('email')
            ->assertTableColumnStateSet(
                'name',
                'Administrador Principal',
                $admin,
            )
            ->assertTableColumnStateSet(
                'email',
                'principal@example.test',
                $admin,
            );
    });

    test('searches administrators by name or email', function (string $search): void {
        $company = createCompany();

        $matching = createUserWithRole(UserRole::Admin, $company);
        $matching->update([
            'name' => 'Ana Administradora',
            'email' => 'ana@example.test',
        ]);

        $other = createUserWithRole(UserRole::Admin, $company);
        $other->update([
            'name' => 'Carlos Encargado',
            'email' => 'carlos@example.test',
        ]);

        foreach ([$matching, $other] as $user) {
            CompanyUser::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
            ]);
        }

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(ListUsers::class)
            ->searchTable($search)
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    })->with([
                'name' => ['Ana Administradora'],
                'email' => ['ana@example.test'],
            ]);
});