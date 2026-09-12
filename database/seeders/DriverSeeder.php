<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DriverSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('slug', 'smartbus-demo')->first();
        $user = User::query()->first();

        if (! $company || ! $user) {
            $this->command->warn('No se creo el conductor: se requiere una empresa y un usuario MySQL.');

            return;
        }

        $driver = Driver::query()->firstOrNew(['user_id' => $user->getKey()]);
        $driver->id ??= (string) Str::uuid();
        $driver->company_id = $company->getKey();
        $driver->license = 'LIC-0001-CR';
        $driver->status = 'active';
        $driver->save();
    }
}
