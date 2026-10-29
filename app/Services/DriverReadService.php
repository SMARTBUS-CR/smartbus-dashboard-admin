<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class DriverReadService
{
    /** @var list<string> */
    private const PROFILE_FIELDS = [
        'id', 'company_id', 'user_id', 'license', 'license_expires_at',
        'employment_starts_on', 'employment_ends_on', 'status', 'created_at', 'updated_at', 'deleted_at',
    ];

    /** @return Collection<int, array<string, mixed>> */
    public function listFor(User $actor, Company $company): Collection
    {
        Gate::forUser($actor)->authorize('viewAny', [Driver::class, $company]);

        return Driver::query()->where('company_id', $company->getKey())->orderBy('id')
            ->get(self::PROFILE_FIELDS)->map(fn (Driver $driver): array => $driver->toArray());
    }

    /** @return array<string, mixed> */
    public function findFor(User $actor, Company $company, string $driverId): array
    {
        $driver = Driver::query()->where('company_id', $company->getKey())->findOrFail($driverId, self::PROFILE_FIELDS);
        Gate::forUser($actor)->authorize('view', [Driver::class, $company, $driver]);

        return $driver->toArray();
    }

    /** @return array<string, mixed> */
    public function findArchivedFor(User $actor, Company $company, string $driverId): array
    {
        $driver = Driver::onlyTrashed()->where('company_id', $company->getKey())->findOrFail($driverId, self::PROFILE_FIELDS);
        Gate::forUser($actor)->authorize('view', [Driver::class, $company, $driver]);

        return $driver->toArray();
    }

    /** @return array{salary_amount: string, salary_currency: string, salary_period: string} */
    public function salaryFor(User $actor, Company $company, string $driverId): array
    {
        $driver = Driver::query()->where('company_id', $company->getKey())->findOrFail($driverId, ['id', 'company_id']);
        Gate::forUser($actor)->authorize('viewSalary', [Driver::class, $company, $driver]);
        $salary = Driver::query()->where('company_id', $company->getKey())
            ->findOrFail($driverId, ['salary_amount', 'salary_currency', 'salary_period']);

        return [
            'salary_amount' => $salary->salary_amount,
            'salary_currency' => $salary->salary_currency,
            'salary_period' => $salary->salary_period,
        ];
    }
}
