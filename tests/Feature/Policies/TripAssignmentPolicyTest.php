<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\TripAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

describe('Trip Assignment Policy', function (): void {
    test('requires active membership and the operation permission', function (string $ability, string $permission): void {
        [$company, $previous] = createTenantPair();
        $assignment = TripAssignment::factory()->for($company)->create();
        $actor = User::factory()->create();
        CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $actor->id]);
        $arguments = in_array($ability, ['viewAny', 'create'], true)
            ? [TripAssignment::class, $company]
            : [TripAssignment::class, $company, $assignment];
        setPermissionsTeamId($previous->id);

        expect(Gate::forUser($actor)->allows($ability, $arguments))->toBeFalse();
        grantShield($actor, [$permission], $company);
        expect(Gate::forUser($actor)->allows($ability, $arguments))->toBeTrue()
            ->and(getPermissionsTeamId())->toBe($previous->id);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $actor->id)->delete();
        expect(Gate::forUser($actor)->allows($ability, $arguments))->toBeFalse();
    })->with([
        ['viewAny', 'ViewAny:TripAssignment'],
        ['view', 'View:TripAssignment'],
        ['create', 'Create:TripAssignment'],
        ['update', 'Update:TripAssignment'],
        ['delete', 'Delete:TripAssignment'],
        ['restore', 'Restore:TripAssignment'],
    ]);

    test('allows global administrators while preserving company boundaries', function (): void {
        [$company, $other] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $assignment = TripAssignment::factory()->for($other)->create();

        expect(Gate::forUser($actor)->allows('viewAny', [TripAssignment::class, $company]))->toBeTrue()
            ->and(Gate::forUser($actor)->allows('view', [TripAssignment::class, $company, $assignment]))->toBeFalse()
            ->and(Gate::forUser($actor)->allows('update', [TripAssignment::class, $company, $assignment]))->toBeFalse()
            ->and(Gate::forUser($actor)->allows('delete', [TripAssignment::class, $company, $assignment]))->toBeFalse();
    });

    test('does not grant assignment administration through employee roles', function (UserRole $role): void {
        $company = createCompany();
        $actor = createUserWithRole($role, $company);
        CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $actor->id]);
        grantShield($actor, [
            'ViewAny:TripAssignment', 'View:TripAssignment', 'Create:TripAssignment',
            'Update:TripAssignment', 'Delete:TripAssignment', 'Restore:TripAssignment',
        ], $company);
        $assignment = TripAssignment::factory()->for($company)->create();

        foreach (['viewAny', 'create', 'view', 'update', 'delete', 'restore'] as $ability) {
            $arguments = in_array($ability, ['viewAny', 'create'], true)
                ? [TripAssignment::class, $company]
                : [TripAssignment::class, $company, $assignment];
            expect(Gate::forUser($actor)->allows($ability, $arguments))->toBeFalse();
        }
    })->with([UserRole::Driver, UserRole::Passenger]);
});
