<?php

use App\Enums\UserRole;
use App\Models\CompanyUser;
use App\Models\Stop;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

describe('Stop Lifecycle Authorization', function (): void {
    beforeEach(function (): void {
        [$this->company, $this->otherCompany] = createTenantPair();

        $this->actor = createUserWithRole(
            'stop-manager',
            $this->company,
        );

        CompanyUser::create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->actor->getKey(),
        ]);

        actingAsInCompany($this->actor, $this->company);
    });

    test('requires the specific permission and company ownership', function (string $scope, bool $hasPermission, bool $expected, string $ability, string $permission): void {
        if ($hasPermission) {
            grantShield(
                $this->actor,
                [$permission],
                $this->company,
            );
        }

        $stop = Stop::factory()->create([
            'company_id' => match ($scope) {
                'own' => $this->company->getKey(),
                'foreign' => $this->otherCompany->getKey(),
                'shared' => null,
            },
        ]);

        if ($ability === 'restore') {
            $stop->delete();
        }

        expect(
            Gate::forUser($this->actor)->allows($ability, $stop),
        )->toBe($expected);
    })
        ->with([
            'own stop with permission' => ['own', true, true],
            'own stop without permission' => ['own', false, false],
            'foreign stop with permission' => ['foreign', true, false],
            'shared stop with permission' => ['shared', true, false],
        ])
        ->with([
            'archive' => ['delete', 'Delete:Stop'],
            'restore' => ['restore', 'Restore:Stop'],
        ]);

    test('allows a system admin to manage private and shared stops', function (bool $shared, string $ability): void {
        Filament::setTenant(null, isQuiet: true);

        $actor = createUserWithRole(UserRole::SuperAdmin);

        expect($actor->isSuperAdmin())->toBeTrue();

        actingAsInCompany($actor, $this->company);

        $stop = Stop::factory()->create([
            'company_id' => $shared
                ? null
                : $this->company->getKey(),
        ]);

        if ($ability === 'restore') {
            $stop->delete();
        }

        expect(
            Gate::forUser($actor)->allows($ability, $stop),
        )->toBeTrue()
            ->and(CompanyUser::query()
                ->where('user_id', $actor->getKey())->exists())->toBeFalse();
    })
        ->with([
            'private stop' => false,
            'shared stop' => true,
        ])
        ->with([
            'archive' => 'delete',
            'restore' => 'restore',
        ]);

    test('does not substitute archive and restore permissions', function (string $ability, string $otherPermission): void {
        grantShield(
            $this->actor,
            [$otherPermission],
            $this->company,
        );

        $stop = Stop::factory()->for($this->company)->create();

        if ($ability === 'restore') {
            $stop->delete();
        }

        expect(
            Gate::forUser($this->actor)->allows($ability, $stop),
        )->toBeFalse();
    })->with([
        'restore permission does not allow archiving' => [
            'delete',
            'Restore:Stop',
        ],
        'archive permission does not allow restoring' => [
            'restore',
            'Delete:Stop',
        ],
    ]);
});
