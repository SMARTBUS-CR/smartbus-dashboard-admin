<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\User;
use App\Services\DriverManagementService;
use App\Services\DriverScheduleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DriverSeeder extends Seeder
{
    public function run(DriverManagementService $drivers, DriverScheduleService $schedules): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->command?->warn('DriverSeeder skipped: only local, development and testing environments are approved.');

            return;
        }

        $company = Company::query()->where('slug', 'smartbus-demo')->where('status', CompanyStatus::ACTIVE)->first();
        if ($company === null) {
            $this->command?->warn('DriverSeeder skipped: an active smartbus-demo company is required.');

            return;
        }

        $actor = $this->administrator($company);
        if ($actor === null) {
            $this->command?->warn('DriverSeeder skipped: an active company administrator is required.');

            return;
        }

        $this->call(WorkforcePermissionsSeeder::class);

        foreach (['001', '002', '003'] as $number) {
            $id = 'd3000000-0000-4000-8000-000000000'.$number;
            $email = 'demo.driver.'.$number.'@example.invalid';
            $license = 'DEMO-DRIVER-'.$number;
            $user = User::withTrashed()->find($id);

            if (($user !== null && ($user->trashed() || $user->email !== $email))
                || User::withTrashed()->where('email', $email)->whereKeyNot($id)->exists()
                || Driver::withTrashed()->where('company_id', $company->id)->where('user_id', $id)->exists()
                || Driver::withTrashed()->where('company_id', $company->id)->where('license', $license)->exists()
                || CompanyUser::onlyTrashed()->where('company_id', $company->id)->where('user_id', $id)->exists()) {
                $this->command?->warn('DriverSeeder skipped DEMO '.$number.': its identity or employment is already reserved.');

                continue;
            }

            $createdIdentity = $user === null;
            $user ??= User::factory()->create([
                'id' => $id, 'name' => 'DEMO Driver '.$number, 'email' => $email,
                'password' => Str::password(32), 'remember_token' => null,
            ]);

            try {
                $driver = $drivers->createForExistingUser($actor, $company, $user, [
                    'license' => $license, 'license_expires_at' => '2030-12-31',
                    'employment_starts_on' => '2026-10-01', 'employment_ends_on' => null,
                    'salary_amount' => '650000.00', 'salary_currency' => 'CRC', 'salary_period' => 'monthly',
                ]);
            } catch (Throwable $failure) {
                if ($createdIdentity && ! Driver::withTrashed()->where('user_id', $id)->exists()) {
                    $user->forceDelete();
                }

                throw $failure;
            }

            DB::connection('pgsql')->transaction(function () use ($actor, $company, $driver, $schedules): void {
                foreach (range(1, 5) as $day) {
                    $schedules->create($actor, $company, $driver, [
                        'day_of_week' => $day, 'start_time' => '08:00:00', 'end_time' => '16:00:00',
                        'ends_next_day' => false, 'valid_from' => null, 'valid_until' => null,
                    ]);
                }
            });
        }
    }

    private function administrator(Company $company): ?User
    {
        $members = CompanyUser::query()->where('company_id', $company->id)->pluck('user_id');
        $ids = DB::connection('mysql')->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.company_id', $company->id)->where('roles.company_id', $company->id)
            ->where('roles.name', UserRole::Admin->value)->where('roles.guard_name', 'web')
            ->where('assignments.model_type', (new User)->getMorphClass())->pluck('assignments.model_uuid');

        return User::query()->whereIn('id', $members)->whereIn('id', $ids)->orderBy('id')->first();
    }
}
