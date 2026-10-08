<?php

use App\Enums\UserRole;
use App\Filament\Resources\Stops\Pages\ListStops;
use App\Filament\Resources\Stops\Pages\ViewStop;
use App\Filament\Resources\Stops\StopResource;
use Filament\Actions\Testing\TestAction;
use App\Models\CompanyUser;
use App\Models\Stop;
use Livewire\Livewire;

describe('Stop Resource View', function (): void {
    test('shows company and shared stop details to authorized users', function (string $role, bool $shared): void {
        $company = createCompany();

        $stop = Stop::factory()->create([
            'company_id' => $shared ? null : $company->getKey(),
            'name' => 'Central Terminal',
            'description' => 'Boarding area beside the main entrance.',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $company);

            CompanyUser::create([
                'company_id' => $company->getKey(),
                'user_id' => $actor->getKey(),
            ]);

            grantShield($actor, [
                'ViewAny:Stop',
                'View:Stop',
            ], $company);
        }

        actingAsInCompany($actor, $company);

        Livewire::test(ViewStop::class, [
            'record' => $stop->getRouteKey(),
        ])
            ->assertSuccessful()
            ->assertSee('Central Terminal')
            ->assertSee('Boarding area beside the main entrance.')
            ->assertSee('10.4523456')
            ->assertSee('-84.0123456');
    })->with([
        'system admin company stop' => [UserRole::SuperAdmin->value, false],
        'system admin shared stop' => [UserRole::SuperAdmin->value, true],
        'company admin company stop' => [UserRole::Admin->value, false],
        'company admin shared stop' => [UserRole::Admin->value, true],
        'custom role company stop' => ['stop-viewer', false],
        'custom role shared stop' => ['stop-viewer', true],
    ]);

    test('rejects access without permission to view the stop', function (): void {
        $company = createCompany();
        $stop = Stop::factory()->for($company)->create();

        $actor = createUserWithRole('stop-viewer', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, ['ViewAny:Stop'], $company);

        actingAsInCompany($actor, $company);

        Livewire::test(ViewStop::class, [
            'record' => $stop->getRouteKey(),
        ])
            ->assertForbidden();
    });

    test('does not resolve a foreign company stop even for a system admin', function (): void {
        $company = createCompany();
        $otherCompany = createCompany();
        $foreignStop = Stop::factory()->for($otherCompany)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(ViewStop::class, [
            'record' => $foreignStop->getRouteKey(),
        ])
            ->assertNotFound();
    });

    test('does not resolve an archived stop', function (): void {
        $company = createCompany();
        $stop = Stop::factory()->for($company)->create();
        $stop->delete();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(ViewStop::class, [
            'record' => $stop->getRouteKey(),
        ])
            ->assertNotFound();
    });

    test('selects stop editing or viewing according to the record permissions', function (bool $shared): void {
        $company = createCompany();

        $stop = Stop::factory()->create([
            'company_id' => $shared ? null : $company->getKey(),
        ]);

        $actor = createUserWithRole(UserRole::Admin, $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Stop',
            'View:Stop',
            'Update:Stop',
        ], $company);

        actingAsInCompany($actor, $company);

        $page = Livewire::test(ListStops::class);

        if ($shared) {
            $page
                ->assertActionVisible(TestAction::make('view')->table($stop))
                ->assertActionHidden(TestAction::make('edit')->table($stop));
        } else {
            $page
                ->assertActionVisible(TestAction::make('edit')->table($stop))
                ->assertActionHidden(TestAction::make('view')->table($stop));
        }

        expect($page->instance()->getTable()->getRecordUrl($stop))
            ->toBe(StopResource::getUrl(
                $shared ? 'view' : 'edit',
                ['record' => $stop->getRouteKey()],
                panel: 'admin',
                tenant: $company,
            ));
    })->with([
                'company stop' => false,
                'shared stop' => true,
            ]);
});
