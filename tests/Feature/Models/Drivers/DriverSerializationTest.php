<?php

use App\Models\Driver;

test('excludes salary and loaded identity data from ordinary arrays and JSON', function (): void {
    $driver = Driver::factory()->create(['salary_amount' => '650000.25'])->fresh()->load('user');

    foreach ([$driver->toArray(), json_decode($driver->toJson(), true, flags: JSON_THROW_ON_ERROR)] as $serialized) {
        $keys = array_keys($serialized);
        sort($keys);
        expect($keys)->toBe([
            'company_id', 'created_at', 'deleted_at', 'employment_ends_on', 'employment_starts_on',
            'id', 'license', 'license_expires_at', 'status', 'updated_at', 'user_id',
        ]);
        expect($serialized['status'])->toBe('active');
    }

    expect($driver->salary_amount)->toBe('650000.25');
    expect($driver->salary_currency)->toBe('CRC');
    expect($driver->salary_period)->toBe('monthly');
});
