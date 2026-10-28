<?php

use App\Enums\DriverStatus;
use App\Models\Driver;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

describe('Driver Values', function (): void {
    test('normalizes license and currency before persistence', function (): void {
        $driver = Driver::factory()->create([
            'license' => " \t cr-12345 \n",
            'salary_currency' => ' crc ',
        ])->fresh();

        expect($driver->license)->toBe('CR-12345');
        expect($driver->salary_currency)->toBe('CRC');
    });

    test('persists valid salary boundaries as exact decimal strings', function (string|int $amount, string $expected): void {
        $driver = Driver::factory()->create(['salary_amount' => $amount])->fresh();

        expect($driver->salary_amount)->toBe($expected);
    })->with([
        'integer zero' => [0, '0.00'],
        'zero string' => ['0.00', '0.00'],
        'one cent' => ['0.01', '0.01'],
        'one decimal' => ['123.4', '123.40'],
        'maximum amount' => ['9999999999.99', '9999999999.99'],
    ]);

    test('rejects invalid employment values with field errors before insertion', function (array $changes, string $field): void {
        $driver = Driver::factory()->make($changes);

        expect(fn () => $driver->save())->toThrow(function (ValidationException $exception) use ($field): void {
            expect($exception->errors())->toHaveKey($field);
        });
        $this->assertDatabaseCount('drivers', 0);
    })->with([
        'negative salary' => [['salary_amount' => '-0.01'], 'salary_amount'],
        'excess precision' => [['salary_amount' => '1.005'], 'salary_amount'],
        'above maximum' => [['salary_amount' => '10000000000.00'], 'salary_amount'],
        'non numeric salary' => [['salary_amount' => 'invalid'], 'salary_amount'],
        'missing salary' => [['salary_amount' => null], 'salary_amount'],
        'floating point salary' => [['salary_amount' => 1.25], 'salary_amount'],
        'scientific notation' => [['salary_amount' => '1e2'], 'salary_amount'],
        'unsupported salary period' => [['salary_period' => 'weekly'], 'salary_period'],
        'missing salary period' => [['salary_period' => null], 'salary_period'],
        'unsupported currency' => [['salary_currency' => 'ZZZ'], 'salary_currency'],
        'missing currency' => [['salary_currency' => null], 'salary_currency'],
        'missing license' => [['license' => null], 'license'],
        'blank license' => [['license' => '   '], 'license'],
        'oversized license' => [['license' => str_repeat('A', 51)], 'license'],
        'missing expiry' => [['license_expires_at' => null], 'license_expires_at'],
        'missing employment start' => [['employment_starts_on' => null], 'employment_starts_on'],
        'missing status' => [['status' => null], 'status'],
        'inverted employment dates' => [['employment_ends_on' => '2026-09-30'], 'employment_ends_on'],
    ]);

    test('rejects excess salary precision even after reading its cast and preserves stored salary', function (): void {
        $driver = Driver::factory()->create(['salary_amount' => '650000.25']);
        $driver->salary_amount = '1.005';
        $driver->salary_amount;

        expect(fn () => $driver->save())->toThrow(ValidationException::class);
        expect($driver->fresh()->salary_amount)->toBe('650000.25');
    });

    test('rejects invalid original dates when creating employment with a field specific validation error', function (string $field, string $value): void {
        expect(fn () => Driver::factory()->create([
            'license_expires_at' => '2030-12-31',
            'employment_starts_on' => '2026-01-01',
            'employment_ends_on' => '2027-12-31',
            $field => $value,
        ]))->toThrow(function (ValidationException $exception) use ($field): void {
            expect(array_keys($exception->errors()))->toBe([$field]);
        });
        $this->assertDatabaseCount('drivers', 0);
    })->with(['license_expires_at', 'employment_starts_on', 'employment_ends_on'])->with([
        'impossible day' => '2026-02-30',
        'impossible month' => '2026-13-01',
        'unparseable string' => 'not-a-date',
    ]);

    test('rejects invalid original dates on update and preserves all stored attributes', function (string $field, string $value): void {
        $driver = Driver::factory()->create([
            'license_expires_at' => '2030-12-31',
            'employment_starts_on' => '2026-01-01',
            'employment_ends_on' => '2027-12-31',
        ])->fresh();
        $stored = $driver->getRawOriginal();
        $this->assertModelExists($driver);

        expect(fn () => $driver->update(['salary_amount' => '700000.00', $field => $value]))
            ->toThrow(function (ValidationException $exception) use ($field): void {
                expect(array_keys($exception->errors()))->toBe([$field]);
            });
        expect($driver->fresh()->getRawOriginal())->toBe($stored);
    })->with(['license_expires_at', 'employment_starts_on', 'employment_ends_on'])->with([
        'impossible day' => '2026-02-30',
        'impossible month' => '2026-13-01',
        'unparseable string' => 'not-a-date',
    ]);

    test('persists valid leap day strings and date objects with immutable casts', function (): void {
        $driver = Driver::factory()->create([
            'license_expires_at' => CarbonImmutable::parse('2032-02-29'),
            'employment_starts_on' => new DateTimeImmutable('2024-02-29'),
            'employment_ends_on' => '2028-02-29',
        ])->fresh();

        expect($driver->license_expires_at)->toBeInstanceOf(CarbonImmutable::class);
        expect($driver->employment_starts_on)->toBeInstanceOf(CarbonImmutable::class);
        expect($driver->employment_ends_on)->toBeInstanceOf(CarbonImmutable::class);
        expect($driver->license_expires_at->toDateString())->toBe('2032-02-29');
        expect($driver->employment_starts_on->toDateString())->toBe('2024-02-29');
        expect($driver->employment_ends_on->toDateString())->toBe('2028-02-29');
    });

    test('allows clearing the employment end date without changing the required dates', function (): void {
        $driver = Driver::factory()->create(['employment_ends_on' => '2027-12-31']);

        $driver->update(['employment_ends_on' => null]);

        expect($driver->fresh()->employment_ends_on)->toBeNull();
        expect($driver->fresh()->license_expires_at->toDateString())->toBe('2030-12-31');
        expect($driver->fresh()->employment_starts_on->toDateString())->toBe('2026-10-01');
    });

    test('rejects unsupported status during enum assignment and preserves the stored status', function (): void {
        $driver = Driver::factory()->create();

        expect(fn () => $driver->update(['status' => 'unknown']))->toThrow(ValueError::class);
        expect($driver->fresh()->status)->toBe(DriverStatus::ACTIVE);
    });
});
