<?php

use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

describe('Driver Persistence', function (): void {
    test('persists a UUID employment on PostgreSQL and resolves its existing MySQL identity separately', function (): void {
        $company = createCompany();
        $user = User::factory()->create();
        $passwordHash = $user->getRawOriginal('password');

        $driver = Driver::factory()->for($company)->for($user)->create([
            'license' => 'LICENSE-001',
            'license_expires_at' => '2030-12-31',
            'employment_starts_on' => '2026-10-01',
            'employment_ends_on' => '2027-10-01',
            'salary_amount' => '650000.25',
            'salary_currency' => 'CRC',
        ])->fresh();

        expect(Str::isUuid($driver->getKey()))->toBeTrue();
        expect($driver->getConnection()->getName())->toBe('pgsql');
        expect($driver->company->is($company))->toBeTrue();
        expect($driver->user->is($user))->toBeTrue();
        expect($driver->user->getConnection()->getName())->toBe('mysql');
        expect($driver->license_expires_at)->toBeInstanceOf(CarbonImmutable::class);
        expect($driver->license_expires_at->toDateString())->toBe('2030-12-31');
        expect($driver->employment_starts_on->toDateString())->toBe('2026-10-01');
        expect($driver->employment_ends_on->toDateString())->toBe('2027-10-01');
        expect($driver->salary_amount)->toBe('650000.25');
        expect($driver->salary_currency)->toBe('CRC');
        expect($driver->salary_period)->toBe('monthly');
        expect($driver->status)->toBe(DriverStatus::ACTIVE);
        expect($user->fresh()->getRawOriginal('password'))->toBe($passwordHash);
        $this->assertDatabaseCount('users', 1, 'mysql');
        $this->assertModelExists($driver);
    });

    test('persists an inactive employment with an open ended date', function (): void {
        $driver = Driver::factory()->create(['status' => DriverStatus::INACTIVE])->fresh();

        expect($driver->status)->toBe(DriverStatus::INACTIVE);
        expect($driver->employment_ends_on)->toBeNull();
    });

    test('eager loads identity on MySQL separately from PostgreSQL employment and company queries', function (): void {
        $driver = Driver::factory()->create();
        $pgsql = DB::connection('pgsql');
        $mysql = DB::connection('mysql');
        $pgsql->enableQueryLog();
        $mysql->enableQueryLog();
        $pgsql->flushQueryLog();
        $mysql->flushQueryLog();

        try {
            $loaded = Driver::with(['company', 'user'])->findOrFail($driver->id);

            expect($loaded->user->getKey())->toBe($driver->user_id);
            expect($loaded->company->getKey())->toBe($driver->company_id);
            expect($pgsql->getQueryLog())->toHaveCount(2);
            expect($mysql->getQueryLog())->toHaveCount(1);
            expect($mysql->getQueryLog()[0]['query'])->toContain('users')->not->toContain('drivers', 'companies');
            foreach ($pgsql->getQueryLog() as $query) {
                expect($query['query'])->not->toContain('users');
            }
        } finally {
            $pgsql->disableQueryLog();
            $mysql->disableQueryLog();
            $pgsql->flushQueryLog();
            $mysql->flushQueryLog();
        }
    });
});
