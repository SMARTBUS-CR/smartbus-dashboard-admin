<?php

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

describe('Driver Schedule Policy', function (): void {
    test('requires the schedule permission and company membership', function (string $ability, string $permission): void {
        [$company, $other] = createTenantPair();
        $driver = Driver::factory()->for($company)->create();
        $schedule = DriverSchedule::factory()->for($driver)->create();
        $actor = User::factory()->create();
        $arguments = [DriverSchedule::class, $company, $ability === 'create' ? $driver : $schedule];
        grantShield($actor, [$permission], $company);
        expect(Gate::forUser($actor)->allows($ability, $arguments))->toBeFalse();
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        setPermissionsTeamId($other->id);
        expect(Gate::forUser($actor)->allows($ability, $arguments))->toBeTrue();
        expect(getPermissionsTeamId())->toBe($other->id);
        CompanyUser::where('company_id', $company->id)->where('user_id', $actor->id)->sole()->delete();
        expect(Gate::forUser($actor)->allows($ability, $arguments))->toBeFalse();
    })->with([
        ['viewAny', 'ViewAny:DriverSchedule'], ['view', 'View:DriverSchedule'],
        ['create', 'Create:DriverSchedule'], ['update', 'Update:DriverSchedule'], ['delete', 'Delete:DriverSchedule'],
    ]);

    test('rejects cross-company schedules for administrators including global administrators', function (bool $global): void {
        [$company, $other] = createTenantPair();
        $driver = Driver::factory()->for($other)->create();
        $schedule = DriverSchedule::factory()->for($driver)->create();
        $actor = $global ? createUserWithRole(UserRole::SuperAdmin) : User::factory()->create();
        if (! $global) {
            CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
            grantShield($actor, ['View:DriverSchedule', 'Update:DriverSchedule', 'Delete:DriverSchedule'], $company);
        }
        foreach (['view', 'update', 'delete'] as $ability) {
            expect(Gate::forUser($actor)->allows($ability, [DriverSchedule::class, $company, $schedule]))->toBeFalse();
        }
    })->with([false, true]);

    test('authorizes schedule creation only for a persisted driver in the explicit company', function (): void {
        [$company, $other] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $driver = Driver::factory()->for($company)->create();
        expect(Gate::forUser($actor)->allows('create', [DriverSchedule::class, $company, $driver]))->toBeTrue();
        expect(Gate::forUser($actor)->allows('create', [DriverSchedule::class, $other, $driver]))->toBeFalse();
        $company->update(['status' => CompanyStatus::INACTIVE]);
        expect(Gate::forUser($actor)->allows('create', [DriverSchedule::class, $company, $driver]))->toBeFalse();
    });

    test('does not grant schedule administration through driver or passenger roles', function (UserRole $role): void {
        $company = createCompany();
        $actor = createUserWithRole($role, $company);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        $driver = Driver::factory()->for($company)->create();
        $schedule = DriverSchedule::factory()->for($driver)->create();

        foreach (['viewAny', 'view', 'create', 'update', 'delete'] as $ability) {
            $record = $ability === 'create' ? $driver : $schedule;
            expect(Gate::forUser($actor)->allows($ability, [DriverSchedule::class, $company, $record]))->toBeFalse();
        }
    })->with([UserRole::Driver, UserRole::Passenger]);

    test('checks persisted ownership instead of a substituted driver relation', function (): void {
        [$company, $other] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $local = Driver::factory()->for($company)->create();
        $foreign = Driver::factory()->for($other)->create();
        $schedule = DriverSchedule::factory()->for($foreign)->create();
        $schedule->driver_id = $local->id;
        $schedule->setRelation('driver', $local);

        expect(Gate::forUser($actor)->allows('view', [DriverSchedule::class, $company, $schedule]))->toBeFalse();
    });

    test('ignores permissions from a different team and denies archived actors', function (): void {
        [$company, $other] = createTenantPair();
        $actor = User::factory()->create();
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        grantShield($actor, ['View:DriverSchedule'], $other);
        $driver = Driver::factory()->for($company)->create();
        $schedule = DriverSchedule::factory()->for($driver)->create();
        setPermissionsTeamId($other->id);
        $actor->load('roles', 'permissions');
        expect(Gate::forUser($actor)->allows('view', [DriverSchedule::class, $company, $schedule]))->toBeFalse();
        expect(getPermissionsTeamId())->toBe($other->id);
        expect($actor->relationLoaded('roles'))->toBeFalse();
        grantShield($actor, ['View:DriverSchedule'], $company);
        User::whereKey($actor->id)->delete();
        expect(Gate::forUser($actor)->allows('view', [DriverSchedule::class, $company, $schedule]))->toBeFalse();
    });
});
