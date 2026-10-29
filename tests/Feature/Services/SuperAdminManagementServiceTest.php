<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\UserManagementService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;

describe('Global SuperAdmin Creation', function (): void {
    beforeEach(function (): void {
        $this->mock(UncompromisedVerifier::class)
            ->shouldReceive('verify')
            ->andReturnTrue();
    });

    test('creates a verified global super-admin without company membership', function (bool $withTenant): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);

        if ($withTenant) {
            actingAsInCompany($actor, createCompany());
        } else {
            $this->actingAs($actor, 'web');

            Filament::setCurrentPanel('admin');
            Filament::setTenant(null, isQuiet: true);
            setPermissionsTeamId(null);
        }

        $previousTeam = getPermissionsTeamId();
        $membershipsBefore = CompanyUser::withTrashed()->count();

        $created = app(UserManagementService::class)->createSuperAdmin(
            $actor,
            [
                'name' => 'Global Administrator',
                'email' => 'global-admin@example.test',
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
            ],
        );

        $persisted = User::where('email', 'global-admin@example.test')->sole();

        expect($persisted->getKey())->toBe($created->getKey())
            ->and($persisted->name)->toBe('Global Administrator')
            ->and($persisted->hasVerifiedEmail())->toBeTrue()
            ->and(Hash::check('N7v!qL2#rX9@kP4', $persisted->password))
            ->toBeTrue()
            ->and(getPermissionsTeamId())->toBe($previousTeam)
            ->and(CompanyUser::withTrashed()->count())->toBe($membershipsBefore)
            ->and(CompanyUser::withTrashed()
                ->where('user_id', $persisted->getKey())->exists())->toBeFalse();

        $assignments = DB::connection('mysql')
            ->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_type', $persisted->getMorphClass())
            ->where('assignments.model_uuid', $persisted->getKey())
            ->select([
                'assignments.company_id as assignment_company_id',
                'roles.company_id as role_company_id',
                'roles.name',
                'roles.guard_name',
            ])
            ->get();

        expect($assignments)->toHaveCount(1);

        $assignment = $assignments->sole();

        expect($assignment->name)->toBe(UserRole::SuperAdmin->value)
            ->and($assignment->guard_name)->toBe('web')
            ->and($assignment->assignment_company_id)->toBeNull()
            ->and($assignment->role_company_id)->toBeNull();
    })->with([
        'without a selected company' => [false],
        'with a selected company' => [true],
    ]);

    test('rejects a company admin even with user creation permission', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        actingAsInCompany($actor, $company);

        grantShield($actor, ['Create:User'], $company);

        $usersBefore = User::withTrashed()->count();
        $membershipsBefore = CompanyUser::withTrashed()->count();
        $assignmentsBefore = DB::connection('mysql')
            ->table('model_has_roles')
            ->count();

        expect(fn () => app(UserManagementService::class)->createSuperAdmin(
            $actor,
            [
                'name' => 'Unauthorized SuperAdmin',
                'email' => 'unauthorized-super-admin@example.test',
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
            ],
        ))->toThrow(AuthorizationException::class)
            ->and(User::withTrashed()->count())->toBe($usersBefore)
            ->and(CompanyUser::withTrashed()->count())->toBe($membershipsBefore)
            ->and(DB::connection('mysql')->table('model_has_roles')->count())->toBe($assignmentsBefore)
            ->and(getPermissionsTeamId())->toBe($company->getKey());
    });

    test('rejects forged attributes without creating an account', function (string $field, mixed $value): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $company = createCompany();

        actingAsInCompany($actor, $company);

        $usersBefore = User::withTrashed()->count();
        $membershipsBefore = CompanyUser::withTrashed()->count();
        $assignmentsBefore = DB::connection('mysql')
            ->table('model_has_roles')
            ->count();

        $data = [
            'name' => 'Global Administrator',
            'email' => 'forged-super-admin@example.test',
            'password' => 'N7v!qL2#rX9@kP4',
            'password_confirmation' => 'N7v!qL2#rX9@kP4',
            $field => $value,
        ];

        try {
            app(UserManagementService::class)->createSuperAdmin($actor, $data);

            $this->fail('Expected validation to reject the forged attribute.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);
        }

        expect(User::withTrashed()->count())->toBe($usersBefore)
            ->and(CompanyUser::withTrashed()->count())->toBe($membershipsBefore)
            ->and(
                DB::connection('mysql')->table('model_has_roles')->count()
            )->toBe($assignmentsBefore)
            ->and(getPermissionsTeamId())->toBe($company->getKey());
    })->with([
        'company assignment' => ['company_id', 'forged-company'],
        'verification timestamp' => ['email_verified_at', '2026-01-01 00:00:00'],
        'single role' => ['role', 'admin'],
        'multiple roles' => ['roles', [1]],
        'authentication guard' => ['guard_name', 'api'],
        'account identifier' => ['id', 'forged-user'],
    ]);

    test('rolls back account creation when email approval fails', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $company = createCompany();

        actingAsInCompany($actor, $company);

        $usersBefore = User::withTrashed()->count();
        $membershipsBefore = CompanyUser::withTrashed()->count();
        $assignmentsBefore = DB::connection('mysql')
            ->table('model_has_roles')
            ->count();

        $dispatcher = User::getEventDispatcher();
        $temporaryDispatcher = clone $dispatcher;

        User::setEventDispatcher($temporaryDispatcher);

        try {
            User::updating(function (User $user): void {
                if ($user->email === 'interrupted-super-admin@example.test'
                    && $user->isDirty('email_verified_at')) {
                    throw new RuntimeException('Email approval failed.');
                }
            });

            expect(fn () => app(UserManagementService::class)->createSuperAdmin(
                $actor,
                [
                    'name' => 'Interrupted SuperAdmin',
                    'email' => 'interrupted-super-admin@example.test',
                    'password' => 'N7v!qL2#rX9@kP4',
                    'password_confirmation' => 'N7v!qL2#rX9@kP4',
                ],
            ))->toThrow(RuntimeException::class, 'Email approval failed.');
        } finally {
            User::setEventDispatcher($dispatcher);
        }

        expect(User::withTrashed()->count())->toBe($usersBefore)
            ->and(
                User::withTrashed()
                    ->where('email', 'interrupted-super-admin@example.test')
                    ->exists()
            )->toBeFalse()
            ->and(CompanyUser::withTrashed()->count())->toBe($membershipsBefore)
            ->and(
                DB::connection('mysql')->table('model_has_roles')->count()
            )->toBe($assignmentsBefore)
            ->and(getPermissionsTeamId())->toBe($company->getKey());
    });
});

describe('Global SuperAdmin Update', function (): void {
    beforeEach(function (): void {
        $this->mock(UncompromisedVerifier::class)
            ->shouldReceive('verify')
            ->andReturnTrue();
    });

    test('updates a global super-admin without assigning a company or changing a blank password', function (bool $withTenant): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);

        if ($withTenant) {
            actingAsInCompany($actor, createCompany());
        } else {
            $this->actingAs($actor, 'web');

            Filament::setCurrentPanel('admin');
            Filament::setTenant(null, isQuiet: true);
            setPermissionsTeamId(null);
        }

        $originalPassword = $target->password;
        $previousTeam = getPermissionsTeamId();
        $membershipsBefore = CompanyUser::withTrashed()->count();

        $updated = app(UserManagementService::class)->updateSuperAdmin(
            $actor,
            $target,
            [
                'name' => 'Updated SuperAdmin',
                'email' => 'updated-super-admin@example.test',
                'password' => '',
                'password_confirmation' => '',
            ],
        );

        $persisted = User::query()->findOrFail($target->getKey());

        expect($updated->getKey())->toBe($target->getKey())
            ->and($persisted->name)->toBe('Updated SuperAdmin')
            ->and($persisted->email)->toBe('updated-super-admin@example.test')
            ->and($persisted->hasVerifiedEmail())->toBeTrue()
            ->and($persisted->password)->toBe($originalPassword)
            ->and(getPermissionsTeamId())->toBe($previousTeam)
            ->and(CompanyUser::withTrashed()->count())->toBe($membershipsBefore)
            ->and(CompanyUser::withTrashed()
                ->where('user_id', $persisted->getKey())->exists())->toBeFalse();

        $assignments = DB::connection('mysql')
            ->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_type', $persisted->getMorphClass())
            ->where('assignments.model_uuid', $persisted->getKey())
            ->select([
                'assignments.company_id as assignment_company_id',
                'roles.company_id as role_company_id',
                'roles.name',
                'roles.guard_name',
            ])
            ->get();

        expect($assignments)->toHaveCount(1);

        $assignment = $assignments->sole();

        expect($assignment->name)->toBe(UserRole::SuperAdmin->value)
            ->and($assignment->guard_name)->toBe('web')
            ->and($assignment->assignment_company_id)->toBeNull()
            ->and($assignment->role_company_id)->toBeNull();
    })->with([
        'without a selected company' => [false],
        'with a selected company' => [true],
    ]);

    test('rejects a company admin even with user update permission', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);
        $target = createUserWithRole(UserRole::SuperAdmin);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        actingAsInCompany($actor, $company);
        grantShield($actor, ['Update:User'], $company);

        $originalName = $target->name;
        $originalEmail = $target->email;
        $originalPassword = $target->password;
        $membershipsBefore = CompanyUser::withTrashed()->count();

        expect(fn () => app(UserManagementService::class)->updateSuperAdmin(
            $actor,
            $target,
            [
                'name' => 'Rejected Name',
                'email' => 'rejected-super-admin@example.test',
                'password' => '',
                'password_confirmation' => '',
            ],
        ))->toThrow(AuthorizationException::class);

        $target->refresh();

        expect($target->name)->toBe($originalName)
            ->and($target->email)->toBe($originalEmail)
            ->and($target->password)->toBe($originalPassword)
            ->and(CompanyUser::withTrashed()->count())->toBe($membershipsBefore)
            ->and(getPermissionsTeamId())->toBe($company->getKey());
    });

    test('rejects a company user as the target of global administration', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $company = createCompany();
        $target = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $target->getKey(),
        ]);

        actingAsInCompany($actor, $company);

        $originalName = $target->name;
        $originalEmail = $target->email;
        $originalPassword = $target->password;
        $membershipsBefore = CompanyUser::withTrashed()->count();

        expect(fn () => app(UserManagementService::class)->updateSuperAdmin(
            $actor,
            $target,
            [
                'name' => 'Rejected Name',
                'email' => 'rejected-company-user@example.test',
                'password' => '',
                'password_confirmation' => '',
            ],
        ))->toThrow(AuthorizationException::class);

        $target->refresh();

        expect($target->name)->toBe($originalName)
            ->and($target->email)->toBe($originalEmail)
            ->and($target->password)->toBe($originalPassword)
            ->and(CompanyUser::withTrashed()->count())->toBe($membershipsBefore)
            ->and(getPermissionsTeamId())->toBe($company->getKey());
    });

    test('revokes target tokens only when a new password is provided', function (string $passwordMode): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);
        $company = createCompany();

        actingAsInCompany($actor, $company);

        $originalPassword = $target->password;

        foreach ([$target, $actor] as $user) {
            DB::connection('mysql')
                ->table('personal_access_tokens')
                ->insert([
                    'tokenable_type' => $user->getMorphClass(),
                    'tokenable_id' => $user->getKey(),
                    'name' => 'test-device',
                    'token' => hash('sha256', $user->getKey()),
                    'abilities' => '["*"]',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $data = [
            'name' => 'Updated SuperAdmin',
            'email' => $target->email,
        ];

        if ($passwordMode === 'new') {
            $data['password'] = 'X9z!mK4#pQ7@vL2';
            $data['password_confirmation'] = 'X9z!mK4#pQ7@vL2';
        } elseif ($passwordMode === 'blank') {
            $data['password'] = '';
            $data['password_confirmation'] = '';
        }

        app(UserManagementService::class)->updateSuperAdmin(
            $actor,
            $target,
            $data,
        );

        $target->refresh();

        expect($target->name)->toBe('Updated SuperAdmin')
            ->and(getPermissionsTeamId())->toBe($company->getKey());

        $targetToken = [
            'tokenable_type' => $target->getMorphClass(),
            'tokenable_id' => $target->getKey(),
        ];

        if ($passwordMode === 'new') {
            expect($target->password)->not->toBe($originalPassword)
                ->and(Hash::check('X9z!mK4#pQ7@vL2', $target->password))
                ->toBeTrue();

            $this->assertDatabaseMissing(
                'personal_access_tokens',
                $targetToken,
                'mysql',
            );
        } else {
            expect($target->password)->toBe($originalPassword);

            $this->assertDatabaseHas(
                'personal_access_tokens',
                $targetToken,
                'mysql',
            );
        }

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => $actor->getMorphClass(),
            'tokenable_id' => $actor->getKey(),
        ], 'mysql');
    })->with([
        'new password' => ['new'],
        'blank password' => ['blank'],
        'omitted password' => ['omitted'],
    ]);

    test('rejects invalid data while preserving the account and tokens', function (array $overrides, string $field): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);
        $company = createCompany();

        $reserved = User::factory()->create([
            'email' => 'reserved-global@example.test',
        ]);
        $reserved->delete();

        actingAsInCompany($actor, $company);

        $target->refresh();
        $original = $target->getRawOriginal();
        $membershipsBefore = CompanyUser::withTrashed()->count();
        $assignmentsBefore = DB::connection('mysql')
            ->table('model_has_roles')
            ->count();

        $token = [
            'tokenable_type' => $target->getMorphClass(),
            'tokenable_id' => $target->getKey(),
            'name' => 'test-device',
            'token' => hash('sha256', $target->getKey()),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::connection('mysql')
            ->table('personal_access_tokens')
            ->insert($token);

        $data = array_replace([
            'name' => 'Rejected Name',
            'email' => 'rejected-update@example.test',
            'password' => 'X9z!mK4#pQ7@vL2',
            'password_confirmation' => 'X9z!mK4#pQ7@vL2',
        ], $overrides);

        try {
            app(UserManagementService::class)->updateSuperAdmin(
                $actor,
                $target,
                $data,
            );

            $this->fail('Expected validation to reject the update.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);
        }

        $target->refresh();

        expect($target->getRawOriginal())->toBe($original)
            ->and(CompanyUser::withTrashed()->count())->toBe($membershipsBefore)
            ->and(
                DB::connection('mysql')->table('model_has_roles')->count()
            )->toBe($assignmentsBefore)
            ->and(getPermissionsTeamId())->toBe($company->getKey());

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => $target->getMorphClass(),
            'tokenable_id' => $target->getKey(),
            'token' => $token['token'],
        ], 'mysql');
    })->with([
        'missing name' => [
            ['name' => null],
            'name',
        ],
        'invalid email' => [
            ['email' => 'invalid-email'],
            'email',
        ],
        'email reserved by a deleted account' => [
            ['email' => 'reserved-global@example.test'],
            'email',
        ],
        'mismatched password confirmation' => [
            ['password_confirmation' => 'Different9!Password'],
            'password',
        ],
        'forged company' => [
            ['company_id' => 'forged-company'],
            'company_id',
        ],
        'forged verification timestamp' => [
            ['email_verified_at' => '2026-01-01 00:00:00'],
            'email_verified_at',
        ],
        'forged single role' => [
            ['role' => 'admin'],
            'role',
        ],
        'forged multiple roles' => [
            ['roles' => [1]],
            'roles',
        ],
        'forged guard' => [
            ['guard_name' => 'api'],
            'guard_name',
        ],
        'forged identifier' => [
            ['id' => 'forged-user'],
            'id',
        ],
    ]);

    test('rolls back profile changes and token revocation when the transaction fails', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);
        $company = createCompany();

        actingAsInCompany($actor, $company);

        $target->refresh();
        $original = $target->getRawOriginal();

        $token = [
            'tokenable_type' => $target->getMorphClass(),
            'tokenable_id' => $target->getKey(),
            'name' => 'test-device',
            'token' => hash('sha256', $target->getKey()),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::connection('mysql')
            ->table('personal_access_tokens')
            ->insert($token);

        $connection = DB::connection('mysql');
        $originalDispatcher = $connection->getEventDispatcher();
        $temporaryDispatcher = clone $originalDispatcher;

        $connection->setEventDispatcher($temporaryDispatcher);

        try {
            $temporaryDispatcher->listen(
                QueryExecuted::class,
                function (QueryExecuted $event): void {
                    if (
                        $event->connectionName === 'mysql'
                        && str_starts_with(strtolower(ltrim($event->sql)), 'delete')
                        && str_contains($event->sql, 'personal_access_tokens')
                    ) {
                        throw new RuntimeException('Token revocation interrupted.');
                    }
                },
            );

            expect(fn () => app(UserManagementService::class)->updateSuperAdmin(
                $actor,
                $target,
                [
                    'name' => 'Interrupted Update',
                    'email' => 'interrupted-update@example.test',
                    'password' => 'X9z!mK4#pQ7@vL2',
                    'password_confirmation' => 'X9z!mK4#pQ7@vL2',
                ],
            ))->toThrow(
                RuntimeException::class,
                'Token revocation interrupted.',
            );
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        $target->refresh();

        expect($target->getRawOriginal())->toBe($original)
            ->and(getPermissionsTeamId())->toBe($company->getKey());

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => $target->getMorphClass(),
            'tokenable_id' => $target->getKey(),
            'token' => $token['token'],
        ], 'mysql');
    });
});

describe('Global SuperAdmin Deactivation', function (): void {
    test('rejects deactivating the last verified global administrator', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);

        $company = createCompany();
        actingAsInCompany($actor, $company);

        $actor->refresh();
        $original = $actor->getRawOriginal();

        $token = [
            'tokenable_type' => $actor->getMorphClass(),
            'tokenable_id' => $actor->getKey(),
            'name' => 'test-device',
            'token' => hash('sha256', $actor->getKey()),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::connection('mysql')
            ->table('personal_access_tokens')
            ->insert($token);

        try {
            app(UserManagementService::class)->deactivateSuperAdmin(
                $actor,
                $actor,
            );

            $this->fail('Expected deactivation to be rejected.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('access')
                ->and($exception->errors()['access'])->toContain(__('The system must retain at least one active global system admin with a verified email.'));
        }

        $persisted = User::withTrashed()->findOrFail($actor->getKey());

        expect($persisted->getRawOriginal())->toBe($original)
            ->and($persisted->trashed())->toBeFalse()
            ->and(getPermissionsTeamId())->toBe($company->getKey());

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => $actor->getMorphClass(),
            'tokenable_id' => $actor->getKey(),
            'token' => $token['token'],
        ], 'mysql');
    });

    test('deactivates an account while preserving another verified global administrator', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);

        $company = createCompany();
        actingAsInCompany($actor, $company);

        foreach ([$actor, $target] as $user) {
            DB::connection('mysql')
                ->table('personal_access_tokens')
                ->insert([
                    'tokenable_type' => $user->getMorphClass(),
                    'tokenable_id' => $user->getKey(),
                    'name' => 'test-device',
                    'token' => hash('sha256', $user->getKey()),
                    'abilities' => '["*"]',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        app(UserManagementService::class)->deactivateSuperAdmin(
            $actor,
            $target,
        );

        $persisted = User::withTrashed()->findOrFail($target->getKey());

        expect($persisted->trashed())->toBeTrue()
            ->and($persisted->email)->toBe($target->email)
            ->and(User::query()->whereKey($actor->getKey())->exists())->toBeTrue()
            ->and(getPermissionsTeamId())->toBe($company->getKey());

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => $target->getMorphClass(),
            'tokenable_id' => $target->getKey(),
        ], 'mysql');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => $actor->getMorphClass(),
            'tokenable_id' => $actor->getKey(),
        ], 'mysql');

        expect(CompanyUser::withTrashed()
            ->whereIn('user_id', [
                $actor->getKey(),
                $target->getKey(),
            ])
            ->exists())->toBeFalse();
    });

    test('rejects company accounts as actor or target', function (bool $actorIsGlobal, bool $targetIsGlobal): void {
        $company = createCompany();

        $actor = createUserWithRole(
            $actorIsGlobal ? UserRole::SuperAdmin : UserRole::Admin,
            $actorIsGlobal ? null : $company,
        );

        $target = createUserWithRole(
            $targetIsGlobal ? UserRole::SuperAdmin : UserRole::Admin,
            $targetIsGlobal ? null : $company,
        );

        foreach ([
            [$actor, $actorIsGlobal],
            [$target, $targetIsGlobal],
        ] as [$user, $isGlobal]) {
            if (! $isGlobal) {
                CompanyUser::create([
                    'company_id' => $company->getKey(),
                    'user_id' => $user->getKey(),
                ]);
            }
        }

        actingAsInCompany($actor, $company);

        if (! $actorIsGlobal) {
            grantShield($actor, ['Delete:User'], $company);
        }

        $target->refresh();
        $original = $target->getRawOriginal();
        $membershipsBefore = CompanyUser::withTrashed()->count();

        expect(fn () => app(UserManagementService::class)->deactivateSuperAdmin(
            $actor,
            $target,
        ))->toThrow(AuthorizationException::class);

        $persisted = User::withTrashed()->findOrFail($target->getKey());

        expect($persisted->getRawOriginal())->toBe($original)
            ->and(CompanyUser::withTrashed()->count())->toBe($membershipsBefore)
            ->and(getPermissionsTeamId())->toBe($company->getKey());
    })->with([
        'company admin with deletion permission' => [false, true],
        'company account targeted by a super-admin' => [true, false],
    ]);

    test('rejects deactivation when the remaining global administrator cannot provide verified access', function (string $condition): void {
        $target = createUserWithRole(UserRole::SuperAdmin);
        $remaining = createUserWithRole(UserRole::SuperAdmin);

        if ($condition === 'deleted') {
            $remaining->delete();
        } else {
            $remaining->forceFill([
                'email_verified_at' => null,
            ])->save();
        }

        $company = createCompany();
        actingAsInCompany($target, $company);

        $target->refresh();
        $original = $target->getRawOriginal();

        DB::connection('mysql')
            ->table('personal_access_tokens')
            ->insert([
                'tokenable_type' => $target->getMorphClass(),
                'tokenable_id' => $target->getKey(),
                'name' => 'test-device',
                'token' => hash('sha256', $target->getKey()),
                'abilities' => '["*"]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        try {
            app(UserManagementService::class)->deactivateSuperAdmin(
                $target,
                $target,
            );

            $this->fail('Expected deactivation to be rejected.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['access'] ?? [])
                ->toContain(
                    __('The system must retain at least one active global system admin with a verified email.')
                );
        }

        $persisted = User::withTrashed()->findOrFail($target->getKey());

        expect($persisted->getRawOriginal())->toBe($original)
            ->and(getPermissionsTeamId())->toBe($company->getKey());

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => $target->getMorphClass(),
            'tokenable_id' => $target->getKey(),
            'token' => hash('sha256', $target->getKey()),
        ], 'mysql');
    })->with([
        'deleted administrator' => ['deleted'],
        'unverified administrator' => ['unverified'],
    ]);

    test('rolls back account deactivation and token revocation when the transaction fails', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);

        $company = createCompany();
        actingAsInCompany($actor, $company);

        $target->refresh();
        $original = $target->getRawOriginal();

        $token = [
            'tokenable_type' => $target->getMorphClass(),
            'tokenable_id' => $target->getKey(),
            'name' => 'test-device',
            'token' => hash('sha256', $target->getKey()),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::connection('mysql')
            ->table('personal_access_tokens')
            ->insert($token);

        $connection = DB::connection('mysql');
        $originalDispatcher = $connection->getEventDispatcher();
        $temporaryDispatcher = clone $originalDispatcher;

        $connection->setEventDispatcher($temporaryDispatcher);

        try {
            $temporaryDispatcher->listen(
                QueryExecuted::class,
                function (QueryExecuted $event): void {
                    if (
                        $event->connectionName === 'mysql'
                        && str_starts_with(strtolower(ltrim($event->sql)), 'delete')
                        && str_contains($event->sql, 'personal_access_tokens')
                    ) {
                        throw new RuntimeException(
                            'Token revocation interrupted.'
                        );
                    }
                },
            );

            expect(fn () => app(UserManagementService::class)->deactivateSuperAdmin(
                $actor,
                $target,
            ))->toThrow(
                RuntimeException::class,
                'Token revocation interrupted.',
            );
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        $persisted = User::withTrashed()->findOrFail($target->getKey());

        expect($persisted->getRawOriginal())->toBe($original)
            ->and($persisted->trashed())->toBeFalse()
            ->and(getPermissionsTeamId())->toBe($company->getKey());

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => $target->getMorphClass(),
            'tokenable_id' => $target->getKey(),
            'token' => $token['token'],
        ], 'mysql');
    });
});
