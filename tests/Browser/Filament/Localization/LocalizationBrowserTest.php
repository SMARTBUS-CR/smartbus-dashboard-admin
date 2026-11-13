<?php

use App\Filament\Resources\Buses\BusResource;
use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Bus;
use App\Models\Driver;
use Carbon\CarbonImmutable;
use Tests\Support\BrowserSession;
use Tests\Support\WithDriverTrips;

pest()->use(WithDriverTrips::class);

describe('Filament Multi Language And Localization Browser Experience', function (): void {
    beforeEach(function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
    });

    test('CP-0122: Verifies table column headers, filter options and navigation in Spanish locale', function (): void {
        config(['app.locale' => 'es']);
        app()->setLocale('es');

        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $bus = Bus::factory()->for($company)->create(['unit_number' => 'BUS-ES-01']);
        $driver = Driver::factory()->for($company)->active()->create(['license' => 'LIC-ES-01']);

        $actor = $this->createWorkforceAdministrator($company);
        grantShield($actor, [
            'ViewAny:Bus', 'View:Bus',
            'ViewAny:Driver', 'View:Driver',
            'ViewAny:Trip', 'View:Trip',
        ], $company);

        BrowserSession::start($actor);

        $busesPath = parse_url(BusResource::getUrl('index', tenant: $company), PHP_URL_PATH).'?locale=es';
        visit($busesPath)
            ->assertSee('Autobuses')
            ->assertSee('BUS-ES-01')
            ->assertNoJavaScriptErrors();

        $driversPath = parse_url(DriverResource::getUrl('index', tenant: $company), PHP_URL_PATH).'?locale=es';
        visit($driversPath)
            ->assertSee('Conductores')
            ->assertSee('LIC-ES-01')
            ->assertNoJavaScriptErrors();

        $tripsPath = parse_url(TripResource::getUrl('index', tenant: $company), PHP_URL_PATH).'?locale=es';
        visit($tripsPath)
            ->assertSee('Viajes')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0123: Verifies table column headers, filter options and navigation in English locale', function (): void {
        config(['app.locale' => 'en']);
        app()->setLocale('en');

        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $bus = Bus::factory()->for($company)->create(['unit_number' => 'BUS-EN-01']);
        $driver = Driver::factory()->for($company)->active()->create(['license' => 'LIC-EN-01']);

        $actor = $this->createWorkforceAdministrator($company);
        grantShield($actor, [
            'ViewAny:Bus', 'View:Bus',
            'ViewAny:Driver', 'View:Driver',
            'ViewAny:Trip', 'View:Trip',
        ], $company);

        BrowserSession::start($actor);

        $busesPath = parse_url(BusResource::getUrl('index', tenant: $company), PHP_URL_PATH);
        visit($busesPath)
            ->assertSee('Buses')
            ->assertSee('BUS-EN-01')
            ->assertNoJavaScriptErrors();

        $driversPath = parse_url(DriverResource::getUrl('index', tenant: $company), PHP_URL_PATH);
        visit($driversPath)
            ->assertSee('Drivers')
            ->assertSee('LIC-EN-01')
            ->assertNoJavaScriptErrors();

        $tripsPath = parse_url(TripResource::getUrl('index', tenant: $company), PHP_URL_PATH);
        visit($tripsPath)
            ->assertSee('Trips')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0124: Verifies resource form section titles, field labels and helper texts in Spanish', function (): void {
        config(['app.locale' => 'es']);
        app()->setLocale('es');

        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = $this->createWorkforceAdministrator($company);
        grantShield($actor, ['ViewAny:Trip', 'View:Trip', 'Create:Trip'], $company);

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('create', tenant: $company), PHP_URL_PATH).'?locale=es';

        visit($path)
            ->assertSee('Plan del viaje')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });

    test('CP-0125: Verifies resource form section titles, field labels and helper texts in English', function (): void {
        config(['app.locale' => 'en']);
        app()->setLocale('en');

        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $actor = $this->createWorkforceAdministrator($company);
        grantShield($actor, ['ViewAny:Trip', 'View:Trip', 'Create:Trip'], $company);

        BrowserSession::start($actor);

        $path = parse_url(TripResource::getUrl('create', tenant: $company), PHP_URL_PATH).'?locale=en';

        visit($path)
            ->assertSee('Trip Plan')
            ->assertSee('Route Pattern')
            ->assertSee('Route Schedule')
            ->assertNoJavaScriptErrors();

        BrowserSession::assertTokenWasValidated();
    });
});
