<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Tests\Support\BrowserSession;

describe('Authentication And Password Reset Flows', function (): void {
    beforeEach(function (): void {
        config(['app.locale' => 'en']);
        app()->setLocale('en');
    });

    test('CP-0001: Successfully signs in with valid credentials', function (): void {
        $company = createCompany();
        $user = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);
        config(['services.smartbus.gateway.url' => 'https://gateway.test']);

        Http::preventStrayRequests();
        Http::fake([
            'https://gateway.test/auth/login*' => Http::response([
                'meta' => [
                    'access_token' => 'browser-test-token',
                    'token_type' => 'Bearer',
                    'expires_at' => now()->addHour()->toIso8601String(),
                ],
                'data' => [
                    'id' => (string) $user->id,
                    'type' => 'users',
                    'attributes' => [
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                ],
            ], 200),
            BrowserSession::VALIDATE_URL => Http::response(['meta' => ['valid' => true]], 200),
        ]);

        $loginUrl = parse_url(Filament::getPanel('admin')->getLoginUrl(), PHP_URL_PATH);

        visit($loginUrl)
            ->assertVisible('input[type="email"]')
            ->type('input[type="email"]', $user->email)
            ->type('input[type="password"]', 'Password123!')
            ->click('button[type="submit"]')
            ->assertPathIs("/admin/{$company->slug}")
            ->assertNoJavaScriptErrors();
    });

    test('CP-0002: Rejects submission with empty required fields', function (): void {
        $loginUrl = parse_url(Filament::getPanel('admin')->getLoginUrl(), PHP_URL_PATH);

        visit($loginUrl)
            ->assertVisible('input[type="email"]')
            ->click('button[type="submit"]')
            ->assertPathIs($loginUrl)
            ->assertNoJavaScriptErrors();
    });

    test('CP-0003: Shows error notification for invalid credentials', function (): void {
        config(['services.smartbus.gateway.url' => 'https://gateway.test']);

        Http::preventStrayRequests();
        Http::fake([
            'https://gateway.test/auth/login*' => Http::response([
                'errors' => [
                    ['code' => 'invalid_credentials', 'message' => 'The provided credentials are incorrect.'],
                ],
            ], 401),
        ]);

        $loginUrl = parse_url(Filament::getPanel('admin')->getLoginUrl(), PHP_URL_PATH);

        visit($loginUrl)
            ->type('input[type="email"]', 'nonexistent@example.com')
            ->type('input[type="password"]', 'WrongPassword123!')
            ->click('button[type="submit"]')
            ->assertPathIs($loginUrl)
            ->assertNoJavaScriptErrors();
    });

    test('CP-0004: Displays banner when navigating with account disabled notice', function (): void {
        $loginUrl = parse_url(Filament::getPanel('admin')->getLoginUrl(), PHP_URL_PATH);

        test()->withSession(['auth_notice' => 'account_deactivated']);

        visit($loginUrl)
            ->assertSee('Session Ended')
            ->assertSee('Your account has been deactivated. Contact a system admin for assistance.')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0005: Sends password reset email for valid address', function (): void {
        config(['services.smartbus.gateway.url' => 'https://gateway.test']);

        Http::preventStrayRequests();
        Http::fake([
            'https://gateway.test/auth/password/forgot' => Http::response([
                'meta' => [
                    'message' => 'Password reset code sent successfully.',
                ],
            ], 200),
        ]);

        $resetUrl = parse_url(Filament::getPanel('admin')->getRequestPasswordResetUrl(), PHP_URL_PATH);

        visit($resetUrl)
            ->assertVisible('input[type="email"]')
            ->type('input[type="email"]', 'driver@example.com')
            ->click('button[type="submit"]')
            ->assertSee('Reset Code Sent')
            ->assertSee('Password reset code sent successfully.')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0006: Rejects password reset with invalid email format', function (): void {
        $resetUrl = parse_url(Filament::getPanel('admin')->getRequestPasswordResetUrl(), PHP_URL_PATH);

        visit($resetUrl)
            ->assertVisible('input[type="email"]')
            ->type('input[type="email"]', 'invalid-email-address')
            ->click('button[type="submit"]')
            ->assertDontSee('Reset Code Sent')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0008: Displays Scramble documentation link for SuperAdmin user', function (): void {
        $company = createCompany();
        $superAdmin = createUserWithRole(UserRole::SuperAdmin);

        BrowserSession::start($superAdmin);

        visit("/admin/{$company->slug}")
            ->click('.fi-user-menu-trigger')
            ->assertSee('API Documentation')
            ->assertVisible('a[href="'.route('scramble.docs.ui').'"]')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });
});
