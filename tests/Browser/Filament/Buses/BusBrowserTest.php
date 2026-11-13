<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Buses\BusResource;
use App\Models\Bus;
use App\Models\CompanyUser;
use App\Models\Trip;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('Bus Fleet Browser Management', function (): void {
    beforeEach(function (): void {
        config(['app.locale' => 'en']);
        app()->setLocale('en');

        $this->company = createCompany(['country_code' => 'CR', 'timezone' => 'America/Costa_Rica']);
        $this->actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->actor->id,
        ]);
        grantShield($this->actor, [
            'ViewAny:Bus', 'View:Bus', 'Create:Bus', 'Update:Bus', 'Delete:Bus', 'Restore:Bus',
        ], $this->company);
    });

    test('CP-0065: Renders company fleet list with unit, plate, vehicle and capacity columns', function (): void {
        $bus = Bus::factory()->for($this->company)->create([
            'unit_number' => 'U-101',
            'plate_number' => 'ABC-123',
            'country_code' => 'CR',
            'brand' => 'Mercedes-Benz',
            'model' => 'Sprinter',
            'capacity' => 24,
            'is_active' => true,
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(BusResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('U-101')
            ->assertSee('CR - ABC-123')
            ->assertSee('Mercedes-Benz Sprinter')
            ->assertSee('24')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0066: Filters fleet in real time by unit number and plate number', function (): void {
        $busA = Bus::factory()->for($this->company)->create([
            'unit_number' => 'ALPHA-99',
            'plate_number' => 'AAA-111',
            'country_code' => 'CR',
        ]);
        $busB = Bus::factory()->for($this->company)->create([
            'unit_number' => 'BETA-88',
            'plate_number' => 'BBB-222',
            'country_code' => 'CR',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(BusResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('ALPHA-99')
            ->assertSee('BETA-88')
            ->type('.fi-ta-search-field input', 'ALPHA-99')
            ->assertSee('ALPHA-99')
            ->assertDontSee('BETA-88')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0067: Toggles between active and archived fleet units using TrashedFilter', function (): void {
        $activeBus = Bus::factory()->for($this->company)->create(['unit_number' => 'ACTIVE-01']);
        $archivedBus = Bus::factory()->for($this->company)->create(['unit_number' => 'ARCHIVED-02']);
        $archivedBus->delete();

        BrowserSession::start($this->actor);

        $path = parse_url(BusResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('ACTIVE-01')
            ->assertDontSee('ARCHIVED-02')
            ->click('button[aria-label="Filter"]')
            ->select('select[id$="trashed.value"]', '0')
            ->click('button:has-text("Apply filters")')
            ->click('button[aria-label="Filter"]')
            ->assertSee('ARCHIVED-02')
            ->assertDontSee('ACTIVE-01')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0068: Creates a company bus with complete vehicle attributes', function (): void {
        BrowserSession::start($this->actor);

        $path = parse_url(BusResource::getUrl('create', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('button[id$="country_code"]')
            ->assertVisible('[role="option"][data-value="CR"]')
            ->click('[role="option"][data-value="CR"]')
            ->type('input[id$="plate_number"]', 'PLT-777')
            ->type('input[id$="unit_number"]', 'UNIT-777')
            ->type('input[id$="brand"]', 'Volvo')
            ->type('input[id$="model"]', '9700')
            ->type('input[id$="year"]', '2025')
            ->type('input[id$="capacity"]', '45')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertSee('Created')
            ->assertNoJavaScriptErrors();

        $this->assertDatabaseHas(Bus::class, [
            'company_id' => $this->company->id,
            'unit_number' => 'UNIT-777',
            'plate_number' => 'PLT-777',
            'country_code' => 'CR',
            'brand' => 'Volvo',
            'model' => '9700',
            'year' => 2025,
            'capacity' => 45,
            'is_active' => true,
        ]);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0069: Rejects duplicate unit number within the same company', function (): void {
        Bus::factory()->for($this->company)->create([
            'unit_number' => 'DUP-UNIT',
            'country_code' => 'CR',
            'plate_number' => 'PLT-111',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(BusResource::getUrl('create', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('button[id$="country_code"]')
            ->assertVisible('[role="option"][data-value="CR"]')
            ->click('[role="option"][data-value="CR"]')
            ->type('input[id$="plate_number"]', 'PLT-999')
            ->type('input[id$="unit_number"]', 'DUP-UNIT')
            ->type('input[id$="capacity"]', '40')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertDontSee('Created')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0070: Rejects duplicate plate number for the same country', function (): void {
        Bus::factory()->for($this->company)->create([
            'unit_number' => 'ORIG-UNIT',
            'country_code' => 'CR',
            'plate_number' => 'SAME-PLATE',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(BusResource::getUrl('create', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('button[id$="country_code"]')
            ->assertVisible('[role="option"][data-value="CR"]')
            ->click('[role="option"][data-value="CR"]')
            ->type('input[id$="plate_number"]', 'SAME-PLATE')
            ->type('input[id$="unit_number"]', 'NEW-UNIT')
            ->type('input[id$="capacity"]', '40')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertDontSee('Created')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0071: Rejects bus creation with missing required fields', function (): void {
        BrowserSession::start($this->actor);

        $path = parse_url(BusResource::getUrl('create', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertDontSee('Created')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0072: Updates bus vehicle details without altering company ownership', function (): void {
        $bus = Bus::factory()->for($this->company)->create([
            'brand' => 'OldBrand',
            'capacity' => 30,
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(BusResource::getUrl('edit', ['record' => $bus], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertValue('input[id$="brand"]', 'OldBrand')
            ->type('input[id$="brand"]', 'Scania')
            ->type('input[id$="capacity"]', '52')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Save changes', 'exact' => true]))
            ->assertSee('Saved')
            ->assertNoJavaScriptErrors();

        expect($bus->fresh())
            ->brand->toBe('Scania')
            ->capacity->toBe(52)
            ->company_id->toBe($this->company->id);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0073: Deactivates an active bus with confirmation modal', function (): void {
        $bus = Bus::factory()->for($this->company)->active()->create([
            'unit_number' => 'DEACT-01',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(BusResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('DEACT-01')
            ->click('button:has-text("Deactivate")')
            ->assertSee('Are you sure')
            ->click('[aria-modal="true"] button:has-text("Confirm")')
            ->assertSee('Bus Deactivated')
            ->assertNoJavaScriptErrors();

        expect($bus->fresh()->is_active)->toBeFalse();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0074: Reactivates an inactive bus with confirmation modal', function (): void {
        $bus = Bus::factory()->for($this->company)->inactive()->create([
            'unit_number' => 'ACT-01',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(BusResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('ACT-01')
            ->click('button:has-text("Activate")')
            ->assertSee('Are you sure')
            ->click('[aria-modal="true"] button:has-text("Confirm")')
            ->assertSee('Bus Activated')
            ->assertNoJavaScriptErrors();

        expect($bus->fresh()->is_active)->toBeTrue();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0075: Blocks deactivation of bus assigned to committed trip', function (): void {
        $bus = Bus::factory()->for($this->company)->active()->create([
            'unit_number' => 'COMMITTED-BUS',
        ]);
        Trip::factory()->for($this->company)->create([
            'bus_id' => $bus->id,
            'status' => TripStatus::SCHEDULED,
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(BusResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('COMMITTED-BUS')
            ->click('button:has-text("Deactivate")')
            ->click('[aria-modal="true"] button:has-text("Confirm")')
            ->assertSee('Fleet Change Rejected')
            ->assertNoJavaScriptErrors();

        expect($bus->fresh()->is_active)->toBeTrue();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0076: Archives bus without physical database deletion', function (): void {
        $bus = Bus::factory()->for($this->company)->create([
            'unit_number' => 'ARCHIVE-ME',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(BusResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('ARCHIVE-ME')
            ->click('button:has-text("Archive")')
            ->assertSee('Archive Bus')
            ->click('[aria-modal="true"] button:has-text("Archive")')
            ->assertSee('Bus Archived')
            ->assertNoJavaScriptErrors();

        $this->assertSoftDeleted($bus);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0077: Blocks archival of bus assigned to in-progress trip', function (): void {
        $bus = Bus::factory()->for($this->company)->create([
            'unit_number' => 'INPROGRESS-BUS',
        ]);
        Trip::factory()->for($this->company)->inProgress()->create([
            'bus_id' => $bus->id,
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(BusResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('INPROGRESS-BUS')
            ->click('button:has-text("Archive")')
            ->click('[aria-modal="true"] button:has-text("Archive")')
            ->assertSee('Fleet Change Rejected')
            ->assertNoJavaScriptErrors();

        $this->assertNotSoftDeleted($bus);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0078: Restores an archived bus back to active fleet', function (): void {
        $bus = Bus::factory()->for($this->company)->create([
            'unit_number' => 'RESTORE-ME',
        ]);
        $bus->delete();

        BrowserSession::start($this->actor);

        $path = parse_url(BusResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('button[aria-label="Filter"]')
            ->select('select[id$="trashed.value"]', '0')
            ->click('button:has-text("Apply filters")')
            ->click('button[aria-label="Filter"]')
            ->assertSee('RESTORE-ME')
            ->click('button:has-text("Restore")')
            ->assertSee('Restore Bus')
            ->click('[aria-modal="true"] button:has-text("Restore")')
            ->assertSee('Bus Restored')
            ->assertNoJavaScriptErrors();

        $this->assertNotSoftDeleted($bus);

        BrowserSession::assertTokenWasValidated();
    });
});
