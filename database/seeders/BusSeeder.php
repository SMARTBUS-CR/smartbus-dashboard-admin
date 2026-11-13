<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Models\Bus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

class BusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('BusSeeder skipped in production.');

            return;
        }

        $company = Company::query()->where('slug', 'smartbus-demo')
            ->where('status', CompanyStatus::ACTIVE)->first();

        if (! $company) {
            $this->command?->warn('BusSeeder skipped: an active smartbus-demo company is required.');

            return;
        }

        $company->getConnection()->transaction(function () use ($company): void {
            foreach ([
                'b0500000-0000-4000-8000-000000000001' => '001',
                'b0500000-0000-4000-8000-000000000002' => '002',
                'b0500000-0000-4000-8000-000000000003' => '003',
            ] as $id => $number) {
                if (Bus::withTrashed()->whereKey($id)->exists()) {
                    continue;
                }

                $plate = 'DEMO-BUS-'.$number;
                $unit = 'DEMO-'.$number;
                $reserved = Bus::withTrashed()->where(function (Builder $query) use ($company, $plate, $unit): void {
                    $query->where(function (Builder $query) use ($plate): void {
                        $query->where('country_code', 'CR')->where('plate_number', $plate);
                    })->orWhere(function (Builder $query) use ($company, $unit): void {
                        $query->where('company_id', $company->getKey())->where('unit_number', $unit);
                    });
                })->exists();

                if ($reserved) {
                    $this->command?->warn("BusSeeder skipped {$unit}: its identity is already reserved.");

                    continue;
                }

                $bus = new Bus([
                    'company_id' => $company->getKey(),
                    'country_code' => 'CR',
                    'plate_number' => $plate,
                    'unit_number' => $unit,
                    'capacity' => 40,
                ]);
                $bus->id = $id;
                $bus->save();
            }
        });
    }
}
