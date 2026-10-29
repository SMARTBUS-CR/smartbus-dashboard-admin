<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
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

class DriverManagementService
{
    public function __construct(private readonly UserManagementService $users) {}

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
            return $this->inRegistrationTransactions($company, function () use ($actor, $company, $identity, $employment): Driver {
                $user = $this->users->createDriverIdentity($actor, $company, $identity);

                return $this->provisionEmployment($company, $user, $employment);
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
            return $this->inRegistrationTransactions($company, function () use ($company, $user, $employment): Driver {
                $identity = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

                if ($identity === null) {
                    throw ValidationException::withMessages(['user_id' => __('The selected user is unavailable.')]);
                }

                return $this->provisionEmployment($company, $identity, $employment);
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
     * Connection-local rollbacks do not provide atomic cross-database commits.
     * Commit failure compensation is deferred to Subtask 03.
     * Acquire the Company row lock before any User write or row lock, matching
     * company-access removal. A shared lock permits parallel registrations.
     *
     * @param  Closure(): Driver  $operation
     */
    private function inRegistrationTransactions(Company $company, Closure $operation): Driver
    {
        try {
            return DB::connection('mysql')->transaction(
                fn (): Driver => DB::connection('pgsql')->transaction(function () use ($company, $operation): Driver {
                    $lockedCompany = Company::query()->whereKey($company->getKey())
                        ->where('status', CompanyStatus::ACTIVE)->sharedLock()->first();

                    if ($lockedCompany === null) {
                        throw ValidationException::withMessages(['company_id' => __('The selected company is unavailable.')]);
                    }

                    return $operation();
                }),
            );
        } catch (UniqueConstraintViolationException $exception) {
            if ($exception->getConnectionName() !== 'pgsql'
                || ($exception->errorInfo[0] ?? null) !== '23505'
                || ! str_contains($exception->errorInfo[2] ?? '', '"drivers_company_id_license_unique"')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'license' => __('This license is already reserved by a driver in this company.'),
            ]);
        }
    }

    /** @param array<string, mixed> $employment */
    private function provisionEmployment(Company $company, User $user, array $employment): Driver
    {
        if ($user->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'user_id' => __('A global system admin cannot be registered as a driver.'),
            ]);
        }

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

            if (! $membership->save()) {
                throw new RuntimeException('Company membership creation was cancelled.');
            }
        } elseif ($membership->trashed() && ! $membership->restore()) {
            throw new RuntimeException('Company membership restoration was cancelled.');
        }

        $role = Role::withoutEvents(fn (): Role => Role::withoutGlobalScopes()->firstOrCreate([
            'company_id' => $company->getKey(), 'name' => UserRole::Driver->value, 'guard_name' => 'web',
        ], ['color' => Role::generateColor()]));

        $user->unsetRelation('roles')->unsetRelation('permissions');
        $user->assignRole($role);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $driver->setRelation('user', $user);
    }
}
