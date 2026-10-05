<?php

use App\Enums\UserRole;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('External Token Validation In The Browser', function (): void {
    beforeEach(function (): void {
        config(['app.locale' => 'en']);
        app()->setLocale('en');
    });

    test('redirects an opened form to login without saving when its token expires', function (): void {
        $company = createCompany();
        $originalName = $company->legal_name;
        $expired = false;

        BrowserSession::start(createUserWithRole(UserRole::SuperAdmin), [
            BrowserSession::VALIDATE_URL => function () use (&$expired) {
                return $expired
                    ? Http::response([], 401)
                    : Http::response(['meta' => ['valid' => true]], 200);
            },
            'https://gateway.test/auth/logout' => Http::response(['meta' => ['message' => 'Logged out']], 200),
        ]);

        $page = visit(parse_url(CompanyResource::getUrl('edit', ['record' => $company], tenant: $company), PHP_URL_PATH))
            ->assertValue('input[id$=".legal_name"]', $originalName)
            ->type('input[id$=".legal_name"]', 'Expired Session Company');

        $expired = true;

        $page->click(Selector::getByRoleSelector('button', ['name' => 'Save changes', 'exact' => true]))
            ->assertPathIs(parse_url(Filament::getPanel('admin')->getLoginUrl(), PHP_URL_PATH))
            ->assertSee('Sign in')
            ->assertNoJavaScriptErrors();

        expect($company->fresh()->legal_name)->toBe($originalName);
        BrowserSession::assertTokenWasValidated();
        Http::assertSent(fn ($request): bool => $request->url() === 'https://gateway.test/auth/logout');
    });
});
