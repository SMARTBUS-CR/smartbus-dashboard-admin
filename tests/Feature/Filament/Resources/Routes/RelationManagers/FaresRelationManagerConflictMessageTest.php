<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Pages\EditRoute;
use App\Filament\Resources\Routes\RelationManagers\FaresRelationManager;
use App\Models\Route;
use App\Models\RouteFare;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Route Fare Conflict Messages', function (): void {
    test('identifies an existing open-ended fare when it conflicts with a future free fare', function (string $locale, string $expectedMessage, ): void {
        app()->setLocale($locale);

        $company = createCompany();
        $route = Route::factory()->for($company)->create();

        $existingFare = RouteFare::factory()->create([
            'route_id' => $route->getKey(),
            'amount' => '600.00',
            'currency' => 'CRC',
            'valid_from' => '2027-01-01',
            'valid_until' => null,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(FaresRelationManager::class, [
            'ownerRecord' => $route,
            'pageClass' => EditRoute::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'amount' => '0',
                'currency' => 'CRC',
                'valid_from' => '2028-01-01',
                'valid_until' => '2028-12-01',
            ])
            ->assertHasFormErrors([
                'currency' => $expectedMessage,
            ]);

        expect($route->fares()->count())->toBe(1);

        $this->assertDatabaseHas(RouteFare::class, [
            'id' => $existingFare->getKey(),
            'amount' => '600.00',
            'valid_from' => '2027-01-01',
            'valid_until' => null,
        ]);
    })->with([
                'English' => [
                    'en',
                    'A fare of 600.00 CRC (from January 1, 2027, with no end date) already applies during the selected validity period.',
                ],
                'Spanish' => [
                    'es',
                    'Ya existe una tarifa de 600.00 CRC (desde el 1 de enero de 2027, sin fecha de finalización) vigente durante el periodo seleccionado.',
                ],
            ]);
});