<?php

use App\Enums\UserRole;
use App\Filament\Resources\Buses\Pages\ListBuses;
use App\Models\Bus;
use App\Models\CompanyUser;
use Livewire\Livewire;

describe('Bus Resource Action Security', function (): void {
    test('rejects a forged lifecycle action without its permission', function (string $action, bool $archived): void {
        $company = createCompany();
        $bus = Bus::factory()->for($company)->create();
        if ($archived) {
            $bus->delete();
        }

        $actor = createUserWithRole('fleet-viewer', $company);
        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);
        grantShield($actor, ['ViewAny:Bus', 'View:Bus'], $company);
        actingAsInCompany($actor, $company);

        $component = Livewire::test(ListBuses::class);
        if ($archived) {
            $component->filterTable('trashed', false);
        }

        $component
            ->call('mountAction', $action, [], [
                'table' => true,
                'recordKey' => $bus->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        $archived ? $this->assertSoftDeleted($bus) : $this->assertNotSoftDeleted($bus);
    })->with([
        'deactivation' => ['deactivate', false],
        'archival' => ['delete', false],
        'restoration' => ['restore', true],
    ]);

    test('rejects lifecycle actions against another company bus', function (string $action, bool $archived): void {
        [$company, $otherCompany] = createTenantPair();
        $foreignBus = Bus::factory()->for($otherCompany)->create();
        if ($archived) {
            $foreignBus->delete();
        }

        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        $component = Livewire::test(ListBuses::class);
        if ($archived) {
            $component->filterTable('trashed', false);
        }

        $component
            ->assertCanNotSeeTableRecords([$foreignBus])
            ->call('mountAction', $action, [], [
                'table' => true,
                'recordKey' => $foreignBus->getKey(),
            ])
            ->call('callMountedAction')
            ->assertActionNotMounted();

        $archived ? $this->assertSoftDeleted($foreignBus) : $this->assertNotSoftDeleted($foreignBus);
    })->with([
        'foreign deactivation' => ['deactivate', false],
        'foreign archival' => ['delete', false],
        'foreign restoration' => ['restore', true],
    ]);
});
