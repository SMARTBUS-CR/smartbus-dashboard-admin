<?php

use App\Enums\UserRole;
use App\Filament\Resources\Buses\BusResource;
use App\Models\Bus;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

test('Desactivar unidad desde el menú', function (): void {
    config(['app.locale' => 'en']);
    app()->setLocale('en');
    $company = createCompany();
    $bus = Bus::factory()->for($company)->active()->create();
    BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

    visit(parse_url(BusResource::getUrl('index', tenant: $company), PHP_URL_PATH))
        ->click(Selector::getByRoleSelector('tab', ['name' => 'Active 1', 'exact' => true]))
        ->assertSee($bus->unit_number)
        ->click(Selector::getByRoleSelector('button', ['name' => 'More Actions', 'exact' => true]))
        ->click(Selector::getByRoleSelector('button', ['name' => 'Deactivate', 'exact' => true]))
        ->click(Selector::getByRoleSelector('button', ['name' => 'Confirm', 'exact' => true]))
        ->assertSee('Bus Deactivated')
        ->assertDontSee($bus->unit_number)
        ->assertSeeIn(Selector::getByRoleSelector('tab', ['name' => 'Active 0', 'exact' => true]), '0')
        ->click(Selector::getByRoleSelector('tab', ['name' => 'Inactive 1', 'exact' => true]))
        ->assertSee($bus->unit_number)
        ->assertNoJavaScriptErrors();

    expect($bus->fresh()->is_active)->toBeFalse();
});

test('Mostrar unidad, matrícula y capacidad de la flota', function (): void {
    config(['app.locale' => 'en']);
    app()->setLocale('en');
    $company = createCompany();
    $bus = Bus::factory()->for($company)->create([
        'country_code' => 'CR',
        'brand' => 'Volvo',
        'model' => 'B8R',
        'year' => 2024,
        'capacity' => 50,
    ]);
    BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

    visit(BusResource::getUrl('index', isAbsolute: false, tenant: $company))
        ->assertSeeIn('td.fi-ta-cell-unit-number .fi-badge', $bus->unit_number)
        ->assertSeeIn('td.fi-ta-cell-plate-number', $bus->plate_number)
        ->assertSeeIn('td.fi-ta-cell-country-code .fi-badge', 'CR')
        ->assertSeeIn('td.fi-ta-cell-brand', 'Volvo')
        ->assertSeeIn('td.fi-ta-cell-brand .fi-ta-text-description', 'B8R · 2024')
        ->assertSeeIn('td.fi-ta-cell-capacity', '50 passengers')
        ->assertNotPresent('td.fi-ta-cell-is-active')
        ->assertNoJavaScriptErrors();
});
