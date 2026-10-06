<?php

use App\Enums\UserRole;
use App\Filament\Resources\Companies\CompanyResource;

describe('Company Resource Access', function (): void {
    test('only super-admins can access the companies resource', function () {
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);
        $companyAdmin = createUserWithRole(UserRole::Admin, createCompany());

        $this->actingAs($superAdmin);

        expect(CompanyResource::canAccess())->toBeTrue();

        $this->actingAs($companyAdmin);

        // Resource hides itself: proves the denial path, not just a 200.
        expect(CompanyResource::canAccess())->toBeFalse();
    });

});
