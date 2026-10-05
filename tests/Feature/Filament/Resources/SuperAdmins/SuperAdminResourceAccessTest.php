<?php

use App\Enums\UserRole;
use App\Filament\Resources\SuperAdmins\SuperAdminResource;
use App\Models\CompanyUser;
use Filament\Facades\Filament;

describe('Global SuperAdmin Resource Access', function (): void {
    test('allows a super-admin to manage global accounts independently of the tenant', function (bool $withTenant): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);

        if ($withTenant) {
            actingAsInCompany($actor, createCompany());
        } else {
            $this->actingAs($actor, 'web');

            Filament::setCurrentPanel('admin');
            Filament::setTenant(null, isQuiet: true);
            setPermissionsTeamId(null);
        }

        expect(SuperAdminResource::canViewAny())->toBeTrue()
            ->and(SuperAdminResource::canCreate())->toBeTrue()
            ->and(SuperAdminResource::canEdit($target))->toBeTrue();
    })->with([
        'without a selected company' => [false],
        'with a selected company' => [true],
    ]);

    test('rejects a company admin even with user management permissions', function (): void {
        $actorCompany = createCompany();
        $target = createUserWithRole(UserRole::SuperAdmin);
        $actor = createUserWithRole(UserRole::Admin, $actorCompany);

        CompanyUser::create([
            'company_id' => $actorCompany->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        actingAsInCompany($actor, $actorCompany);

        grantShield($actor, [
            'ViewAny:User',
            'Create:User',
            'View:User',
            'Update:User',
        ], $actorCompany);

        expect(SuperAdminResource::canViewAny())->toBeFalse()
            ->and(SuperAdminResource::canCreate())->toBeFalse()
            ->and(SuperAdminResource::canEdit($target))->toBeFalse();
    });

    test('rejects editing a company account through the global resource', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $company = createCompany();
        $target = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $target->getKey(),
        ]);

        actingAsInCompany($actor, $company);

        expect(SuperAdminResource::canEdit($target))->toBeFalse();
    });
});
