<?php

use App\Enums\CompanyStatus;
use App\Models\Bus;
use Database\Seeders\BusSeeder;
use Illuminate\Support\Facades\Http;

describe('Bus Seeding', function (): void {
    test('creates deterministic demo buses without network calls or unrelated companies', function (): void {
        Http::preventStrayRequests();
        $company = createCompany(['slug' => 'smartbus-demo']);
        $otherCompany = createCompany();

        $this->seed(BusSeeder::class);

        $buses = $company->buses()->orderBy('unit_number')->get();
        expect($buses)->toHaveCount(3)
            ->and($buses->pluck('unit_number')->all())->toBe(['DEMO-001', 'DEMO-002', 'DEMO-003'])
            ->and($otherCompany->buses()->exists())->toBeFalse();
        foreach ($buses as $bus) {
            expect($bus->getKey())->toBeUuid()
                ->and($bus->country_code)->toBe('CR')
                ->and($bus->is_active)->toBeTrue()
                ->and($bus->capacity)->toBe(40);
        }
        $before = $buses->map->getAttributes()->all();
        $this->seed(BusSeeder::class);
        expect($company->buses()->orderBy('unit_number')->get()->map->getAttributes()->all())->toBe($before);
        $this->assertDatabaseCount('buses', 3);
        Http::assertNothingSent();
    });

    test('preserves edited identities and archived buses when seeded again', function (): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $this->seed(BusSeeder::class);
        $bus = $company->buses()->where('unit_number', 'DEMO-001')->sole();
        $bus->update(['unit_number' => 'CUSTOM-001', 'plate_number' => 'CUSTOM-PLATE', 'capacity' => 55]);
        $archived = $company->buses()->where('unit_number', 'DEMO-002')->sole();
        $archived->delete();
        $before = Bus::withTrashed()->orderBy('id')->get()->map->getAttributes()->all();

        $this->seed(BusSeeder::class);

        expect(Bus::withTrashed()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
        $this->assertSoftDeleted($archived);
        $this->assertDatabaseCount('buses', 3);
    });

    test('skips missing inactive and archived demo companies with an explanation', function (string $scenario): void {
        if ($scenario !== 'missing') {
            $company = createCompany(['slug' => 'smartbus-demo']);
            if ($scenario === 'archived') {
                $company->delete();
            } else {
                $company->update(['status' => CompanyStatus::INACTIVE]);
            }
        }

        $this->artisan('db:seed', ['--class' => BusSeeder::class, '--force' => true])
            ->expectsOutputToContain('BusSeeder skipped: an active smartbus-demo company is required.')
            ->assertSuccessful();
        $this->assertDatabaseCount('buses', 0);
    })->with(['missing', 'inactive', 'archived']);

    test('does not create demo buses in production', function (): void {
        createCompany(['slug' => 'smartbus-demo']);
        $this->app->instance('env', 'production');

        try {
            $this->artisan('db:seed', ['--class' => BusSeeder::class, '--force' => true])
                ->expectsOutputToContain('BusSeeder skipped in production.')
                ->assertSuccessful();
        } finally {
            $this->app->instance('env', 'testing');
        }

        $this->assertDatabaseCount('buses', 0);
    });

    test('does not take identities already reserved by another bus', function (string $field, string $value, bool $archived, bool $foreign): void {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $owner = $foreign ? createCompany() : $company;
        $factory = Bus::factory()->for($owner);
        $existing = ($archived ? $factory->archived() : $factory)->create([$field => $value, 'country_code' => 'CR']);
        $before = $existing->fresh()->getAttributes();

        $this->seed(BusSeeder::class);

        expect($existing->fresh()->getAttributes())->toBe($before);
        $this->assertDatabaseCount('buses', 3);
    })->with([
        'reserved plate' => ['plate_number', 'DEMO-BUS-001', false, false],
        'reserved unit' => ['unit_number', 'DEMO-001', false, false],
        'archived plate' => ['plate_number', 'DEMO-BUS-001', true, false],
        'archived unit' => ['unit_number', 'DEMO-001', true, false],
        'foreign plate' => ['plate_number', 'DEMO-BUS-001', false, true],
    ]);
});
