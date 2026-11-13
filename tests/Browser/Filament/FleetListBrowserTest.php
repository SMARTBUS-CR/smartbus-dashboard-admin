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
