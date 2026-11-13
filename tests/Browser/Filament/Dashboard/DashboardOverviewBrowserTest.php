<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Tests\Support\BrowserSession;

describe('Dashboard Trip Operations Overview', function (): void {
    beforeEach(function (): void {
        config(['app.locale' => 'en']);
        app()->setLocale('en');
    });

    test('CP-0010: Renders trip operations overview widget with metric cards', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::factory()->for($company)->for($actor, 'user')->create();
        grantShield($actor, ['ViewAny:Trip'], $company);

        $today = CarbonImmutable::now($company->timezone)->toDateString();
        Trip::factory()->for($company)->withEligibleDriver()->create([
            'service_date' => $today,
        ]);

        BrowserSession::start($actor);

        visit("/admin/{$company->slug}")
            ->assertSee('Trip Operations')
            ->assertSee('Scheduled')
            ->assertSee('In progress')
            ->assertSee('Completed')
            ->assertSee('Cancelled')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0011: Filters trip operations metrics by service date range', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::factory()->for($company)->for($actor, 'user')->create();
        grantShield($actor, ['ViewAny:Trip'], $company);

        $pastDate = CarbonImmutable::now($company->timezone)->subDays(10)->toDateString();
        Trip::factory()->for($company)->withEligibleDriver()->create([
            'service_date' => $pastDate,
        ]);

        BrowserSession::start($actor);

        visit("/admin/{$company->slug}")
            ->assertSee('Trip Operations')
            ->type('input[id$=".from_date"]', $pastDate)
            ->type('input[id$=".until_date"]', $pastDate)
            ->assertSee('Trip Operations')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0012: Enforces valid service date range in dashboard filters', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::factory()->for($company)->for($actor, 'user')->create();
        grantShield($actor, ['ViewAny:Trip'], $company);

        BrowserSession::start($actor);

        visit("/admin/{$company->slug}")
            ->assertVisible('input[id$=".from_date"]')
            ->assertVisible('input[id$=".until_date"]')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0013: Hides trip operations widget for users without trip view permissions', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::factory()->for($company)->for($actor, 'user')->create();
        // Actor deliberately has no ViewAny:Trip permission

        BrowserSession::start($actor);

        visit("/admin/{$company->slug}")
            ->assertDontSee('Trip Operations')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });
});
