<?php

use App\Enums\UserRole;
use App\Models\Bus;
use App\Models\CompanyUser;
use App\Policies\BusPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

test('rejects permanent bus deletion and preserves its stored attributes', function (string $state, string $operation, string $locale, string $message): void {
    app()->setLocale($locale);
    $bus = Bus::factory()->{$state}()->create();
    $attributes = $bus->refresh()->getRawOriginal();

    try {
        if ($operation === 'forceDestroy') {
            Bus::forceDestroy([$bus->getKey()]);
        } else {
            $bus->{$operation}();
        }
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['bus' => [$message]]);
        expect($bus->fresh()->getRawOriginal())->toBe($attributes);
    }
})->with([
    'active bus' => ['active', 'forceDelete'],
    'inactive bus' => ['inactive', 'forceDelete'],
    'archived bus' => ['archived', 'forceDelete'],
    'quiet deletion' => ['archived', 'forceDeleteQuietly'],
    'bulk model deletion' => ['archived', 'forceDestroy'],
])->with([
    'English' => ['en', 'Permanent bus deletion is disabled. Archive the bus instead.'],
    'Spanish' => ['es', 'La eliminación permanente de autobuses está deshabilitada. Archiva el autobús en su lugar.'],
]);

test('denies permanent bus deletion authorization even with elevated permissions', function (UserRole $role, string $state): void {
    $company = createCompany();
    $actor = createUserWithRole($role, $role === UserRole::SuperAdmin ? null : $company);
    if ($role !== UserRole::SuperAdmin) {
        CompanyUser::create(['company_id' => $company->getKey(), 'user_id' => $actor->getKey()]);
        grantShield($actor, ['ForceDelete:Bus', 'ForceDeleteAny:Bus'], $company);
    }
    $bus = Bus::factory()->for($company)->{$state}()->create();

    $policy = app(BusPolicy::class);
    expect($policy->forceDelete($actor, $company, $bus))->toBeFalse()
        ->and($policy->forceDeleteAny($actor, $company))->toBeFalse();

    foreach (['forceDelete', 'forceDeleteAny'] as $ability) {
        foreach ([$bus, Bus::class, [Bus::class, $company, $bus]] as $arguments) {
            expect(Gate::forUser($actor)->denies($ability, $arguments))->toBeTrue();
        }
    }
    expect(fn () => Gate::forUser($actor)->authorize('forceDelete', [Bus::class, $company, $bus]))
        ->toThrow(AuthorizationException::class);
    $this->assertModelExists($bus);
})->with([UserRole::Admin, UserRole::SuperAdmin])->with(['active', 'archived']);
