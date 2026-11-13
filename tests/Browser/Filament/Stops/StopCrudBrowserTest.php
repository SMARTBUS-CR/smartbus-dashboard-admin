<?php

use App\Enums\UserRole;
use App\Filament\Resources\Stops\StopResource;
use App\Models\CompanyUser;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('Stop CRUD Browser Management', function () {
    beforeEach(function () {
        $this->company = createCompany(['country_code' => 'CR', 'timezone' => 'America/Costa_Rica']);
        $this->actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->actor->id,
        ]);
        grantShield($this->actor, [
            'ViewAny:Stop', 'View:Stop', 'Create:Stop', 'Update:Stop', 'Delete:Stop', 'Restore:Stop',
        ], $this->company);
    });

    test('CP-0035: Renders stop list with coordinates and scope badge', function () {
        Stop::factory()->for($this->company)->create([
            'name' => 'Terminal San Jose',
            'latitude' => 9.9333,
            'longitude' => -84.0833,
        ]);
        Stop::factory()->create([
            'name' => 'Parada Parque Morazan',
            'company_id' => null,
            'latitude' => 9.9350,
            'longitude' => -84.0800,
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(StopResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('Terminal San Jose')
            ->assertSee('Company')
            ->assertSee('9.9333')
            ->assertSee('-84.0833')
            ->assertSee('Parada Parque Morazan')
            ->assertSee('Shared')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0036: Filters archived stops and shows restore action', function () {
        Stop::factory()->for($this->company)->create(['name' => 'Active Stop Alpha']);
        $archived = Stop::factory()->for($this->company)->create(['name' => 'Archived Stop Beta']);
        $archived->delete();

        BrowserSession::start($this->actor);

        $path = parse_url(StopResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('Active Stop Alpha')
            ->assertDontSee('Archived Stop Beta')
            ->click('button[aria-label="Filter"]')
            ->select('select[id$="trashed.value"]', '0')
            ->click('button:has-text("Apply filters")')
            ->click('button[aria-label="Filter"]')
            ->assertSee('Archived Stop Beta')
            ->assertDontSee('Active Stop Alpha')
            ->click(Selector::getByRoleSelector('button', ['name' => 'More Actions', 'exact' => true]))
            ->assertVisible(Selector::getByRoleSelector('button', ['name' => 'Restore', 'exact' => true]))
            ->assertNoJavaScriptErrors();
    });

    test('CP-0041: Blocks archiving of stop assigned to route pattern', function () {
        $stop = Stop::factory()->for($this->company)->create(['name' => 'In-Use Stop Omega']);
        $route = Route::factory()->for($this->company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        RoutePatternStop::create([
            'route_pattern_id' => $pattern->id,
            'stop_id' => $stop->id,
            'stop_sequence' => 1,
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(StopResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('In-Use Stop Omega')
            ->click(Selector::getByRoleSelector('button', ['name' => 'More Actions', 'exact' => true]))
            ->click(Selector::getByRoleSelector('button', ['name' => 'Archive', 'exact' => true]))
            ->assertSee('Archive Stop')
            ->click('[aria-modal="true"] button:has-text("Archive")')
            ->assertSee('Could Not Archive Stop')
            ->assertNoJavaScriptErrors();
    });

    test('CP-0042: Restores archived stop with confirmation modal', function () {
        $stop = Stop::factory()->for($this->company)->create(['name' => 'Archived To Restore']);
        $stop->delete();

        BrowserSession::start($this->actor);

        $path = parse_url(StopResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('button[aria-label="Filter"]')
            ->select('select[id$="trashed.value"]', '0')
            ->click('button:has-text("Apply filters")')
            ->click('button[aria-label="Filter"]')
            ->assertSee('Archived To Restore')
            ->click(Selector::getByRoleSelector('button', ['name' => 'More Actions', 'exact' => true]))
            ->click(Selector::getByRoleSelector('button', ['name' => 'Restore', 'exact' => true]))
            ->assertSee('Restore Stop')
            ->click('[aria-modal="true"] button:has-text("Restore")')
            ->assertSee('Stop Restored')
            ->assertNoJavaScriptErrors();
    });
});
