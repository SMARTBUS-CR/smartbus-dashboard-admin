<?php

use App\Enums\UserRole;
use App\Filament\Resources\Stops\Pages\ListStops;
use Filament\Schemas\Components\Callout;
use Livewire\Livewire;

describe('Stop Resource Presentation', function (): void {
    test('explains shared stops in a callout above the table', function (): void {
        app()->setLocale('en');

        $company = createCompany();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(ListStops::class)
            ->assertSchemaComponentExists(
                'shared_stops_notice',
                schema: 'content',
                checkComponentUsing: function ($component): bool {
                    expect($component)->toBeInstanceOf(Callout::class);

                    return true;
                },
            )
            ->assertSee(
                'Shared stops can be used by multiple companies.',
            );
    });
});