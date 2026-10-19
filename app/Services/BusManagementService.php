<?php

namespace App\Services;

use App\Models\Bus;
use App\Models\Company;
use App\Models\User;

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
        return Bus::query()->create([
            'company_id' => $company->getKey(),
            'country_code' => $data['country_code'],
            'plate_number' => $data['plate_number'],
            'unit_number' => $data['unit_number'],
            'capacity' => $data['capacity'],
        ]);
    }
}
