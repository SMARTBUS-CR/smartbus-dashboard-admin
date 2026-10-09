<?php

use App\Enums\UserRole;
use App\Filament\Resources\Stops\StopResource;
use App\Models\Role;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;

describe('Stop Permission Generation', function (): void {
    test('offers only supported stop permissions', function (): void {
        Filament::setCurrentPanel('admin');

        $permissions = FilamentShield::getResourcePermissions(
            StopResource::class,
        );

        $expected = [
            'ViewAny:Stop',
            'View:Stop',
            'Create:Stop',
            'Update:Stop',
            'Delete:Stop',
            'Restore:Stop',
        ];

        sort($permissions);
        sort($expected);

        expect($permissions)->toBe($expected);
    });

    test('does not create company super admin roles when generating permissions', function (): void {
        createCompany();

        $rolesBefore = Role::withoutGlobalScopes()
            ->where('name', UserRole::SuperAdmin->value)
            ->whereNotNull('company_id')
            ->count();

        $this->artisan('shield:generate', [
            '--resource' => 'StopResource',
            '--option' => 'permissions',
            '--panel' => 'admin',
        ])->assertExitCode(0);

        expect(
            Role::withoutGlobalScopes()
                ->where('name', UserRole::SuperAdmin->value)
                ->whereNotNull('company_id')
                ->count(),
        )->toBe($rolesBefore);
    });
});
