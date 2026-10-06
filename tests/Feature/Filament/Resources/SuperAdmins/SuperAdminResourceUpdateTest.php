<?php

use App\Enums\UserRole;
use App\Filament\Resources\SuperAdmins\Pages\EditSuperAdmin;
use App\Models\CompanyUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Livewire\Livewire;

describe('Global SuperAdmin Resource Update', function (): void {
    beforeEach(function (): void {
        $this->mock(UncompromisedVerifier::class)
            ->shouldReceive('verify')
            ->andReturnTrue();

        Filament::setCurrentPanel('admin');
        Filament::setTenant(null, isQuiet: true);
        setPermissionsTeamId(null);
    });

    test('loads the profile while leaving both password fields empty', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($actor, createCompany());

        Livewire::test(EditSuperAdmin::class, [
            'record' => $target->getRouteKey(),
        ])
            ->assertSchemaStateSet([
                'name' => $target->name,
                'email' => $target->email,
                'password' => null,
                'password_confirmation' => null,
            ]);
    });

    test('updates the profile and approves the new email without changing a blank password', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);

        $target->forceFill([
            'email_verified_at' => null,
        ])->save();

        $originalPassword = $target->password;

        $company = createCompany();
        actingAsInCompany($actor, $company);

        $membershipsBefore = CompanyUser::withTrashed()->count();

        Livewire::test(EditSuperAdmin::class, [
            'record' => $target->getRouteKey(),
        ])
            ->fillForm([
                'name' => 'Updated Global Administrator',
                'email' => 'edited-super-admin@example.test',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $target->refresh();

        expect($target->name)->toBe('Updated Global Administrator')
            ->and($target->email)->toBe('edited-super-admin@example.test')
            ->and($target->hasVerifiedEmail())->toBeTrue()
            ->and($target->password)->toBe($originalPassword)
            ->and(getPermissionsTeamId())->toBe($company->getKey())
            ->and(CompanyUser::withTrashed()->count())->toBe($membershipsBefore)
            ->and(CompanyUser::withTrashed()
                ->where('user_id', $target->getKey())->exists())->toBeFalse();
    });

    test('shows a reserved email error while preserving the account', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);

        $reserved = User::factory()->create([
            'email' => 'reserved-form-global@example.test',
        ]);
        $reserved->delete();

        actingAsInCompany($actor, createCompany());

        $target->refresh();
        $original = $target->getRawOriginal();

        Livewire::test(EditSuperAdmin::class, [
            'record' => $target->getRouteKey(),
        ])
            ->fillForm([
                'name' => 'Rejected Update',
                'email' => 'reserved-form-global@example.test',
                'password' => 'X9z!mK4#pQ7@vL2',
                'password_confirmation' => 'X9z!mK4#pQ7@vL2',
            ])
            ->call('save')
            ->assertHasFormErrors([
                'email' => __('validation.unique', [
                    'attribute' => __('validation.attributes.email'),
                ]),
            ])
            ->assertNotNotified();

        $target->refresh();

        expect($target->getRawOriginal())->toBe($original)
            ->and(CompanyUser::withTrashed()
                ->where('user_id', $target->getKey())->exists())->toBeFalse();
    });
});
