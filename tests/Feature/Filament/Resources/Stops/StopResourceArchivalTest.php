<?php

use App\Enums\UserRole;
use App\Filament\Resources\Stops\Pages\ListStops;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Livewire\Livewire;

describe('Stop Resource Archival', function (): void {
    beforeEach(function (): void {
        app()->setLocale('en');

        $this->company = createCompany();

        $actor = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($actor, $this->company);
    });

    test('archives an unused stop through the table', function (): void {
        $stop = Stop::factory()->for($this->company)->create();

        Livewire::test(ListStops::class)
            ->callAction(TestAction::make('delete')->table($stop))
            ->assertHasNoErrors()
            ->assertNotified('Stop Archived')
            ->assertCanNotSeeTableRecords([$stop]);

        expect($stop->fresh()->trashed())->toBeTrue();
    });

    test('restores an archived stop through the table', function (): void {
        $stop = Stop::factory()->for($this->company)->create();
        $stop->delete();

        Livewire::test(ListStops::class)
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$stop])
            ->callAction(TestAction::make('restore')->table($stop))
            ->assertHasNoErrors()
            ->assertNotified('Stop Restored');

        expect($stop->fresh()->trashed())->toBeFalse();

        Livewire::test(ListStops::class)
            ->assertCanSeeTableRecords([$stop]);
    });

    test('keeps a referenced stop active and explains why it cannot be archived', function (bool $archivedPattern): void {
        $route = Route::factory()->for($this->company)->create();
        $pattern = RoutePattern::factory()->for($route)->create();
        $stop = Stop::factory()->for($this->company)->create();

        $occurrence = RoutePatternStop::factory()->create([
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'stop_sequence' => 1,
            'minutes_from_start' => 0,
        ]);

        if ($archivedPattern) {
            $pattern->delete();
        }

        Livewire::test(ListStops::class)
            ->callAction(TestAction::make('delete')->table($stop))
            ->assertHasNoErrors()
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title('Could Not Archive Stop')
                    ->body(
                        'This stop is used by a route pattern. Remove it from all patterns before archiving it.',
                    ),
            )
            ->assertCanSeeTableRecords([$stop]);

        expect($stop->fresh()->trashed())->toBeFalse();

        $this->assertDatabaseHas(RoutePatternStop::class, [
            'id' => $occurrence->getKey(),
            'route_pattern_id' => $pattern->getKey(),
            'stop_id' => $stop->getKey(),
            'minutes_from_start' => 0,
        ]);
    })->with([
        'active pattern' => false,
        'archived pattern' => true,
    ]);

    test('allows a system admin to archive and restore a shared stop', function (): void {
        $stop = Stop::factory()->shared()->create();

        Livewire::test(ListStops::class)
            ->callAction(TestAction::make('delete')->table($stop))
            ->assertHasNoErrors()
            ->assertNotified('Stop Archived')
            ->assertCanNotSeeTableRecords([$stop]);

        expect($stop->fresh()->trashed())->toBeTrue()
            ->and($stop->fresh()->company_id)->toBeNull();

        Livewire::test(ListStops::class)
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$stop])
            ->callAction(TestAction::make('restore')->table($stop))
            ->assertHasNoErrors()
            ->assertNotified('Stop Restored');

        expect($stop->fresh()->trashed())->toBeFalse()
            ->and($stop->fresh()->company_id)->toBeNull();

        Livewire::test(ListStops::class)
            ->assertCanSeeTableRecords([$stop]);
    });
});
