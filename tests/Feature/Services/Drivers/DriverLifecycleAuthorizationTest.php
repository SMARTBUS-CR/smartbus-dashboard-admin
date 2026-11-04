<?php

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\User;
use App\Services\DriverManagementService;
use App\Services\DriverReadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

describe('Driver Lifecycle Authorization', function (): void {
    test('requires the operation permission and restores the previous Spatie context', function (string $method, string $permission): void {
        [$company, $previous] = createTenantPair();
        $actor = User::factory()->create();
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        $driver = Driver::factory()->for($company)->create();
        $argument = $method === 'setActive' ? false : [];
        $before = $driver->fresh()->getRawOriginal();
        setPermissionsTeamId($previous->id);
        $actor->load('roles', 'permissions');

        expect(fn () => app(DriverManagementService::class)->{$method}($actor, $company, $driver, $argument))->toThrow(AuthorizationException::class);
        expect($driver->fresh()->getRawOriginal())->toBe($before);
        expect(getPermissionsTeamId())->toBe($previous->id);
        expect($actor->relationLoaded('roles'))->toBeFalse();
        grantShield($actor, [$permission], $company);
        app(DriverManagementService::class)->{$method}($actor, $company, $driver, $argument);
        expect(getPermissionsTeamId())->toBe($previous->id);
        expect($actor->relationLoaded('roles'))->toBeFalse();
        expect($actor->relationLoaded('permissions'))->toBeFalse();
    })->with([
        ['updateEmployment', 'Update:Driver'], ['setActive', 'Update:Driver'],
        ['archive', 'Delete:Driver'], ['restore', 'Restore:Driver'],
    ]);

    test('rejects foreign employment even for global administrators', function (string $method): void {
        [$company, $other] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $driver = Driver::factory()->for($other)->create();
        $before = $driver->fresh()->getRawOriginal();
        $driver->company_id = $company->id;
        $argument = $method === 'setActive' ? false : [];

        expect(fn () => app(DriverManagementService::class)->{$method}($actor, $company, $driver, $argument))->toThrow(ModelNotFoundException::class);
        expect($driver->fresh()->getRawOriginal())->toBe($before);
    })->with(['updateEmployment', 'setActive', 'archive', 'restore']);

    test('rejects an inactive company without changing employment', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $driver = Driver::factory()->for($company)->create();
        $before = $driver->fresh()->getRawOriginal();
        $company->update(['status' => CompanyStatus::INACTIVE]);

        expect(fn () => app(DriverManagementService::class)->archive($actor, $company, $driver))->toThrow(AuthorizationException::class);
        expect($driver->fresh()->getRawOriginal())->toBe($before);
    });

    test('requires viewing permission for archived history and preserves salary confidentiality', function (): void {
        $company = createCompany();
        $actor = User::factory()->create();
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        $driver = Driver::factory()->for($company)->create();
        $driver->delete();
        $service = app(DriverReadService::class);

        expect(fn () => $service->findArchivedFor($actor, $company, $driver->id))->toThrow(AuthorizationException::class);
        grantShield($actor, ['View:Driver'], $company);
        $history = $service->findArchivedFor($actor, $company, $driver->id);
        expect($history['id'])->toBe($driver->id);
        expect($history['deleted_at'])->not->toBeNull();
        expect($history)->not->toHaveKeys(['salary_amount', 'salary_currency', 'salary_period', 'user']);
    });

    test('limits historical access to archived records within the explicit company', function (bool $foreign): void {
        [$company, $other] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $driver = Driver::factory()->for($foreign ? $other : $company)->create();
        if ($foreign) {
            $driver->delete();
        }

        expect(fn () => app(DriverReadService::class)->findArchivedFor($actor, $company, $driver->id))->toThrow(ModelNotFoundException::class);
    })->with([false, true]);
});
