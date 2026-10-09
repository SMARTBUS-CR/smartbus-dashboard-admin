<?php

use App\Filament\Resources\Stops\Pages\ListStops;
use App\Models\CompanyUser;
use App\Models\Stop;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Stop Resource Lifecycle Authorization', function (): void {
    test('requires the corresponding permission to execute a lifecycle action', function (bool $allowed, string $action, string $permission, string $notification): void {
        app()->setLocale('en');

        $company = createCompany();
        $actor = createUserWithRole('stop-manager', $company);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        $permissions = ['ViewAny:Stop'];

        if ($allowed) {
            $permissions[] = $permission;
        }

        grantShield($actor, $permissions, $company);
        actingAsInCompany($actor, $company);

        $stop = Stop::factory()->for($company)->create();

        if ($action === 'restore') {
            $stop->delete();
        }

        $component = Livewire::test(ListStops::class);

        if ($action === 'restore') {
            $component->filterTable('trashed', false);
        }

        $tableAction = TestAction::make($action)->table($stop);

        if (! $allowed) {
            $component
                ->assertActionHidden($tableAction)
                ->call(
                    'mountAction',
                    $action,
                    [],
                    [
                        'table' => true,
                        'recordKey' => $stop->getKey(),
                    ],
                )
                ->call('callMountedAction')
                ->assertActionNotMounted();

            expect($stop->fresh()->trashed())
                ->toBe($action === 'restore');

            return;
        }

        $component
            ->callAction($tableAction)
            ->assertHasNoErrors()
            ->assertNotified($notification);

        expect($stop->fresh()->trashed())
            ->toBe($action === 'delete');
    })
        ->with([
            'with permission' => true,
            'without permission' => false,
        ])
        ->with([
            'archive' => [
                'delete',
                'Delete:Stop',
                'Stop Archived',
            ],
            'restore' => [
                'restore',
                'Restore:Stop',
                'Stop Restored',
            ],
        ]);
});
