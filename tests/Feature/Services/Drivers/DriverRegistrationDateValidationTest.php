<?php

use App\Services\DriverManagementService;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Validation\ValidationException;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class);

describe('Driver Registration Date Validation', function (): void {
    test('rejects invalid raw employment dates before Eloquent can normalize them', function (string $field, string $date): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();
        $company = createCompany();
        $actor = $this->createWorkforceAdministrator($company);

        expect(fn () => app(DriverManagementService::class)->register($actor, $company, $this->validDriverIdentity(), $this->validDriverEmployment([$field => $date])))
            ->toThrow(function (ValidationException $exception) use ($field): void {
                expect($exception->errors())->toHaveKey($field);
            });
        $this->assertDatabaseCount('users', 1, 'mysql');
        $this->assertDatabaseCount('drivers', 0);
        $this->assertDatabaseCount('company_users', 1);
    })->with([
        'invalid expiry' => ['license_expires_at', 'not-a-date'],
        'impossible expiry' => ['license_expires_at', '2030-02-30'],
        'invalid start' => ['employment_starts_on', 'not-a-date'],
        'impossible start' => ['employment_starts_on', '2026-02-30'],
        'invalid end' => ['employment_ends_on', 'not-a-date'],
        'impossible end' => ['employment_ends_on', '2027-02-30'],
    ]);
});
