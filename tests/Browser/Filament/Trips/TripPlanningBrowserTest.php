<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;
use Tests\Support\WithDriverTrips;

pest()->use(WithDriverTrips::class);

beforeEach(function (): void {
    config(['app.locale' => 'en']);
    app()->setLocale('en');
});

test('Mostrar viajes de hoy y consultar otra fecha', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-27T03:00:00Z'));
    $company = createCompany(['timezone' => 'America/Costa_Rica']);
    $today = Trip::factory()->for($company)->withEligibleDriver()->create();
    $tomorrow = Trip::factory()->for($company)->withEligibleDriver()->create(['service_date' => '2026-10-27']);
    BrowserSession::start(createUserWithRole(UserRole::SuperAdmin));

    $dateSelector = 'select[id$=".service_date.period"]';
    $fromDate = 'input[id$=".service_date.from_date"]';
    $untilDate = 'input[id$=".service_date.until_date"]';
    $page = visit(parse_url(TripResource::getUrl('index', tenant: $company), PHP_URL_PATH))
        ->assertSee($today->bus->unit_number)
        ->assertDontSee($tomorrow->bus->unit_number)
        ->assertSee('06:30 AM → 07:30 AM')
        ->assertNotPresent('td.fi-ta-cell-scheduled-arrival-at')
        ->click(Selector::getByRoleSelector('button', ['name' => 'Filter', 'exact' => true]))
        ->assertValue($dateSelector, 'today')
        ->assertNotPresent($fromDate)
        ->assertNotPresent($untilDate)
        ->select($dateSelector, 'custom')
        ->assertVisible($fromDate)
        ->assertVisible($untilDate)
        ->fill($fromDate, '2026-10-27')
        ->click(Selector::getByRoleSelector('button', ['name' => 'Apply filters', 'exact' => true]))
        ->assertSee($tomorrow->bus->unit_number)
        ->assertDontSee($today->bus->unit_number)
        ->assertSee('Tab counts show trips for today')
        ->assertSeeIn(Selector::getByRoleSelector('tab', ['name' => 'All']), '1');

    expect($page->script('document.querySelectorAll("tbody td.fi-ta-cell-service-date").length'))->toBe(1);

    foreach ([375, 768, 1280] as $width) {
        $page->resize($width, 900)
            ->assertVisible('td.fi-ta-cell-service-date')
            ->assertVisible(Selector::getByRoleSelector('button', ['name' => 'Filter', 'exact' => true]));
    }

    $page->assertNoJavaScriptErrors();
});

test('Iniciar y completar un viaje', function (): void {
    [$actor, $trip] = $this->createAssignedDriverTrip();
    grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $trip->company);
    BrowserSession::start($actor);

    $start = Selector::getByRoleSelector('button', ['name' => 'Start Trip', 'exact' => true]);
    $complete = Selector::getByRoleSelector('button', ['name' => 'Complete Trip', 'exact' => true]);
    $confirm = Selector::getByRoleSelector('button', ['name' => 'Confirm', 'exact' => true]);
    $moreActions = Selector::getByRoleSelector('button', ['name' => 'More Actions', 'exact' => true]);
    $index = parse_url(TripResource::getUrl('index', tenant: $trip->company), PHP_URL_PATH);

    $page = visit($index)
        ->assertVisible($start)
        ->assertNotPresent($complete)
        ->click($moreActions)
        ->assertVisible(Selector::getByRoleSelector('link', ['name' => 'Replan', 'exact' => true]))
        ->click($moreActions)
        ->click($start)
        ->click($confirm)
        ->assertVisible($complete)
        ->assertSeeIn('td.fi-ta-cell-status', 'In progress')
        ->assertSeeIn(Selector::getByRoleSelector('tab', ['name' => 'In progress']), '1');

    $this->travelTo($trip->scheduled_arrival_at);
    $page->click($complete)
        ->click($confirm)
        ->assertSeeIn('td.fi-ta-cell-status', 'Completed')
        ->assertNotPresent($start)
        ->assertNotPresent($complete)
        ->assertSeeIn(Selector::getByRoleSelector('tab', ['name' => 'Completed']), '1')
        ->click($moreActions)
        ->click(Selector::getByRoleSelector('link', ['name' => 'View Trip History', 'exact' => true]))
        ->assertSee('Actual Arrival')
        ->assertNoJavaScriptErrors();

    $trip->refresh();
    expect($trip->status)->toBe(TripStatus::COMPLETED)
        ->and($trip->actual_departure_at->equalTo($trip->scheduled_departure_at))->toBeTrue()
        ->and($trip->actual_arrival_at->equalTo($trip->scheduled_arrival_at))->toBeTrue();
});

test('Abrir edición o historial según el estado', function (): void {
    [$actor, $scheduled] = $this->createAssignedDriverTrip();
    $company = $scheduled->company;
    grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $company);
    $completed = Trip::factory()->for($company)->withEligibleDriver()->completed()->create();
    BrowserSession::start($actor);

    $index = parse_url(TripResource::getUrl('index', tenant: $company), PHP_URL_PATH);
    $edit = parse_url(TripResource::getUrl('edit', ['record' => $scheduled], tenant: $company), PHP_URL_PATH);
    $history = parse_url(TripResource::getUrl('view', ['record' => $completed], tenant: $company), PHP_URL_PATH);

    visit($index)
        ->click(Selector::getByRoleSelector('link', ['name' => $scheduled->bus->unit_number]))
        ->assertPathIs($edit)
        ->navigate($index)
        ->click(Selector::getByRoleSelector('link', ['name' => $completed->bus->unit_number]))
        ->assertPathIs($history)
        ->assertSee('Actual Arrival')
        ->assertNoJavaScriptErrors();
});
