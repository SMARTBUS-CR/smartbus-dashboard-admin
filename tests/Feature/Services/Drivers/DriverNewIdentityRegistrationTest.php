<?php

use App\Models\CompanyUser;
use App\Models\User;
use App\Services\DriverManagementService;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class);

describe('Driver New Identity Registration', function (): void {
    test('registers a new hashed identity and employment through the existing identity infrastructure', function (): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->once()->andReturnTrue();
        $company = createCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $previousTeam = createCompany()->id;
        setPermissionsTeamId($previousTeam);

        $driver = app(DriverManagementService::class)->register($actor, $company, $this->validDriverIdentity(), $this->validDriverEmployment());

        $user = $driver->user->fresh();
        expect($user->email)->toBe('new-driver@example.test');
        expect($user->name)->toBe('New Driver');
        expect(Hash::check('N7v!qL2#rX9@kP4', $user->getRawOriginal('password')))->toBeTrue();
        expect($user->hasVerifiedEmail())->toBeTrue();
        expect($driver->user_id)->toBe($user->id);
        expect($driver->company_id)->toBe($company->id);
        expect(getPermissionsTeamId())->toBe($previousTeam);
        expect(CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->exists())->toBeTrue();
        $this->assertDatabaseCount('users', 2, 'mysql');
        $this->assertDatabaseCount('drivers', 1);
    });

    test('rejects invalid identity fields without creating accounts or employment', function (array $changes, string $field): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();
        $company = createCompany();
        $actor = $this->createWorkforceAdministrator($company);

        expect(fn () => app(DriverManagementService::class)->register($actor, $company, $this->validDriverIdentity($changes), $this->validDriverEmployment()))
            ->toThrow(function (ValidationException $exception) use ($field): void {
                expect($exception->errors())->toHaveKey($field);
            });
        $this->assertDatabaseCount('users', 1, 'mysql');
        $this->assertDatabaseCount('drivers', 0);
        $this->assertDatabaseCount('company_users', 1);
    })->with([
        'missing name' => [['name' => ''], 'name'],
        'oversized name' => [['name' => str_repeat('A', 101)], 'name'],
        'missing email' => [['email' => null], 'email'],
        'malformed email' => [['email' => 'invalid'], 'email'],
        'oversized email' => [['email' => str_repeat('a', 50).'@'.str_repeat('b', 45).'.test'], 'email'],
        'missing password' => [['password' => null], 'password'],
        'missing confirmation' => [['password_confirmation' => null], 'password'],
        'mismatched confirmation' => [['password_confirmation' => 'DifferentPassword1!'], 'password'],
        'short password' => [['password' => 'A1!bcde', 'password_confirmation' => 'A1!bcde'], 'password'],
        'missing uppercase' => [['password' => 'abcdef12!', 'password_confirmation' => 'abcdef12!'], 'password'],
        'missing lowercase' => [['password' => 'ABCDEFG12!', 'password_confirmation' => 'ABCDEFG12!'], 'password'],
        'missing number' => [['password' => 'ValidPassword!', 'password_confirmation' => 'ValidPassword!'], 'password'],
        'missing symbol' => [['password' => 'ValidPassword123', 'password_confirmation' => 'ValidPassword123'], 'password'],
        'forged company' => [['company_id' => '11111111-1111-4111-8111-111111111111'], 'company_id'],
        'forged role' => [['role' => 'super-admin'], 'role'],
        'forged role list' => [['roles' => [1]], 'roles'],
        'forged verification' => [['email_verified_at' => '2026-10-26'], 'email_verified_at'],
        'forged guard' => [['guard_name' => 'api'], 'guard_name'],
        'forged id' => [['id' => '11111111-1111-4111-8111-111111111111'], 'id'],
    ]);

    test('accepts name and email at their one hundred character boundary', function (): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();
        $company = createCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $email = str_repeat('a', 50).'@'.str_repeat('b', 44).'.test';

        $driver = app(DriverManagementService::class)->register($actor, $company, $this->validDriverIdentity([
            'name' => str_repeat('A', 100), 'email' => $email,
        ]), $this->validDriverEmployment());

        expect($driver->user->name)->toBe(str_repeat('A', 100));
        expect($driver->user->email)->toBe($email);
    });

    test('rejects a password identified as compromised by the existing verifier', function (): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->once()->andReturnFalse();
        $company = createCompany();
        $actor = $this->createWorkforceAdministrator($company);

        expect(fn () => app(DriverManagementService::class)->register($actor, $company, $this->validDriverIdentity(), $this->validDriverEmployment()))
            ->toThrow(ValidationException::class);
        $this->assertDatabaseCount('users', 1, 'mysql');
        $this->assertDatabaseCount('drivers', 0);
    });

    test('rejects an email reserved by an existing or archived identity without resetting credentials', function (bool $archived): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();
        $company = createCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $existing = User::factory()->create(['email' => 'new-driver@example.test']);
        $originalPassword = $existing->getRawOriginal('password');
        if ($archived) {
            $existing->delete();
        }

        expect(fn () => app(DriverManagementService::class)->register($actor, $company, $this->validDriverIdentity(), $this->validDriverEmployment()))
            ->toThrow(function (ValidationException $exception): void {
                expect($exception->errors())->toHaveKey('email');
            });
        expect($existing->fresh()->getRawOriginal('password'))->toBe($originalPassword);
        expect($existing->fresh()->trashed())->toBe($archived);
        $this->assertDatabaseCount('users', 2, 'mysql');
        $this->assertDatabaseCount('drivers', 0);
    })->with(['existing identity' => false, 'archived identity' => true]);

    test('rolls back the new identity when employment validation fails', function (): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();
        $company = createCompany();
        $actor = $this->createWorkforceAdministrator($company);

        expect(fn () => app(DriverManagementService::class)->register($actor, $company, $this->validDriverIdentity(), $this->validDriverEmployment(['salary_amount' => '1.005'])))
            ->toThrow(ValidationException::class);
        $this->assertDatabaseCount('users', 1, 'mysql');
        $this->assertDatabaseCount('drivers', 0);
        $this->assertDatabaseCount('company_users', 1);
    });
});
