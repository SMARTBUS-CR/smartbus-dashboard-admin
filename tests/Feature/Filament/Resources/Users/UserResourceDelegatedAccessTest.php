<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

describe('Delegated User Resource Access', function (): void {
    test('allows a custom company role to read users only with the corresponding permission', function (): void {
        $company = createCompany();
        $user = createUserWithRole('dispatcher', $company);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id]);
        actingAsInCompany($user, $company);

        Livewire::test(ListUsers::class)->assertForbidden();

        grantShield($user, ['ViewAny:User'], $company);
        Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$user]);
        expect(Gate::forUser($user)->allows('create', User::class))->toBeFalse();
    });
});
