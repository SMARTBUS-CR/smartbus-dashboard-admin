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

        expect(Str::isUuid($driver->getKey()))->toBeTrue()
            ->and($driver->getConnection()->getName())->toBe('pgsql')
            ->and($driver->company->is($company))->toBeTrue()
            ->and($driver->user->is($user))->toBeTrue()
            ->and($driver->user->getConnection()->getName())->toBe('mysql')
            ->and($driver->license_expires_at)->toBeInstanceOf(CarbonImmutable::class)
            ->and($driver->license_expires_at->toDateString())->toBe('2030-12-31')
            ->and($driver->employment_starts_on->toDateString())->toBe('2026-10-01')
            ->and($driver->employment_ends_on->toDateString())->toBe('2027-10-01')
            ->and($driver->salary_amount)->toBe('650000.25')
            ->and($driver->salary_currency)->toBe('CRC')
            ->and($driver->salary_period)->toBe('monthly')
            ->and($driver->status)->toBe(DriverStatus::ACTIVE)
            ->and($user->fresh()->getRawOriginal('password'))->toBe($passwordHash);
        $this->assertDatabaseCount('users', 1, 'mysql');
        $this->assertModelExists($driver);
    });

    test('persists an inactive employment with an open ended date', function (): void {
        $driver = Driver::factory()->create(['status' => DriverStatus::INACTIVE])->fresh();

        expect($driver->status)->toBe(DriverStatus::INACTIVE)
            ->and($driver->employment_ends_on)->toBeNull();
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

            expect($loaded->user->getKey())->toBe($driver->user_id)
                ->and($loaded->company->getKey())->toBe($driver->company_id)
                ->and($pgsql->getQueryLog())->toHaveCount(2)
                ->and($mysql->getQueryLog())->toHaveCount(1)
                ->and($mysql->getQueryLog()[0]['query'])->toContain('users')->not->toContain('drivers', 'companies');
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
