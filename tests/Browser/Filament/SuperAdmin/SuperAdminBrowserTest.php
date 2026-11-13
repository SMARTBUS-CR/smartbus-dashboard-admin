<?php

use App\Enums\UserRole;
use App\Filament\Resources\SuperAdmins\SuperAdminResource;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('SuperAdmin Browser Management', function () {
    test('CP-0031: Lists platform super administrators with action controls', function () {
        $tenant = createCompany();
        $admin = createUserWithRole(UserRole::SuperAdmin);
        $admin->update(['name' => 'Global Admin', 'email' => 'global.admin@example.com']);

        BrowserSession::start($admin);

        $path = parse_url(SuperAdminResource::getUrl('index', tenant: $tenant), PHP_URL_PATH);

        visit($path)
            ->assertSee('Global Admin')
            ->assertSee('global.admin@example.com')
            ->assertVisible('button:has-text("Deactivate")')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0032: Validates weak or compromised password on superadmin creation', function () {
        $tenant = createCompany();
        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $path = parse_url(SuperAdminResource::getUrl('create', tenant: $tenant), PHP_URL_PATH);

        visit($path)
            ->type('input[id$=".name"]', 'New Admin')
            ->type('input[id$=".email"]', 'new.admin@example.com')
            ->type('input[id$=".password"]', '12345678')
            ->type('input[id$=".password_confirmation"]', '12345678')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertVisible('input[id$=".password"]:invalid, [data-validation-error], .fi-fo-field-wrp-error-message')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0033: Deactivates another superadmin account with confirmation modal', function () {
        $tenant = createCompany();
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $actor->update(['email_verified_at' => now()]);

        $other = createUserWithRole(UserRole::SuperAdmin);
        $other->update([
            'email_verified_at' => now(),
            'name' => 'Other Admin',
            'email' => 'other.admin@example.com',
        ]);

        BrowserSession::start($actor);

        $path = parse_url(SuperAdminResource::getUrl('index', tenant: $tenant), PHP_URL_PATH);

        visit($path)
            ->assertSee('Other Admin')
            ->click('tr:has-text("Other Admin") button:has-text("Deactivate")')
            ->assertSee('Deactivate System Admin')
            ->click('[aria-modal="true"] button:has-text("Deactivate")')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0034: Deactivating own superadmin account revokes session and redirects to login', function () {
        $tenant = createCompany();
        $remainingAdmin = createUserWithRole(UserRole::SuperAdmin);
        $remainingAdmin->update(['email_verified_at' => now(), 'name' => 'Remaining Admin']);

        $self = createUserWithRole(UserRole::SuperAdmin);
        $self->update([
            'email_verified_at' => now(),
            'name' => 'Self Superadmin',
            'email' => 'self.admin@example.com',
        ]);

        BrowserSession::start($self);

        $path = parse_url(SuperAdminResource::getUrl('index', tenant: $tenant), PHP_URL_PATH);

        visit($path)
            ->assertSee('Self Superadmin')
            ->click('tr:has-text("Self Superadmin") button:has-text("Deactivate")')
            ->assertSee('Deactivate System Admin')
            ->click('[aria-modal="true"] button:has-text("Deactivate")')
            ->assertSee('Session Ended')
            ->assertNoJavaScriptErrors();
    });
});
