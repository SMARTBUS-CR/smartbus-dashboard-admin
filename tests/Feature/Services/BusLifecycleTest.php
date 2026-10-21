<?php

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\CompanyUser;
use App\Services\BusManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

describe('Bus Lifecycle', function (): void {
    test('rejects archival outside the company authorization matrix', function (string $scenario): void {
        [$company, $otherCompany] = createTenantPair();
        $actorCompany = $scenario === 'foreign admin' ? $otherCompany : $company;
        $role = match ($scenario) {
            'driver' => UserRole::Driver,
            'passenger' => UserRole::Passenger,
            default => UserRole::Admin,
        };
        $actor = createUserWithRole($role, $actorCompany);
        if ($scenario !== 'missing membership') {
            $membership = CompanyUser::create([
                'company_id' => $actorCompany->getKey(), 'user_id' => $actor->getKey(),
            ]);
        }
        if ($scenario !== 'missing permission') {
            grantShield($actor, ['Delete:Bus'], $actorCompany);
        }
        if ($scenario === 'archived membership') {
            $membership->delete();
        }
        if ($scenario === 'archived actor') {
            $actor->delete();
        }
        $bus = Bus::factory()->for($company)->create();
        $before = $bus->fresh()->getAttributes();

        expect(fn () => app(BusManagementService::class)->archive($actor, $company, $bus->getKey()))
            ->toThrow(AuthorizationException::class);

        expect($bus->fresh()->getAttributes())->toBe($before);
    })->with([
        'missing permission', 'missing membership', 'foreign admin', 'driver', 'passenger',
        'archived membership', 'archived actor',
    ]);

    test('hides foreign buses when archiving even from a super admin', function (): void {
        [$company, $otherCompany] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $bus = Bus::factory()->for($otherCompany)->create();
        $before = $bus->fresh()->getAttributes();

        expect(fn () => app(BusManagementService::class)->archive($actor, $company, $bus->getKey()))
            ->toThrow(ModelNotFoundException::class);

        expect($bus->fresh()->getAttributes())->toBe($before);
    });

    test('rejects archival for a nonoperational company using stored state', function (bool $archived): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $bus = Bus::factory()->for($company)->create();
        $before = $bus->fresh()->getAttributes();
        $changedCompany = $company->fresh();
        if ($archived) {
            $changedCompany->delete();
        } else {
            $changedCompany->update(['status' => CompanyStatus::INACTIVE]);
        }

        try {
            app(BusManagementService::class)->archive($actor, $company, $bus->getKey());
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('company');
            expect($bus->fresh()->getAttributes())->toBe($before);
        }
    })->with(['inactive' => [false], 'archived' => [true]]);

    test('preserves identity details and active state when archiving', function (bool $active): void {
        $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00'));
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $factory = Bus::factory()->for($company);
        $bus = ($active ? $factory->active() : $factory->inactive())->create([
            'brand' => 'Mercedes-Benz', 'model' => 'O 500', 'year' => 2025, 'capacity' => 55,
        ])->fresh();
        $before = $bus->getAttributes();
        $this->travel(1)->minutes();

        $archived = app(BusManagementService::class)->archive($actor, $company, $bus->getKey());

        $unchanged = array_diff_key($before, array_flip(['deleted_at', 'updated_at']));
        expect(array_diff_key($archived->fresh()->getAttributes(), array_flip(['deleted_at', 'updated_at'])))
            ->toBe($unchanged);
        expect($archived->getKey())->toBe($bus->getKey())
            ->and($archived->is_active)->toBe($active)
            ->and($archived->deleted_at->format('Y-m-d H:i:s'))->toBe('2026-10-21 12:01:00');
        $this->assertSoftDeleted($archived);
        $this->assertDatabaseCount('buses', 1);
    })->with(['active bus' => [true], 'inactive bus' => [false]]);

    test('does not overwrite an existing archive timestamp', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $bus = Bus::factory()->for($company)->archived()->create();
        $before = $bus->fresh()->getAttributes();

        expect(fn () => app(BusManagementService::class)->archive($actor, $company, $bus->getKey()))
            ->toThrow(ModelNotFoundException::class);

        expect($bus->fresh()->getAttributes())->toBe($before);
    });

    test('restores permission context after archival outcomes', function (string $outcome): void {
        [$company, $previousCompany] = createTenantPair();
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        if ($outcome !== 'denied') {
            grantShield($actor, ['Delete:Bus'], $company);
        }
        $owner = $outcome === 'foreign' ? $previousCompany : $company;
        $bus = Bus::factory()->for($owner)->create();
        $before = $bus->fresh()->getAttributes();
        if ($outcome === 'inactive company') {
            $company->update(['status' => CompanyStatus::INACTIVE]);
        }
        $originalTeam = getPermissionsTeamId();
        setPermissionsTeamId($previousCompany->getKey());
        $actor->load('roles', 'permissions');

        try {
            try {
                $id = $outcome === 'missing' ? '00000000-0000-4000-8000-000000000001' : $bus->getKey();
                app(BusManagementService::class)->archive($actor, $company, $id);
                expect($outcome)->toBe('success');
                $this->assertSoftDeleted($bus);
            } catch (AuthorizationException $exception) {
                expect($outcome)->toBe('denied');
            } catch (ValidationException $exception) {
                expect($outcome)->toBe('inactive company');
                expect($exception->errors())->toHaveKey('company');
            } catch (ModelNotFoundException $exception) {
                expect($outcome)->toBeIn(['missing', 'foreign']);
            }

            expect(getPermissionsTeamId())->toBe($previousCompany->getKey())
                ->and($actor->relationLoaded('roles'))->toBeFalse()
                ->and($actor->relationLoaded('permissions'))->toBeFalse();
            if ($outcome !== 'success') {
                expect($bus->fresh()->getAttributes())->toBe($before);
            }
        } finally {
            setPermissionsTeamId($originalTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    })->with(['success', 'denied', 'inactive company', 'missing', 'foreign']);

    test('rolls back archival side effects and restores context when deletion fails', function (): void {
        [$company, $previousCompany] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $bus = Bus::factory()->for($company)->create();
        $before = $bus->fresh()->getAttributes();
        $dispatcher = Bus::getEventDispatcher();
        Bus::setEventDispatcher(clone $dispatcher);
        $originalTeam = getPermissionsTeamId();
        setPermissionsTeamId($previousCompany->getKey());

        try {
            Bus::deleting(function (Bus $record): void {
                $record->capacity = 99;
                $record->save();
                throw new RuntimeException('Archival interrupted.');
            });

            expect(fn () => app(BusManagementService::class)->archive($actor, $company, $bus->getKey()))
                ->toThrow(RuntimeException::class, 'Archival interrupted.');

            expect($bus->fresh()->getAttributes())->toBe($before);
            expect(getPermissionsTeamId())->toBe($previousCompany->getKey())
                ->and($actor->relationLoaded('roles'))->toBeFalse()
                ->and($actor->relationLoaded('permissions'))->toBeFalse();
        } finally {
            Bus::setEventDispatcher($dispatcher);
            setPermissionsTeamId($originalTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    });

    test('archives a company bus for an authorized administrator', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['Delete:Bus'], $company);

        $bus = Bus::factory()->for($company)->active()->create();

        $this->assertModelExists($bus);

        $archived = app(BusManagementService::class)->archive(
            $actor,
            $company,
            $bus->getKey(),
        );

        expect($archived->getKey())->toBe($bus->getKey())
            ->and($archived->company_id)->toBe($company->getKey())
            ->and($archived->trashed())->toBeTrue();

        $this->assertSoftDeleted('buses', [
            'id' => $bus->getKey(),
            'company_id' => $company->getKey(),
        ]);
    });
    test('rejects active state changes for an archived bus', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);

        $bus = Bus::factory()->for($company)->archived()->create();

        $this->assertSoftDeleted($bus);

        try {
            app(BusManagementService::class)->setActive(
                $actor,
                $company,
                $bus->getKey(),
                false,
            );

            $this->fail('Expected a model not found exception.');
        } catch (ModelNotFoundException $exception) {
            $this->assertDatabaseHas('buses', [
                'id' => $bus->getKey(),
                'company_id' => $company->getKey(),
                'is_active' => true,
            ]);

            $this->assertSoftDeleted($bus);
        }
    });
    test('rejects state changes for an archived company even for a super admin', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);

        $bus = Bus::factory()->for($company)->active()->create();

        $company->delete();

        try {
            app(BusManagementService::class)->setActive(
                $actor,
                $company,
                $bus->getKey(),
                false,
            );

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('company');

            $this->assertDatabaseHas('buses', [
                'id' => $bus->getKey(),
                'company_id' => $company->getKey(),
                'is_active' => true,
                'deleted_at' => null,
            ]);
        }
    });
    test('rejects state changes for an inactive company even for a super admin', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);

        $bus = Bus::factory()->for($company)->active()->create();

        $company->update([
            'status' => CompanyStatus::INACTIVE,
        ]);

        try {
            app(BusManagementService::class)->setActive(
                $actor,
                $company,
                $bus->getKey(),
                false,
            );

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('company');

            $this->assertDatabaseHas('buses', [
                'id' => $bus->getKey(),
                'company_id' => $company->getKey(),
                'is_active' => true,
                'deleted_at' => null,
            ]);
        }
    });
    test('hides another company bus when changing its active state', function (): void {
        [$company, $otherCompany] = createTenantPair();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['Update:Bus'], $company);

        $bus = Bus::factory()->for($otherCompany)->active()->create();

        try {
            app(BusManagementService::class)->setActive(
                $actor,
                $company,
                $bus->getKey(),
                false,
            );

            $this->fail('Expected a model not found exception.');
        } catch (ModelNotFoundException $exception) {
            $this->assertDatabaseHas('buses', [
                'id' => $bus->getKey(),
                'company_id' => $otherCompany->getKey(),
                'is_active' => true,
                'deleted_at' => null,
            ]);
        }
    });
    test('rejects state changes when the company admin lacks permission', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        $bus = Bus::factory()->for($company)->active()->create();

        try {
            app(BusManagementService::class)->setActive(
                $actor,
                $company,
                $bus->getKey(),
                false,
            );

            $this->fail('Expected an authorization exception.');
        } catch (AuthorizationException $exception) {
            $this->assertDatabaseHas('buses', [
                'id' => $bus->getKey(),
                'company_id' => $company->getKey(),
                'is_active' => true,
                'deleted_at' => null,
            ]);
        }
    });
    test('reactivates a company bus for an authorized administrator', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['Update:Bus'], $company);

        $bus = Bus::factory()->for($company)->inactive()->create();

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'is_active' => false,
        ]);

        $updated = app(BusManagementService::class)->setActive(
            $actor,
            $company,
            $bus->getKey(),
            true,
        );

        expect($updated->getKey())->toBe($bus->getKey())
            ->and($updated->is_active)->toBeTrue();

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(),
            'company_id' => $company->getKey(),
            'is_active' => true,
            'deleted_at' => null,
        ]);
    });
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
