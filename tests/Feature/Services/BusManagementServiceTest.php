<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Services\BusManagementService;
use illuminate\Auth\Access\AuthorizationException;
use illuminate\validation\ValidationException;

describe('Bus Creation', function (): void {
    test('creates a bus for an authorized company admin without a Filament tenant', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['Create:Bus'], $company);

        $bus = app(BusManagementService::class)->create(
            $actor,
            $company,
            [
                'country_code' => 'CR',
                'plate_number' => 'BUS-101',
                'unit_number' => 'UNIT-101',
                'capacity' => 40,
            ],
        );

        $this->assertModelExists($bus);

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'company_id' => $company->getKey(),
            'country_code' => 'CR',
            'plate_number' => 'BUS-101',
            'unit_number' => 'UNIT-101',
            'capacity' => 40,
            'is_active' => true,
        ]);
    });
    test('rejects bus creation when the company admin lacks permission', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        try {
            app(BusManagementService::class)->create(
                $actor,
                $company,
                [
                    'country_code' => 'CR',
                    'plate_number' => 'BUS-101',
                    'unit_number' => 'UNIT-101',
                    'capacity' => 40,
                ],
            );

            $this->fail('Expected an authorization exception.');
        } catch (AuthorizationException $exception) {
            $this->assertDatabaseMissing('buses', [
                'company_id' => $company->getKey(),
                'plate_number' => 'BUS-101',
            ]);
        }
    });

    test('rejects bus creation in an inactive company even for a super admin', function (): void {
        $company = Company::factory()->inactive()->create();
        $actor = createUserWithRole(UserRole::SuperAdmin);

        try {
            app(BusManagementService::class)->create(
                $actor,
                $company,
                [
                    'country_code' => 'CR',
                    'plate_number' => 'BUS-101',
                    'unit_number' => 'UNIT-101',
                    'capacity' => 40,
                ],
            );

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('company');

            $this->assertDatabaseMissing('buses', [
                'company_id' => $company->getKey(),
                'plate_number' => 'BUS-101',
            ]);
        }
    });
});
