<?php

use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\CompanyUser;
use App\Services\BusManagementService;

describe('Bus Lifecycle', function (): void {
    test('deactivates a company bus for an authorized administrator', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['Update:Bus'], $company);

        $bus = Bus::factory()->for($company)->active()->create();

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'is_active' => true,
        ]);

        $updated = app(BusManagementService::class)->setActive(
            $actor,
            $company,
            $bus->getKey(),
            false,
        );

        expect($updated->getKey())->toBe($bus->getKey())
            ->and($updated->is_active)->toBeFalse();

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'company_id' => $company->getKey(),
            'is_active' => false,
            'deleted_at' => null,
        ]);
    });
});
