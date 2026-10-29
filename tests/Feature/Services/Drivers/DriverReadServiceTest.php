<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\User;
use App\Services\DriverReadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

describe('Driver Read Service', function (): void {
    test('returns only ordinary employment fields without salary or credentials', function (): void {
        [$company, $other] = createTenantPair();
        $actor = User::factory()->create();
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        grantShield($actor, ['ViewAny:Driver', 'View:Driver'], $company);
        $driver = Driver::factory()->for($company)->create(['salary_amount' => '9999999999.99']);
        Driver::factory()->for($other)->create();
        $driver->load('user');
        $driver->makeVisible(['salary_amount', 'salary_currency', 'salary_period', 'user']);
        Log::spy();
        $service = app(DriverReadService::class);

        $profile = $service->findFor($actor, $company, $driver->id);
        $profiles = $service->listFor($actor, $company);

        expect($profiles)->toHaveCount(1);
        expect($profiles->sole())->toBe($profile);
        expect($profile)->toHaveKeys(['id', 'company_id', 'user_id', 'license', 'status']);
        foreach (['salary_amount', 'salary_currency', 'salary_period', 'user', 'name', 'email', 'password'] as $key) {
            expect($profile)->not->toHaveKey($key);
        }
        expect(fn () => $service->salaryFor($actor, $company, $driver->id))->toThrow(AuthorizationException::class);
        Log::shouldNotHaveReceived('log');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
    });

    test('returns exact salary only through a separately authorized projection', function (string $amount): void {
        [$company, $previous] = createTenantPair();
        $actor = User::factory()->create();
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        grantShield($actor, ['ViewSalary:Driver'], $company);
        $driver = Driver::factory()->for($company)->create(['salary_amount' => $amount, 'salary_currency' => 'CRC']);
        setPermissionsTeamId($previous->id);
        $actor->load('roles', 'permissions');

        $salary = app(DriverReadService::class)->salaryFor($actor, $company, $driver->id);

        expect($salary)->toBe(['salary_amount' => $amount, 'salary_currency' => 'CRC', 'salary_period' => 'monthly']);
        expect(getPermissionsTeamId())->toBe($previous->id);
        expect($actor->relationLoaded('roles'))->toBeFalse();
        expect($actor->relationLoaded('permissions'))->toBeFalse();
        expect($driver->fresh()->toArray())->not->toHaveKeys(['salary_amount', 'salary_currency', 'salary_period']);
    })->with(['0.00', '650000.25', '9999999999.99']);

    test('denies every supported read without its permission and restores context', function (string $method): void {
        [$company, $previous] = createTenantPair();
        $actor = User::factory()->create();
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $actor->id]);
        $driver = Driver::factory()->for($company)->create();
        setPermissionsTeamId($previous->id);
        $actor->load('roles', 'permissions');

        expect(fn () => app(DriverReadService::class)->{$method}($actor, $company, $driver->id))->toThrow(AuthorizationException::class);
        expect(getPermissionsTeamId())->toBe($previous->id);
        expect($actor->relationLoaded('roles'))->toBeFalse();
        expect($actor->relationLoaded('permissions'))->toBeFalse();
    })->with(['listFor', 'findFor', 'salaryFor']);

    test('does not expose another company record even to global administrators', function (string $method): void {
        [$company, $other] = createTenantPair();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $driver = Driver::factory()->for($other)->create();

        expect(fn () => app(DriverReadService::class)->{$method}($actor, $company, $driver->id))->toThrow(ModelNotFoundException::class);
    })->with(['findFor', 'salaryFor']);
});
