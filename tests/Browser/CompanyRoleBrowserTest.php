<?php

use App\Enums\UserRole;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;

beforeEach(function () {
    config(['app.locale' => 'en']);
    app()->setLocale('en');
});

test('generates the role identifier on blur and saves the selected permission in the browser', function () {
    $company = createCompany();
    $this->actingAs(createUserWithRole(UserRole::SuperAdmin));

    visit(parse_url(RoleResource::getUrl('create', tenant: $company), PHP_URL_PATH))
        ->wait(1)
        ->type('input[id="form.display_name"]', 'Traffic Dispatcher')
        ->keys('input[id="form.display_name"]', ['Tab'])
        // Wait for the Livewire component to update the 'System Identifier' field
        ->assertValue('input[id="form.name"]', 'traffic-dispatcher')

        // Mark the checkbox using the exact selector from Filament Shield
        ->check('input[value="View:Role"]')
        ->wait(1) // Wait for the checkbox state to be registered

        // Click the submit button to create the role
        ->click('.fi-ac button[type="submit"]')

        // Wait for the notification to appear and assert its text
        ->waitForText('Created')
        ->assertNoJavaScriptErrors();

    // Change to a normal query since the role saved in the DB by Shield often uses snake_case or the exact string of the Name
    $role = Role::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->where('name', 'traffic-dispatcher')
        ->firstOrFail();

    expect($role->permissions()->pluck('name')->all())->toContain('View:Role');
});

test('switches company through the tenant menu and shows only its roles', function () {
    $companyA = createCompany(['legal_name' => 'Northern Transport']);
    $companyB = createCompany(['legal_name' => 'Southern Transport']);

    Role::create(['name' => 'northern-dispatcher', 'display_name' => 'Northern Dispatcher', 'guard_name' => 'web', 'company_id' => $companyA->id]);
    Role::create(['name' => 'southern-dispatcher', 'display_name' => 'Southern Dispatcher', 'guard_name' => 'web', 'company_id' => $companyB->id]);

    $this->actingAs(createUserWithRole(UserRole::SuperAdmin));

    // Visit the Roles page for Company A and assert that only its role is visible
    visit(parse_url(RoleResource::getUrl('index', tenant: $companyA), PHP_URL_PATH))
        ->assertSee('Northern Dispatcher')
        ->assertDontSee('Southern Dispatcher')

        // Open the tenant menu and wait for the company name to appear
        ->click('.fi-tenant-menu-trigger')
        ->waitForText('Southern Transport')

        // Force the click by specifically targeting the link that redirects to Company B's slug
        ->click("a[href*='/{$companyB->slug}']")

        ->wait(2) // Waits for asynchronous Livewire updates to complete
        ->assertPathIs("/admin/{$companyB->slug}")
        ->assertNoJavaScriptErrors();

    // Visit the Roles page for Company B and assert that only its role is visible
    visit(parse_url(RoleResource::getUrl('index', tenant: $companyB), PHP_URL_PATH))
        ->waitForText('Southern Dispatcher')

        ->assertSee('Southern Dispatcher')
        ->assertDontSee('Northern Dispatcher')
        ->assertNoJavaScriptErrors();
});

test('requires confirmation before deleting a company through its modal', function () {
    $tenant = createCompany();
    $company = createCompany(['legal_name' => 'Company To Delete']);
    $this->actingAs(createUserWithRole(UserRole::SuperAdmin));

    $page = visit(parse_url(CompanyResource::getUrl('edit', ['record' => $company], tenant: $tenant), PHP_URL_PATH))
        ->click('Delete')->assertSee('Are you sure');

    $this->assertNotSoftDeleted($company);

    $page->click('.fi-modal-footer .fi-color-danger')
        ->assertSee('Deleted')->assertNoJavaScriptErrors();

    $this->assertSoftDeleted($company);
    $this->assertNotSoftDeleted($tenant);
});
