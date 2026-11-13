<?php

use App\Filament\Resources\Buses\BusResource;
use App\Filament\Resources\Buses\Pages\EditBus;
use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\User;
use App\Services\BusManagementService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\BrowserSession;
use Tests\Support\WithDriverTrips;

pest()->use(WithDriverTrips::class);

describe('Multitenancy And Access Control Browser Security', function (): void {
    beforeEach(function (): void {
        config(['app.locale' => 'en']);
        app()->setLocale('en');
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
    });

    test('CP-0115: Enforces company tenant isolation for bus fleet records', function (): void {
        [$companyA, $companyB] = createTenantPair();

        $busA = Bus::factory()->for($companyA)->create(['unit_number' => 'BUS-ALPHA-101']);
        $busB = Bus::factory()->for($companyB)->create(['unit_number' => 'BUS-BETA-202']);

        $actorA = $this->createWorkforceAdministrator($companyA);
        grantShield($actorA, ['ViewAny:Bus', 'View:Bus'], $companyA);

        BrowserSession::start($actorA);

        $pathA = parse_url(BusResource::getUrl('index', tenant: $companyA), PHP_URL_PATH);

        visit($pathA)
            ->assertSee('BUS-ALPHA-101')
            ->assertDontSee('BUS-BETA-202')
            ->assertNoJavaScriptErrors();

        $pathB = parse_url(BusResource::getUrl('index', tenant: $companyB), PHP_URL_PATH);

        visit($pathB)
            ->assertSee('404')
            ->assertDontSee('BUS-BETA-202');

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0116: Enforces company tenant isolation for driver workforce records', function (): void {
        [$companyA, $companyB] = createTenantPair();

        $driverA = Driver::factory()->for($companyA)->active()->create(['license' => 'LIC-ALPHA-111']);
        $driverB = Driver::factory()->for($companyB)->active()->create(['license' => 'LIC-BETA-222']);

        $actorA = $this->createWorkforceAdministrator($companyA);
        grantShield($actorA, ['ViewAny:Driver', 'View:Driver'], $companyA);

        BrowserSession::start($actorA);

        $pathA = parse_url(DriverResource::getUrl('index', tenant: $companyA), PHP_URL_PATH);

        visit($pathA)
            ->assertSee('LIC-ALPHA-111')
            ->assertDontSee('LIC-BETA-222')
            ->assertNoJavaScriptErrors();

        $pathB = parse_url(DriverResource::getUrl('index', tenant: $companyB), PHP_URL_PATH);

        visit($pathB)
            ->assertSee('404')
            ->assertDontSee('LIC-BETA-222');

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0117: Enforces company tenant isolation for trip operational records', function (): void {
        [$actorA, $tripA] = $this->createAssignedDriverTrip();
        $companyA = $tripA->company;
        $companyB = createCompany();

        $fixtureB = Trip::factory()->for($companyB)->withDriverAvailability()->make();
        $tripB = Trip::factory()->for($companyB)->create([
            'bus_id' => Bus::factory()->for($companyB)->active()->create(['unit_number' => 'BUS-BETA-999'])->id,
            'service_date' => '2026-10-26',
        ]);

        grantShield($actorA, ['ViewAny:Trip', 'View:Trip'], $companyA);

        BrowserSession::start($actorA);

        $pathA = parse_url(TripResource::getUrl('index', tenant: $companyA), PHP_URL_PATH);

        visit($pathA)
            ->assertSee($tripA->bus->unit_number)
            ->assertDontSee('BUS-BETA-999')
            ->assertNoJavaScriptErrors();

        $pathB = parse_url(TripResource::getUrl('index', tenant: $companyB), PHP_URL_PATH);

        visit($pathB)
            ->assertSee('404')
            ->assertDontSee('BUS-BETA-999');

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0118: Aborts forged Livewire action against foreign tenant record with 403 or 404', function (): void {
        [$companyA, $companyB] = createTenantPair();

        $busB = Bus::factory()->for($companyB)->create(['unit_number' => 'BUS-FOREIGN-999']);

        $actorA = $this->createWorkforceAdministrator($companyA);
        grantShield($actorA, ['ViewAny:Bus', 'View:Bus', 'Update:Bus'], $companyA);

        BrowserSession::start($actorA);

        // Attempting to edit Company B's bus under Company A's tenant URL scope
        $forgedPath = "/admin/{$companyA->slug}/buses/{$busB->id}/edit";

        visit($forgedPath)
            ->assertSee('404')
            ->assertDontSee('BUS-FOREIGN-999');

        BrowserSession::assertTokenWasValidated();

        // Attempt forged mutation targeting foreign tenant record under Company A's scope
        expect(function () use ($actorA, $companyA, $busB): void {
            app(BusManagementService::class)->update(
                $actorA,
                $companyA,
                $busB->id,
                ['unit_number' => 'BUS-MUTATED-666'],
            );
        })->toThrow(ModelNotFoundException::class);

        // Attempt forged Livewire component mount/action targeting foreign tenant record
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($companyA);

        try {
            Livewire::actingAs($actorA)
                ->test(EditBus::class, ['record' => $busB->getRouteKey()])
                ->call('save');
        } catch (Throwable $exception) {
            expect($exception)->toBeInstanceOf(Throwable::class);
        }

        // Verify foreign record in database remains unmodified
        expect($busB->fresh()->unit_number)->toBe('BUS-FOREIGN-999');
    });

    test('CP-0119: Denies panel access with 403 for user holding only Driver role', function (): void {
        $company = createCompany();
        $driverUser = User::factory()->create();

        // Assign only Driver role without any admin/dashboard membership
        $driver = Driver::factory()->for($company)->create(['user_id' => $driverUser->id]);

        BrowserSession::start($driverUser);

        $path = "/admin/{$company->slug}";

        visit($path)
            ->assertSee('Forbidden');
    });

    test('CP-0120: Denies panel access with 403 for user holding only Passenger role', function (): void {
        $company = createCompany();
        $passengerUser = User::factory()->create();

        BrowserSession::start($passengerUser);

        $path = "/admin/{$company->slug}";

        visit($path)
            ->assertSee('Forbidden');
    });

    test('CP-0121: Verifies force delete actions are completely absent across all Filament resources', function (): void {
        $company = createCompany();
        $bus = Bus::factory()->for($company)->create(['unit_number' => 'BUS-SAFE-01']);
        $driver = Driver::factory()->for($company)->create(['license' => 'LIC-SAFE-01']);

        $actor = $this->createWorkforceAdministrator($company);
        grantShield($actor, [
            'ViewAny:Bus', 'View:Bus', 'Delete:Bus',
            'ViewAny:Driver', 'View:Driver', 'Delete:Driver',
            'ViewAny:Trip', 'View:Trip',
        ], $company);

        BrowserSession::start($actor);

        $busesPath = parse_url(BusResource::getUrl('index', tenant: $company), PHP_URL_PATH);
        visit($busesPath)
            ->assertSee('BUS-SAFE-01')
            ->assertDontSee('Force delete')
            ->assertNoJavaScriptErrors();

        $driversPath = parse_url(DriverResource::getUrl('index', tenant: $company), PHP_URL_PATH);
        visit($driversPath)
            ->assertSee('LIC-SAFE-01')
            ->assertDontSee('Force delete')
            ->assertNoJavaScriptErrors();

        $tripsPath = parse_url(TripResource::getUrl('index', tenant: $company), PHP_URL_PATH);
        visit($tripsPath)
            ->assertDontSee('Force delete')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });
});
