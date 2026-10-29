<?php

use App\Enums\CompanyStatus;
use App\Enums\DriverStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\Role;
use App\Services\TripDriverEligibilityService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** @return array{Company, Driver} */
function eligiblePlanningDriver(): array
{
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $user = createUserWithRole(UserRole::Driver, $company);
    CompanyUser::factory()->for($company)->for($user)->create();
    $driver = Driver::factory()->for($company)->for($user)->create([
        'employment_starts_on' => '2026-10-26', 'employment_ends_on' => '2026-10-27',
        'license_expires_at' => '2026-10-27',
    ]);

    return [$company, $driver];
}

test('accepts inclusive employment and license boundaries without changing permission context or writing trips', function (): void {
    Http::preventStrayRequests();
    [$company, $driver] = eligiblePlanningDriver();
    $other = createCompany();
    setPermissionsTeamId($other->getKey());

    $result = app(TripDriverEligibilityService::class)->validate($company, $driver->getKey(), ['2026-10-26', '2026-10-27']);

    expect($result->is($driver))->toBeTrue();
    expect(getPermissionsTeamId())->toBe($other->getKey());
    $this->assertDatabaseCount('trips', 0);
    Http::assertNothingSent();
});

test('rejects unavailable or ineligible real driver records privately', function (string $scenario): void {
    [$company, $driver] = eligiblePlanningDriver();
    $user = $driver->user;
    $id = $driver->getKey();
    match ($scenario) {
        'foreign' => $company = createCompany(),
        'missing' => $id = '00000000-0000-4000-8000-000000000001',
        'inactive driver' => $driver->update(['status' => DriverStatus::INACTIVE]),
        'archived driver' => $driver->delete(),
        'archived user' => $user->delete(),
        'missing user' => $user->getConnection()->table('users')->where('id', $user->getKey())->delete(),
        'inactive company' => $company->update(['status' => CompanyStatus::INACTIVE]),
        'archived company' => $company->delete(),
        'missing membership' => CompanyUser::query()->where('user_id', $user->getKey())->delete(),
        'wrong company membership' => (function () use ($user): void {
            CompanyUser::query()->where('user_id', $user->getKey())->delete();
            CompanyUser::factory()->for(createCompany())->for($user)->create();
        })(),
        'wrong role' => $user->getConnection()->table('model_has_roles')->where('model_uuid', $user->getKey())->delete(),
        'foreign role' => (function () use ($user): void {
            $foreignCompany = createCompany();
            $role = Role::query()->where('company_id', $foreignCompany->getKey())->where('name', UserRole::Driver->value)->firstOrFail();
            $user->getConnection()->table('model_has_roles')->where('model_uuid', $user->getKey())->update(['role_id' => $role->getKey()]);
        })(),
        'foreign role assignment' => $user->getConnection()->table('model_has_roles')->where('model_uuid', $user->getKey())->update(['company_id' => createCompany()->getKey()]),
        'wrong guard' => Role::query()->where('company_id', $company->getKey())->where('name', UserRole::Driver->value)->update(['guard_name' => 'api']),
        'admin role' => (function () use ($user, $company): void {
            $user->getConnection()->table('model_has_roles')->where('model_uuid', $user->getKey())->update([
                'role_id' => Role::query()->where('company_id', $company->getKey())->where('name', UserRole::Admin->value)->firstOrFail()->getKey(),
            ]);
        })(),
        'super admin role' => Role::query()->where('company_id', $company->getKey())->where('name', UserRole::Driver->value)->update(['name' => UserRole::SuperAdmin->value]),
        'employment starts later' => $driver->update(['employment_starts_on' => '2026-10-27']),
        'employment ends earlier' => $driver->update(['employment_ends_on' => '2026-10-26']),
        'license expires earlier' => $driver->update(['license_expires_at' => '2026-10-26']),
    };
    $originalTeam = createCompany()->getKey();
    setPermissionsTeamId($originalTeam);

    try {
        app(TripDriverEligibilityService::class)->validate($company, $id, ['2026-10-26', '2026-10-27']);
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['driver_id' => [__('The selected driver is unavailable or ineligible for the planned dates.')]]);
        expect(getPermissionsTeamId())->toBe($originalTeam);
        $this->assertDatabaseCount('trips', 0);
    }
})->with([
    'foreign', 'missing', 'inactive driver', 'archived driver', 'archived user', 'missing user', 'inactive company', 'archived company', 'missing membership',
    'wrong company membership', 'wrong role', 'foreign role', 'foreign role assignment', 'wrong guard', 'admin role', 'super admin role',
    'employment starts later', 'employment ends earlier', 'license expires earlier',
]);

test('accepts open ended employment and rejects an invalid local date list', function (array $dates, bool $valid): void {
    [$company, $driver] = eligiblePlanningDriver();
    $driver->update(['employment_ends_on' => null]);

    if ($valid) {
        expect(app(TripDriverEligibilityService::class)->validate($company, $driver->getKey(), $dates)->is($driver))->toBeTrue();
    } else {
        expect(fn () => app(TripDriverEligibilityService::class)->validate($company, $driver->getKey(), $dates))
            ->toThrow(ValidationException::class);
    }
})->with([
    'single day' => [['2026-10-26'], true],
    'empty' => [[], false],
    'invalid day' => [['2026-02-30'], false],
]);

test('localizes private driver eligibility errors', function (string $locale, string $message): void {
    $company = createCompany();
    app()->setLocale($locale);

    try {
        app(TripDriverEligibilityService::class)->validate($company, '00000000-0000-4000-8000-000000000001', ['2026-10-26']);
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['driver_id' => [$message]]);
    }
})->with([
    'English' => ['en', 'The selected driver is unavailable or ineligible for the planned dates.'],
    'Spanish' => ['es', 'El conductor seleccionado no está disponible o no cumple los requisitos para las fechas planificadas.'],
]);

test('rejects a malformed driver identifier before querying PostgreSQL', function (): void {
    $company = createCompany();

    try {
        app(TripDriverEligibilityService::class)->validate($company, 'not-a-uuid', ['2026-10-26']);
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('driver_id');
        $this->assertDatabaseCount('trips', 0);
    }
});
