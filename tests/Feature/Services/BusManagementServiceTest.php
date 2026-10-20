<?php

use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Services\BusManagementService;
use illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use illuminate\validation\ValidationException;

describe('Bus Creation', function (): void {
    test('maps identity conflicts raised after validation to field errors', function (string $field): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $dispatcher = Bus::getEventDispatcher();
        Bus::setEventDispatcher(clone $dispatcher);

        try {
            Bus::creating(function (Bus $bus) use ($field): void {
                DB::table('buses')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $bus->company_id,
                    'country_code' => $bus->country_code,
                    'plate_number' => $field === 'plate_number' ? $bus->plate_number : 'OTHER-PLATE',
                    'unit_number' => $field === 'unit_number' ? $bus->unit_number : 'OTHER-UNIT',
                    'capacity' => 40,
                ]);
            });

            try {
                app(BusManagementService::class)->create($actor, $company, [
                    'country_code' => 'CR',
                    'plate_number' => 'BUS-101',
                    'unit_number' => 'UNIT-101',
                    'capacity' => 40,
                ]);

                $this->fail('Expected a validation exception.');
            } catch (ValidationException $exception) {
                expect($exception->errors())->toHaveKey($field);
            }
        } finally {
            Bus::setEventDispatcher($dispatcher);
        }

        $this->assertDatabaseCount('buses', 0);
    })->with([
        'plate conflict' => ['plate_number'],
        'unit conflict' => ['unit_number'],
    ]);

    test('allows identifiers reused outside their uniqueness scope', function (bool $differentCompany): void {
        $company = createCompany();
        $targetCompany = $differentCompany ? createCompany() : $company;
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $service = app(BusManagementService::class);

        $existing = $service->create($actor, $company, [
            'country_code' => 'CR',
            'plate_number' => 'BUS-101',
            'unit_number' => 'UNIT-101',
            'capacity' => 40,
        ]);

        $this->assertModelExists($existing);

        $bus = $service->create($actor, $targetCompany, [
            'country_code' => $differentCompany ? 'CR' : 'PA',
            'plate_number' => $differentCompany ? 'BUS-102' : 'BUS-101',
            'unit_number' => $differentCompany ? 'UNIT-101' : 'UNIT-102',
            'capacity' => 40,
        ]);

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'company_id' => $targetCompany->getKey(),
            'country_code' => $differentCompany ? 'CR' : 'PA',
            'plate_number' => $differentCompany ? 'BUS-102' : 'BUS-101',
            'unit_number' => $differentCompany ? 'UNIT-101' : 'UNIT-102',
        ]);
        $this->assertDatabaseCount('buses', 2);
    })->with([
        'same plate in another country' => [false],
        'same unit in another company' => [true],
    ]);

    test('rejects the same country and plate registered by another company', function (): void {
        [$company, $otherCompany] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $service = app(BusManagementService::class);

        $existing = $service->create($actor, $company, [
            'country_code' => 'CR',
            'plate_number' => 'BUS-101',
            'unit_number' => 'UNIT-101',
            'capacity' => 40,
        ]);

        $this->assertModelExists($existing);

        try {
            $service->create($actor, $otherCompany, [
                'country_code' => 'CR',
                'plate_number' => 'BUS-101',
                'unit_number' => 'UNIT-102',
                'capacity' => 40,
            ]);

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('plate_number');
            $this->assertDatabaseMissing('buses', ['company_id' => $otherCompany->getKey()]);
            $this->assertDatabaseCount('buses', 1);
        }
    });

    test('rejects reserved bus identifiers including archived buses', function (string $field, bool $archived): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $service = app(BusManagementService::class);
        $existing = $service->create($actor, $company, [
            'country_code' => 'CR',
            'plate_number' => 'BUS-101',
            'unit_number' => 'UNIT-101',
            'capacity' => 40,
        ]);

        $this->assertModelExists($existing);

        if ($archived) {
            $existing->delete();
            $this->assertSoftDeleted($existing);
        }

        try {
            $service->create($actor, $company, [
                'country_code' => 'CR',
                'plate_number' => $field === 'plate_number' ? ' bus-101 ' : 'BUS-102',
                'unit_number' => $field === 'unit_number' ? ' UNIT-101 ' : 'UNIT-102',
                'capacity' => 40,
            ]);

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);
            $this->assertDatabaseCount('buses', 1);
        }
    })->with([
        'existing plate' => ['plate_number', false],
        'archived plate' => ['plate_number', true],
        'existing unit' => ['unit_number', false],
        'archived unit' => ['unit_number', true],
    ]);

    test('rejects invalid optional bus details without persisting a bus', function (string $field, mixed $value): void {
        $this->travelTo(new DateTimeImmutable('2026-10-19 12:00:00'));

        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);

        try {
            app(BusManagementService::class)->create($actor, $company, [
                'country_code' => 'CR',
                'plate_number' => 'BUS-101',
                'unit_number' => 'UNIT-101',
                'capacity' => 40,
                $field => $value,
            ]);

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);

            $this->assertDatabaseMissing('buses', ['company_id' => $company->getKey()]);
        }
    })->with([
        'brand too long' => ['brand', str_repeat('A', 101)],
        'non-string brand' => ['brand', 123],
        'model too long' => ['model', str_repeat('A', 101)],
        'non-string model' => ['model', 123],
        'year below minimum' => ['year', 1899],
        'year above maximum' => ['year', 2028],
        'fractional year' => ['year', 2025.5],
        'non-numeric year' => ['year', 'invalid'],
    ]);

    test('accepts optional bus details at valid boundaries', function (?int $year, ?string $brand, ?string $model): void {
        $this->travelTo(new DateTimeImmutable('2026-10-19 12:00:00'));

        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);

        $bus = app(BusManagementService::class)->create($actor, $company, [
            'country_code' => 'CR',
            'plate_number' => 'BUS-101',
            'unit_number' => 'UNIT-101',
            'capacity' => 40,
            'year' => $year,
            'brand' => $brand,
            'model' => $model,
        ]);

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'year' => $year,
            'brand' => $brand,
            'model' => $model,
        ]);
    })->with([
        'minimum year and maximum lengths' => [1900, str_repeat('A', 100), str_repeat('B', 100)],
        'next year' => [2027, 'Mercedes-Benz', 'O 500'],
        'explicit nulls' => [null, null, null],
    ]);

    test('persists normalized optional bus details', function (): void {
        $this->travelTo(new DateTimeImmutable('2026-10-19 12:00:00'));

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
                'brand' => ' Mercedes-Benz ',
                'model' => ' O 500 ',
                'year' => 2025,
            ],
        );

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'brand' => 'Mercedes-Benz',
            'model' => 'O 500',
            'year' => 2025,
        ]);
    });

    test('allows a supported registration country different from the company country', function (): void {
        $company = Company::factory()->forCountry('CR')->create();
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
                'country_code' => 'PA',
                'plate_number' => 'BUS-101',
                'unit_number' => 'UNIT-101',
                'capacity' => 40,
            ],
        );

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'company_id' => $company->getKey(),
            'country_code' => 'PA',
        ]);
    });

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
