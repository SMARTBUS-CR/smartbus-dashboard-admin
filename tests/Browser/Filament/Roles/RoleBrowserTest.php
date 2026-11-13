<?php

use App\Enums\UserRole;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('Role Browser Management', function () {
    test('CP-0022: Renders role list with identifier, display name and permission count badge', function () {
        $company = createCompany();
        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $path = parse_url(RoleResource::getUrl('index', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->assertSee('Access Control')
            ->assertSee('Identifier')
            ->assertSee('Name')
            ->assertSee('Permissions')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0024: Rejects duplicate role identifier within the tenant', function () {
        $company = createCompany();
        Role::create([
            'name' => 'unique-dispatcher',
            'display_name' => 'Unique Dispatcher',
            'guard_name' => 'web',
            'company_id' => $company->id,
        ]);

        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $path = parse_url(RoleResource::getUrl('create', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->type('input[id="form.display_name"]', 'Unique Dispatcher')
            ->keys('input[id="form.display_name"]', ['Tab'])
            ->assertValue('input[id="form.name"]', 'unique-dispatcher')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertSee('has already been taken')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0025: Selects all resource permissions using Shield select-all action', function () {
        $company = createCompany();
        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $path = parse_url(RoleResource::getUrl('create', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->click('button[wire\:click^="mountAction(\'selectAll\',"][wire\:click*="BusResource"]')
            ->assertChecked('input[value="ViewAny:Bus"]')
            ->assertChecked('input[value="Create:Bus"]')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0026: Disables or hides delete action on protected system roles', function () {
        $company = createCompany();
        Role::findOrCreate(UserRole::Driver->value, 'web');

        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $path = parse_url(RoleResource::getUrl('index', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->assertSee('Driver')
            ->assertNotPresent('tr:has-text("Driver") button:has-text("Delete")')
            ->assertNoJavaScriptErrors();
    });
});
