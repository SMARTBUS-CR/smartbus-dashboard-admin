<?php

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\CompanyUser;
use App\Services\BusManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

describe('Bus Update', function (): void {
    test('keeps its own identifiers and clears optional details explicitly', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $service = app(BusManagementService::class);
        $bus = $service->create($actor, $company, [
            'country_code' => 'CR', 'plate_number' => 'BUS-101', 'unit_number' => 'UNIT-101',
            'capacity' => 40, 'brand' => 'Mercedes-Benz', 'model' => 'O 500', 'year' => 2025,
        ]);

        $service->update($actor, $company, $bus->getKey(), [
            'plate_number' => ' bus-101 ', 'unit_number' => ' UNIT-101 ',
            'brand' => null, 'model' => null, 'year' => null,
        ]);

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(), 'company_id' => $company->getKey(),
            'plate_number' => 'BUS-101', 'unit_number' => 'UNIT-101',
            'brand' => null, 'model' => null, 'year' => null, 'is_active' => true,
        ]);
    });

    test('rejects updates for nonoperational companies using current stored state', function (bool $archived): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $service = app(BusManagementService::class);
        $bus = $service->create($actor, $company, [
            'country_code' => 'CR', 'plate_number' => 'BUS-101', 'unit_number' => 'UNIT-101', 'capacity' => 40,
        ]);
        $changedCompany = $company->fresh();
        if ($archived) {
            $changedCompany->delete();
        } else {
            $changedCompany->update(['status' => CompanyStatus::INACTIVE]);
        }

        try {
            $service->update($actor, $company, $bus->getKey(), ['capacity' => 50]);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('company');
            expect($bus->fresh()->capacity)->toBe(40);
        }
    })->with(['inactive' => [false], 'archived' => [true]]);

    test('does not edit archived buses', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $service = app(BusManagementService::class);
        $bus = $service->create($actor, $company, [
            'country_code' => 'CR', 'plate_number' => 'BUS-101', 'unit_number' => 'UNIT-101', 'capacity' => 40,
        ]);
        $bus->delete();

        expect(fn () => $service->update($actor, $company, $bus->getKey(), ['capacity' => 50]))
            ->toThrow(ModelNotFoundException::class);
        $this->assertDatabaseHas('buses', ['id' => $bus->getKey(), 'capacity' => 40]);
    });

    test('restores the previous permission context after update outcomes', function (string $outcome): void {
        [$company, $previousCompany] = createTenantPair();
        $service = app(BusManagementService::class);
        $bus = $service->create(createUserWithRole(UserRole::SuperAdmin), $company, [
            'country_code' => 'CR', 'plate_number' => 'BUS-101', 'unit_number' => 'UNIT-101', 'capacity' => 40,
        ]);
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        if ($outcome !== 'authorization failure') {
            grantShield($actor, ['Update:Bus'], $company);
        }
        $originalTeam = getPermissionsTeamId();
        setPermissionsTeamId($previousCompany->getKey());
        $actor->load('roles', 'permissions');

        try {
            try {
                $service->update($actor, $company, $bus->getKey(), ['capacity' => $outcome === 'validation failure' ? 0 : 50]);
                expect($outcome)->toBe('success');
            } catch (AuthorizationException $exception) {
                expect($outcome)->toBe('authorization failure');
            } catch (ValidationException $exception) {
                expect($outcome)->toBe('validation failure');
            }
            expect(getPermissionsTeamId())->toBe($previousCompany->getKey())
                ->and($actor->relationLoaded('roles'))->toBeFalse()
                ->and($actor->relationLoaded('permissions'))->toBeFalse();
            expect($bus->fresh()->capacity)->toBe($outcome === 'success' ? 50 : 40);
        } finally {
            setPermissionsTeamId($originalTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    })->with(['success', 'authorization failure', 'validation failure']);

    test('translates late update conflicts without partial writes', function (string $locale, string $field, string $message): void {
        app()->setLocale($locale);
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $service = app(BusManagementService::class);
        $bus = $service->create($actor, $company, [
            'country_code' => 'CR', 'plate_number' => 'BUS-101', 'unit_number' => 'UNIT-101', 'capacity' => 40,
        ]);
        $before = $bus->fresh()->getAttributes();
        $dispatcher = Bus::getEventDispatcher();
        Bus::setEventDispatcher(clone $dispatcher);

        try {
            Bus::updating(function (Bus $record) use ($field): void {
                DB::table('buses')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $record->company_id,
                    'country_code' => $record->country_code,
                    'plate_number' => $field === 'plate_number' ? $record->plate_number : 'OTHER-PLATE',
                    'unit_number' => $field === 'unit_number' ? $record->unit_number : 'OTHER-UNIT',
                    'capacity' => 40,
                ]);
            });
            try {
                $service->update($actor, $company, $bus->getKey(), [
                    'plate_number' => 'BUS-102', 'unit_number' => 'UNIT-102', 'capacity' => 50,
                ]);
                $this->fail('Expected a validation exception.');
            } catch (ValidationException $exception) {
                expect($exception->errors()[$field])->toBe([$message]);
            }
        } finally {
            Bus::setEventDispatcher($dispatcher);
        }

        expect($bus->fresh()->getAttributes())->toBe($before);
        $this->assertDatabaseCount('buses', 1);
    })->with([
        'English plate' => ['en', 'plate_number', 'The plate number has already been taken.'],
        'Spanish plate' => ['es', 'plate_number', 'El campo matrícula ya ha sido registrado.'],
        'English unit' => ['en', 'unit_number', 'The unit number has already been taken.'],
        'Spanish unit' => ['es', 'unit_number', 'El campo número de unidad ya ha sido registrado.'],
    ]);

    test('updates normalized details while preserving ownership and omitted fields', function (): void {
        $this->travelTo(new DateTimeImmutable('2026-10-19 12:00:00'));
        $company = createCompany();
        $service = app(BusManagementService::class);
        $bus = $service->create(createUserWithRole(UserRole::SuperAdmin), $company, [
            'country_code' => 'CR', 'plate_number' => 'BUS-101',
            'unit_number' => 'UNIT-101', 'capacity' => 40, 'brand' => 'Mercedes-Benz',
        ]);
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        grantShield($actor, ['Update:Bus'], $company);

        $updated = $service->update($actor, $company, $bus->getKey(), [
            'country_code' => ' pa ', 'plate_number' => ' bus-102 ',
            'unit_number' => ' UNIT-102 ', 'capacity' => 50, 'model' => ' O 500 ', 'year' => 2027,
        ]);

        expect($updated->getKey())->toBe($bus->getKey());
        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(), 'company_id' => $company->getKey(),
            'country_code' => 'PA', 'plate_number' => 'BUS-102', 'unit_number' => 'UNIT-102',
            'capacity' => 50, 'brand' => 'Mercedes-Benz', 'model' => 'O 500', 'year' => 2027,
            'is_active' => true,
        ]);
    });

    test('rejects invalid updates without changing persisted details', function (string $field, mixed $value): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $service = app(BusManagementService::class);
        $bus = $service->create($actor, $company, [
            'country_code' => 'CR', 'plate_number' => 'BUS-101', 'unit_number' => 'UNIT-101', 'capacity' => 40,
        ]);
        $before = $bus->fresh()->getAttributes();

        try {
            $service->update($actor, $company, $bus->getKey(), ['brand' => 'Changed', $field => $value]);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);
            expect($bus->fresh()->getAttributes())->toBe($before);
        }
    })->with([
        'ownership' => ['company_id', null],
        'state' => ['is_active', false],
        'id' => ['id', null],
        'creation time' => ['created_at', null],
        'update time' => ['updated_at', null],
        'archive time' => ['deleted_at', null],
        'unknown field' => ['unexpected', null],
        'empty plate' => ['plate_number', '  '],
        'unsupported country' => ['country_code', 'US'],
        'invalid capacity' => ['capacity', 0],
        'invalid brand type' => ['brand', []],
    ]);

    test('reserves identifiers of other buses including archived buses during update', function (string $field, bool $archived): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $service = app(BusManagementService::class);
        $existing = $service->create($actor, $company, [
            'country_code' => 'CR', 'plate_number' => 'BUS-101', 'unit_number' => 'UNIT-101', 'capacity' => 40,
        ]);
        $bus = $service->create($actor, $company, [
            'country_code' => 'CR', 'plate_number' => 'BUS-102', 'unit_number' => 'UNIT-102', 'capacity' => 40,
        ]);
        if ($archived) {
            $existing->delete();
        }
        $before = $bus->fresh()->getAttributes();

        try {
            $service->update($actor, $company, $bus->getKey(), [$field => $existing->{$field}, 'capacity' => 50]);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);
            expect($bus->fresh()->getAttributes())->toBe($before);
        }
    })->with([
        'plate' => ['plate_number', false], 'archived plate' => ['plate_number', true],
        'unit' => ['unit_number', false], 'archived unit' => ['unit_number', true],
    ]);

    test('hides buses outside the requested company even from a super admin', function (): void {
        [$company, $otherCompany] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $service = app(BusManagementService::class);
        $bus = $service->create($actor, $otherCompany, [
            'country_code' => 'CR', 'plate_number' => 'BUS-101', 'unit_number' => 'UNIT-101', 'capacity' => 40,
        ]);

        expect(fn () => $service->update($actor, $company, $bus->getKey(), ['capacity' => 50]))
            ->toThrow(ModelNotFoundException::class);
        expect($bus->fresh()->capacity)->toBe(40);
    });

    test('rejects an update without company access and permission', function (string $scenario): void {
        [$company, $otherCompany] = createTenantPair();
        $service = app(BusManagementService::class);
        $bus = $service->create(createUserWithRole(UserRole::SuperAdmin), $company, [
            'country_code' => 'CR', 'plate_number' => 'BUS-101', 'unit_number' => 'UNIT-101', 'capacity' => 40,
        ]);
        $actorCompany = $scenario === 'foreign admin' ? $otherCompany : $company;
        $role = match ($scenario) {
            'driver' => UserRole::Driver, 'passenger' => UserRole::Passenger, default => UserRole::Admin,
        };
        $actor = createUserWithRole($role, $actorCompany);
        $membership = CompanyUser::create(['company_id' => $actorCompany->getKey(), 'user_id' => $actor->getKey()]);
        if ($scenario !== 'missing permission') {
            grantShield($actor, ['Update:Bus'], $actorCompany);
        }
        if ($scenario === 'archived membership') {
            $membership->delete();
        }

        expect(fn () => $service->update($actor, $company, $bus->getKey(), ['capacity' => 50]))
            ->toThrow(AuthorizationException::class);
        expect($bus->fresh()->capacity)->toBe(40);
    })->with(['missing permission', 'foreign admin', 'driver', 'passenger', 'archived membership']);
});
