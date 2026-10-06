<?php

use App\Enums\UserRole;
use App\Filament\Resources\SuperAdmins\Pages\ListSuperAdmins;
use App\Filament\Resources\SuperAdmins\SuperAdminResource;
use Filament\Facades\Filament;
use Livewire\Livewire;

describe('Global SuperAdmin Resource Table', function (): void {
    test('displays identifiable global administrators', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $target = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($actor, createCompany());

        Livewire::test(ListSuperAdmins::class)
            ->assertCanSeeTableRecords([$actor, $target])
            ->assertCanRenderTableColumn('name')
            ->assertCanRenderTableColumn('email')
            ->assertSee($target->name)
            ->assertSee($target->email);
    });

    test('offers a distinct super-admin navigation item', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($actor, createCompany());

        $items = collect(Filament::getCurrentPanel()->getNavigation())
            ->flatMap(fn ($group) => $group->getItems());

        $item = $items->first(
            fn ($item): bool => $item->getUrl() === SuperAdminResource::getUrl('index')
        );

        expect($item)->not->toBeNull()
            ->and($item->getLabel())->toBe(__('System Admins'));
    });
});
