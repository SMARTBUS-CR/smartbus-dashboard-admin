<?php

use App\Enums\UserRole;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('Company And Role Browser Flows', function (): void {
    beforeEach(function () {
        config(['app.locale' => 'en']);
        app()->setLocale('en');
    });

    test('updates the role identifier on blur and submits the selected permission', function () {
        $company = createCompany();
        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        visit(parse_url(RoleResource::getUrl('create', tenant: $company), PHP_URL_PATH))
            ->assertVisible('input[id="form.display_name"]')
            ->type('input[id="form.display_name"]', 'Traffic Dispatcher')
            ->keys('input[id="form.display_name"]', ['Tab'])
            ->assertValue('input[id="form.name"]', 'traffic-dispatcher')
            ->check('input[value="View:Role"]')
            ->assertChecked('input[value="View:Role"]')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertSee('Created')
            ->assertNoJavaScriptErrors();

        $role = Role::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('name', 'traffic-dispatcher')
            ->firstOrFail();

        expect($role->permissions()->pluck('name')->all())->toContain('View:Role');
        BrowserSession::assertTokenWasValidated();
    });

    test('switches company through the tenant menu and shows only its roles', function () {
        $companyA = createCompany(['legal_name' => 'Northern Transport']);
        $companyB = createCompany(['legal_name' => 'Southern Transport']);

        Role::create(['name' => 'northern-dispatcher', 'display_name' => 'Northern Dispatcher', 'guard_name' => 'web', 'company_id' => $companyA->id]);
        Role::create(['name' => 'southern-dispatcher', 'display_name' => 'Southern Dispatcher', 'guard_name' => 'web', 'company_id' => $companyB->id]);

        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $page = visit(parse_url(RoleResource::getUrl('index', tenant: $companyA), PHP_URL_PATH))
            ->assertSee('Northern Dispatcher')
            ->assertDontSee('Southern Dispatcher')

            ->click('.fi-tenant-menu-trigger')
            ->assertSee('Southern Transport')
            ->click('Southern Transport')
            ->assertPathIs("/admin/{$companyB->slug}")
            ->assertNoJavaScriptErrors();
        BrowserSession::assertTokenWasValidated();

        $rolesPath = parse_url(RoleResource::getUrl('index', tenant: $companyB), PHP_URL_PATH);

        $page->click('a[href$="'.$rolesPath.'"]')
            ->assertSee('Southern Dispatcher')
            ->assertDontSee('Northern Dispatcher')
            ->assertNoJavaScriptErrors();
    });

    test('cancels company deletion and deletes only after confirming the modal', function () {
        $tenant = createCompany();
        $company = createCompany(['legal_name' => 'Company To Delete']);
        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $page = visit(parse_url(CompanyResource::getUrl('edit', ['record' => $company], tenant: $tenant), PHP_URL_PATH))
            ->click('Delete')->assertSee('Are you sure');

        $this->assertNotSoftDeleted($company);

        $page->click('[aria-modal="true"] button:has-text("Cancel")');

        $this->assertNotSoftDeleted($company);

        $page->click('Delete')->assertSee('Are you sure')
            ->click('[aria-modal="true"] button:has-text("Delete")')
            ->assertSee('Deleted')->assertNoJavaScriptErrors();

        $this->assertSoftDeleted($company);
        $this->assertNotSoftDeleted($tenant);
        BrowserSession::assertTokenWasValidated();
    });

});
