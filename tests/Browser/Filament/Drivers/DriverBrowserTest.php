<?php

use App\Enums\DriverStatus;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Drivers\DriverResource;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Pest\Browser\Support\Selector;
use Tests\Support\BrowserSession;

describe('Driver Workforce Browser Management', function (): void {
    beforeEach(function (): void {
        config(['app.locale' => 'en']);
        app()->setLocale('en');

        $this->company = createCompany(['timezone' => 'America/Costa_Rica']);
        $this->actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->actor->id,
        ]);
        grantShield($this->actor, [
            'ViewAny:Driver', 'View:Driver', 'Create:Driver', 'Update:Driver', 'Delete:Driver', 'Restore:Driver',
            'ViewAny:DriverSchedule', 'View:DriverSchedule', 'Create:DriverSchedule', 'Update:DriverSchedule', 'Delete:DriverSchedule',
        ], $this->company);
    });

    test('CP-0079: Renders driver workforce list with name, email, license and status', function (): void {
        $driver = Driver::factory()->for($this->company)->active()->create([
            'license' => 'LIC-79001',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee($driver->user->name)
            ->assertSee($driver->user->email)
            ->assertSee('LIC-79001')
            ->assertSee('Active')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0080: Filters driver list by license number in real time', function (): void {
        $driverA = Driver::factory()->for($this->company)->create(['license' => 'LIC-AAA-111']);
        $driverB = Driver::factory()->for($this->company)->create(['license' => 'LIC-BBB-222']);

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('LIC-AAA-111')
            ->assertSee('LIC-BBB-222')
            ->type('.fi-ta-search-field input', 'LIC-AAA-111')
            ->assertSee('LIC-AAA-111')
            ->assertDontSee('LIC-BBB-222')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0081: Filters drivers by active, inactive and archived status', function (): void {
        $activeDriver = Driver::factory()->for($this->company)->active()->create(['license' => 'ACTIVE-DRV']);
        $archivedDriver = Driver::factory()->for($this->company)->create(['license' => 'ARCHIVED-DRV']);
        $archivedDriver->delete();

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('ACTIVE-DRV')
            ->assertDontSee('ARCHIVED-DRV')
            ->click('button[aria-label="Filter"]')
            ->select('select[id$="trashed.value"]', '0')
            ->click('button:has-text("Apply filters")')
            ->click('button[aria-label="Filter"]')
            ->assertSee('ARCHIVED-DRV')
            ->assertDontSee('ACTIVE-DRV')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0082: Provisions driver with new user identity and initial monthly salary', function (): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('create', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->type('input[id$="name"]', 'Carlos Delgado')
            ->type('input[id$="email"]', 'carlos.delgado@example.test')
            ->type('input[id$="password"]', 'N7v!qL2#rX9@kP4')
            ->type('input[id$="password_confirmation"]', 'N7v!qL2#rX9@kP4')
            ->type('input[id$="license"]', 'LIC-CD-888')
            ->type('input[id$="license_expires_at"]', '2030-12-31')
            ->type('input[id$="employment_starts_on"]', '2026-11-01')
            ->type('input[id$="salary_amount"]', '750000.00')
            ->select('select[id$="salary_currency"]', 'CRC')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertSee('Created')
            ->assertNoJavaScriptErrors();

        $this->assertDatabaseHas(Driver::class, [
            'company_id' => $this->company->id,
            'license' => 'LIC-CD-888',
            'salary_amount' => '750000.00',
            'salary_currency' => 'CRC',
        ]);

        $this->assertDatabaseHas(User::class, [
            'email' => 'carlos.delgado@example.test',
        ]);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0083: Links existing user identity to driver employment preserving password', function (): void {
        $existingUser = User::factory()->create([
            'email' => 'existing.driver@example.test',
            'name' => 'Existing Driver User',
            'password' => 'OriginalPassword123!',
        ]);
        $originalHash = $existingUser->getRawOriginal('password');

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('create', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->select('select[id$="registration_type"]', 'existing')
            ->assertSee('Existing User')
            ->click('button[id="form.user_id"]')
            ->type('input[placeholder="Start typing to search..."]', 'existing.driver@example.test')
            ->click('[role="option"]:has-text("Existing Driver User")')
            ->type('input[id$="license"]', 'LIC-EXIST-999')
            ->type('input[id$="license_expires_at"]', '2030-12-31')
            ->type('input[id$="employment_starts_on"]', '2026-11-01')
            ->type('input[id$="salary_amount"]', '820000.00')
            ->select('select[id$="salary_currency"]', 'USD')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertSee('Created')
            ->assertNoJavaScriptErrors();

        expect($existingUser->fresh()->getRawOriginal('password'))->toBe($originalHash);

        $this->assertDatabaseHas(Driver::class, [
            'company_id' => $this->company->id,
            'user_id' => $existingUser->id,
            'license' => 'LIC-EXIST-999',
        ]);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0084: Rejects duplicate driver license within the same company', function (): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();
        Driver::factory()->for($this->company)->create(['license' => 'DUP-LIC-001']);

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('create', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->type('input[id$="name"]', 'Dup Driver')
            ->type('input[id$="email"]', 'dup.driver@example.test')
            ->type('input[id$="password"]', 'N7v!qL2#rX9@kP4')
            ->type('input[id$="password_confirmation"]', 'N7v!qL2#rX9@kP4')
            ->type('input[id$="license"]', 'DUP-LIC-001')
            ->type('input[id$="license_expires_at"]', '2030-12-31')
            ->type('input[id$="employment_starts_on"]', '2026-11-01')
            ->type('input[id$="salary_amount"]', '500000.00')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertDontSee('Created')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0085: Rejects new driver provisioning with pre-existing user email', function (): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();
        $existing = User::factory()->create(['email' => 'taken@example.test']);

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('create', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->type('input[id$="name"]', 'New Collision')
            ->type('input[id$="email"]', 'taken@example.test')
            ->type('input[id$="password"]', 'N7v!qL2#rX9@kP4')
            ->type('input[id$="password_confirmation"]', 'N7v!qL2#rX9@kP4')
            ->type('input[id$="license"]', 'LIC-UNIQUE-123')
            ->type('input[id$="license_expires_at"]', '2030-12-31')
            ->type('input[id$="employment_starts_on"]', '2026-11-01')
            ->type('input[id$="salary_amount"]', '500000.00')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Create', 'exact' => true]))
            ->assertDontSee('Created')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0086: Strictly omits salary attributes from general driver list columns', function (): void {
        Driver::factory()->for($this->company)->create([
            'license' => 'CONF-LIC-001',
            'salary_amount' => '987654.32',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('CONF-LIC-001')
            ->assertDontSee('987654.32')
            ->assertDontSee('Salary')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0087: Strictly omits salary attributes from driver view and infolist', function (): void {
        $driver = Driver::factory()->for($this->company)->create([
            'license' => 'VIEW-CONF-002',
            'salary_amount' => '987654.32',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('view', ['record' => $driver], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('VIEW-CONF-002')
            ->assertDontSee('987654.32')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0088: Updates driver license expiration and employment dates', function (): void {
        $driver = Driver::factory()->for($this->company)->create([
            'license' => 'EDIT-LIC-001',
            'license_expires_at' => '2027-01-01',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('edit', ['record' => $driver], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->type('input[id$="license_expires_at"]', '2031-12-31')
            ->type('input[id$="employment_ends_on"]', '2031-12-31')
            ->click(Selector::getByRoleSelector('button', ['name' => 'Save changes', 'exact' => true]))
            ->assertSee('Saved')
            ->assertNoJavaScriptErrors();

        expect($driver->fresh())
            ->license_expires_at->toDateString()->toBe('2031-12-31')
            ->employment_ends_on->toDateString()->toBe('2031-12-31');

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0089: Deactivates an active driver with confirmation modal', function (): void {
        $driver = Driver::factory()->for($this->company)->active()->create([
            'license' => 'DEACT-DRV-01',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('DEACT-DRV-01')
            ->click('button:has-text("Deactivate")')
            ->assertSee('Are you sure')
            ->click('[aria-modal="true"] button:has-text("Confirm")')
            ->assertSee('Driver Deactivated')
            ->assertNoJavaScriptErrors();

        expect($driver->fresh()->status)->toBe(DriverStatus::INACTIVE);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0090: Reactivates an inactive driver with confirmation modal', function (): void {
        $driver = Driver::factory()->for($this->company)->inactive()->create([
            'license' => 'REACT-DRV-01',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('REACT-DRV-01')
            ->click('button:has-text("Activate")')
            ->assertSee('Are you sure')
            ->click('[aria-modal="true"] button:has-text("Confirm")')
            ->assertSee('Driver Activated')
            ->assertNoJavaScriptErrors();

        expect($driver->fresh()->status)->toBe(DriverStatus::ACTIVE);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0091: Blocks driver deactivation when committed to active trip', function (): void {
        $driver = Driver::factory()->for($this->company)->active()->create([
            'license' => 'TRIP-COMMITTED-DRV',
        ]);
        Trip::factory()->for($this->company)->create([
            'driver_id' => $driver->id,
            'status' => TripStatus::SCHEDULED,
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('TRIP-COMMITTED-DRV')
            ->click('button:has-text("Deactivate")')
            ->click('[aria-modal="true"] button:has-text("Confirm")')
            ->assertSee('Employment Change Rejected')
            ->assertNoJavaScriptErrors();

        expect($driver->fresh()->status)->toBe(DriverStatus::ACTIVE);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0092: Archives driver employment preserving unique UUID and identity', function (): void {
        $driver = Driver::factory()->for($this->company)->create([
            'license' => 'ARCHIVE-DRV-UUID',
        ]);
        $originalId = $driver->id;
        $originalUserId = $driver->user_id;

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('ARCHIVE-DRV-UUID')
            ->click('button:has-text("Archive")')
            ->assertSee('Archive Driver Employment')
            ->click('[aria-modal="true"] button:has-text("Archive")')
            ->assertSee('Driver Employment Archived')
            ->assertNoJavaScriptErrors();

        $this->assertSoftDeleted($driver);
        expect($driver->fresh()->id)->toBe($originalId)
            ->and($driver->fresh()->user_id)->toBe($originalUserId);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0093: Restores an archived driver preserving original identity and UUID', function (): void {
        $driver = Driver::factory()->for($this->company)->create([
            'license' => 'RESTORE-DRV-UUID',
        ]);
        $driver->delete();
        $originalId = $driver->id;
        $originalUserId = $driver->user_id;

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('index', tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->click('button[aria-label="Filter"]')
            ->select('select[id$="trashed.value"]', '0')
            ->click('button:has-text("Apply filters")')
            ->click('button[aria-label="Filter"]')
            ->assertSee('RESTORE-DRV-UUID')
            ->click('button:has-text("Restore")')
            ->assertSee('Restore Driver Employment')
            ->click('[aria-modal="true"] button:has-text("Restore")')
            ->assertSee('Driver Employment Restored')
            ->assertNoJavaScriptErrors();

        $this->assertNotSoftDeleted($driver);
        expect($driver->fresh()->id)->toBe($originalId)
            ->and($driver->fresh()->user_id)->toBe($originalUserId);

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0094: Manages weekly driver availability schedule in relation manager', function (): void {
        $driver = Driver::factory()->for($this->company)->active()->create([
            'license' => 'SCHED-DRV-001',
        ]);
        DriverSchedule::factory()->create([
            'driver_id' => $driver->id,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);

        BrowserSession::start($this->actor);

        $path = parse_url(DriverResource::getUrl('edit', ['record' => $driver], tenant: $this->company), PHP_URL_PATH);

        visit($path)
            ->assertSee('Working Availability')
            ->assertSee('Monday')
            ->assertSee('08:00:00')
            ->assertSee('17:00:00')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });
});
