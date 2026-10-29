<?php

use App\Models\Permission;
use Database\Seeders\DriverPermissionsSeeder;
use Illuminate\Support\Facades\DB;

describe('Driver Permissions Seeder', function (): void {
    test('provisions all workforce permissions idempotently without granting roles', function (): void {
        $expected = [
            'Create:Driver', 'Create:DriverSchedule', 'Delete:Driver', 'Delete:DriverSchedule',
            'Restore:Driver', 'Update:Driver', 'Update:DriverSchedule', 'View:Driver', 'View:DriverSchedule',
            'ViewAny:Driver', 'ViewAny:DriverSchedule', 'ViewAttendance:Driver', 'ViewSalary:Driver',
        ];
        $this->seed(DriverPermissionsSeeder::class);
        $first = Permission::query()->whereIn('name', $expected)->orderBy('name')->pluck('id', 'name')->all();
        $this->seed(DriverPermissionsSeeder::class);

        expect(array_keys($first))->toBe($expected);
        expect(Permission::query()->whereIn('name', $expected)->orderBy('name')->pluck('id', 'name')->all())->toBe($first);
        expect(Permission::query()->whereIn('name', $expected)->pluck('guard_name')->unique()->all())->toBe(['web']);
        expect(DB::connection('mysql')->table('role_has_permissions')->count())->toBe(0);
    });
});
