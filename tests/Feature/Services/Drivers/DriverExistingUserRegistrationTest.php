<?php

use App\Models\CompanyUser;
use App\Models\User;
use App\Services\DriverManagementService;
use Illuminate\Support\Facades\DB;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class);

describe('Driver Existing User Registration', function (): void {
    test('links an existing identity with active membership and only the target company driver role without Filament', function (): void {
        $company = createCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $user = User::factory()->create();
        $originalPassword = $user->getRawOriginal('password');
        $previousTeam = createCompany()->id;
        setPermissionsTeamId($previousTeam);

        $driver = app(DriverManagementService::class)->createForExistingUser($actor, $company, $user, $this->validDriverEmployment());

        $this->assertModelExists($driver);
        expect($driver->company_id)->toBe($company->id);
        expect($driver->user_id)->toBe($user->id);
        expect($driver->salary_amount)->toBe('650000.25');
        expect($user->fresh()->getRawOriginal('password'))->toBe($originalPassword);
        expect(getPermissionsTeamId())->toBe($previousTeam);
        expect(CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->exists())->toBeTrue();
        $assignment = DB::connection('mysql')->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_uuid', $user->id)
            ->where('assignments.model_type', $user->getMorphClass())
            ->select(['assignments.company_id as team', 'roles.company_id as owner', 'roles.name', 'roles.guard_name'])
            ->sole();
        expect((array) $assignment)->toBe(['team' => $company->id, 'owner' => $company->id, 'name' => 'driver', 'guard_name' => 'web']);
        $this->assertDatabaseCount('users', 2, 'mysql');
    });
});
