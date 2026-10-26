<?php

use App\Models\Driver;
use Illuminate\Validation\ValidationException;

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
    'inverted employment dates' => [['employment_ends_on' => '2026-09-30'], 'employment_ends_on'],
]);

test('rejects excess salary precision even after reading its cast and preserves stored salary', function (): void {
    $driver = Driver::factory()->create(['salary_amount' => '650000.25']);
    $driver->salary_amount = '1.005';
    $driver->salary_amount;

    expect(fn () => $driver->save())->toThrow(ValidationException::class);
    expect($driver->fresh()->salary_amount)->toBe('650000.25');
});
