<?php

use App\Enums\UserRole;
use App\Filament\Resources\SuperAdmins\Pages\ListSuperAdmins;
use App\Models\CompanyUser;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Global SuperAdmin Resource Deactivation', function (): void {
    test('requires confirmation before deactivating an account', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($actor, createCompany());

        $action = TestAction::make('deactivateSuperAdmin')
            ->table($target);

        $page = Livewire::test(ListSuperAdmins::class)
            ->assertActionVisible($action)
            ->mountAction($action)
            ->assertActionMounted($action);

        expect(User::query()->whereKey($target->getKey())->exists())
            ->toBeTrue();

        $page
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertNotified()
            ->assertCanNotSeeTableRecords([$target])
            ->assertCanSeeTableRecords([$actor]);

        expect(User::withTrashed()
            ->findOrFail($target->getKey())
            ->trashed())->toBeTrue()
            ->and(User::query()->whereKey($actor->getKey())->exists())->toBeTrue()
            ->and(CompanyUser::withTrashed()
                ->whereIn('user_id', [
                    $actor->getKey(),
                    $target->getKey(),
                ])->exists())->toBeFalse();
    });

    test('shows an error when deactivating the last verified administrator', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($actor, createCompany());

        $action = TestAction::make('deactivateSuperAdmin')
            ->table($actor);

        Livewire::test(ListSuperAdmins::class)
            ->callAction($action)
            ->assertHasFormErrors([
                'access' => __('The system must retain at least one active global system admin with a verified email.'),
            ])
            ->assertNotNotified()
            ->assertActionMounted($action)
            ->assertMountedActionModalSee(
                __('The system must retain at least one active global system admin with a verified email.')
            )
            ->assertCanSeeTableRecords([$actor]);

        expect(User::query()->whereKey($actor->getKey())->exists())
            ->toBeTrue();
    });
});
