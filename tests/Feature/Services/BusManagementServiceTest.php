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

    test('normalizes bus identifiers before persisting them', function (): void {
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
                'country_code' => ' cr ',
                'plate_number' => ' bus-101 ',
                'unit_number' => ' UNIT-101 ',
                'capacity' => 40,
            ],
        );

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'company_id' => $company->getKey(),
            'country_code' => 'CR',
            'plate_number' => 'BUS-101',
            'unit_number' => 'UNIT-101',
        ]);
    });
    test('rejects a plate number that is empty after normalization', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['Create:Bus'], $company);

        try {
            app(BusManagementService::class)->create(
                $actor,
                $company,
                [
                    'country_code' => 'CR',
                    'plate_number' => '   ',
                    'unit_number' => 'UNIT-101',
                    'capacity' => 40,
                ],
            );

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('plate_number');

            $this->assertDatabaseMissing('buses', [
                'company_id' => $company->getKey(),
                'unit_number' => 'UNIT-101',
            ]);
        }
    });
    test('rejects invalid capacity without persisting a bus', function (mixed $capacity): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['Create:Bus'], $company);

        try {
            app(BusManagementService::class)->create(
                $actor,
                $company,
                [
                    'country_code' => 'CR',
                    'plate_number' => 'BUS-101',
                    'unit_number' => 'UNIT-101',
                    'capacity' => $capacity,
                ],
            );

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('capacity');

            $this->assertDatabaseMissing('buses', [
                'company_id' => $company->getKey(),
                'unit_number' => 'UNIT-101',
            ]);
        }
    })->with([
        'zero' => [0],
        'negative' => [-1],
        'above maximum' => [32768],
        'fractional' => [40.5],
        'non-numeric' => ['invalid'],
        'null' => [null],
    ]);

    test('accepts capacity at the allowed boundaries', function (int $capacity): void {
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
                'capacity' => $capacity,
            ],
        );

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'company_id' => $company->getKey(),
            'capacity' => $capacity,
        ]);
    })->with([
        'minimum' => [1],
        'maximum' => [32767],
    ]);
    test('rejects protected creation fields without persisting a bus', function (
        string $field,
        mixed $value,
    ): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['Create:Bus'], $company);

        $data = [
            'country_code' => 'CR',
            'plate_number' => 'BUS-101',
            'unit_number' => 'UNIT-101',
            'capacity' => 40,
            $field => $value,
        ];

        try {
            app(BusManagementService::class)->create(
                $actor,
                $company,
                $data,
            );

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);

            $this->assertDatabaseMissing('buses', [
                'company_id' => $company->getKey(),
                'unit_number' => 'UNIT-101',
            ]);
        }
    })->with([
        'supplied id' => ['id', '00000000-0000-4000-8000-000000000001'],
        'supplied company' => ['company_id', '00000000-0000-4000-8000-000000000002'],
        'supplied active state' => ['is_active', false],
        'supplied creation timestamp' => ['created_at', '2026-01-01 00:00:00'],
        'supplied update timestamp' => ['updated_at', '2026-01-01 00:00:00'],
        'supplied archive timestamp' => ['deleted_at', '2026-01-01 00:00:00'],
        'supplied null id' => ['id', null],
    ]);
    test('rejects missing required fields without persisting a bus', function (string $field): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['Create:Bus'], $company);

        $data = [
            'country_code' => 'CR',
            'plate_number' => 'BUS-101',
            'unit_number' => 'UNIT-101',
            'capacity' => 40,
        ];

        unset($data[$field]);

        try {
            app(BusManagementService::class)->create(
                $actor,
                $company,
                $data,
            );

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);

            $this->assertDatabaseMissing('buses', [
                'company_id' => $company->getKey(),
            ]);
        }
    })->with([
        'missing country' => ['country_code'],
        'missing plate' => ['plate_number'],
        'missing unit' => ['unit_number'],
        'missing capacity' => ['capacity'],
    ]);
    test('rejects an unsupported registration country without persisting a bus', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['Create:Bus'], $company);

        try {
            app(BusManagementService::class)->create(
                $actor,
                $company,
                [
                    'country_code' => 'US',
                    'plate_number' => 'BUS-101',
                    'unit_number' => 'UNIT-101',
                    'capacity' => 40,
                ],
            );

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('country_code');

            $this->assertDatabaseMissing('buses', [
                'company_id' => $company->getKey(),
                'unit_number' => 'UNIT-101',
            ]);
        }
    });
});
