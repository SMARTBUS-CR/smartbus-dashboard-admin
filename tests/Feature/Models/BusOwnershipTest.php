<?php

use App\Enums\UserRole;
use App\Services\BusManagementService;
use Illuminate\Validation\ValidationException;

test('rejects a company transfer through the bus model', function (): void {
    [$company, $otherCompany] = createTenantPair();
    $actor = createUserWithRole(UserRole::SuperAdmin);
    $bus = app(BusManagementService::class)->create($actor, $company, [
        'country_code' => 'CR', 'plate_number' => 'BUS-101',
        'unit_number' => 'UNIT-101', 'capacity' => 40,
    ]);
    $this->assertModelExists($bus);

    try {
        $bus->update(['company_id' => $otherCompany->getKey()]);
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('company_id');
        expect($bus->fresh()->company_id)->toBe($company->getKey());
    }
});
