<?php

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * Create an admin with an active membership in the company.
 *
 * @param  array<string, mixed>  $attributes
 */
function createManagedAdmin(Company $company, array $attributes = []): User
{
    $admin = createUserWithRole(UserRole::Admin, $company);

    $admin->forceFill($attributes)->save();

    CompanyUser::create([
        'company_id' => $company->id,
        'user_id' => $admin->id,
    ]);

    return $admin;
}

describe('Company User Resource Update', function (): void {
    beforeEach(function (): void {
        $this->mock(UncompromisedVerifier::class)
            ->shouldReceive('verify')
            ->andReturnTrue();
    });

    test('loads name, email and role but leaves both passwords empty', function (): void {
        $company = createCompany();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        $target = createManagedAdmin($company, [
            'name' => 'Administrador Original',
            'email' => 'original@example.com',
        ]);

        $dispatcher = Role::create([
            'name' => 'dispatcher',
            'display_name' => 'Dispatcher',
            'guard_name' => 'web',
            'company_id' => $company->id,
        ]);

        $target->assignRole($dispatcher);

        Livewire::test(EditUser::class, [
            'record' => $target->getRouteKey(),
        ])
            ->assertSchemaStateSet([
                'name' => 'Administrador Original',
                'email' => 'original@example.com',
                'roles' => companyRoleIds($company, ['admin', 'dispatcher']),
                'password' => null,
                'password_confirmation' => null,
            ]);
    });

    test('keeps the existing password hash when saving without a password', function (): void {
        $company = createCompany();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        $target = createManagedAdmin($company);
        $originalPassword = $target->password;

        Livewire::test(EditUser::class, [
            'record' => $target->getRouteKey(),
        ])
            ->fillForm([
                'name' => 'Administrador Actualizado',
                'email' => 'actualizado@example.com',
                'roles' => companyRoleIds($company),
                'password' => '',
                'password_confirmation' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $target->refresh();

        expect($target->name)->toBe('Administrador Actualizado')
            ->and($target->email)->toBe('actualizado@example.com')
            ->and($target->password)->toBe($originalPassword)
            ->and($target->email_verified_at)->not->toBeNull();
    });

    test('changes the password hash when a new password is provided', function (): void {
        $company = createCompany();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        $target = createManagedAdmin($company);
        $originalPassword = $target->password;

        Livewire::test(EditUser::class, [
            'record' => $target->getRouteKey(),
        ])
            ->fillForm([
                'name' => $target->name,
                'email' => $target->email,
                'roles' => companyRoleIds($company),
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $target->refresh();

        expect($target->password)->not->toBe($originalPassword)
            ->and(Hash::check('N7v!qL2#rX9@kP4', $target->password))->toBeTrue();
    });

    test('rejects an email that belongs to another account', function (bool $deleted): void {
        $reserved = User::factory()->create(['email' => 'reserved@example.test']);

        if ($deleted) {
            $reserved->delete();
        }

        $company = createCompany();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        $target = createManagedAdmin($company, ['email' => 'original@example.test']);

        Livewire::test(EditUser::class, [
            'record' => $target->getRouteKey(),
        ])
            ->fillForm([
                'name' => $target->name,
                'email' => 'reserved@example.test',
                'roles' => companyRoleIds($company),
                'password' => '',
                'password_confirmation' => '',
            ])
            ->call('save')
            ->assertHasFormErrors(['email']);

        expect($target->refresh()->email)->toBe('original@example.test');
    })->with([
        'active account' => [false],
        'deleted account' => [true],
    ]);

    test('rejects a mismatched password confirmation and keeps the original data', function (): void {
        $company = createCompany();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        $target = createManagedAdmin($company, [
            'name' => 'Administrador Original',
            'email' => 'original@example.test',
        ]);
        $originalPassword = $target->password;

        Livewire::test(EditUser::class, [
            'record' => $target->getRouteKey(),
        ])
            ->fillForm([
                'name' => 'Nombre Rechazado',
                'email' => 'rechazado@example.test',
                'roles' => companyRoleIds($company),
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'X9z!mK4#pQ7@vL2',
            ])
            ->call('save')
            ->assertHasFormErrors(['password' => 'confirmed']);

        $target->refresh();

        expect($target->name)->toBe('Administrador Original')
            ->and($target->email)->toBe('original@example.test')
            ->and($target->password)->toBe($originalPassword);
    });

    test('rejects a compromised password and keeps the original data', function (): void {
        $company = createCompany();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        $target = createManagedAdmin($company, [
            'name' => 'Administrador Original',
            'email' => 'original@example.test',
        ]);
        $originalPassword = $target->password;

        $this->mock(UncompromisedVerifier::class)
            ->shouldReceive('verify')
            ->andReturnFalse();

        Livewire::test(EditUser::class, [
            'record' => $target->getRouteKey(),
        ])
            ->fillForm([
                'name' => 'Nombre Rechazado',
                'email' => 'rechazado@example.test',
                'roles' => companyRoleIds($company),
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
            ])
            ->call('save')
            ->assertHasFormErrors(['password']);

        $target->refresh();

        expect($target->name)->toBe('Administrador Original')
            ->and($target->email)->toBe('original@example.test')
            ->and($target->password)->toBe($originalPassword);
    });
});
