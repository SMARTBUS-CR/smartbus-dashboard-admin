<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusManagementService
{
    /**
     * @return Collection<int, Bus>
     */
    public function listFor(User $actor, Company $company): Collection
    {
        $previousTeam = getPermissionsTeamId();

        try {
            setPermissionsTeamId($company->getKey());
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($actor)->authorize('viewAny', [Bus::class, $company]);
            $this->ensureActiveCompany($company);

            return Bus::query()->where('company_id', $company->getKey())
                ->orderBy('unit_number')->orderBy('id')->get();
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function findFor(User $actor, Company $company, string $busId): Bus
    {
        $previousTeam = getPermissionsTeamId();

        try {
            setPermissionsTeamId($company->getKey());
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($actor)->authorize('view', [Bus::class, $company]);
            $this->ensureActiveCompany($company);

            $bus = Bus::withTrashed()->where('company_id', $company->getKey())->findOrFail($busId);
            Gate::forUser($actor)->authorize('view', [Bus::class, $company, $bus]);

            return $bus;
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        User $actor,
        Company $company,
        array $data,
    ): Bus {
        $previousTeam = getPermissionsTeamId();

        try {
            setPermissionsTeamId($company->getKey());

            $actor->unsetRelation('roles')->unsetRelation('permissions');

            Gate::forUser($actor)->authorize(
                'create',
                [Bus::class, $company],
            );

            $this->ensureActiveCompany($company);

            $this->validateAllowedFields($data);

            $normalized = $this->validateDetails($company, $data);

            return $this->persist(fn (): Bus => Bus::query()->create([
                'company_id' => $company->getKey(),
                ...$normalized,
            ])->refresh());
        } finally {
            setPermissionsTeamId($previousTeam);

            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Company $company, string $busId, array $data): Bus
    {
        $previousTeam = getPermissionsTeamId();

        try {
            setPermissionsTeamId($company->getKey());
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            Gate::forUser($actor)->authorize('update', [Bus::class, $company]);
            $this->ensureActiveCompany($company);
            $this->validateAllowedFields($data);

            return $this->persist(function () use ($actor, $company, $busId, $data): Bus {
                $bus = Bus::query()->where('company_id', $company->getKey())
                    ->lockForUpdate()->findOrFail($busId);

                Gate::forUser($actor)->authorize('update', [Bus::class, $company, $bus]);

                $details = array_replace($bus->only([
                    'country_code', 'plate_number', 'unit_number', 'capacity', 'brand', 'model', 'year',
                ]), $data);
                $bus->fill($this->validateDetails($company, $details, $bus));
                $bus->save();

                return $bus;
            });
        } finally {
            setPermissionsTeamId($previousTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function setActive(
        User $actor,
        Company $company,
        string $busId,
        bool $active,
    ): Bus {
        $previousTeam = getPermissionsTeamId();

        try {
            setPermissionsTeamId($company->getKey());

            $actor->unsetRelation('roles')->unsetRelation('permissions');

            Gate::forUser($actor)->authorize(
                'update',
                [Bus::class, $company],
            );

            $this->ensureActiveCompany($company);

            return $this->persist(function () use (
                $actor,
                $company,
                $busId,
                $active,
            ): Bus {
                $bus = Bus::query()
                    ->where('company_id', $company->getKey())
                    ->lockForUpdate()
                    ->findOrFail($busId);

                Gate::forUser($actor)->authorize(
                    'update',
                    [Bus::class, $company, $bus],
                );

                $bus->is_active = $active;
                $bus->save();

                return $bus->refresh();
            });
        } finally {
            setPermissionsTeamId($previousTeam);

            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function archive(
        User $actor,
        Company $company,
        string $busId,
    ): Bus {
        $previousTeam = getPermissionsTeamId();

        try {
            setPermissionsTeamId($company->getKey());

            $actor->unsetRelation('roles')->unsetRelation('permissions');

            Gate::forUser($actor)->authorize(
                'delete',
                [Bus::class, $company],
            );

            $this->ensureActiveCompany($company);

            return $this->persist(function () use (
                $actor,
                $company,
                $busId,
            ): Bus {
                $bus = Bus::query()
                    ->where('company_id', $company->getKey())
                    ->lockForUpdate()
                    ->findOrFail($busId);

                Gate::forUser($actor)->authorize(
                    'delete',
                    [Bus::class, $company, $bus],
                );

                $bus->delete();

                return $bus;
            });
        } finally {
            setPermissionsTeamId($previousTeam);

            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    private function ensureActiveCompany(Company $company): void
    {
        $current = Company::query()->find($company->getKey());

        if (! $current || $current->status !== CompanyStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'company' => __('An active company context is required.'),
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function validateAllowedFields(array $data): void
    {
        $allowed = ['country_code', 'plate_number', 'unit_number', 'brand', 'model', 'year', 'capacity'];
        $unexpected = array_diff(array_keys($data), $allowed);
        Validator::make($data, array_fill_keys($unexpected, ['missing']))->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateDetails(Company $company, array $data, ?Bus $bus = null): array
    {
        $normalized = [
            'country_code' => isset($data['country_code'])
                && is_string($data['country_code'])
                    ? strtoupper(trim($data['country_code']))
                    : ($data['country_code'] ?? null),
            'plate_number' => isset($data['plate_number'])
                && is_string($data['plate_number'])
                    ? strtoupper(trim($data['plate_number']))
                    : ($data['plate_number'] ?? null),
            'unit_number' => isset($data['unit_number'])
                && is_string($data['unit_number'])
                    ? trim($data['unit_number'])
                    : ($data['unit_number'] ?? null),
            'capacity' => $data['capacity'] ?? null,
            'brand' => isset($data['brand']) && is_string($data['brand'])
                ? trim($data['brand'])
                : ($data['brand'] ?? null),
            'model' => isset($data['model']) && is_string($data['model'])
                ? trim($data['model'])
                : ($data['model'] ?? null),
            'year' => is_string($data['year'] ?? null)
                && trim($data['year']) === ''
                    ? null
                    : ($data['year'] ?? null),
        ];

        Validator::make($normalized, [
            'country_code' => [
                'required',
                'string',
                'size:2',
                Rule::in(array_keys(config('countries'))),
            ],
            'plate_number' => ['required', 'string', 'max:30'],
            'unit_number' => ['required', 'string', 'max:50'],
            'capacity' => ['required', 'integer', 'between:1,32767'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'between:1900,'.(now()->year + 1)],
        ])->validate();

        Validator::make($normalized, [
            'plate_number' => [
                Rule::unique(Bus::class, 'plate_number')
                    ->where('country_code', $normalized['country_code'])
                    ->ignore($bus?->getKey()),
            ],
            'unit_number' => [
                Rule::unique(Bus::class, 'unit_number')
                    ->where('company_id', $company->getKey())
                    ->ignore($bus?->getKey()),
            ],
        ])->validate();

        return $normalized;
    }

    /** @param Closure(): Bus $operation */
    private function persist(Closure $operation): Bus
    {
        try {
            return (new Bus)->getConnection()->transaction($operation);
        } catch (UniqueConstraintViolationException $exception) {
            $detail = $exception->errorInfo[2] ?? '';

            $field = match (true) {
                str_contains($detail, '"buses_country_code_plate_number_unique"') => 'plate_number',
                str_contains($detail, '"buses_company_id_unit_number_unique"') => 'unit_number',
                default => null,
            };

            if ($field === null) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                $field => __('validation.unique', [
                    'attribute' => __('validation.attributes.'.$field),
                ]),
            ]);
        }
    }
}
