<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\DriverStatus;
use App\Enums\UserRole;
use App\Exceptions\DriverRegistrationCompensationException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class DriverManagementService
{
    public function __construct(private readonly UserManagementService $users, private readonly PermissionRegistrar $permissions) {}

    /** @param array<string, mixed> $data */
    public function updateEmployment(User $actor, Company $company, Driver $driver, array $data): Driver
    {
        return $this->withLockedEmployment($actor, $company, $driver, 'update', function (Driver $employment) use ($company, $data): Driver {
            $allowed = [
                'license', 'license_expires_at', 'employment_starts_on', 'employment_ends_on',
                'salary_amount', 'salary_currency', 'salary_period',
            ];
            $errors = [];
            foreach (array_diff(array_keys($data), $allowed) as $field) {
                $errors[$field] = __('This field cannot be changed through employment updates.');
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $this->ensureEmploymentIsNotArchived($employment);
            $employment->fill($data);
            if (is_string($employment->license) && Driver::withTrashed()->where('company_id', $company->getKey())
                ->where('license', $employment->license)->whereKeyNot($employment->getKey())->exists()) {
                throw ValidationException::withMessages(['license' => __('This license is already reserved by a driver in this company.')]);
            }
            $this->ensureLifecycleOperationAllowed($company, $employment, 'updateEmployment');
            if (! $employment->save()) {
                throw ValidationException::withMessages(['driver' => __('The employment operation was cancelled.')]);
            }

            return $employment;
        });
    }

    public function setActive(User $actor, Company $company, Driver $driver, bool $active): Driver
    {
        return $this->withLockedEmployment($actor, $company, $driver, 'update', function (Driver $employment) use ($company, $active): Driver {
            $this->ensureEmploymentIsNotArchived($employment);
            $employment->status = $active ? DriverStatus::ACTIVE : DriverStatus::INACTIVE;
            $this->ensureLifecycleOperationAllowed($company, $employment, 'setActive');
            if (! $employment->save()) {
                throw ValidationException::withMessages(['driver' => __('The employment operation was cancelled.')]);
            }

            return $employment;
        });
    }

    public function archive(User $actor, Company $company, Driver $driver): void
    {
        $this->withLockedEmployment($actor, $company, $driver, 'delete', function (Driver $employment) use ($company): Driver {
            $this->ensureLifecycleOperationAllowed($company, $employment, 'archive');
            if (! $employment->trashed() && ! $employment->delete()) {
                throw ValidationException::withMessages(['driver' => __('The employment operation was cancelled.')]);
            }

            return $employment;
        });
    }

    public function restore(User $actor, Company $company, Driver $driver): Driver
    {
        return $this->withLockedEmployment($actor, $company, $driver, 'restore', function (Driver $employment) use ($company): Driver {
            $this->ensureLifecycleOperationAllowed($company, $employment, 'restore');
            if ($employment->trashed() && ! $employment->restore()) {
                throw ValidationException::withMessages(['driver' => __('The employment operation was cancelled.')]);
            }

            return $employment;
        });
    }

    /**
     * Subtask 09 will add real Trip assignment checks here. Called inside the
     * PostgreSQL transaction with a locked employment and its proposed values.
     */
    protected function ensureLifecycleOperationAllowed(Company $company, Driver $driver, string $operation): void {}

    private function ensureEmploymentIsNotArchived(Driver $driver): void
    {
        if ($driver->trashed()) {
            throw ValidationException::withMessages(['driver' => __('Restore the archived driver before changing employment.')]);
        }
    }

    /** @param Closure(Driver): Driver $operation */
    private function withLockedEmployment(User $actor, Company $company, Driver $driver, string $ability, Closure $operation): Driver
    {
        $previousTeam = getPermissionsTeamId();

        try {
            setPermissionsTeamId($company->getKey());
            $actor->unsetRelation('roles')->unsetRelation('permissions');

            return DB::connection('pgsql')->transaction(function () use ($actor, $company, $driver, $ability, $operation): Driver {
                $persistedCompany = Company::query()->whereKey($company->getKey())->sharedLock()->firstOrFail();
                $employment = Driver::withTrashed()->where('company_id', $persistedCompany->getKey())
                    ->whereKey($driver->getKey())->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize($ability, [Driver::class, $persistedCompany, $employment]);

                return $operation($employment);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($exception->getConnectionName() !== 'pgsql'
                || ($exception->errorInfo[0] ?? null) !== '23505'
                || ! str_contains($exception->errorInfo[2] ?? '', '"drivers_company_id_license_unique"')) {
                throw $exception;
            }

            throw ValidationException::withMessages(['license' => __('This license is already reserved by a driver in this company.')]);
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * @param  array{name: string, email: string, password: string, password_confirmation: string}  $identity
     * @param array{
     *     license: string,
     *     license_expires_at: string|\DateTimeInterface,
     *     employment_starts_on: string|\DateTimeInterface,
     *     employment_ends_on?: string|\DateTimeInterface|null,
     *     salary_amount: string|int,
     *     salary_currency: string,
     *     salary_period?: 'monthly'
     * } $employment
     */
    public function register(User $actor, Company $company, array $identity, array $employment): Driver
    {
        return $this->withCompanyContext($actor, $company, function (Company $company) use ($actor, $identity, $employment): Driver {
            return $this->inRegistrationTransactions($company, function (DriverRegistrationOperation $operation) use ($actor, $company, $identity, $employment): Driver {
                $user = $this->users->createDriverIdentity($actor, $company, $identity);
                $operation->newUserId = $user->getKey();

                return $this->provisionEmployment($company, $user, $employment, $operation);
            });
        });
    }

    /**
     * @param array{
     *     license: string,
     *     license_expires_at: string|\DateTimeInterface,
     *     employment_starts_on: string|\DateTimeInterface,
     *     employment_ends_on?: string|\DateTimeInterface|null,
     *     salary_amount: string|int,
     *     salary_currency: string,
     *     salary_period?: 'monthly'
     * } $employment
     */
    public function createForExistingUser(User $actor, Company $company, User $user, array $employment): Driver
    {
        return $this->withCompanyContext($actor, $company, function (Company $company) use ($user, $employment): Driver {
            return $this->inRegistrationTransactions($company, function (DriverRegistrationOperation $operation) use ($company, $user, $employment): Driver {
                $identity = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

                if ($identity === null) {
                    throw ValidationException::withMessages(['user_id' => __('The selected user is unavailable.')]);
                }

                return $this->provisionEmployment($company, $identity, $employment, $operation);
            });
        }, $user);
    }

    /** @param Closure(Company): Driver $operation */
    private function withCompanyContext(User $actor, Company $company, Closure $operation, ?User $target = null): Driver
    {
        $previousTeam = getPermissionsTeamId();

        try {
            $company = Company::query()->whereKey($company->getKey())->where('status', CompanyStatus::ACTIVE)->first();

            if ($company === null) {
                throw ValidationException::withMessages(['company_id' => __('The selected company is unavailable.')]);
            }

            setPermissionsTeamId($company->getKey());
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $target?->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($actor)->authorize('create', [Driver::class, $company]);

            return $operation($company);
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $target?->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * Commit employment first, then identity. Any exception compensates the
     * operation's recorded deltas after rolling back its open transactions.
     * Acquire the Company row lock before any User write or row lock, matching
     * company-access removal. A shared lock permits parallel registrations.
     *
     * @param  Closure(DriverRegistrationOperation): Driver  $callback
     */
    private function inRegistrationTransactions(Company $company, Closure $callback): Driver
    {
        $operation = new DriverRegistrationOperation($company->getKey(), $this->permissions);
        $mysql = DB::connection('mysql');
        $pgsql = DB::connection('pgsql');
        $levels = ['pgsql' => $pgsql->transactionLevel(), 'mysql' => $mysql->transactionLevel()];

        try {
            $mysql->beginTransaction();
            $pgsql->beginTransaction();
            $lockedCompany = Company::query()->whereKey($company->getKey())
                ->where('status', CompanyStatus::ACTIVE)->sharedLock()->first();

            if ($lockedCompany === null) {
                throw ValidationException::withMessages(['company_id' => __('The selected company is unavailable.')]);
            }

            $driver = $callback($operation);
            $this->permissions->forgetCachedPermissions();
            $pgsql->commit();
            $mysql->commit();

            return $driver;
        } catch (Throwable $failure) {
            $recoveryFailures = [];

            foreach (['pgsql', 'mysql'] as $name) {
                try {
                    DB::connection($name)->rollBack($levels[$name]);
                } catch (Throwable $rollbackFailure) {
                    $recoveryFailures[] = $rollbackFailure;
                }
            }

            try {
                $operation->compensate();
            } catch (Throwable $compensationFailure) {
                $recoveryFailures[] = $compensationFailure;
            }

            if ($recoveryFailures !== []) {
                throw new DriverRegistrationCompensationException($operation, $recoveryFailures, $failure);
            }

            if ($failure instanceof UniqueConstraintViolationException
                && $failure->getConnectionName() === 'pgsql'
                && ($failure->errorInfo[0] ?? null) === '23505'
                && str_contains($failure->errorInfo[2] ?? '', '"drivers_company_id_license_unique"')) {
                throw ValidationException::withMessages([
                    'license' => __('This license is already reserved by a driver in this company.'),
                ]);
            }

            throw $failure;
        }
    }

    /** @param array<string, mixed> $employment */
    private function provisionEmployment(Company $company, User $user, array $employment, DriverRegistrationOperation $operation): Driver
    {
        if ($user->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'user_id' => __('A global system admin cannot be registered as a driver.'),
            ]);
        }

        $operation->userId = $user->getKey();
        $operation->modelType = $user->getMorphClass();

        Validator::make($employment, [
            ...array_fill_keys([
                'id', 'company_id', 'user_id', 'status', 'deleted_at', 'created_at', 'updated_at',
                'role', 'roles', 'guard_name', 'email_verified_at',
            ], ['missing']),
            'license_expires_at' => ['required', 'date'],
            'employment_starts_on' => ['required', 'date'],
            'employment_ends_on' => ['nullable', 'date'],
        ])->validate();

        if (Driver::withTrashed()->where('company_id', $company->getKey())->where('user_id', $user->getKey())->exists()) {
            throw ValidationException::withMessages([
                'user_id' => __('A driver employment already exists for this user in this company, including archived records.'),
            ]);
        }

        $driver = new Driver(Arr::only($employment, [
            'license', 'license_expires_at', 'employment_starts_on', 'employment_ends_on',
            'salary_amount', 'salary_currency', 'salary_period',
        ]));
        $driver->company_id = $company->getKey();
        $driver->user_id = $user->getKey();
        $driver->id = $operation->driverId = $driver->newUniqueId();

        if (is_string($driver->license) && Driver::withTrashed()->where('company_id', $company->getKey())->where('license', $driver->license)->exists()) {
            throw ValidationException::withMessages([
                'license' => __('This license is already reserved by a driver in this company.'),
            ]);
        }

        if (! $driver->save()) {
            throw new RuntimeException('Driver creation was cancelled.');
        }

        $membership = CompanyUser::withTrashed()->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())->lockForUpdate()->first();

        if ($membership === null) {
            $membership = new CompanyUser(['company_id' => $company->getKey(), 'user_id' => $user->getKey()]);
            $membership->id = $operation->newMembershipId = $membership->newUniqueId();

            if (! $membership->save()) {
                throw new RuntimeException('Company membership creation was cancelled.');
            }
        } elseif ($membership->trashed()) {
            $operation->restoredMembership = Arr::only($membership->getRawOriginal(), ['id', 'deleted_at', 'updated_at']);

            if (! $membership->restore()) {
                throw new RuntimeException('Company membership restoration was cancelled.');
            }
        }

        $role = Role::withoutEvents(fn (): Role => Role::withoutGlobalScopes()->firstOrCreate([
            'company_id' => $company->getKey(), 'name' => UserRole::Driver->value, 'guard_name' => 'web',
        ], ['color' => Role::generateColor()]));
        $operation->roleId = $role->getKey();
        $operation->roleCreated = $role->wasRecentlyCreated;
        $operation->roleAssigned = ! DB::connection('mysql')->table('model_has_roles')
            ->where('role_id', $role->getKey())->where('model_uuid', $user->getKey())
            ->where('model_type', $user->getMorphClass())->where('company_id', $company->getKey())->exists();

        $user->unsetRelation('roles')->unsetRelation('permissions');
        $user->assignRole($role);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $driver->setRelation('user', $user);
    }
}
