<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

describe('Company User Role Assignment', function (): void {
    test('prevents delegated user managers from granting administrator access', function (): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();
        $company = createCompany();
        $actor = User::factory()->create();
        setPermissionsTeamId($company->id);
        $role = Role::create(['name' => 'user-manager', 'guard_name' => 'web', 'company_id' => $company->id]);
        $actor->assignRole($role);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        grantShield($actor, ['Create:User'], $company);
        actingAsInCompany($actor, $company);
        $adminRole = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'admin')->sole();

        expect(fn () => app(UserManagementService::class)->createCompanyUser($actor, [
            'name' => 'Unauthorized Admin', 'email' => 'escalation@example.test',
            'password' => 'N7v!qL2#rX9@kP4', 'password_confirmation' => 'N7v!qL2#rX9@kP4',
            'roles' => [$adminRole->id],
        ]))->toThrow(AuthorizationException::class);
        expect(User::count())->toBe(1);
    });

    beforeEach(function (): void {
        $this->mock(UncompromisedVerifier::class)
            ->shouldReceive('verify')
            ->andReturnTrue();
    });

    test('creates a verified company user with multiple selected roles', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        $adminRole = Role::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('name', UserRole::Admin->value)
            ->where('guard_name', 'web')
            ->sole();

        $dispatcherRole = Role::create([
            'name' => 'dispatcher',
            'display_name' => 'Dispatcher',
            'guard_name' => 'web',
            'company_id' => $company->id,
        ]);

        $user = app(UserManagementService::class)->createCompanyUser(
            $actor,
            [
                'name' => 'Company Operator',
                'email' => 'operator@example.test',
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
                'roles' => [$adminRole->id, $dispatcherRole->id],
            ],
        );

        expect($user->hasVerifiedEmail())->toBeTrue();

        $assignedRoleIds = DB::connection('mysql')
            ->table('model_has_roles')
            ->where('model_type', $user->getMorphClass())
            ->where('model_uuid', $user->id)
            ->where('company_id', $company->id)
            ->pluck('role_id')
            ->all();

        expect($assignedRoleIds)->toEqualCanonicalizing([
            $adminRole->id,
            $dispatcherRole->id,
        ])
            ->and(CompanyUser::query()
                ->where('company_id', $company->id)
                ->where('user_id', $user->id)->exists())->toBeTrue()
            ->and(CompanyUser::withTrashed()
                ->where('user_id', $actor->id)->exists())->toBeFalse();
    });

    test('rejects roles outside the permitted company scope without creating a user', function (string $roleName, string $scope, string $guard): void {
        [$company, $foreign] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        $roleCompanyId = match ($scope) {
            'current' => $company->id,
            'foreign' => $foreign->id,
            'global' => null,
        };

        $role = Role::withoutEvents(
            fn (): Role => Role::withoutGlobalScopes()->firstOrCreate([
                'name' => $roleName,
                'guard_name' => $guard,
                'company_id' => $roleCompanyId,
            ])
        );

        expect($role->refresh()->company_id)->toBe($roleCompanyId);

        $usersBefore = User::withTrashed()->count();

        try {
            app(UserManagementService::class)->createCompanyUser(
                $actor,
                [
                    'name' => 'Rejected Operator',
                    'email' => 'rejected@example.test',
                    'password' => 'N7v!qL2#rX9@kP4',
                    'password_confirmation' => 'N7v!qL2#rX9@kP4',
                    'roles' => [$role->id],
                ],
            );

            $this->fail('Expected role validation to reject the request.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('roles.0');
        }

        expect(User::withTrashed()->count())->toBe($usersBefore)
            ->and(CompanyUser::withTrashed()->exists())->toBeFalse();
    })->with([
        'driver' => ['driver', 'current', 'web'],
        'passenger' => ['passenger', 'current', 'web'],
        'global super-admin' => ['super-admin', 'global', 'web'],
        'foreign company role' => ['dispatcher', 'foreign', 'web'],
        'global custom role' => ['dispatcher', 'global', 'web'],
        'different guard' => ['dispatcher', 'current', 'api'],
    ]);

    test('replaces current company roles while preserving other company assignments', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $target = createUserWithRole(UserRole::Admin, $company);
        $backup = createUserWithRole(UserRole::Admin, $company);

        foreach ([$target, $backup] as $user) {
            CompanyUser::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
            ]);
        }

        CompanyUser::create([
            'company_id' => $otherCompany->id,
            'user_id' => $target->id,
        ]);

        setPermissionsTeamId($otherCompany->id);

        $otherRole = Role::withoutGlobalScopes()
            ->where('company_id', $otherCompany->id)
            ->where('name', UserRole::Admin->value)
            ->where('guard_name', 'web')
            ->sole();

        $target->assignRole($otherRole);

        $otherAssignmentsBefore = DB::connection('mysql')
            ->table('model_has_roles')
            ->where('model_type', $target->getMorphClass())
            ->where('model_uuid', $target->id)
            ->where('company_id', $otherCompany->id)
            ->orderBy('role_id')
            ->get()
            ->toArray();

        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        $selectedRoles = collect(['dispatcher', 'auditor'])
            ->map(fn (string $name): Role => Role::create([
                'name' => $name,
                'guard_name' => 'web',
                'company_id' => $company->id,
            ]));

        $selectedIds = $selectedRoles->pluck('id')->all();

        app(UserManagementService::class)->updateCompanyUser(
            $actor,
            $target,
            [
                'name' => 'Updated Operator',
                'email' => $target->email,
                'roles' => $selectedIds,
            ],
        );

        $currentIds = DB::connection('mysql')
            ->table('model_has_roles')
            ->where('model_type', $target->getMorphClass())
            ->where('model_uuid', $target->id)
            ->where('company_id', $company->id)
            ->pluck('role_id')
            ->all();

        expect($currentIds)->toEqualCanonicalizing($selectedIds)
            ->and(DB::connection('mysql')
                ->table('model_has_roles')
                ->where('model_type', $target->getMorphClass())
                ->where('model_uuid', $target->id)
                ->where('company_id', $otherCompany->id)
                ->orderBy('role_id')
                ->get()->toArray())->toEqual($otherAssignmentsBefore)
            ->and($target->refresh()->name)->toBe('Updated Operator')
            ->and(CompanyUser::query()->where('user_id', $target->id)->count())->toBe(2);
    });

    test('rejects removing the admin role from the last company administrator', function (): void {
        $company = createCompany();
        $target = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $target->id,
        ]);

        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        $dispatcher = Role::create([
            'name' => 'dispatcher',
            'guard_name' => 'web',
            'company_id' => $company->id,
        ]);

        $originalName = $target->name;
        $adminIds = companyRoleIds($company);

        try {
            app(UserManagementService::class)->updateCompanyUser(
                $actor,
                $target,
                [
                    'name' => 'Rejected Change',
                    'email' => $target->email,
                    'roles' => [$dispatcher->id],
                ],
            );

            $this->fail('Expected the last administrator protection.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('roles')
                ->and($exception->errors()['roles'])->toContain(__(
                    __('The company must retain at least one active administrator with a verified email.')
                ));
        }

        expect($target->refresh()->name)->toBe($originalName);

        $assignedIds = DB::connection('mysql')
            ->table('model_has_roles')
            ->where('model_type', $target->getMorphClass())
            ->where('model_uuid', $target->id)
            ->where('company_id', $company->id)
            ->pluck('role_id')
            ->all();

        expect($assignedIds)->toEqualCanonicalizing($adminIds);
    });
});
