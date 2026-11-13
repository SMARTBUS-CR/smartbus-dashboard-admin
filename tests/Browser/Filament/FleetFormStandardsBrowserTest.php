<?php

use App\Enums\UserRole;
use App\Filament\Resources\Buses\BusResource;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

test('Completar campos de Flota en pantallas pequeñas y medianas', function (): void {
    config(['app.locale' => 'en']);
    app()->setLocale('en');
    $company = createCompany();
    BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

    $plate = 'input[id="form.plate_number"]';
    $unit = 'input[id="form.unit_number"]';
    $page = visit(BusResource::getUrl('create', isAbsolute: false, tenant: $company));

    foreach ([375, 768, 1280] as $width) {
        $page->resize($width, 900)
            ->assertSee('Fleet Identification')
            ->assertVisible($plate)
            ->assertVisible($unit);
    }

    $page->resize(375, 900)
        ->fill($plate, 'SJB-5421')
        ->fill($unit, 'BUS-04')
        ->assertValue($plate, 'SJB-5421')
        ->assertValue($unit, 'BUS-04')
        ->assertVisible(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
        ->assertNoJavaScriptErrors();
});
