<?php

use App\Enums\TripStatus;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\Trip;
use App\Services\TripManagementService;
use Carbon\CarbonImmutable;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;
use Tests\Support\WithDriverTrips;

pest()->use(WithDriverTrips::class);

describe('Trip Operations Browser Management', function (): void {
    beforeEach(function (): void {
        config(['app.locale' => 'en']);
        app()->setLocale('en');
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
    });

    test('CP-0095: Renders trips list with service date, itinerary, schedule, bus, driver and status', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $trip->company);
        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('index', tenant: $trip->company), PHP_URL_PATH);

        visit($path)
            ->assertSee($trip->service_date->format('d M, Y'))
            ->assertSee($trip->planned_route_snapshot['pattern_code'])
            ->assertSee('06:30 AM → 07:30 AM')
            ->assertSee($trip->bus->unit_number)
            ->assertSee($trip->driver->user->name)
            ->assertSee('Scheduled')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0096: Sorts trips chronologically by scheduled departure time', function (): void {
        [$actor, $tripA] = $this->createAssignedDriverTrip('scheduled', '06:00:00', '2026-10-26T07:00:00-06:00');
        $company = $tripA->company;
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $company);

        $fixtureB = Trip::factory()->for($company)->withDriverAvailability()->make();
        $fixtureB->schedule->update(['departure_time' => '14:00:00']);
        $tripB = app(TripManagementService::class)->schedule($actor, $company, [
            ...$fixtureB->only(['route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id']),
            'service_date' => '2026-10-26',
            'arrival_at' => '2026-10-26T15:00:00-06:00',
        ]);

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('index', tenant: $company), PHP_URL_PATH);

        $page = visit($path);
        $page->assertNoJavaScriptErrors();
        $text = $page->script('document.body.innerText');
        expect(strpos($text, $tripA->bus->unit_number))->toBeLessThan(strpos($text, $tripB->bus->unit_number));

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0097: Filters trips by service date range', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
        [$actor, $todayTrip] = $this->createAssignedDriverTrip('scheduled', '06:30:00', '2026-10-26T07:30:00-06:00');
        $company = $todayTrip->company;
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $company);

        $tomorrowTrip = Trip::factory()->for($company)->withEligibleDriver()->create([
            'service_date' => '2026-10-27',
        ]);

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('index', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->assertSee($todayTrip->bus->unit_number)
            ->assertDontSee($tomorrowTrip->bus->unit_number)
            ->click(Selector::getByRoleSelector('button', ['name' => 'Filter', 'exact' => true]))
            ->select('select[id$=".service_date.period"]', 'custom')
            ->fill('input[id$=".service_date.from_date"]', '2026-10-27')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Apply filters', 'exact' => true]))
            ->assertSee($tomorrowTrip->bus->unit_number)
            ->assertDontSee($todayTrip->bus->unit_number)
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0098: Filters trips by operational status', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        $company = $trip->company;
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $company);

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('index', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->assertSee($trip->bus->unit_number)
            ->click(Selector::getByRoleSelector('tab', ['name' => 'Completed']))
            ->assertDontSee($trip->bus->unit_number)
            ->click(Selector::getByRoleSelector('tab', ['name' => 'Scheduled']))
            ->assertSee($trip->bus->unit_number)
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0099: Filters trips by assigned driver', function (): void {
        [$actor, $tripA] = $this->createAssignedDriverTrip();
        $company = $tripA->company;
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $company);

        $tripB = Trip::factory()->for($company)->withEligibleDriver()->create([
            'service_date' => '2026-10-26',
        ]);

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('index', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->assertSee($tripA->bus->unit_number)
            ->assertSee($tripB->bus->unit_number)
            ->click(Selector::getByRoleSelector('button', ['name' => 'Filter', 'exact' => true]))
            ->select('select[id$="driver_id.value"]', (string) $tripA->driver_id)
            ->click(Selector::getByRoleSelector('button', ['name' => 'Apply filters', 'exact' => true]))
            ->click(Selector::getByRoleSelector('button', ['name' => 'Filter', 'exact' => true]))
            ->assertSee($tripA->bus->unit_number)
            ->assertDontSee($tripB->bus->unit_number)
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0100: Filters trips by assigned bus unit', function (): void {
        [$actor, $tripA] = $this->createAssignedDriverTrip();
        $company = $tripA->company;
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $company);

        $tripB = Trip::factory()->for($company)->withEligibleDriver()->create([
            'service_date' => '2026-10-26',
        ]);

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('index', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->assertSee($tripA->bus->unit_number)
            ->assertSee($tripB->bus->unit_number)
            ->click(Selector::getByRoleSelector('button', ['name' => 'Filter', 'exact' => true]))
            ->select('select[id$="bus_id.value"]', (string) $tripA->bus_id)
            ->click(Selector::getByRoleSelector('button', ['name' => 'Apply filters', 'exact' => true]))
            ->click(Selector::getByRoleSelector('button', ['name' => 'Filter', 'exact' => true]))
            ->assertSee($tripA->bus->unit_number)
            ->assertDontSee($tripB->bus->unit_number)
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0101: Combines multiple filters for driver, status and service date', function (): void {
        [$actor, $tripA] = $this->createAssignedDriverTrip();
        $company = $tripA->company;
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $company);

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('index', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->click(Selector::getByRoleSelector('button', ['name' => 'Filter', 'exact' => true]))
            ->select('select[id$="driver_id.value"]', (string) $tripA->driver_id)
            ->click(Selector::getByRoleSelector('button', ['name' => 'Apply filters', 'exact' => true]))
            ->click(Selector::getByRoleSelector('button', ['name' => 'Filter', 'exact' => true]))
            ->click(Selector::getByRoleSelector('tab', ['name' => 'Scheduled']))
            ->assertSee($tripA->bus->unit_number)
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0102: Schedules a new trip with frozen route snapshot', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = $this->createWorkforceAdministrator($company);
        grantShield($actor, ['ViewAny:Trip', 'View:Trip', 'Create:Trip'], $company);

        $fixture = Trip::factory()->for($company)->withDriverAvailability()->make();
        $fixture->driver->update(['employment_starts_on' => '2026-10-01']);
        $pattern = RoutePattern::findOrFail($fixture->route_pattern_id);
        $schedule = RouteSchedule::findOrFail($fixture->route_schedule_id);
        $bus = Bus::findOrFail($fixture->bus_id);
        $driver = Driver::findOrFail($fixture->driver_id);

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('create', tenant: $company), PHP_URL_PATH);

        $page = visit($path)
            ->click('button[id$="route_pattern_id"]')
            ->click('[role="option"]:has-text("'.$pattern->code.'")')
            ->click('button[id$="route_schedule_id"]')
            ->click('[role="option"]:has-text("'.substr($schedule->departure_time, 0, 5).'")')
            ->fill('input[id$="service_date"]', '2026-10-26');

        $page->script("document.querySelector('input[id$=\"arrival_at\"]').value = '2026-10-26T15:00:00'; document.querySelector('input[id$=\"arrival_at\"]').dispatchEvent(new Event('input', { bubbles: true })); document.querySelector('input[id$=\"arrival_at\"]').dispatchEvent(new Event('change', { bubbles: true }));");

        $page->click('button[id$="bus_id"]')
            ->click('[role="option"]:has-text("'.$bus->unit_number.'")')
            ->click('button[id$="driver_id"]')
            ->click('[role="option"]:has-text("'.$driver->license.'")')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertSee('Created')
            ->assertNoJavaScriptErrors();

        $this->assertDatabaseHas(Trip::class, [
            'company_id' => $company->id,
            'service_date' => '2026-10-26',
        ]);

        $created = Trip::where('company_id', $company->id)->first();
        expect($created->planned_route_snapshot)->not->toBeNull();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0103: Rejects scheduling trip with inactive bus unit', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = $this->createWorkforceAdministrator($company);
        grantShield($actor, ['ViewAny:Trip', 'View:Trip', 'Create:Trip'], $company);

        $inactiveBus = Bus::factory()->for($company)->inactive()->create();

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('create', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->click('button[id$="bus_id"]')
            ->assertDontSee($inactiveBus->unit_number)
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0104: Rejects scheduling trip with driver holding expired license', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = $this->createWorkforceAdministrator($company);
        grantShield($actor, ['ViewAny:Trip', 'View:Trip', 'Create:Trip'], $company);

        $expiredDriver = Driver::factory()->for($company)->active()->create([
            'license_expires_at' => '2025-01-01',
        ]);

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('create', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->click('button[id$="driver_id"]')
            ->assertDontSee($expiredDriver->license)
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0105: Rejects scheduling trip with bus already committed to overlapping trip', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        $company = $trip->company;
        grantShield($actor, ['ViewAny:Trip', 'View:Trip', 'Create:Trip'], $company);

        $otherDriver = Driver::factory()->for($company)->active()->create(['employment_starts_on' => '2026-10-01']);
        $pattern = RoutePattern::findOrFail($trip->route_pattern_id);
        $schedule = RouteSchedule::findOrFail($trip->route_schedule_id);

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('create', tenant: $company), PHP_URL_PATH);

        $page = visit($path)
            ->click('button[id$="route_pattern_id"]')
            ->click('[role="option"]:has-text("'.$pattern->code.'")')
            ->click('button[id$="route_schedule_id"]')
            ->click('[role="option"]:has-text("'.substr($schedule->departure_time, 0, 5).'")')
            ->fill('input[id$="service_date"]', '2026-10-26');

        $page->script("document.querySelector('input[id$=\"arrival_at\"]').value = '2026-10-26T15:00:00'; document.querySelector('input[id$=\"arrival_at\"]').dispatchEvent(new Event('input', { bubbles: true })); document.querySelector('input[id$=\"arrival_at\"]').dispatchEvent(new Event('change', { bubbles: true }));");

        $page->click('button[id$="bus_id"]')
            ->click('[role="option"]:has-text("'.$trip->bus->unit_number.'")')
            ->click('button[id$="driver_id"]')
            ->click('[role="option"]:has-text("'.$otherDriver->license.'")')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertDontSee('Created')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0106: Rejects scheduling trip with driver already committed to overlapping trip', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        $company = $trip->company;
        grantShield($actor, ['ViewAny:Trip', 'View:Trip', 'Create:Trip'], $company);

        $otherBus = Bus::factory()->for($company)->active()->create();
        $pattern = RoutePattern::findOrFail($trip->route_pattern_id);
        $schedule = RouteSchedule::findOrFail($trip->route_schedule_id);

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('create', tenant: $company), PHP_URL_PATH);

        $page = visit($path)
            ->click('button[id$="route_pattern_id"]')
            ->click('[role="option"]:has-text("'.$pattern->code.'")')
            ->click('button[id$="route_schedule_id"]')
            ->click('[role="option"]:has-text("'.substr($schedule->departure_time, 0, 5).'")')
            ->fill('input[id$="service_date"]', '2026-10-26');

        $page->script("document.querySelector('input[id$=\"arrival_at\"]').value = '2026-10-26T15:00:00'; document.querySelector('input[id$=\"arrival_at\"]').dispatchEvent(new Event('input', { bubbles: true })); document.querySelector('input[id$=\"arrival_at\"]').dispatchEvent(new Event('change', { bubbles: true }));");

        $page->click('button[id$="bus_id"]')
            ->click('[role="option"]:has-text("'.$otherBus->unit_number.'")')
            ->click('button[id$="driver_id"]')
            ->click('[role="option"]:has-text("'.$trip->driver->license.'")')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertDontSee('Created')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0107: Replans scheduled trip by reassigning eligible bus and driver', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        $company = $trip->company;
        grantShield($actor, ['ViewAny:Trip', 'View:Trip', 'Update:Trip'], $company);

        $newBus = Bus::factory()->for($company)->active()->create();

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('edit', ['record' => $trip], tenant: $company), PHP_URL_PATH);

        $page = visit($path);

        $page->script("document.querySelector('input[id$=\"arrival_at\"]').value = '2026-10-26T15:00:00'; document.querySelector('input[id$=\"arrival_at\"]').dispatchEvent(new Event('input', { bubbles: true })); document.querySelector('input[id$=\"arrival_at\"]').dispatchEvent(new Event('change', { bubbles: true }));");

        $page->click('button[id$="bus_id"]')
            ->click('[role="option"]:has-text("'.$newBus->unit_number.'")')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Save changes', 'exact' => true]))
            ->assertSee('Saved')
            ->assertNoJavaScriptErrors();

        expect($trip->fresh()->bus_id)->toBe($newBus->id);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0108: Blocks replanning on in-progress, completed or cancelled trips', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip('completed');
        $company = $trip->company;
        grantShield($actor, ['ViewAny:Trip', 'View:Trip', 'Update:Trip'], $company);

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('index', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->click(Selector::getByRoleSelector('button', ['name' => 'More Actions', 'exact' => true]))
            ->assertDontSee('Replan')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0109: Starts scheduled trip recording actual departure time', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $trip->company);
        BrowserSession::start($actor);

        $startBtn = Selector::getByRoleSelector('button', ['name' => 'Start Trip', 'exact' => true]);
        $confirmBtn = Selector::getByRoleSelector('button', ['name' => 'Confirm', 'exact' => true]);
        $path = parse_url(TripResource::getUrl('index', tenant: $trip->company), PHP_URL_PATH);

        visit($path)
            ->assertVisible($startBtn)
            ->click($startBtn)
            ->click($confirmBtn)
            ->assertSee('In progress')
            ->assertNoJavaScriptErrors();

        expect($trip->fresh()->status)->toBe(TripStatus::IN_PROGRESS);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0110: Completes in-progress trip recording actual arrival time', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip('in_progress');
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $trip->company);
        BrowserSession::start($actor);

        $completeBtn = Selector::getByRoleSelector('button', ['name' => 'Complete Trip', 'exact' => true]);
        $confirmBtn = Selector::getByRoleSelector('button', ['name' => 'Confirm', 'exact' => true]);
        $path = parse_url(TripResource::getUrl('index', tenant: $trip->company), PHP_URL_PATH);

        visit($path)
            ->assertVisible($completeBtn)
            ->click($completeBtn)
            ->click($confirmBtn)
            ->assertSee('Completed')
            ->assertNoJavaScriptErrors();

        expect($trip->fresh()->status)->toBe(TripStatus::COMPLETED);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0111: Cancels scheduled trip requiring mandatory cancellation reason in modal', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $trip->company);
        BrowserSession::start($actor);

        $moreActions = Selector::getByRoleSelector('button', ['name' => 'More Actions', 'exact' => true]);
        $path = parse_url(TripResource::getUrl('index', tenant: $trip->company), PHP_URL_PATH);

        visit($path)
            ->click($moreActions)
            ->click(Selector::getByRoleSelector('button', ['name' => 'Cancel Trip', 'exact' => true]))
            ->assertSee('Cancel Trip')
            ->fill('textarea[id$="cancellation_reason"]', 'Mechanical emergency on vehicle.')
            ->click('[aria-modal="true"] button:has-text("Confirm")')
            ->assertSee('Trip Cancelled')
            ->assertNoJavaScriptErrors();

        expect($trip->fresh()->status)->toBe(TripStatus::CANCELLED);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0112: Rejects trip cancellation with empty reason in modal', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        grantShield($actor, ['ViewAny:Trip', 'View:Trip'], $trip->company);
        BrowserSession::start($actor);

        $moreActions = Selector::getByRoleSelector('button', ['name' => 'More Actions', 'exact' => true]);
        $path = parse_url(TripResource::getUrl('index', tenant: $trip->company), PHP_URL_PATH);

        visit($path)
            ->click($moreActions)
            ->click(Selector::getByRoleSelector('button', ['name' => 'Cancel Trip', 'exact' => true]))
            ->assertSee('Cancel Trip')
            ->click('[aria-modal="true"] button:has-text("Confirm")')
            ->assertDontSee('Trip Cancelled')
            ->assertNoJavaScriptErrors();

        expect($trip->fresh()->status)->toBe(TripStatus::SCHEDULED);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0113: Creates replacement trip prefilled from cancelled trip', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip('cancelled');
        grantShield($actor, ['ViewAny:Trip', 'View:Trip', 'Create:Trip'], $trip->company);
        BrowserSession::start($actor);

        $moreActions = Selector::getByRoleSelector('button', ['name' => 'More Actions', 'exact' => true]);
        $path = parse_url(TripResource::getUrl('index', tenant: $trip->company), PHP_URL_PATH);

        visit($path)
            ->click(Selector::getByRoleSelector('tab', ['name' => 'Cancelled']))
            ->click($moreActions)
            ->assertVisible(Selector::getByRoleSelector('link', ['name' => 'Create Replacement', 'exact' => true]))
            ->click(Selector::getByRoleSelector('link', ['name' => 'Create Replacement', 'exact' => true]))
            ->assertPathIs(parse_url(TripResource::getUrl('create', tenant: $trip->company), PHP_URL_PATH))
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0114: Renders action group options conditioned on trip lifecycle status', function (): void {
        [$actor, $trip] = $this->createAssignedDriverTrip();
        $company = $trip->company;
        grantShield($actor, ['ViewAny:Trip', 'View:Trip', 'Update:Trip'], $company);
        BrowserSession::start($actor);

        $moreActions = Selector::getByRoleSelector('button', ['name' => 'More Actions', 'exact' => true]);
        $path = parse_url(TripResource::getUrl('index', tenant: $company), PHP_URL_PATH);

        visit($path)
            ->click($moreActions)
            ->assertVisible(Selector::getByRoleSelector('link', ['name' => 'Replan', 'exact' => true]))
            ->assertVisible(Selector::getByRoleSelector('button', ['name' => 'Cancel Trip', 'exact' => true]))
            ->assertVisible(Selector::getByRoleSelector('link', ['name' => 'View Trip History', 'exact' => true]))
            ->assertDontSee('Create Replacement')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });
});
