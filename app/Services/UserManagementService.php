<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class UserManagementService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createSuperAdmin(User $actor, array $data): User
    {
        $persistedActor = User::query()->find($actor->getKey());

        $isGlobalSuperAdmin = $persistedActor !== null
            && DB::connection('mysql')
                ->table('model_has_roles as assignments')
                ->join('roles', 'roles.id', '=', 'assignments.role_id')
                ->where('assignments.model_type', $persistedActor->getMorphClass())
                ->where('assignments.model_uuid', $persistedActor->getKey())
                ->whereNull('assignments.company_id')
                ->whereNull('roles.company_id')
                ->where('roles.guard_name', 'web')
                ->where('roles.name', UserRole::SuperAdmin->value)
                ->exists();

        if (! $isGlobalSuperAdmin) {
            throw new AuthorizationException(
                __('Only a global system admin may create system admin accounts.')
            );
        }

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:100',
                Rule::unique('mysql.users', 'email'),
            ],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ],
            'company_id' => ['missing'],
            'email_verified_at' => ['missing'],
            'role' => ['missing'],
            'roles' => ['missing'],
            'guard_name' => ['missing'],
            'id' => ['missing'],
        ])->validate();

        $previousTeam = getPermissionsTeamId();

        try {
            setPermissionsTeamId(null);

            return DB::connection('mysql')->transaction(
                function () use ($validated): User {
                    $role = Role::withoutGlobalScopes()
                        ->whereNull('company_id')
                        ->where('guard_name', 'web')
                        ->where('name', UserRole::SuperAdmin->value)
                        ->lockForUpdate()
                        ->sole();

                    $user = new User([
                        'name' => $validated['name'],
                        'email' => $validated['email'],
                        'password' => $validated['password'],
                    ]);

                    if (! $user->save()) {
                        throw new RuntimeException(
                            'Super-admin creation was cancelled.'
                        );
                    }

                    if (! $user->markEmailAsVerified()) {
                        throw new RuntimeException(
                            'Email approval was cancelled.'
                        );
                    }

                    $user->assignRole($role);

                    return $user->unsetRelation('roles')
                        ->unsetRelation('permissions');
                }
            );
        } finally {
            setPermissionsTeamId($previousTeam);

            $actor->unsetRelation('roles')
                ->unsetRelation('permissions');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSuperAdmin(
        User $actor,
        User $target,
        array $data,
    ): User {
        $persistedActor = User::query()->find($actor->getKey());
        $persistedTarget = User::query()->find($target->getKey());

        if (
            ! $persistedActor
            || ! $this->hasGlobalSuperAdminRole($persistedActor)
            || ! $persistedTarget
            || ! $this->hasGlobalSuperAdminRole($persistedTarget)
        ) {
            throw new AuthorizationException(
                __('Only global system admins may manage global system admin accounts.')
            );
        }

        if (
            array_key_exists('password', $data)
            && ($data['password'] === null || $data['password'] === '')
        ) {
            unset($data['password'], $data['password_confirmation']);
        }

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:100',
                Rule::unique('mysql.users', 'email')
                    ->ignore(
                        $persistedTarget->getKey(),
                        $persistedTarget->getKeyName(),
                    ),
            ],
            'password' => [
                'sometimes',
                'required',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ],
            'company_id' => ['missing'],
            'email_verified_at' => ['missing'],
            'role' => ['missing'],
            'roles' => ['missing'],
            'guard_name' => ['missing'],
            'id' => ['missing'],
        ])->validate();

        $previousTeam = getPermissionsTeamId();

        try {
            setPermissionsTeamId(null);

            return DB::connection('mysql')->transaction(
                function () use ($persistedTarget, $validated): User {
                    $record = User::query()
                        ->whereKey($persistedTarget->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (! $this->hasGlobalSuperAdminRole($record)) {
                        throw new AuthorizationException(
                            __('The target must remain a global system admin.')
                        );
                    }

                    $record->fill([
                        'name' => $validated['name'],
                        'email' => $validated['email'],
                    ]);

                    if ($record->isDirty('email')) {
                        $record->email_verified_at = now();
                    }

                    if (array_key_exists('password', $validated)) {
                        $record->password = $validated['password'];
                    }

                    if (! $record->save()) {
                        throw new RuntimeException(
                            'Super-admin update was cancelled.'
                        );
                    }

                    if (array_key_exists('password', $validated)) {
                        DB::connection('mysql')
                            ->table('personal_access_tokens')
                            ->where('tokenable_type', $record->getMorphClass())
                            ->where('tokenable_id', $record->getKey())
                            ->delete();
                    }

                    return $record;
                }
            );
        } finally {
            setPermissionsTeamId($previousTeam);

            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $target->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCompanyUser(User $actor, array $data): User
    {
        $company = Filament::getTenant();

        if (! $company instanceof Company || $company->trashed()) {
            throw ValidationException::withMessages([
                'company' => __('An active company context is required.'),
            ]);
        }

        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId($company->getKey());
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        try {
            Gate::forUser($actor)->authorize('create', User::class);

            $validated = Validator::make($data, [
                'name' => ['required', 'string', 'max:100'],
                'email' => [
                    'required',
                    'email',
                    'max:100',
                    Rule::unique('mysql.users', 'email'),
                ],
                'password' => [
                    'required',
                    'confirmed',
                    Password::min(8)
                        ->mixedCase()
                        ->numbers()
                        ->symbols()
                        ->uncompromised(),
                ],
                'company_id' => ['missing'],
                'email_verified_at' => ['missing'],
                'role' => ['missing'],
                'roles' => ['required', 'array', 'list', 'min:1'],
                'roles.*' => [
                    'bail',
                    'required',
                    'integer',
                    'distinct',
                    Rule::exists('mysql.roles', 'id')->where(
                        fn ($query) => $query
                            ->where('company_id', $company->getKey())
                            ->where('guard_name', 'web')
                            ->whereNotIn('name', [
                                UserRole::Driver->value,
                                UserRole::Passenger->value,
                                UserRole::SuperAdmin->value,
                            ])
                    ),
                ],
                'guard_name' => ['missing'],
                'id' => ['missing'],
            ])->validate();

            $userId = (string) str()->uuid();

            try {
                return DB::connection('mysql')->transaction(
                    function () use ($validated, $company, $userId, $actor): User {
                        $roles = Role::withoutGlobalScopes()
                            ->whereIn('id', $validated['roles'])
                            ->where('company_id', $company->getKey())
                            ->where('guard_name', 'web')
                            ->whereNotIn('name', [
                                UserRole::Driver->value,
                                UserRole::Passenger->value,
                                UserRole::SuperAdmin->value,
                            ])
                            ->lockForUpdate()
                            ->get();

                        if ($roles->count() !== count($validated['roles'])) {
                            throw ValidationException::withMessages([
                                'roles' => __('One or more selected roles are no longer available.'),
                            ]);
                        }

                        $this->authorizeCompanyRoleAssignment($actor, $roles);

                        $user = new User([
                            'name' => $validated['name'],
                            'email' => $validated['email'],
                            'password' => $validated['password'],
                        ]);

                        $user->id = $userId;

                        if (! $user->save()) {
                            throw new RuntimeException('User creation was cancelled.');
                        }

                        if (! $user->markEmailAsVerified()) {
                            throw new RuntimeException('Email approval was cancelled.');
                        }

                        $user->assignRole(...$roles->all());

                        DB::connection('pgsql')->transaction(
                            function () use ($company, $user): void {
                                $membership = new CompanyUser([
                                    'company_id' => $company->getKey(),
                                    'user_id' => $user->getKey(),
                                ]);

                                if (! $membership->save()) {
                                    throw new RuntimeException(
                                        'Company membership creation was cancelled.'
                                    );
                                }
                            }
                        );

                        return $user;
                    }
                );
            } catch (Throwable $exception) {
                try {
                    CompanyUser::withTrashed()
                        ->where('user_id', $userId)
                        ->where('company_id', $company->getKey())
                        ->forceDelete();
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }

                throw $exception;
            }
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCompanyUser(
        User $actor,
        User $target,
        array $data,
    ): User {
        $company = Filament::getTenant();

        if (! $company instanceof Company || $company->trashed()) {
            throw ValidationException::withMessages([
                'company' => __('An active company context is required.'),
            ]);
        }

        if ($target->isSuperAdmin()) {
            throw new AuthorizationException(
                __('System admin accounts must be managed globally.')
            );
        }

        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId($company->getKey());
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        try {
            Gate::forUser($actor)->authorize('update', $target);

            if (
                array_key_exists('password', $data)
                && ($data['password'] === null || $data['password'] === '')
            ) {
                unset($data['password'], $data['password_confirmation']);
            }

            $validated = Validator::make($data, [
                'name' => ['required', 'string', 'max:100'],
                'email' => [
                    'required',
                    'email',
                    'max:100',
                    Rule::unique('mysql.users', 'email')
                        ->ignore($target->getKey(), $target->getKeyName()),
                ],
                'password' => [
                    'sometimes',
                    'required',
                    'confirmed',
                    Password::min(8)
                        ->mixedCase()
                        ->numbers()
                        ->symbols()
                        ->uncompromised(),
                ],
                'company_id' => ['missing'],
                'email_verified_at' => ['missing'],
                'role' => ['missing'],
                'roles' => ['required', 'array', 'list', 'min:1'],
                'roles.*' => [
                    'bail',
                    'required',
                    'integer',
                    'distinct',
                    Rule::exists('mysql.roles', 'id')->where(
                        fn ($query) => $query
                            ->where('company_id', $company->getKey())
                            ->where('guard_name', 'web')
                            ->whereNotIn('name', [
                                UserRole::Driver->value,
                                UserRole::Passenger->value,
                                UserRole::SuperAdmin->value,
                            ])
                    ),
                ],
                'guard_name' => ['missing'],
                'id' => ['missing'],
            ])->validate();

            return DB::connection('pgsql')->transaction(
                function () use ($company, $actor, $target, $validated): User {
                    Company::query()
                        ->whereKey($company->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    return DB::connection('mysql')->transaction(
                        function () use ($company, $actor, $target, $validated): User {
                            $record = User::query()
                                ->whereKey($target->getKey())
                                ->lockForUpdate()
                                ->firstOrFail();

                            if ($record->isSuperAdmin()) {
                                throw new AuthorizationException(
                                    __('System admin accounts must be managed globally.')
                                );
                            }

                            Gate::forUser($actor)->authorize('update', $record);

                            $roles = Role::withoutGlobalScopes()
                                ->whereIn('id', $validated['roles'])
                                ->where('company_id', $company->getKey())
                                ->where('guard_name', 'web')
                                ->whereNotIn('name', [
                                    UserRole::Driver->value,
                                    UserRole::Passenger->value,
                                    UserRole::SuperAdmin->value,
                                ])
                                ->lockForUpdate()
                                ->get();

                            if ($roles->count() !== count($validated['roles'])) {
                                throw ValidationException::withMessages([
                                    'roles' => __(
                                        'One or more selected roles are no longer available.'
                                    ),
                                ]);
                            }

                            $this->authorizeCompanyRoleAssignment($actor, $roles);

                            $wasAdmin = $record->roles()
                                ->where('roles.company_id', $company->getKey())
                                ->where('roles.guard_name', 'web')
                                ->where('roles.name', UserRole::Admin->value)
                                ->exists();

                            $willBeAdmin = $roles->contains(
                                'name',
                                UserRole::Admin->value,
                            );

                            if (
                                $wasAdmin
                                && ! $willBeAdmin
                                && ! $this->hasOtherActiveCompanyAdmin($company, $record)
                            ) {
                                throw ValidationException::withMessages([
                                    'roles' => __(
                                        'The company must retain at least one active administrator with a verified email.'
                                    ),
                                ]);
                            }

                            $record->fill([
                                'name' => $validated['name'],
                                'email' => $validated['email'],
                            ]);

                            if ($record->isDirty('email')) {
                                $record->email_verified_at = now();
                            }

                            if (array_key_exists('password', $validated)) {
                                $record->password = $validated['password'];
                            }

                            if (! $record->save()) {
                                throw new RuntimeException('User update was cancelled.');
                            }

                            $record->syncRoles(...$roles->all());

                            if (array_key_exists('password', $validated)) {
                                DB::connection('mysql')
                                    ->table('personal_access_tokens')
                                    ->where('tokenable_type', $record->getMorphClass())
                                    ->where('tokenable_id', $record->getKey())
                                    ->delete();
                            }

                            return $record;
                        }
                    );
                }
            );
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $target->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function deactivateSuperAdmin(User $actor, User $target): void
    {
        $previousTeam = getPermissionsTeamId();

        try {
            setPermissionsTeamId(null);

            DB::connection('mysql')->transaction(
                function () use ($actor, $target): void {
                    $role = Role::withoutGlobalScopes()
                        ->whereNull('company_id')
                        ->where('guard_name', 'web')
                        ->where('name', UserRole::SuperAdmin->value)
                        ->lockForUpdate()
                        ->first();

                    if (! $role) {
                        throw new AuthorizationException(
                            __('A global system admin role is required.')
                        );
                    }

                    $user = new User;

                    $globalUserIds = DB::connection('mysql')
                        ->table('model_has_roles')
                        ->where('role_id', $role->getKey())
                        ->where('model_type', $user->getMorphClass())
                        ->whereNull('company_id')
                        ->select('model_uuid');

                    $administrators = User::query()
                        ->whereIn('id', $globalUserIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    $persistedActor = $administrators->firstWhere(
                        'id',
                        $actor->getKey(),
                    );

                    $persistedTarget = $administrators->firstWhere(
                        'id',
                        $target->getKey(),
                    );

                    if (! $persistedActor || ! $persistedTarget) {
                        throw new AuthorizationException(
                            __('Only active global system admins may manage global system admin accounts.')
                        );
                    }

                    $hasAnotherVerifiedAdministrator = $administrators
                        ->contains(
                            fn (User $administrator): bool => $administrator->getKey() !== $persistedTarget->getKey()
                                && $administrator->hasVerifiedEmail()
                        );

                    if (! $hasAnotherVerifiedAdministrator) {
                        throw ValidationException::withMessages([
                            'access' => __('The system must retain at least one active global system admin with a verified email.'),
                        ]);
                    }

                    if (! $persistedTarget->delete()) {
                        throw new RuntimeException(
                            'Super-admin deactivation was cancelled.'
                        );
                    }

                    DB::connection('mysql')
                        ->table('personal_access_tokens')
                        ->where(
                            'tokenable_type',
                            $persistedTarget->getMorphClass(),
                        )
                        ->where('tokenable_id', $persistedTarget->getKey())
                        ->delete();
                }
            );
        } finally {
            setPermissionsTeamId($previousTeam);

            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $target->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function removeCompanyAccess(User $actor, User $target): void
    {
        $company = Filament::getTenant();

        if (! $company instanceof Company || $company->trashed()) {
            throw ValidationException::withMessages([
                'company' => __('An active company context is required.'),
            ]);
        }

        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId($company->getKey());
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        $membershipId = null;

        try {
            Gate::forUser($actor)->authorize('removeCompanyAccess', $target);

            DB::connection('pgsql')->transaction(
                function () use ($company, $actor, $target, &$membershipId): void {
                    Company::query()
                        ->whereKey($company->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    DB::connection('mysql')->transaction(
                        function () use ($company, $actor, $target, &$membershipId): void {
                            $record = User::query()
                                ->whereKey($target->getKey())
                                ->lockForUpdate()
                                ->firstOrFail();

                            Gate::forUser($actor)->authorize(
                                'removeCompanyAccess',
                                $record,
                            );

                            $membership = CompanyUser::query()
                                ->where('company_id', $company->getKey())
                                ->where('user_id', $record->getKey())
                                ->lockForUpdate()
                                ->firstOrFail();

                            $hasRemainingAdmin = $this->hasOtherActiveCompanyAdmin(
                                $company,
                                $record,
                            );

                            if (! $hasRemainingAdmin) {
                                throw ValidationException::withMessages([
                                    'access' => __(
                                        'The company must retain at least one active administrator with a verified email.'
                                    ),
                                ]);
                            }

                            if (! $membership->delete()) {
                                throw new RuntimeException(
                                    'Company access removal was cancelled.'
                                );
                            }

                            foreach (['model_has_roles', 'model_has_permissions'] as $table) {
                                DB::connection('mysql')
                                    ->table($table)
                                    ->where('model_type', $record->getMorphClass())
                                    ->where('model_uuid', $record->getKey())
                                    ->where('company_id', $company->getKey())
                                    ->delete();
                            }

                            $membershipId = $membership->getKey();
                        }
                    );
                }
            );
        } catch (Throwable $exception) {
            if ($membershipId === null) {
                throw $exception;
            }

            $recovered = false;

            try {
                $recovered = $this->completeCompanyAccessRemoval(
                    $company,
                    $target,
                    $membershipId,
                );
            } catch (Throwable $recoveryException) {
                report($recoveryException);
            }

            if (! $recovered) {
                throw $exception;
            }

            report($exception);
        } finally {
            setPermissionsTeamId($previousTeam);

            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $target->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    private function completeCompanyAccessRemoval(
        Company $company,
        User $target,
        string $membershipId,
    ): bool {
        $mysql = DB::connection('mysql');
        $pgsql = DB::connection('pgsql');

        if (
            $mysql->transactionLevel() !== 0
            || $mysql->getPdo()->inTransaction()
        ) {
            return false;
        }

        if (
            $pgsql->transactionLevel() === 0
            && $pgsql->getPdo()->inTransaction()
        ) {
            return false;
        }

        return $pgsql->transaction(
            function () use ($mysql, $company, $target, $membershipId): bool {
                Company::query()
                    ->whereKey($company->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                return $mysql->transaction(
                    function () use ($mysql, $company, $target, $membershipId): bool {
                        $record = User::withoutGlobalScopes()
                            ->whereKey($target->getKey())
                            ->lockForUpdate()
                            ->firstOrFail();

                        if ($record->isSuperAdmin()) {
                            return false;
                        }

                        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
                            $hasAssignments = $mysql->table($table)
                                ->where('model_type', $record->getMorphClass())
                                ->where('model_uuid', $record->getKey())
                                ->where('company_id', $company->getKey())
                                ->exists();

                            if ($hasAssignments) {
                                return false;
                            }
                        }

                        $membership = CompanyUser::withTrashed()
                            ->whereKey($membershipId)
                            ->where('company_id', $company->getKey())
                            ->where('user_id', $record->getKey())
                            ->lockForUpdate()
                            ->firstOrFail();

                        if ($membership->trashed()) {
                            return true;
                        }

                        if (! $membership->delete()) {
                            throw new RuntimeException(
                                'Company access removal recovery was cancelled.'
                            );
                        }

                        return true;
                    }
                );
            }
        );
    }

    private function hasOtherActiveCompanyAdmin(
        Company $company,
        User $record,
    ): bool {
        $memberIds = CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->pluck('user_id');

        $adminIds = DB::connection('mysql')
            ->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_type', $record->getMorphClass())
            ->where('assignments.company_id', $company->getKey())
            ->where('roles.company_id', $company->getKey())
            ->where('roles.guard_name', 'web')
            ->where('roles.name', UserRole::Admin->value)
            ->select('assignments.model_uuid');

        $superAdminIds = DB::connection('mysql')
            ->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_type', $record->getMorphClass())
            ->where('roles.name', UserRole::SuperAdmin->value)
            ->select('assignments.model_uuid');

        return User::query()
            ->whereIn('users.id', $memberIds)
            ->whereIn('users.id', $adminIds)
            ->whereNotIn('users.id', $superAdminIds)
            ->where('users.id', '!=', $record->getKey())
            ->whereNotNull('users.email_verified_at')
            ->exists();
    }

    private function hasGlobalSuperAdminRole(User $user): bool
    {
        return DB::connection('mysql')
            ->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_type', $user->getMorphClass())
            ->where('assignments.model_uuid', $user->getKey())
            ->whereNull('assignments.company_id')
            ->whereNull('roles.company_id')
            ->where('roles.guard_name', 'web')
            ->where('roles.name', UserRole::SuperAdmin->value)
            ->exists();
    }

    public function canAssignCompanyRole(User $actor, Role $role): bool
    {
        if ($actor->isSuperAdmin() || $actor->roles()->where('roles.company_id', $role->company_id)
            ->where('roles.guard_name', 'web')->where('roles.name', UserRole::Admin->value)->exists()) {
            return true;
        }

        return $role->name !== UserRole::Admin->value
            && $role->permissions->every(fn (Permission $permission): bool => $actor->can($permission->name));
    }

    /** @param Collection<int, Role> $roles */
    private function authorizeCompanyRoleAssignment(User $actor, Collection $roles): void
    {
        if (! $roles->every(fn (Role $role): bool => $this->canAssignCompanyRole($actor, $role))) {
            throw new AuthorizationException(__('You cannot assign administrator access or permissions you do not hold.'));
        }
    }
}
