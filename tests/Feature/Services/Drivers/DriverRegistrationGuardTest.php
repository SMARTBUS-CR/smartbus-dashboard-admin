<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\User;
use App\Services\DriverManagementService;
use Illuminate\Validation\ValidationException;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class);

describe('Driver Registration Guards', function (): void {
    test('rejects a global super admin identity without granting driver access', function (): void {
        $company = createCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $target = createUserWithRole(UserRole::SuperAdmin);

        expect(fn () => app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment()))
            ->toThrow(function (ValidationException $exception): void {
                expect($exception->errors())->toHaveKey('user_id');
            });
        $this->assertDatabaseCount('drivers', 0);
        expect(CompanyUser::withTrashed()->where('user_id', $target->id)->exists())->toBeFalse();
    });

    test('rejects duplicate employment or normalized license including archived profiles', function (string $field, bool $archived): void {
        $company = createCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $existing = Driver::factory()->for($company)->create(['license' => 'LICENSE-EXISTING']);
        if ($archived) {
            $existing->delete();
        }
        $target = $field === 'user_id' ? $existing->user : User::factory()->create();
        $employment = $this->validDriverEmployment($field === 'license' ? ['license' => ' license-existing '] : []);

        expect(fn () => app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $employment))
            ->toThrow(function (ValidationException $exception) use ($field): void {
                expect($exception->errors())->toHaveKey($field);
            });
        $this->assertDatabaseCount('drivers', 1);
        expect($existing->fresh()->trashed())->toBe($archived);
        expect(CompanyUser::withTrashed()->where('user_id', $target->id)->exists())->toBeFalse();
    })->with([
        'existing employment' => ['user_id', false],
        'archived employment' => ['user_id', true],
        'existing license' => ['license', false],
        'archived license' => ['license', true],
    ]);

    test('rejects ownership lifecycle and role fields in employment input', function (string $field, mixed $value): void {
        $company = createCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $target = User::factory()->create();

        expect(fn () => app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment([$field => $value])))
            ->toThrow(function (ValidationException $exception) use ($field): void {
                expect($exception->errors())->toHaveKey($field);
            });
        $this->assertDatabaseCount('drivers', 0);
        expect(CompanyUser::withTrashed()->where('user_id', $target->id)->exists())->toBeFalse();
    })->with([
        'record id' => ['id', '11111111-1111-4111-8111-111111111111'],
        'company ownership' => ['company_id', '11111111-1111-4111-8111-111111111111'],
        'user ownership' => ['user_id', '11111111-1111-4111-8111-111111111111'],
        'status' => ['status', 'inactive'],
        'archival' => ['deleted_at', '2026-10-26'],
        'role' => ['role', 'super-admin'],
        'roles' => ['roles', [1]],
        'guard' => ['guard_name', 'api'],
        'verification' => ['email_verified_at', '2026-10-26'],
    ]);

    test('rejects an archived identity without restoring the account', function (): void {
        $company = createCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $target = User::factory()->create();
        $target->delete();

        expect(fn () => app(DriverManagementService::class)->createForExistingUser($actor, $company, $target, $this->validDriverEmployment()))
            ->toThrow(ValidationException::class);
        $this->assertSoftDeleted($target);
        $this->assertDatabaseCount('drivers', 0);
    });
});
