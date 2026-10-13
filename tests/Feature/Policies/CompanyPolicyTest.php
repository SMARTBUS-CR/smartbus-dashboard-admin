<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Policies\CompanyPolicy;
use Illuminate\Foundation\Auth\User as AuthUser;

describe('Company Policy', function () {
    test('before() grants every ability to super-admins', function () {
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);
        $policy = new CompanyPolicy;

        expect($policy->before($superAdmin, 'viewAny'))->toBeTrue()
            ->and($policy->before($superAdmin, 'delete'))->toBeTrue();
    });

    test('before() denies non-super-admins before any ability check', function (string $role) {
        // Contract lock: CompanyPolicy intentionally blocks everyone else at before().
        $user = createUserWithRole($role);
        $policy = new CompanyPolicy;

        expect($policy->before($user, 'viewAny'))->toBeFalse();
    })->with([
        'admin' => UserRole::Admin->value,
        'driver' => UserRole::Driver->value,
        'passenger' => UserRole::Passenger->value,
    ]);

    test('every ability returns false without the super-admin bypass', function () {
        // Proves the denial is the policy itself, not just Gate::before.
        $policy = new CompanyPolicy;
        $user = createUserWithRole(UserRole::Admin);
        $company = createCompany();
        $authUser = new AuthUser;

        expect($policy->viewAny($authUser))->toBeFalse()
            ->and($policy->view($authUser, $company))->toBeFalse()
            ->and($policy->create($authUser))->toBeFalse()
            ->and($policy->update($authUser, $company))->toBeFalse()
            ->and($policy->delete($authUser, $company))->toBeFalse()
            ->and($policy->deleteAny($authUser))->toBeFalse()
            ->and($policy->restore($authUser, $company))->toBeFalse()
            ->and($policy->forceDelete($authUser, $company))->toBeFalse()
            ->and($policy->forceDeleteAny($authUser))->toBeFalse()
            ->and($policy->restoreAny($authUser))->toBeFalse()
            ->and($policy->replicate($authUser, $company))->toBeFalse()
            ->and($policy->reorder($authUser))->toBeFalse()
            ->and($user->can('viewAny', Company::class))->toBeFalse();
    });

    test('super-admin passes the gate for company abilities', function () {
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);
        $company = createCompany();

        $this->actingAs($superAdmin);

        expect($superAdmin->can('viewAny', Company::class))->toBeTrue()
            ->and($superAdmin->can('view', $company))->toBeTrue()
            ->and($superAdmin->can('create', Company::class))->toBeTrue()
            ->and($superAdmin->can('update', $company))->toBeTrue()
            ->and($superAdmin->can('delete', $company))->toBeTrue();
    });
});
