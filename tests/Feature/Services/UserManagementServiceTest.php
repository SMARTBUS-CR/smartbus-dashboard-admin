<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use App\Services\UserManagementService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

describe('Company Admin Creation', function (): void {
    beforeEach(function (): void {
        $this->mock(UncompromisedVerifier::class)
            ->shouldReceive('verify')
            ->andReturnTrue();
    });

    test('creates a verified admin in the active company', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($actor, $company);

        $user = app(UserManagementService::class)->createCompanyAdmin(
            $actor,
            [
                'name' => 'New Administrator',
                'email' => 'new-admin@example.test',
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
                'role' => 'admin',
            ],
        );

        $this->assertModelExists($user);

        expect($user->hasVerifiedEmail())->toBeTrue()
            ->and(Hash::check('N7v!qL2#rX9@kP4', $user->password))->toBeTrue()
            ->and(CompanyUser::query()
                ->where('company_id', $company->id)
                ->where('user_id', $user->id)->exists())->toBeTrue();

        $assignment = DB::connection('mysql')
            ->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_uuid', $user->id)
            ->where('assignments.model_type', $user->getMorphClass())
            ->select([
                'assignments.company_id as assignment_company',
                'roles.company_id as role_company',
                'roles.name',
                'roles.guard_name',
            ])
            ->sole();

        expect($assignment->assignment_company)->toBe($company->id)
            ->and($assignment->role_company)->toBe($company->id)
            ->and($assignment->name)->toBe('admin')
            ->and($assignment->guard_name)->toBe('web')
            ->and(CompanyUser::withTrashed()
                ->where('user_id', $actor->id)->exists())->toBeFalse();
    });

    test('rejects invalid or forged data without creating a user', function (array $overrides, string $field): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        $userCount = User::count();
        $membershipCount = CompanyUser::withTrashed()->count();

        $data = array_replace([
            'name' => 'New Administrator',
            'email' => 'new-admin@example.test',
            'password' => 'N7v!qL2#rX9@kP4',
            'password_confirmation' => 'N7v!qL2#rX9@kP4',
            'role' => 'admin',
        ], $overrides);

        try {
            app(UserManagementService::class)
                ->createCompanyAdmin($actor, $data);

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);
        }

        expect(User::count())->toBe($userCount)
            ->and(CompanyUser::withTrashed()->count())->toBe($membershipCount);
    })->with([
        'missing name' => [['name' => null], 'name'],
        'invalid email' => [['email' => 'invalid'], 'email'],
        'weak password' => [['password' => 'short'], 'password'],
        'confirmation mismatch' => [
            ['password_confirmation' => 'different'],
            'password',
        ],
        'super-admin assignment' => [['role' => 'super-admin'], 'role'],
        'forged company' => [['company_id' => 'another-company'], 'company_id'],
        'forged verification' => [
            ['email_verified_at' => '2026-10-03'],
            'email_verified_at',
        ],
        'forged roles array' => [['roles' => ['super-admin']], 'roles'],
    ]);

    test('rejects an email reserved by a soft deleted account', function (): void {
        $existing = User::factory()->create([
            'email' => 'reserved@example.test',
        ]);
        $existing->delete();

        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        expect(fn () => app(UserManagementService::class)
            ->createCompanyAdmin($actor, [
                'name' => 'New Administrator',
                'email' => 'reserved@example.test',
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
                'role' => 'admin',
            ]))
            ->toThrow(ValidationException::class)
            ->and(User::withTrashed()
                ->where('email', 'reserved@example.test')->count())->toBe(1);
    });

    test('requires creation permission from a company admin', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $actor->id,
        ]);

        actingAsInCompany($actor, $company);

        expect(fn () => app(UserManagementService::class)
            ->createCompanyAdmin($actor, [
                'name' => 'New Administrator',
                'email' => 'new-admin@example.test',
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
                'role' => 'admin',
            ]))
            ->toThrow(AuthorizationException::class)
            ->and(User::where('email', 'new-admin@example.test')->exists())->toBeFalse();
    });

    test('rolls back the user and role when membership creation fails', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        $usersBefore = User::count();
        $rolesBefore = DB::connection('mysql')
            ->table('model_has_roles')
            ->count();

        $originalDispatcher = CompanyUser::getEventDispatcher();
        $isolatedDispatcher = clone $originalDispatcher;

        $isolatedDispatcher->listen(
            'eloquent.creating: '.CompanyUser::class,
            function (): void {
                throw new RuntimeException('Simulated membership failure.');
            },
        );

        CompanyUser::setEventDispatcher($isolatedDispatcher);

        try {
            expect(fn () => app(UserManagementService::class)
                ->createCompanyAdmin($actor, [
                    'name' => 'New Administrator',
                    'email' => 'new-admin@example.test',
                    'password' => 'N7v!qL2#rX9@kP4',
                    'password_confirmation' => 'N7v!qL2#rX9@kP4',
                    'role' => 'admin',
                ]))
                ->toThrow(RuntimeException::class, 'Simulated membership failure.');
        } finally {
            CompanyUser::setEventDispatcher($originalDispatcher);
        }

        expect(User::count())->toBe($usersBefore)
            ->and(DB::connection('mysql')->table('model_has_roles')->count())->toBe($rolesBefore)
            ->and(CompanyUser::withTrashed()
                ->where('user_id', '!=', $actor->id)->exists())->toBeFalse();
    });

    test('allows an authorized company admin to create another admin', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $actor->id,
        ]);

        grantShield($actor, ['Create:User'], $company);
        actingAsInCompany($actor, $company);

        $user = app(UserManagementService::class)->createCompanyAdmin(
            $actor,
            [
                'name' => 'New Administrator',
                'email' => 'authorized-admin@example.test',
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
                'role' => 'admin',
            ],
        );

        $this->assertModelExists($user);

        expect(
            CompanyUser::query()
                ->where('company_id', $company->id)
                ->where('user_id', $user->id)
                ->exists()
        )->toBeTrue()
            ->and($user->hasRole(UserRole::Admin->value))->toBeTrue()
            ->and(getPermissionsTeamId())->toBe($company->id);
    });

    test('requires a selected company even for a super-admin', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);

        $this->actingAs($actor, 'web');
        Filament::setCurrentPanel('admin');
        Filament::setTenant(null, isQuiet: true);
        setPermissionsTeamId(null);

        $userCount = User::count();

        try {
            app(UserManagementService::class)->createCompanyAdmin(
                $actor,
                [
                    'name' => 'New Administrator',
                    'email' => 'without-company@example.test',
                    'password' => 'N7v!qL2#rX9@kP4',
                    'password_confirmation' => 'N7v!qL2#rX9@kP4',
                    'role' => 'admin',
                ],
            );

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('company');
        }

        expect(User::count())->toBe($userCount)
            ->and(CompanyUser::query()->exists())->toBeFalse()
            ->and(getPermissionsTeamId())->toBeNull();
    });

    test('rejects a compromised password and restores the previous team', function (): void {
        [$company, $previousCompany] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($actor, $company);
        setPermissionsTeamId($previousCompany->id);

        $this->mock(UncompromisedVerifier::class)
            ->shouldReceive('verify')
            ->once()
            ->andReturnFalse();

        $userCount = User::count();

        try {
            app(UserManagementService::class)->createCompanyAdmin(
                $actor,
                [
                    'name' => 'New Administrator',
                    'email' => 'compromised-password@example.test',
                    'password' => 'N7v!qL2#rX9@kP4',
                    'password_confirmation' => 'N7v!qL2#rX9@kP4',
                    'role' => 'admin',
                ],
            );

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('password');
        }

        expect(User::count())->toBe($userCount)
            ->and(CompanyUser::query()->exists())->toBeFalse()
            ->and(getPermissionsTeamId())->toBe($previousCompany->id);
    });
});

describe('Company Admin Update', function (): void {
    beforeEach(function (): void {
        $this->mock(UncompromisedVerifier::class)
            ->shouldReceive('verify')
            ->andReturnTrue();
    });

    test('updates the profile without changing a blank password or other company assignments', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $target = createUserWithRole(UserRole::Admin, $company);

        foreach ([$company, $otherCompany] as $membershipCompany) {
            CompanyUser::create([
                'company_id' => $membershipCompany->id,
                'user_id' => $target->id,
            ]);
        }

        setPermissionsTeamId($otherCompany->id);

        $otherRole = Role::withoutGlobalScopes()
            ->where('company_id', $otherCompany->id)
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->sole();

        $target->assignRole($otherRole);

        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        $originalPassword = $target->password;

        $assignmentsBefore = DB::connection('mysql')
            ->table('model_has_roles')
            ->where('model_uuid', $target->id)
            ->orderBy('role_id')
            ->get()
            ->toArray();

        app(UserManagementService::class)->updateCompanyAdmin(
            $actor,
            $target,
            [
                'name' => 'Updated Administrator',
                'email' => 'updated-admin@example.test',
                'role' => 'admin',
                'password' => '',
                'password_confirmation' => '',
            ],
        );

        $target->refresh();

        expect($target->name)->toBe('Updated Administrator')
            ->and($target->email)->toBe('updated-admin@example.test')
            ->and($target->password)->toBe($originalPassword)
            ->and($target->hasVerifiedEmail())->toBeTrue()
            ->and(DB::connection('mysql')
                ->table('model_has_roles')
                ->where('model_uuid', $target->id)
                ->orderBy('role_id')
                ->get()->toArray())->toEqual($assignmentsBefore)
            ->and(CompanyUser::query()->where('user_id', $target->id)->count())->toBe(2);
    });

    test('revokes only the target users tokens when changing the password', function (): void {
        $company = createCompany();
        $target = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $target->id,
        ]);

        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        foreach ([$target, $actor] as $user) {
            DB::connection('mysql')->table('personal_access_tokens')->insert([
                'tokenable_type' => $user->getMorphClass(),
                'tokenable_id' => $user->id,
                'name' => 'test-device',
                'token' => hash('sha256', $user->id),
                'abilities' => '["*"]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(UserManagementService::class)->updateCompanyAdmin(
            $actor,
            $target,
            [
                'name' => $target->name,
                'email' => $target->email,
                'role' => 'admin',
                'password' => 'X9z!mK4#pQ7@vL2',
                'password_confirmation' => 'X9z!mK4#pQ7@vL2',
            ],
        );

        expect(Hash::check(
            'X9z!mK4#pQ7@vL2',
            $target->fresh()->password,
        ))->toBeTrue();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => $target->getMorphClass(),
            'tokenable_id' => $target->id,
        ], 'mysql');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => $actor->getMorphClass(),
            'tokenable_id' => $actor->id,
        ], 'mysql');
    });

    test('rejects a user from another company without changing the profile', function (): void {
        [$company, $foreign] = createTenantPair();

        $target = createUserWithRole(UserRole::Admin, $foreign);

        CompanyUser::create([
            'company_id' => $foreign->id,
            'user_id' => $target->id,
        ]);

        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        $originalName = $target->name;

        expect(fn () => app(UserManagementService::class)
            ->updateCompanyAdmin($actor, $target, [
                'name' => 'Unauthorized Change',
                'email' => $target->email,
                'role' => 'admin',
            ]))
            ->toThrow(AuthorizationException::class)
            ->and($target->fresh()->name)->toBe($originalName);
    });

    test('rejects a super-admin role assignment without changing the account', function (): void {
        $company = createCompany();
        $target = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $target->id,
        ]);

        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        $originalName = $target->name;

        expect(fn () => app(UserManagementService::class)
            ->updateCompanyAdmin($actor, $target, [
                'name' => 'Unauthorized Promotion',
                'email' => $target->email,
                'role' => 'super-admin',
            ]))
            ->toThrow(ValidationException::class)
            ->and($target->fresh()->name)->toBe($originalName)
            ->and($target->fresh()->isSuperAdmin())->toBeFalse();
    });
});

describe('Company Access Removal', function (): void {
    test('removes company access while preserving the account and other company assignments', function (): void {
        [$company, $otherCompany] = createTenantPair();

        $target = createUserWithRole(UserRole::Admin, $company);
        $remainingAdmin = createUserWithRole(UserRole::Admin, $company);

        $membership = CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $target->id,
        ]);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $remainingAdmin->id,
        ]);

        $otherMembership = CompanyUser::create([
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

        app(UserManagementService::class)
            ->removeCompanyAccess($actor, $target);

        expect($membership->refresh()->trashed())->toBeTrue()
            ->and($target->refresh()->trashed())->toBeFalse()
            ->and($otherMembership->refresh()->trashed())->toBeFalse();

        $this->assertDatabaseMissing('model_has_roles', [
            'model_type' => $target->getMorphClass(),
            'model_uuid' => $target->id,
            'company_id' => $company->id,
        ], 'mysql');

        expect(
            DB::connection('mysql')
                ->table('model_has_roles')
                ->where('model_type', $target->getMorphClass())
                ->where('model_uuid', $target->id)
                ->where('company_id', $otherCompany->id)
                ->orderBy('role_id')
                ->get()
                ->toArray()
        )->toEqual($otherAssignmentsBefore)
            ->and(getPermissionsTeamId())->toBe($company->id);
    });

    test('rejects removing the last administrator of a company', function (): void {
        $company = createCompany();
        $target = createUserWithRole(UserRole::Admin, $company);

        $membership = CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $target->id,
        ]);

        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        expect(
            fn () => app(UserManagementService::class)
                ->removeCompanyAccess($actor, $target)
        )->toThrow(ValidationException::class)
            ->and($membership->refresh()->trashed())->toBeFalse()
            ->and($target->refresh()->trashed())->toBeFalse();

        $this->assertDatabaseHas('model_has_roles', [
            'model_type' => $target->getMorphClass(),
            'model_uuid' => $target->id,
            'company_id' => $company->id,
        ], 'mysql');

        expect(getPermissionsTeamId())->toBe($company->id);
    });

    test('rejects removal when the remaining administrator cannot access the company', function (string $condition): void {
        $company = createCompany();
        $target = createUserWithRole(UserRole::Admin, $company);
        $backup = createUserWithRole(UserRole::Admin, $company);

        $membership = CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $target->id,
        ]);

        $backupMembership = CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $backup->id,
        ]);

        match ($condition) {
            'deleted account' => $backup->delete(),
            'deleted membership' => $backupMembership->delete(),
            'unverified email' => $backup->forceFill([
                'email_verified_at' => null,
            ])->save(),
        };

        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        expect(
            fn () => app(UserManagementService::class)
                ->removeCompanyAccess($actor, $target)
        )->toThrow(ValidationException::class)
            ->and($membership->refresh()->trashed())->toBeFalse();

        $this->assertDatabaseHas('model_has_roles', [
            'model_type' => $target->getMorphClass(),
            'model_uuid' => $target->id,
            'company_id' => $company->id,
        ], 'mysql');
    })->with([
        'deleted account',
        'deleted membership',
        'unverified email',
    ]);

    test('rolls back membership removal when a deletion observer fails', function (): void {
        $company = createCompany();
        $target = createUserWithRole(UserRole::Admin, $company);
        $backup = createUserWithRole(UserRole::Admin, $company);

        $membership = CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $target->id,
        ]);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $backup->id,
        ]);

        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        $originalDispatcher = CompanyUser::getEventDispatcher();
        $isolatedDispatcher = clone $originalDispatcher;

        $isolatedDispatcher->listen(
            'eloquent.deleted: '.CompanyUser::class,
            function (): void {
                throw new RuntimeException('Simulated removal failure.');
            },
        );

        CompanyUser::setEventDispatcher($isolatedDispatcher);

        try {
            expect(
                fn () => app(UserManagementService::class)
                    ->removeCompanyAccess($actor, $target)
            )->toThrow(RuntimeException::class, 'Simulated removal failure.');
        } finally {
            CompanyUser::setEventDispatcher($originalDispatcher);
        }

        expect($membership->refresh()->trashed())->toBeFalse()
            ->and($target->refresh()->trashed())->toBeFalse();

        $this->assertDatabaseHas('model_has_roles', [
            'model_type' => $target->getMorphClass(),
            'model_uuid' => $target->id,
            'company_id' => $company->id,
        ], 'mysql');

        expect(getPermissionsTeamId())->toBe($company->id);
    });

    test('completes membership removal when an exception occurs after mysql commits', function (): void {
        $company = createCompany();
        $target = createUserWithRole(UserRole::Admin, $company);
        $backup = createUserWithRole(UserRole::Admin, $company);

        $membership = CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $target->id,
        ]);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $backup->id,
        ]);

        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, $company);

        $mysql = DB::connection('mysql');
        $originalDispatcher = $mysql->getEventDispatcher();
        $isolatedDispatcher = clone $originalDispatcher;

        $failureTriggered = false;

        $isolatedDispatcher->listen(
            TransactionCommitted::class,
            function (TransactionCommitted $event) use (&$failureTriggered, $mysql): void {
                if ($event->connection !== $mysql || $failureTriggered) {
                    return;
                }

                $failureTriggered = true;

                throw new RuntimeException(
                    'Simulated failure after MySQL commit.'
                );
            },
        );

        $mysql->setEventDispatcher($isolatedDispatcher);

        try {
            app(UserManagementService::class)
                ->removeCompanyAccess($actor, $target);
        } finally {
            $mysql->setEventDispatcher($originalDispatcher);
        }

        expect($failureTriggered)->toBeTrue()
            ->and($membership->refresh()->trashed())->toBeTrue()
            ->and($target->refresh()->trashed())->toBeFalse();

        $this->assertDatabaseMissing('model_has_roles', [
            'model_type' => $target->getMorphClass(),
            'model_uuid' => $target->id,
            'company_id' => $company->id,
        ], 'mysql');

        $this->assertDatabaseHas('model_has_roles', [
            'model_type' => $backup->getMorphClass(),
            'model_uuid' => $backup->id,
            'company_id' => $company->id,
        ], 'mysql');

        expect(getPermissionsTeamId())->toBe($company->id);
    });
});
