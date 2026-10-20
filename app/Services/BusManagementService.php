<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BusManagementService
{
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

            if ($company->status !== CompanyStatus::ACTIVE) {
                throw ValidationException::withMessages([
                    'company' => __('An active company context is required.'),
                ]);
            }

            Validator::make($data, [
                'id' => ['missing'],
                'company_id' => ['missing'],
                'is_active' => ['missing'],
                'created_at' => ['missing'],
                'updated_at' => ['missing'],
                'deleted_at' => ['missing'],
            ])->validate();

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
            ];

            Validator::make($normalized, [
                'country_code' => ['required', 'string', 'size:2'],
                'plate_number' => ['required', 'string', 'max:30'],
                'unit_number' => ['required', 'string', 'max:50'],
                'capacity' => ['required', 'integer', 'between:1,32767'],
            ])->validate();

            return Bus::query()->create([
                'company_id' => $company->getKey(),
                ...$normalized,
            ]);
        } finally {
            setPermissionsTeamId($previousTeam);

            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
