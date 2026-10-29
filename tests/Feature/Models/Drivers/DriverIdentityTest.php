<?php

use App\Enums\CompanyStatus;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

describe('Driver Identity', function (): void {
    test('keeps employment and salary independent for one identity in different companies', function (): void {
        $user = User::factory()->create();
        $passwordHash = $user->getRawOriginal('password');
        $first = Driver::factory()->for($user)->create(['license' => 'LICENSE-001']);
        $second = Driver::factory()->for($user)->create([
            'license' => 'LICENSE-001', 'salary_amount' => '1000.25', 'salary_currency' => 'USD',
        ]);

        $second->update(['salary_amount' => '2000.50']);

        expect($first->fresh()->salary_amount)->toBe('650000.00');
        expect($second->fresh()->salary_amount)->toBe('2000.50');
        expect($first->company_id)->not->toBe($second->company_id);
        expect($user->fresh()->getRawOriginal('password'))->toBe($passwordHash);
        $this->assertDatabaseCount('users', 1, 'mysql');
        $this->assertDatabaseCount('drivers', 2);
    });

    test('rejects identity reassignment through the model even after archival', function (string $field, bool $archived): void {
        $driver = Driver::factory()->create();
        $originalIdentity = $driver->getAttributes()[$field];
        $replacement = $field === 'company_id' ? createCompany()->getKey() : User::factory()->create()->getKey();
        if ($archived) {
            $driver->delete();
        }

        expect(fn () => $driver->update([$field => $replacement]))->toThrow(function (ValidationException $exception) use ($field): void {
            expect($exception->errors())->toHaveKey($field);
        });
        expect($driver->fresh()->getAttributes()[$field])->toBe($originalIdentity);
    })->with([
        'active company' => ['company_id', false],
        'active user' => ['user_id', false],
        'archived company' => ['company_id', true],
        'archived user' => ['user_id', true],
    ]);

    test('rejects direct database reassignment of employment identity', function (string $field): void {
        $driver = Driver::factory()->create();
        $originalIdentity = $driver->getAttributes()[$field];
        $replacement = $field === 'company_id' ? createCompany()->getKey() : User::factory()->create()->getKey();

        expect(fn () => DB::transaction(fn () => DB::table('drivers')->where('id', $driver->id)->update([$field => $replacement])))
            ->toThrow(QueryException::class, 'drivers_identity_immutable');
        expect($driver->fresh()->getAttributes()[$field])->toBe($originalIdentity);
    })->with(['company_id', 'user_id']);

    test('rejects a nonexistent or archived MySQL identity on creation', function (bool $archived): void {
        $userId = (string) Str::uuid();
        if ($archived) {
            $user = User::factory()->create();
            $user->delete();
            $userId = $user->getKey();
        }
        $driver = Driver::factory()->make(['user_id' => $userId]);

        expect(fn () => $driver->save())->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toHaveKey('user_id');
        });
        $this->assertDatabaseCount('drivers', 0);
    })->with(['nonexistent identity' => false, 'archived identity' => true]);

    test('rejects employment creation for an inactive or archived company', function (bool $archived): void {
        $company = createCompany(['status' => $archived ? CompanyStatus::ACTIVE : CompanyStatus::INACTIVE]);
        if ($archived) {
            $company->delete();
        }
        $driver = Driver::factory()->make(['company_id' => $company->getKey()]);

        expect(fn () => $driver->save())->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toHaveKey('company_id');
        });
        $this->assertDatabaseCount('drivers', 0);
    })->with(['inactive company' => false, 'archived company' => true]);

    test('archives and restores the same profile without deleting its identity and rejects force deletion', function (): void {
        $driver = Driver::factory()->create();
        $driverId = $driver->getKey();

        $driver->delete();

        $this->assertSoftDeleted($driver);
        $this->assertModelExists($driver->user);
        expect(fn () => $driver->forceDelete())->toThrow(ValidationException::class);
        $driver->restore();
        $this->assertNotSoftDeleted($driver);
        expect($driver->getKey())->toBe($driverId);
    });
});
