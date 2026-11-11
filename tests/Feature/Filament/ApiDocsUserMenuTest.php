<?php

use App\Enums\UserRole;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

describe('Filament API Documentation User Menu Shortcut', function (): void {
    it('displays the api documentation shortcut in user menu exclusively for super admins', function (): void {
        $panel = Filament::getPanel('admin');
        $superAdmin = createUserWithRole('super-admin');

        // 1. Acting as SuperAdmin: item is present, visible and points to Scramble docs UI in new tab
        $this->actingAs($superAdmin);

        $superAdminItems = collect($panel->getUserMenuItems())
            ->filter(fn ($item) => $item->getUrl() === route('scramble.docs.ui'));

        expect($superAdminItems)->not->toBeEmpty();

        $docItem = $superAdminItems->first();
        expect($docItem->getLabel())->toBe(__('API Documentation'))
            ->and($docItem->getIcon())->toBe(Heroicon::OutlinedCodeBracketSquare)
            ->and($docItem->shouldOpenUrlInNewTab())->toBeTrue()
            ->and($docItem->isVisible())->toBeTrue();

        // 2. Acting as Company Admin: item is not visible
        $company = createCompany();
        $companyAdmin = createUserWithRole(UserRole::Admin, $company);
        $this->actingAs($companyAdmin);

        $adminItems = collect($panel->getUserMenuItems())
            ->filter(fn ($item) => $item->getUrl() === route('scramble.docs.ui'));

        expect($adminItems)->toBeEmpty();
    });

    it('enforces gate authorization exclusively for super admins', function (): void {
        $superAdmin = createUserWithRole('super-admin');
        $company = createCompany();
        $companyAdmin = createUserWithRole(UserRole::Admin, $company);

        expect(Gate::forUser($superAdmin)->allows('viewApiDocs'))->toBeTrue()
            ->and(Gate::forUser($companyAdmin)->allows('viewApiDocs'))->toBeFalse()
            ->and(Gate::forUser(null)->allows('viewApiDocs'))->toBeFalse();
    });

    it('provides bilingual translations for the api documentation menu label', function (): void {
        app()->setLocale('en');
        expect(__('API Documentation'))->toBe('API Documentation');

        app()->setLocale('es');
        expect(__('API Documentation'))->toBe('Documentación de la API');
    });
});
