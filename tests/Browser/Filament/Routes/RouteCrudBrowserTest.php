<?php

use App\Enums\UserRole;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RoutePatternResource;
use App\Filament\Resources\Routes\RouteResource;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\RouteSchedule;
use App\Models\Stop;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('Route CRUD Browser Management', function () {
    beforeEach(function () {
        $this->company = createCompany(['country_code' => 'CR', 'timezone' => 'America/Costa_Rica']);
        $this->actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->actor->id,
        ]);
        grantShield($this->actor, [
            'ViewAny:Route', 'View:Route', 'Create:Route', 'Update:Route', 'Delete:Route', 'Restore:Route',
            'ViewAny:RoutePattern', 'View:RoutePattern', 'Create:RoutePattern', 'Update:RoutePattern', 'Delete:RoutePattern', 'Restore:RoutePattern',
            'ViewAny:Stop', 'View:Stop', 'Create:Stop', 'Update:Stop', 'Delete:Stop', 'Restore:Stop',
        ], $this->company);
    });

    test('CP-0043: Renders routes list with monospace route code and full name', function () {
        Route::factory()->for($this->company)->create([
            'code' => 'R-100',
            'name' => 'San Jose - Alajuela Directo',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(RouteResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('R-100')
            ->assertSee('San Jose - Alajuela Directo')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0044: Filters routes by code or name in real time', function () {
        Route::factory()->for($this->company)->create([
            'code' => 'R-ALPHA',
            'name' => 'Ruta Caribe Express',
        ]);
        Route::factory()->for($this->company)->create([
            'code' => 'R-BETA',
            'name' => 'Ruta Pacifico Sur',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(RouteResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('R-ALPHA')
            ->assertSee('R-BETA')
            ->type('.fi-ta-search-field input', 'Caribe Express')
            ->assertSee('R-ALPHA')
            ->assertDontSee('R-BETA')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0045: Rejects duplicate route code within the company', function () {
        Route::factory()->for($this->company)->create([
            'code' => 'R-DUP-1',
            'name' => 'Existing Route',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(RouteResource::getUrl('create', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->type('input[id$=".code"]', 'R-DUP-1')
            ->type('input[id$=".name"]', 'New Duplicate Route')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertSee('has already been taken')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0046: Archives route from ActionGroup menu', function () {
        Route::factory()->for($this->company)->create([
            'code' => 'R-ARCHIVE',
            'name' => 'Route To Archive',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(RouteResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('Route To Archive')
            ->click(Selector::getByRoleSelector('button', ['name' => 'More Actions', 'exact' => true]))
            ->click(Selector::getByRoleSelector('button', ['name' => 'Archive', 'exact' => true]))
            ->assertSee('Archive Route')
            ->click('[aria-modal="true"] button:has-text("Archive")')
            ->assertSee('Route Archived')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0047: Renders fares relation manager with amount and currency', function () {
        $route = Route::factory()->for($this->company)->create(['name' => 'Route With Fares']);
        RouteFare::create([
            'route_id' => $route->id,
            'amount' => 650.00,
            'currency' => 'CRC',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(RouteResource::getUrl('edit', ['record' => $route], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('[role="tab"]:has-text("Fares")')
            ->assertSee('650.00')
            ->assertSee('CRC')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0048: Rejects fare creation with end date earlier than start date', function () {
        $route = Route::factory()->for($this->company)->create(['name' => 'Route Fare Test']);

        BrowserSession::start($this->actor);

        $path = parse_url(RouteResource::getUrl('edit', ['record' => $route], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('[role="tab"]:has-text("Fares")')
            ->click('button:has-text("Add Fare")')
            ->type('input[id$=".amount"]', '500')
            ->select('select[id$=".currency"]', 'CRC')
            ->type('input[id$=".valid_from"]', '2026-11-20')
            ->type('input[id$=".valid_until"]', '2026-11-10')
            ->click('[aria-modal="true"] button:has-text("Add Fare")')
            ->assertVisible('input[id$=".valid_until"]:invalid, [data-validation-error], .fi-fo-field-wrp-error-message')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0049: Rejects overlapping fare validity for the same currency', function () {
        $route = Route::factory()->for($this->company)->create(['name' => 'Route Overlap Test']);
        RouteFare::create([
            'route_id' => $route->id,
            'amount' => 500.00,
            'currency' => 'CRC',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(RouteResource::getUrl('edit', ['record' => $route], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('[role="tab"]:has-text("Fares")')
            ->click('button:has-text("Add Fare")')
            ->type('input[id$=".amount"]', '600')
            ->select('select[id$=".currency"]', 'CRC')
            ->click('[aria-modal="true"] button:has-text("Add Fare")')
            ->assertVisible('[data-validation-error], .fi-fo-field-wrp-error-message')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0050: Renders route patterns tab with code, direction and headsign', function () {
        $route = Route::factory()->for($this->company)->create(['name' => 'Route Pattern Test']);
        RoutePattern::factory()->for($route)->create([
            'code' => 'PAT-01',
            'name' => 'Outbound Pattern',
            'headsign' => 'Terminal Alajuela',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(RouteResource::getUrl('edit', ['record' => $route], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('[role="tab"]:has-text("Patterns")')
            ->assertSee('PAT-01')
            ->assertSee('Outbound Pattern')
            ->assertSee('Terminal Alajuela')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0058: Displays ordered stop sequence from lowest to highest', function () {
        $route = Route::factory()->for($this->company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stopA = Stop::factory()->for($this->company)->create(['name' => 'First Stop Sequence']);
        $stopB = Stop::factory()->for($this->company)->create(['name' => 'Second Stop Sequence']);

        RoutePatternStop::create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stopA->id,
            'stop_sequence' => 1,
        ]);
        RoutePatternStop::create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stopB->id,
            'stop_sequence' => 2,
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(RoutePatternResource::getUrl('edit', ['route' => $route, 'record' => $pattern], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('[role="tab"]:has-text("Stops")')
            ->assertSee('First Stop Sequence')
            ->assertSee('Second Stop Sequence')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0059: Allows duplicate stop as origin and destination in circular route', function () {
        $route = Route::factory()->for($this->company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stopCircular = Stop::factory()->for($this->company)->create(['name' => 'Circular Hub Stop']);

        RoutePatternStop::create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stopCircular->id,
            'stop_sequence' => 1,
        ]);
        RoutePatternStop::create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stopCircular->id,
            'stop_sequence' => 3,
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(RoutePatternResource::getUrl('edit', ['route' => $route, 'record' => $pattern], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('[role="tab"]:has-text("Stops")')
            ->assertSee('Circular Hub Stop')
            ->assertSee('1')
            ->assertSee('3')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0060: Updates estimated minutes from start on stop sequence', function () {
        $route = Route::factory()->for($this->company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($this->company)->create(['name' => 'Time Update Stop']);

        RoutePatternStop::create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stop->id,
            'stop_sequence' => 1,
            'minutes_from_start' => null,
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(RoutePatternResource::getUrl('edit', ['route' => $route, 'record' => $pattern], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('[role="tab"]:has-text("Stops")')
            ->click('button:has-text("Edit Stop")')
            ->type('input[id$=".minutes_from_start"]', '15')
            ->click('[aria-modal="true"] button:has-text("Save")')
            ->assertSee('15 min')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0061: Renders departure schedule relation manager by day of week', function () {
        $route = Route::factory()->for($this->company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        RouteSchedule::create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 1,
            'departure_time' => '07:30:00',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(RoutePatternResource::getUrl('edit', ['route' => $route, 'record' => $pattern], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('[role="tab"]:has-text("Schedules")')
            ->assertSee('Monday')
            ->assertSee('07:30')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0062: Validates departure time format in schedule modal', function () {
        $route = Route::factory()->for($this->company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();

        BrowserSession::start($this->actor);

        $path = parse_url(RoutePatternResource::getUrl('edit', ['route' => $route, 'record' => $pattern], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('[role="tab"]:has-text("Schedules")')
            ->click('button:has-text("Add Departure")')
            ->select('select[id$=".day_of_week"]', '1')
            ->click('[aria-modal="true"] button:has-text("Add Departure")')
            ->assertVisible('input[id$=".departure_time"]:invalid, [data-validation-error], .fi-fo-field-wrp-error-message')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0063: Registers calendar suspension exception for specific date', function () {
        $route = Route::factory()->for($this->company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        RouteSchedule::create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 1,
            'departure_time' => '08:00:00',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(RoutePatternResource::getUrl('edit', ['route' => $route, 'record' => $pattern], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('[role="tab"]:has-text("Schedules")')
            ->click(Selector::getByRoleSelector('button', ['name' => 'More Actions', 'exact' => true]))
            ->click(Selector::getByRoleSelector('button', ['name' => 'Suspend Departure', 'exact' => true]))
            ->type('input[id$=".service_date"]', '2026-11-23')
            ->click('[aria-modal="true"] button:has-text("Suspend Departure")')
            ->assertSee('Departure Suspended')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0064: Removes planned schedule with confirmation modal', function () {
        $route = Route::factory()->for($this->company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        RouteSchedule::create([
            'route_pattern_id' => $pattern->id,
            'day_of_week' => 2,
            'departure_time' => '09:00:00',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(RoutePatternResource::getUrl('edit', ['route' => $route, 'record' => $pattern], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('[role="tab"]:has-text("Schedules")')
            ->click(Selector::getByRoleSelector('button', ['name' => 'More Actions', 'exact' => true]))
            ->click(Selector::getByRoleSelector('button', ['name' => 'Remove Departure', 'exact' => true]))
            ->assertSee('Remove Departure')
            ->click('[aria-modal="true"] button:has-text("Remove Departure")')
            ->assertSee('Departure Removed')
            ->assertNoJavaScriptErrors();
    });
});
