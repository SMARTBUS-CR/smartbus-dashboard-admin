<?php

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\CompanyUser;
use App\Models\Role;
use Filament\Support\Colors\Color;
use Livewire\Livewire;

describe('Company Admin Resource Table', function (): void {
    test('renders roles without a stored color or display name without changing their data', function (): void {
        $company = createCompany();
        $user = createUserWithRole(UserRole::Admin, $company);
        setPermissionsTeamId($company->id);
        $role = $user->roles()->first();
        Role::whereKey($role->id)->update(['color' => null, 'display_name' => null]);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id]);
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        $component = Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$user]);
        $record = $component->instance()->getTableRecord($user->getKey());
        $column = $component->instance()->getTable()->getColumn('roles')->record($record);

        expect($column->formatState((string) $role->id))->toBe($role->name)
            ->and($column->getColor((string) $role->id))->toBeArray()
            ->and($role->fresh()->color)->toBeNull();
    });

    test('renders each assigned role with its own persisted color even when labels match', function (): void {
        $company = createCompany();
        $user = createUserWithRole(UserRole::Admin, $company);
        setPermissionsTeamId($company->id);
        $first = $user->roles()->first();
        $first->update(['display_name' => 'Operations']);
        $second = Role::create(['name' => 'dispatcher', 'display_name' => 'Operations', 'guard_name' => 'web', 'company_id' => $company->id]);
        setPermissionsTeamId($company->id);
        $user->assignRole($second);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id]);
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        $component = Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$user]);
        $record = $component->instance()->getTableRecord($user->getKey());
        $column = $component->instance()->getTable()->getColumn('roles')->record($record);

        expect($column->getState())->toHaveCount(2);
        foreach ([$first, $second] as $role) {
            $component->assertSee(Color::hex($role->fresh()->color)[600], escape: false);
            expect($column->formatState((string) $role->id))->toBe('Operations')
                ->and($column->getColor((string) $role->id))->toBe(Color::hex($role->fresh()->color));
        }
        expect($column->getColor((string) $first->id))->not->toBe($column->getColor((string) $second->id));
    });

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
