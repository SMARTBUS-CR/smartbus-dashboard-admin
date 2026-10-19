<?php

namespace App\Services;

use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use illuminate\support\Facades\Gate;

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

            return Bus::query()->create([
                'company_id' => $company->getKey(),
                'country_code' => $data['country_code'],
                'plate_number' => $data['plate_number'],
                'unit_number' => $data['unit_number'],
                'capacity' => $data['capacity'],
            ]);
        } finally {
            setPermissionsTeamId($previousTeam);

            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
