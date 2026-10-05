<?php

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

describe('Company User Resource Creation', function (): void {
    beforeEach(function (): void {
        $this->mock(UncompromisedVerifier::class)
            ->shouldReceive('verify')
            ->andReturnTrue();
    });

    test('creates an admin through the form', function (): void {
        $company = createCompany();
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Administrator',
                'email' => 'form-admin@example.test',
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
                'roles' => companyRoleIds($company),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $user = User::where('email', 'form-admin@example.test')->sole();

        expect($user->name)->toBe('New Administrator')
            ->and($user->hasVerifiedEmail())->toBeTrue()
            ->and(Hash::check('N7v!qL2#rX9@kP4', $user->password))->toBeTrue()
            ->and($user->hasRole('admin'))->toBeTrue()
            ->and(CompanyUser::query()
                ->where('company_id', $company->id)
                ->where('user_id', $user->id)->exists())->toBeTrue();
    });

    test('rejects missing required fields', function (): void {
        $company = createCompany();
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        $usersBefore = User::count();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => null,
                'email' => null,
                'password' => null,
                'password_confirmation' => null,
                'roles' => [],
            ])
            ->call('create')
            ->assertHasFormErrors([
                'name' => 'required',
                'email' => 'required',
                'password' => 'required',
                'password_confirmation' => 'required',
                'roles' => 'required',
            ]);

        expect(User::count())->toBe($usersBefore);
    });

    test('shows password validation errors returned by the service', function (): void {
        $company = createCompany();
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        $usersBefore = User::count();

        $this->mock(UncompromisedVerifier::class)
            ->shouldReceive('verify')
            ->andReturnFalse();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Administrator',
                'email' => 'rejected-admin@example.test',
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
                'roles' => companyRoleIds($company),
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        expect(User::count())->toBe($usersBefore);
    });

    test('rejects an email reserved by a deleted account', function (): void {
        $existing = User::factory()->create([
            'email' => 'reserved@example.test',
        ]);
        $existing->delete();

        $company = createCompany();
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Administrator',
                'email' => 'reserved@example.test',
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
                'roles' => companyRoleIds($company),
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);

        expect(
            User::withTrashed()
                ->where('email', 'reserved@example.test')
                ->count()
        )->toBe(1);
    });

    test('offers multiple permitted roles from the current company only', function (): void {
        [$company, $foreign] = createTenantPair();

        $dispatcher = Role::withoutEvents(
            fn (): Role => Role::withoutGlobalScopes()->create([
                'name' => 'dispatcher',
                'display_name' => 'Dispatcher',
                'guard_name' => 'web',
                'company_id' => $company->id,
            ])
        );

        foreach ([
            ['name' => 'dispatcher', 'company_id' => $foreign->id, 'guard_name' => 'web'],
            ['name' => 'dispatcher', 'company_id' => null, 'guard_name' => 'web'],
            ['name' => 'passenger', 'company_id' => $company->id, 'guard_name' => 'web'],
            ['name' => 'dispatcher', 'company_id' => $company->id, 'guard_name' => 'api'],
        ] as $attributes) {
            Role::withoutEvents(
                fn (): Role => Role::withoutGlobalScopes()->create($attributes)
            );
        }

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        $expectedIds = companyRoleIds($company, ['admin', 'dispatcher']);

        Livewire::test(CreateUser::class)
            ->assertFormFieldExists(
                'roles',
                function (Select $field) use ($expectedIds): bool {
                    expect($field->isMultiple())->toBeTrue()
                        ->and(array_keys($field->getOptions()))->toEqualCanonicalizing($expectedIds);

                    return true;
                },
            );
    });
});
