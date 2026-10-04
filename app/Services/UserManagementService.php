<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
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
    public function createCompanyAdmin(User $actor, array $data): User
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
                'role' => [
                    'required',
                    Rule::in([UserRole::Admin->value]),
                ],
                'company_id' => ['missing'],
                'email_verified_at' => ['missing'],
                'roles' => ['missing'],
                'guard_name' => ['missing'],
                'id' => ['missing'],
            ])->validate();

            $role = Role::withoutGlobalScopes()
                ->where('company_id', $company->getKey())
                ->where('name', UserRole::Admin->value)
                ->where('guard_name', 'web')
                ->sole();

            $userId = (string) str()->uuid();

            try {
                return DB::connection('mysql')->transaction(
                    function () use ($validated, $company, $role, $userId): User {
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

                        $user->assignRole($role);

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
    public function updateCompanyAdmin(
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
                __('Super-admin accounts must be managed globally.')
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
                'role' => [
                    'required',
                    Rule::in([UserRole::Admin->value]),
                ],
                'company_id' => ['missing'],
                'email_verified_at' => ['missing'],
                'roles' => ['missing'],
                'guard_name' => ['missing'],
                'id' => ['missing'],
            ])->validate();

            return DB::connection('mysql')->transaction(
                function () use ($actor, $target, $validated): User {
                    $record = User::withoutGlobalScopes()
                        ->whereKey($target->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($record->isSuperAdmin()) {
                        throw new AuthorizationException(
                            __('Super-admin accounts must be managed globally.')
                        );
                    }

                    Gate::forUser($actor)->authorize('update', $record);

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

                            $memberIds = CompanyUser::query()
                                ->where('company_id', $company->getKey())
                                ->pluck('user_id');

                            $adminIds = DB::connection('mysql')
                                ->table('model_has_roles as assignments')
                                ->join(
                                    'roles',
                                    'roles.id',
                                    '=',
                                    'assignments.role_id',
                                )
                                ->where(
                                    'assignments.model_type',
                                    $record->getMorphClass(),
                                )
                                ->where(
                                    'assignments.company_id',
                                    $company->getKey(),
                                )
                                ->where('roles.company_id', $company->getKey())
                                ->where('roles.guard_name', 'web')
                                ->where('roles.name', UserRole::Admin->value)
                                ->select('assignments.model_uuid');

                            $superAdminIds = DB::connection('mysql')
                                ->table('model_has_roles as assignments')
                                ->join(
                                    'roles',
                                    'roles.id',
                                    '=',
                                    'assignments.role_id',
                                )
                                ->where(
                                    'assignments.model_type',
                                    $record->getMorphClass(),
                                )
                                ->where('roles.name', UserRole::SuperAdmin->value)
                                ->select('assignments.model_uuid');

                            $hasRemainingAdmin = User::query()
                                ->whereIn('users.id', $memberIds)
                                ->whereIn('users.id', $adminIds)
                                ->whereNotIn('users.id', $superAdminIds)
                                ->where('users.id', '!=', $record->getKey())
                                ->whereNotNull('users.email_verified_at')
                                ->exists();

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
                    function () use ($mysql, $company, $target, $membershipId, ): bool {
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
}
