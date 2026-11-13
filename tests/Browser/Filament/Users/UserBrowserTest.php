<?php

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\CompanyUser;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('User Browser Management', function () {
    test('CP-0027: Renders company users with name, email and assigned role badges', function () {
        $company = createCompany();
        $user = createUserWithRole('fleet-manager', $company);
        $user->update(['name' => 'Carlos Santana', 'email' => 'carlos.santana@example.com']);
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $path = parse_url(UserResource::getUrl('index', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->assertSee('Carlos Santana')
            ->assertSee('carlos.santana@example.com')
            ->assertSee('fleet-manager')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0028: Filters company users by name or email', function () {
        $company = createCompany();
        $userA = createUserWithRole('role-a', $company);
        $userA->update(['name' => 'Alfonso Ramirez', 'email' => 'alfonso@example.com']);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $userA->id]);

        $userB = createUserWithRole('role-b', $company);
        $userB->update(['name' => 'Beatriz Gomez', 'email' => 'beatriz@example.com']);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $userB->id]);

        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $path = parse_url(UserResource::getUrl('index', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->assertSee('Alfonso Ramirez')
            ->assertSee('Beatriz Gomez')
            ->type('.fi-ta-search-field input', 'Alfonso Ramirez')
            ->assertSee('Alfonso Ramirez')
            ->assertDontSee('Beatriz Gomez')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0029: Validates required fields when inviting a company user', function () {
        $company = createCompany();
        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $path = parse_url(UserResource::getUrl('create', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertVisible('input[id$=".name"]:invalid, [data-validation-error], .fi-fo-field-wrp-error-message')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0030: Revokes company user membership with confirmation modal', function () {
        $company = createCompany();
        $admin = createUserWithRole(UserRole::Admin, $company);
        $admin->update(['email_verified_at' => now()]);
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
        ]);

        $targetUser = createUserWithRole('operator', $company);
        $targetUser->update(['name' => 'User To Revoke', 'email' => 'revoke.me@example.com']);
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $targetUser->id,
        ]);

        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

        $path = parse_url(UserResource::getUrl('index', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->assertSee('User To Revoke')
            ->click('tr:has-text("User To Revoke") button:has-text("Remove Access")')
            ->assertSee('Remove Access to This Company')
            ->click('[aria-modal="true"] button:has-text("Remove Access")')
            ->assertDontSee('User To Revoke')
            ->assertNoJavaScriptErrors();
    });
});
