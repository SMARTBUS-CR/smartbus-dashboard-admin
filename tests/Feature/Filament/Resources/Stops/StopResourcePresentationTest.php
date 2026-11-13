<?php

use App\Enums\UserRole;
use App\Filament\Resources\Stops\Pages\ListStops;
use App\Models\CompanyUser;
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

    test('explains who creates shared stops to company users', function (): void {
        app()->setLocale('en');

        $company = createCompany();
        $actor = createUserWithRole('stop-viewer', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['ViewAny:Stop'], $company);
        actingAsInCompany($actor, $company);

        Livewire::test(ListStops::class)
            ->assertSee(
                'Shared stops are created by a System Admin. They can be used to create patterns across multiple companies.',
            )
            ->assertDontSee(
                'Shared stops can be used by multiple companies.',
            );
    });
});
