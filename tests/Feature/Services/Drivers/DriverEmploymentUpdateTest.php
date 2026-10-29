<?php

use App\Models\Driver;
use App\Services\DriverManagementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class);

beforeEach(function (): void {
    $this->company = createCompany();
    $this->actor = $this->createWorkforceAdministrator($this->company);
    grantShield($this->actor, ['Update:Driver'], $this->company);
    $this->driver = Driver::factory()->for($this->company)->create();
});

describe('Driver Employment Update', function (): void {
    test('updates only employment fields and preserves exact salary and identity', function (): void {
        $before = $this->driver->only(['id', 'company_id', 'user_id']);
        $updated = app(DriverManagementService::class)->updateEmployment($this->actor, $this->company, $this->driver, [
            'license' => '  updated-license  ', 'license_expires_at' => '2032-02-29',
            'employment_starts_on' => '2026-11-01', 'employment_ends_on' => '2026-11-01',
            'salary_amount' => '9999999999.99', 'salary_currency' => ' usd ', 'salary_period' => 'monthly',
        ]);

        expect($updated->only(['id', 'company_id', 'user_id']))->toBe($before);
        expect($updated->license)->toBe('UPDATED-LICENSE');
        expect($updated->salary_amount)->toBe('9999999999.99');
        expect($updated->salary_currency)->toBe('USD');
        expect($updated->employment_ends_on->toDateString())->toBe('2026-11-01');
        expect($updated->toArray())->not->toHaveKeys(['salary_amount', 'salary_currency', 'salary_period', 'user']);
        expect($updated->fresh()->salary_amount)->toBe('9999999999.99');
    });

    test('rejects invalid values and preserves all stored attributes', function (array $changes, string $field): void {
        $before = $this->driver->fresh()->getRawOriginal();

        expect(fn () => app(DriverManagementService::class)->updateEmployment($this->actor, $this->company, $this->driver, $changes))
            ->toThrow(function (ValidationException $exception) use ($field): void {
                expect($exception->errors())->toHaveKey($field);
            });
        expect($this->driver->fresh()->getRawOriginal())->toBe($before);
    })->with([
        'negative amount' => [['salary_amount' => '-0.01'], 'salary_amount'],
        'excess precision' => [['salary_amount' => '1.005'], 'salary_amount'],
        'overflow' => [['salary_amount' => '10000000000.00'], 'salary_amount'],
        'float' => [['salary_amount' => 1.25], 'salary_amount'],
        'scientific notation' => [['salary_amount' => '1e2'], 'salary_amount'],
        'null salary' => [['salary_amount' => null], 'salary_amount'],
        'currency' => [['salary_currency' => 'ZZZ'], 'salary_currency'],
        'period' => [['salary_period' => 'weekly'], 'salary_period'],
        'blank license' => [['license' => '  '], 'license'],
        'oversized license' => [['license' => str_repeat('A', 51)], 'license'],
        'inverted dates' => [['employment_ends_on' => '2026-09-01'], 'employment_ends_on'],
    ]);

    test('validates original date input before conversion and preserves stored values', function (string $field, string $value): void {
        $before = $this->driver->fresh()->getRawOriginal();

        expect(fn () => app(DriverManagementService::class)->updateEmployment($this->actor, $this->company, $this->driver, [$field => $value]))
            ->toThrow(function (ValidationException $exception) use ($field): void {
                expect(array_keys($exception->errors()))->toBe([$field]);
            });
        expect($this->driver->fresh()->getRawOriginal())->toBe($before);
    })->with(['license_expires_at', 'employment_starts_on', 'employment_ends_on'])
        ->with(['2026-02-30', '2026-13-01', 'not-a-date']);

    test('rejects protected and unknown payload keys even when unchanged or null', function (string $field): void {
        $before = $this->driver->fresh()->getRawOriginal();

        expect(fn () => app(DriverManagementService::class)->updateEmployment($this->actor, $this->company, $this->driver, [
            'license' => 'MUST-NOT-SAVE', $field => $this->driver->getAttribute($field),
        ]))->toThrow(function (ValidationException $exception) use ($field): void {
            expect($exception->errors())->toHaveKey($field);
        });
        expect($this->driver->fresh()->getRawOriginal())->toBe($before);
    })->with(['id', 'company_id', 'user_id', 'status', 'deleted_at', 'created_at', 'updated_at', 'role', 'email', 'unknown']);

    test('rejects a normalized license reserved by another employment including archived records', function (bool $archived): void {
        $existing = Driver::factory()->for($this->company)->create(['license' => 'RESERVED']);
        if ($archived) {
            $existing->delete();
        }
        $before = $this->driver->fresh()->getRawOriginal();

        expect(fn () => app(DriverManagementService::class)->updateEmployment($this->actor, $this->company, $this->driver, ['license' => '  reserved  ']))
            ->toThrow(function (ValidationException $exception): void {
                expect($exception->errors())->toHaveKey('license');
            });
        expect($this->driver->fresh()->getRawOriginal())->toBe($before);
    })->with([false, true]);

    test('merges partial updates with persisted values rather than dirty caller attributes', function (): void {
        $this->driver->salary_amount = '-100';
        $updated = app(DriverManagementService::class)->updateEmployment($this->actor, $this->company, $this->driver, ['license' => 'PARTIAL']);

        expect($updated->salary_amount)->toBe('650000.00');
        expect($updated->license)->toBe('PARTIAL');
        $same = app(DriverManagementService::class)->updateEmployment($this->actor, $this->company, $updated, ['license' => ' partial ']);
        expect($same->license)->toBe('PARTIAL');
    });

    test('returns translated protected-field and archived-state errors', function (bool $archived, string $locale, string $message): void {
        app()->setLocale($locale);
        if ($archived) {
            $this->driver->delete();
        }
        $data = $archived ? ['license' => 'REJECTED'] : ['id' => $this->driver->id];
        $field = $archived ? 'driver' : 'id';

        expect(fn () => app(DriverManagementService::class)->updateEmployment($this->actor, $this->company, $this->driver, $data))
            ->toThrow(function (ValidationException $exception) use ($field, $message): void {
                expect($exception->errors())->toBe([$field => [$message]]);
            });
    })->with([
        [false, 'en', 'This field cannot be changed through employment updates.'],
        [false, 'es', 'Este campo no puede modificarse mediante actualizaciones del empleo.'],
        [true, 'en', 'Restore the archived driver before changing employment.'],
        [true, 'es', 'Restaura el conductor archivado antes de modificar su empleo.'],
    ]);

    test('translates only the exact database license conflict after rollback', function (bool $licenseConflict): void {
        Driver::factory()->for($this->company)->create(['license' => 'DATABASE-RESERVED']);
        $before = $this->driver->fresh()->getRawOriginal();
        $body = $licenseConflict
            ? "NEW.license := 'DATABASE-RESERVED'; RETURN NEW;"
            : "RAISE EXCEPTION 'Injected unrelated lifecycle failure' USING ERRCODE = '23514';";
        DB::unprepared('CREATE FUNCTION pg_temp.test_driver_update_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN '.$body.' END $$');
        DB::unprepared('CREATE TRIGGER test_driver_update_failure BEFORE UPDATE ON drivers FOR EACH ROW EXECUTE FUNCTION pg_temp.test_driver_update_failure()');

        try {
            expect(fn () => app(DriverManagementService::class)->updateEmployment($this->actor, $this->company, $this->driver, ['license' => 'AVAILABLE']))
                ->toThrow(function (Exception $exception) use ($licenseConflict): void {
                    if ($licenseConflict) {
                        expect($exception)->toBeInstanceOf(ValidationException::class);
                        expect($exception->errors())->toBe(['license' => [__('This license is already reserved by a driver in this company.')]]);
                    } else {
                        expect($exception)->toBeInstanceOf(QueryException::class);
                        expect($exception->errorInfo[0])->toBe('23514');
                    }
                });
            expect($this->driver->fresh()->getRawOriginal())->toBe($before);
        } finally {
            DB::unprepared('DROP TRIGGER test_driver_update_failure ON drivers');
            DB::unprepared('DROP FUNCTION pg_temp.test_driver_update_failure()');
        }
    })->with([false, true]);
});
