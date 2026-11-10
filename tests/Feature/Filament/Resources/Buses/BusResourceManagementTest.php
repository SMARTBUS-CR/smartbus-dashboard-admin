<?php

use App\Enums\UserRole;
use App\Filament\Resources\Buses\Pages\CreateBus;
use App\Filament\Resources\Buses\Pages\EditBus;
use App\Filament\Resources\Buses\Pages\ListBuses;
use App\Models\Bus;
use App\Models\CompanyUser;
use App\Models\Trip;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Bus Resource Management', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany();
        $this->actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->actor->getKey(),
        ]);
        grantShield($this->actor, [
            'ViewAny:Bus', 'View:Bus', 'Create:Bus', 'Update:Bus', 'Delete:Bus', 'Restore:Bus',
        ], $this->company);
        actingAsInCompany($this->actor, $this->company);
    });

    test('creates a normalized bus through the canonical fleet workflow', function (): void {
        Livewire::test(CreateBus::class)
            ->fillForm([
                'country_code' => 'cr',
                'plate_number' => ' bus-123 ',
                'unit_number' => ' 17 ',
                'brand' => ' Mercedes-Benz ',
                'model' => ' O 500 ',
                'year' => 2026,
                'capacity' => 55,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertRedirect();

        $this->assertDatabaseHas(Bus::class, [
            'company_id' => $this->company->getKey(),
            'country_code' => 'CR',
            'plate_number' => 'BUS-123',
            'unit_number' => '17',
            'brand' => 'Mercedes-Benz',
            'model' => 'O 500',
            'year' => 2026,
            'capacity' => 55,
            'is_active' => true,
        ]);
    });

    test('rejects invalid fleet identifiers and capacity', function (): void {
        Livewire::test(CreateBus::class)
            ->fillForm([
                'country_code' => 'XX',
                'plate_number' => null,
                'unit_number' => null,
                'year' => 1800,
                'capacity' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'country_code', 'plate_number', 'unit_number', 'year', 'capacity',
            ])
            ->assertNotNotified();

        expect(Bus::query()->exists())->toBeFalse();
    });

    test('updates bus details without allowing company ownership to be forged', function (): void {
        $bus = Bus::factory()->for($this->company)->create();
        $otherCompany = createCompany();

        Livewire::test(EditBus::class, ['record' => $bus->getKey()])
            ->fillForm([
                'country_code' => 'cr',
                'plate_number' => 'new-plate',
                'unit_number' => 'NEW-UNIT',
                'brand' => 'Volvo',
                'model' => 'B8R',
                'year' => 2025,
                'capacity' => 48,
            ])
            ->set('data.company_id', $otherCompany->getKey())
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas(Bus::class, [
            'id' => $bus->getKey(),
            'company_id' => $this->company->getKey(),
            'plate_number' => 'NEW-PLATE',
            'unit_number' => 'NEW-UNIT',
            'capacity' => 48,
        ]);
    });

    test('deactivates and reactivates a bus through explicit actions', function (): void {
        $bus = Bus::factory()->for($this->company)->active()->create();

        Livewire::test(ListBuses::class)
            ->callAction(TestAction::make('deactivate')->table($bus))
            ->assertNotified();

        expect($bus->fresh()->is_active)->toBeFalse();

        Livewire::test(ListBuses::class)
            ->callAction(TestAction::make('activate')->table($bus->fresh()))
            ->assertNotified();

        expect($bus->fresh()->is_active)->toBeTrue();
    });

    test('archives and restores a bus without physical deletion', function (): void {
        $bus = Bus::factory()->for($this->company)->create();

        Livewire::test(ListBuses::class)
            ->callAction(TestAction::make('delete')->table($bus))
            ->assertNotified();

        $this->assertSoftDeleted($bus);
        $this->assertDatabaseCount('buses', 1);

        Livewire::test(ListBuses::class)
            ->filterTable('trashed', false)
            ->callAction(TestAction::make('restore')->table($bus))
            ->assertNotified();

        $this->assertNotSoftDeleted($bus);
        $this->assertDatabaseCount('buses', 1);
    });

    test('preserves an active bus when a committed trip blocks deactivation', function (): void {
        $bus = Bus::factory()->for($this->company)->active()->create();
        Trip::factory()->create([
            'company_id' => $this->company->getKey(),
            'bus_id' => $bus->getKey(),
        ]);

        Livewire::test(ListBuses::class)
            ->callAction(TestAction::make('deactivate')->table($bus))
            ->assertNotified();

        expect($bus->fresh()->is_active)->toBeTrue();
        $this->assertNotSoftDeleted($bus);
    });
});
