<?php

use App\Enums\UserRole;
use App\Filament\Resources\Drivers\DriverResource;
use App\Models\Driver;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

test('Filtrar conductores por estado', function (): void {
    config(['app.locale' => 'en']);
    app()->setLocale('en');
    $company = createCompany();
    $active = Driver::factory()->for($company)->active()->create();
    $inactive = Driver::factory()->for($company)->inactive()->create();
    BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

    visit(parse_url(DriverResource::getUrl('index', tenant: $company), PHP_URL_PATH))
        ->assertSee($active->license)
        ->assertSee($inactive->license)
        ->assertSeeIn(Selector::getByRoleSelector('tab', ['name' => 'All 2', 'exact' => true]), '2')
        ->click(Selector::getByRoleSelector('tab', ['name' => 'Active 1', 'exact' => true]))
        ->assertSee($active->license)
        ->assertDontSee($inactive->license)
        ->click(Selector::getByRoleSelector('tab', ['name' => 'Inactive 1', 'exact' => true]))
        ->assertSee($inactive->license)
        ->assertDontSee($active->license)
        ->assertNoJavaScriptErrors();
});
