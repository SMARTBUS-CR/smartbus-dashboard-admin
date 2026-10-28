<?php

use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

describe('Driver Constraints', function (): void {
    test('reserves company identity and normalized license combinations including archived profiles', function (string $field, bool $archived): void {
        $driver = Driver::factory()->create(['license' => 'LICENSE-001']);
        if ($archived) {
            $driver->delete();
        }
        $changes = $field === 'user_id'
            ? ['user_id' => $driver->user_id]
            : ['license' => ' license-001 '];
        $duplicate = Driver::factory()->make(['company_id' => $driver->company_id, ...$changes]);

        expect(fn () => DB::transaction(fn () => $duplicate->save()))->toThrow(UniqueConstraintViolationException::class);
        $this->assertDatabaseCount('drivers', 1);
    })->with([
        'active company user' => ['user_id', false],
        'archived company user' => ['user_id', true],
        'active normalized license' => ['license', false],
        'archived normalized license' => ['license', true],
    ]);

    test('rejects malformed license currency and status in direct database writes', function (array $changes, string $constraint): void {
        $driver = Driver::factory()->create();

        expect(fn () => DB::transaction(fn () => DB::table('drivers')->where('id', $driver->id)->update($changes)))
            ->toThrow(QueryException::class, $constraint);
        expect($driver->fresh()->license)->toBe($driver->license);
    })->with([
        'lowercase license' => [['license' => 'license-001'], 'drivers_license_check'],
        'untrimmed license' => [['license' => ' LICENSE-001 '], 'drivers_license_check'],
        'empty license' => [['license' => ''], 'drivers_license_check'],
        'lowercase currency' => [['salary_currency' => 'crc'], 'drivers_salary_check'],
        'invalid currency format' => [['salary_currency' => '123'], 'drivers_salary_check'],
        'unknown status' => [['status' => 'unknown'], 'drivers_status_check'],
    ]);

    test('restricts company deletion and preserves its employment', function (): void {
        $driver = Driver::factory()->create();

        expect(fn () => DB::transaction(fn () => DB::table('companies')->where('id', $driver->company_id)->delete()))
            ->toThrow(QueryException::class, 'drivers_company_id_foreign');
        $this->assertModelExists($driver);
        $this->assertModelExists($driver->company);
    });

    test('requires a PostgreSQL company without imposing a cross database user foreign key', function (): void {
        $driver = Driver::factory()->create();
        $row = [...$driver->getAttributes(), 'id' => (string) Str::uuid(), 'license' => 'LICENSE-UNLINKED', 'user_id' => (string) Str::uuid()];

        DB::table('drivers')->insert($row);

        $this->assertDatabaseHas('drivers', ['id' => $row['id'], 'user_id' => $row['user_id']]);
        $this->assertDatabaseMissing('users', ['id' => $row['user_id']], 'mysql');
        expect(fn () => DB::transaction(fn () => DB::table('drivers')->insert([
            ...$row, 'id' => (string) Str::uuid(), 'company_id' => (string) Str::uuid(),
        ])))->toThrow(QueryException::class, 'drivers_company_id_foreign');
    });

    test('stores no identity or credential columns in the employment schema', function (): void {
        expect(Schema::getColumnListing('drivers'))->not->toContain('name', 'email', 'password', 'remember_token');
    });

    test('allows unchanged identity fields in a direct salary update', function (): void {
        $driver = Driver::factory()->create();

        DB::table('drivers')->where('id', $driver->id)->update([
            'company_id' => $driver->company_id, 'user_id' => $driver->user_id, 'salary_amount' => '700000.25',
        ]);

        expect($driver->fresh()->salary_amount)->toBe('700000.25');
    });

    test('can roll back and reapply identity protection without losing employment data', function (): void {
        $driver = Driver::factory()->create();
        $otherUser = User::factory()->create();
        $migration = require database_path('migrations/2026_10_21_233122_prevent_driver_identity_reassignment.php');

        $migration->down();
        $this->assertModelExists($driver);
        $migration->up();

        expect(fn () => DB::transaction(fn () => DB::table('drivers')->where('id', $driver->id)->update(['user_id' => $otherUser->id])))
            ->toThrow(QueryException::class, 'drivers_identity_immutable');
        expect($driver->fresh()->user_id)->toBe($driver->user_id);
    });
});
