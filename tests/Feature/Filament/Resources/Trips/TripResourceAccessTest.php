<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Filament\Resources\Trips\TripResource;
use App\Models\CompanyUser;
use App\Models\Trip;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

describe('Trip Resource Access', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
        $this->company = createCompany(['timezone' => 'America/Costa_Rica']);
        $this->actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::factory()->for($this->company)->for($this->actor, 'user')->create();
        grantShield($this->actor, [
            'ViewAny:Trip', 'View:Trip', 'Create:Trip', 'Update:Trip',
        ], $this->company);
    });

    test('lists only trips from the selected company', function (): void {
        $trip = Trip::factory()->for($this->company)->withEligibleDriver()->create();
        $otherTrip = Trip::factory()->withEligibleDriver()->create();
        actingAsInCompany($this->actor, $this->company);

        Livewire::test(ListTrips::class)
            ->assertCanSeeTableRecords([$trip])
            ->assertCanNotSeeTableRecords([$otherTrip]);
    });

    test('supports native Filament and explicit company policy arguments', function (): void {
        $trip = Trip::factory()->for($this->company)->withEligibleDriver()->create();
        actingAsInCompany($this->actor, $this->company);

        expect(Gate::forUser($this->actor)->allows('view', $trip))->toBeTrue()
            ->and(Gate::forUser($this->actor)->allows('update', $trip))->toBeTrue()
            ->and(Gate::forUser($this->actor)->allows('view', [Trip::class, $this->company, $trip]))->toBeTrue()
            ->and(Gate::forUser($this->actor)->allows('update', [Trip::class, $this->company, $trip]))->toBeTrue();
    });

    test('prevents cross-company access and replanning after execution begins', function (): void {
        $otherTrip = Trip::factory()->withEligibleDriver()->create();
        $startedTrip = Trip::factory()->for($this->company)->withEligibleDriver()->inProgress()->create();
        actingAsInCompany($this->actor, $this->company);

        expect(TripResource::getEloquentQuery()->find($otherTrip->getKey()))->toBeNull()
            ->and(TripResource::canEdit($startedTrip))->toBeFalse()
            ->and($startedTrip->status)->toBe(TripStatus::IN_PROGRESS);
    });

    test('denies the resource without the required company permissions', function (): void {
        $actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::factory()->for($this->company)->for($actor, 'user')->create();
        actingAsInCompany($actor, $this->company);

        expect(TripResource::canViewAny())->toBeFalse()
            ->and(TripResource::canCreate())->toBeFalse();
    });
});
