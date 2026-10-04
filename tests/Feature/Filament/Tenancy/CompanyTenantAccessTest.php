<?php

use App\Enums\UserRole;
use Filament\Facades\Filament;

describe('Company Tenant Access', function (): void {
    test('company admin resolves only assigned companies as tenants', function () {
        // Confirmed product decision: admin sees/manages ONLY assigned companies.
        [$assigned, $foreign] = createTenantPair();
        $admin = createUserWithRole(UserRole::Admin);
        $admin->companies()->syncWithoutDetaching([$assigned->id]);

        $panel = Filament::getPanel('admin');

        expect($admin->getTenants($panel)->modelKeys())->toBe([$assigned->id])
            ->and($admin->canAccessTenant($assigned))->toBeTrue()
            ->and($admin->canAccessTenant($foreign))->toBeFalse();
    });

});
