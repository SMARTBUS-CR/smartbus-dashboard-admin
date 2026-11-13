<?php

use App\Enums\UserRole;
use App\Filament\Pages\Dashboard;
use App\Models\Trip;
use Tests\Support\BrowserSession;

test('Filtrar métricas por período', function (): void {
    config(['app.locale' => 'en']);
    app()->setLocale('en');
    $this->travelTo('2026-11-01 02:00:00 UTC');
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    foreach (['2026-10-01', '2026-10-28', '2026-10-31'] as $date) {
        Trip::factory()->for($company)->withEligibleDriver()->create(['service_date' => $date]);
    }
    BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

    $period = 'select[id$=".period"]';
    $from = 'input[id$=".from_date"]';
    $until = 'input[id$=".until_date"]';
    $scheduled = '.fi-wi-stats-overview-stat:has-text("Scheduled") .fi-wi-stats-overview-stat-value';

    $page = visit(Dashboard::getUrl(isAbsolute: false, tenant: $company))
        ->assertSee('Trip Operations')
        ->assertValue($period, 'today')
        ->assertNotPresent($from)
        ->assertNotPresent($until)
        ->assertSeeIn($scheduled, '1')
        ->select($period, 'last_7_days')
        ->assertSeeIn($scheduled, '2')
        ->select($period, 'custom')
        ->assertVisible($from)
        ->assertVisible($until)
        ->fill($from, '2026-10-01')
        ->fill($until, '2026-10-31')
        ->assertSeeIn($scheduled, '3');

    foreach ([375, 768, 1280] as $width) {
        $page->resize($width, 900)->assertVisible($period)->assertVisible($from)->assertVisible($scheduled);
    }

    $page->assertNoJavaScriptErrors();
});
