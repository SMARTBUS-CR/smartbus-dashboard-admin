<?php

use App\Models\Bus;

describe('Bus Factory', function (): void {
    test('creates normalized buses with a real company and typed attributes', function (): void {
        $bus = Bus::factory()->create()->fresh();

        $this->assertModelExists($bus);
        $this->assertModelExists($bus->company);
        expect($bus->getKey())->toBeUuid()
            ->and($bus->country_code)->toBeIn(array_keys(config('countries')))
            ->and($bus->plate_number)->toBe(strtoupper(trim($bus->plate_number)))
            ->and($bus->capacity)->toBeInt()->toBeGreaterThan(0)->toBeLessThan(32768)
            ->and($bus->is_active)->toBeTrue()
            ->and($bus->company->buses()->whereKey($bus->getKey())->exists())->toBeTrue();
    });

    test('supports operational fixture states without changing company ownership', function (string $state, bool $active, bool $archived): void {
        $company = createCompany();
        $bus = Bus::factory()->for($company)->{$state}()->create();

        $this->assertDatabaseHas('buses', [
            'id' => $bus->getKey(), 'company_id' => $company->getKey(), 'is_active' => $active,
        ]);
        expect($bus->trashed())->toBe($archived);
        if ($archived) {
            $this->assertSoftDeleted($bus);
        }
    })->with([
        'active' => ['active', true, false],
        'inactive' => ['inactive', false, false],
        'archived' => ['archived', true, true],
    ]);
});
