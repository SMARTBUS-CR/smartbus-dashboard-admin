<?php

use App\Enums\UserRole;
use App\Filament\Resources\Companies\CompanyResource;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('Company Browser Management', function () {
    test('CP-0014: Renders company list with country badge, legal ID and international phone', function () {
        $tenant = createCompany([
            'legal_name' => 'Transportes Centrales S.A.',
            'legal_id' => '3-101-998877',
            'country_code' => 'CR',
            'phone' => '+50622223333',
        ]);

        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $path = parse_url(CompanyResource::getUrl('index', tenant: $tenant), PHP_URL_PATH);

        visit($path)
            ->assertSee('Transportes Centrales S.A.')
            ->assertSee('3-101-998877')
            ->assertSee('CR')
            ->assertSee('+506 2222 3333')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0015: Filters company list by legal name or legal ID', function () {
        $tenant = createCompany([
            'legal_name' => 'Alpha Transit Express S.A.',
            'legal_id' => '3-101-111111',
            'country_code' => 'CR',
        ]);
        createCompany([
            'legal_name' => 'Beta Autobuses del Norte S.A.',
            'legal_id' => '3-102-222222',
            'country_code' => 'CR',
        ]);

        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $path = parse_url(CompanyResource::getUrl('index', tenant: $tenant), PHP_URL_PATH);

        visit($path)
            ->assertSee('Alpha Transit Express S.A.')
            ->assertSee('Beta Autobuses del Norte S.A.')
            ->type('.fi-ta-search-field input', 'Alpha Transit')
            ->assertSee('Alpha Transit Express S.A.')
            ->assertDontSee('Beta Autobuses del Norte S.A.')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0016: Filters company list by archive status using TrashedFilter', function () {
        $tenant = createCompany(['legal_name' => 'Tenant Nav Header S.A.']);
        $active = createCompany(['legal_name' => 'Active Filtered Co S.A.']);
        $archived = createCompany(['legal_name' => 'Archived Filtered Co S.A.']);
        $archived->delete();

        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $path = parse_url(CompanyResource::getUrl('index', tenant: $tenant), PHP_URL_PATH);

        visit($path)
            ->assertSee('Active Filtered Co S.A.')
            ->assertDontSee('Archived Filtered Co S.A.')
            ->click('button[aria-label="Filter"]')
            ->select('select[id$="trashed.value"]', '0')
            ->click('button:has-text("Apply filters")')
            ->click('button[aria-label="Filter"]')
            ->assertSee('Archived Filtered Co S.A.')
            ->assertDontSee('Active Filtered Co S.A.')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0020: Validates required fields when creating a company', function () {
        $tenant = createCompany();
        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $path = parse_url(CompanyResource::getUrl('create', tenant: $tenant), PHP_URL_PATH);

        visit($path)
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertVisible('input[id$=".legal_name"]:invalid, [data-validation-error], .fi-fo-field-wrp-error-message')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0021: Rejects duplicate legal ID within the same country', function () {
        $tenant = createCompany([
            'legal_name' => 'Existing Company S.A.',
            'legal_id' => '3-101-555555',
            'country_code' => 'CR',
        ]);
        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $path = parse_url(CompanyResource::getUrl('create', tenant: $tenant), PHP_URL_PATH);

        visit($path)
            ->type('input[id$=".legal_name"]', 'Duplicate ID Company S.A.')
            ->type('input[id$=".legal_id"]', '3-101-555555')
            ->type('input[id$=".email"]', 'duplicate-legal-id@example.com')
            ->type('input[id$=".phone"]', '88887777')
            ->type('textarea[id$=".address"]', 'Calle Principal 123')
            ->click('button[id="form.country_code"]')
            ->assertVisible('[role="option"][data-value="CR"]')
            ->click('[role="option"][data-value="CR"]')
            ->select('select[id$=".timezone"]', 'America/Costa_Rica')
            ->select('select[id$=".status"]', 'active')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertSee('has already been taken')
            ->assertNoJavaScriptErrors();
    });
});
