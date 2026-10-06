<?php

use App\Enums\UserRole;
use App\Filament\Resources\SuperAdmins\SuperAdminResource;
use App\Models\CompanyUser;
use Filament\Facades\Filament;

describe('Global SuperAdmin Resource Scope', function (): void {
    test('lists only active super-admins independently of the selected company', function (bool $withTenant): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);
        $deleted = createUserWithRole(UserRole::SuperAdmin);
        $deleted->delete();

        $company = createCompany();

        foreach ([
            UserRole::Admin,
            UserRole::Driver,
            UserRole::Passenger,
            'dispatcher',
        ] as $role) {
            $member = createUserWithRole($role, $company);

            CompanyUser::create([
                'company_id' => $company->getKey(),
                'user_id' => $member->getKey(),
            ]);
        }

        if ($withTenant) {
            actingAsInCompany($actor, $company);
        } else {
            $this->actingAs($actor, 'web');

            Filament::setCurrentPanel('admin');
            Filament::setTenant(null, isQuiet: true);
            setPermissionsTeamId(null);
        }

        $previousTeam = getPermissionsTeamId();

        $listedIds = SuperAdminResource::getEloquentQuery()
            ->pluck('users.id')
            ->all();

        expect($listedIds)->toEqualCanonicalizing([
            $actor->getKey(),
            $target->getKey(),
        ])
            ->and(getPermissionsTeamId())->toBe($previousTeam)
            ->and(CompanyUser::withTrashed()
                ->whereIn('user_id', [
                    $actor->getKey(),
                    $target->getKey(),
                ])->exists())->toBeFalse();
    })->with([
        'without a selected company' => [false],
        'with a selected company' => [true],
    ]);
});
