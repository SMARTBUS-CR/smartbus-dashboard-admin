<?php

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\CompanyUser;
use App\Services\BusManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

describe('Bus Reads', function (): void {
    test('lists only unarchived buses from the explicit company without Filament', function (): void {
        [$company, $otherCompany] = createTenantPair();
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        grantShield($actor, ['ViewAny:Bus'], $company);
        $active = Bus::factory()->for($company)->active()->create(['unit_number' => 'A-01']);
        $inactive = Bus::factory()->for($company)->inactive()->create(['unit_number' => 'B-01']);
        Bus::factory()->for($company)->archived()->create();
        Bus::factory()->for($otherCompany)->create();

        $buses = app(BusManagementService::class)->listFor($actor, $company);

        expect($buses->modelKeys())->toBe([$active->getKey(), $inactive->getKey()]);
    });

    test('reads company bus details including archives for historical access', function (bool $archived): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        grantShield($actor, ['View:Bus'], $company);
        $factory = Bus::factory()->for($company);
        $bus = ($archived ? $factory->archived() : $factory)->create();

        $record = app(BusManagementService::class)->findFor($actor, $company, $bus->getKey());

        expect($record->is($bus))->toBeTrue()->and($record->trashed())->toBe($archived);
    })->with(['current' => [false], 'archived' => [true]]);

    test('hides foreign and missing bus identifiers even from a global administrator', function (bool $foreign): void {
        [$company, $otherCompany] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $bus = Bus::factory()->for($otherCompany)->create();
        $id = $foreign ? $bus->getKey() : '00000000-0000-4000-8000-000000000001';

        expect(fn () => app(BusManagementService::class)->findFor($actor, $company, $id))
            ->toThrow(ModelNotFoundException::class);
    })->with(['foreign' => [true], 'missing' => [false]]);

    test('requires the specific permission for each read operation', function (string $operation): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        grantShield($actor, [$operation === 'list' ? 'View:Bus' : 'ViewAny:Bus'], $company);
        $bus = Bus::factory()->for($company)->create();
        $service = app(BusManagementService::class);

        expect(fn () => $operation === 'list'
            ? $service->listFor($actor, $company)
            : $service->findFor($actor, $company, $bus->getKey()))
            ->toThrow(AuthorizationException::class);
    })->with(['list', 'detail']);

    test('restores team context for reads after success and failures', function (string $outcome, string $operation): void {
        [$company, $previousCompany] = createTenantPair();
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        if ($outcome !== 'denied') {
            grantShield($actor, ['ViewAny:Bus', 'View:Bus'], $company);
        }
        $bus = Bus::factory()->for($company)->create();
        if ($outcome === 'inactive company') {
            $company->update(['status' => CompanyStatus::INACTIVE]);
        }
        $originalTeam = getPermissionsTeamId();
        setPermissionsTeamId($previousCompany->getKey());
        $actor->load('roles', 'permissions');

        try {
            try {
                $service = app(BusManagementService::class);
                if ($operation === 'list') {
                    $service->listFor($actor, $company);
                } else {
                    $service->findFor($actor, $company, $outcome === 'missing'
                        ? '00000000-0000-4000-8000-000000000001' : $bus->getKey());
                }
                expect($outcome)->toBe('success');
            } catch (AuthorizationException $exception) {
                expect($outcome)->toBe('denied');
            } catch (ModelNotFoundException $exception) {
                expect($outcome)->toBe('missing');
            } catch (ValidationException $exception) {
                expect($outcome)->toBe('inactive company');
                expect($exception->errors())->toHaveKey('company');
            }
            expect(getPermissionsTeamId())->toBe($previousCompany->getKey())
                ->and($actor->relationLoaded('roles'))->toBeFalse()
                ->and($actor->relationLoaded('permissions'))->toBeFalse();
        } finally {
            setPermissionsTeamId($originalTeam);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    })->with([
        'list success' => ['success', 'list'], 'detail success' => ['success', 'detail'],
        'list denied' => ['denied', 'list'], 'detail denied' => ['denied', 'detail'],
        'detail missing' => ['missing', 'detail'],
        'inactive list' => ['inactive company', 'list'], 'inactive detail' => ['inactive company', 'detail'],
    ]);
});
