<?php

use App\Enums\UserRole;
use App\Filament\Resources\SuperAdmins\SuperAdminResource;
use App\Models\CompanyUser;

describe('Global SuperAdmin Resource Routes', function (): void {
    test('uses the selected company for navigation without creating membership', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);

        [$company, $foreign] = createTenantPair();

        foreach ([$company, $foreign] as $tenant) {
            actingAsInCompany($actor, $tenant);

            $basePath = '/admin/'.$tenant->slug.'/super-admins';

            expect(SuperAdminResource::getUrl(
                'index',
                isAbsolute: false,
                panel: 'admin',
            ))->toBe($basePath)
                ->and(SuperAdminResource::getUrl('create', isAbsolute: false, panel: 'admin'))->toBe($basePath.'/create')
                ->and(SuperAdminResource::getUrl('edit', ['record' => $target->getRouteKey()], isAbsolute: false, panel: 'admin'))->toBe($basePath.'/'.$target->getRouteKey().'/edit')
                ->and(SuperAdminResource::getEloquentQuery()
                    ->pluck('users.id')->all())->toEqualCanonicalizing([
                        $actor->getKey(),
                        $target->getKey(),
                    ])
                ->and(getPermissionsTeamId())->toBe($tenant->getKey());
        }

        expect(CompanyUser::withTrashed()
            ->whereIn('user_id', [
                $actor->getKey(),
                $target->getKey(),
            ])
            ->exists())->toBeFalse();
    });
});
