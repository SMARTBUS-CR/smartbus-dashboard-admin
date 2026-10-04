<?php

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\CompanyUser;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

describe('Company Admin Resource Access Removal', function (): void {
    test('requires confirmation before removing access and keeps the account', function (): void {
        $company = createCompany();
        $target = createUserWithRole(UserRole::Admin, $company);
        $backup = createUserWithRole(UserRole::Admin, $company);

        $membership = CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $target->id,
        ]);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $backup->id,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        $action = TestAction::make('removeCompanyAccess')->table($target);

        $page = Livewire::test(ListUsers::class)
            ->assertActionVisible($action)
            ->mountAction($action)
            ->assertActionMounted($action);

        expect($membership->refresh()->trashed())->toBeFalse();

        $page
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertNotified()
            ->assertCanNotSeeTableRecords([$target])
            ->assertCanSeeTableRecords([$backup]);

        expect($membership->refresh()->trashed())->toBeTrue();

        expect($target->refresh()->trashed())->toBeFalse();
    });

    test('shows an error when removing the last administrator', function (): void {
        $company = createCompany();
        $target = createUserWithRole(UserRole::Admin, $company);

        $membership = CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $target->id,
        ]);

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $company,
        );

        Livewire::test(ListUsers::class)
            ->callAction(
                TestAction::make('removeCompanyAccess')->table($target),
            )
            ->assertHasFormErrors(['access'])
            ->assertNotNotified()
            ->assertCanSeeTableRecords([$target]);

        expect($membership->refresh()->trashed())->toBeFalse();

        expect($target->refresh()->trashed())->toBeFalse();
    });

    test('hides removal from administrators without permission', function (): void {
        $company = createCompany();
        $actor = createUserWithRole(UserRole::Admin, $company);
        $target = createUserWithRole(UserRole::Admin, $company);

        foreach ([$actor, $target] as $user) {
            CompanyUser::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
            ]);
        }

        grantShield($actor, ['ViewAny:User', 'View:User'], $company);
        actingAsInCompany($actor, $company);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$target])
            ->assertActionHidden(
                TestAction::make('removeCompanyAccess')->table($target),
            );
    });
});