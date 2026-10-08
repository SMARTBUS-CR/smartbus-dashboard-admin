<?php

use App\Enums\UserRole;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Livewire\Livewire;

describe('Company Localized Presentation', function (): void {
    test('renders timestamps in each company timezone while preserving UTC storage', function (): void {
        $company = createCompany(['country_code' => 'CR', 'timezone' => 'America/Costa_Rica', 'created_at' => '2026-10-05 12:00:00']);
        $other = createCompany(['country_code' => 'PA', 'timezone' => 'America/Panama', 'created_at' => '2026-10-05 12:00:00']);
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);
        $component = Livewire::test(ListCompanies::class);
        $column = $component->instance()->getTable()->getColumn('created_at');

        expect($column->record($company)->formatState($company->created_at))
            ->toBe('05 oct., 2026 - 06:00 a. m.')
            ->and($column->record($other)->formatState($other->created_at))
            ->toBe('05 oct., 2026 - 07:00 a. m.')
            ->and($company->fresh()->getRawOriginal('created_at'))
            ->toBe('2026-10-05 12:00:00');
    });

    test('resolves display timezone dynamically when switching tenants', function (): void {
        $company = createCompany(['timezone' => 'America/Costa_Rica']);
        $other = createCompany(['timezone' => 'America/Panama']);
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        expect(FilamentTimezone::get())->toBe('America/Costa_Rica');
        Filament::setTenant($other);
        expect(FilamentTimezone::get())->toBe('America/Panama');
        Filament::setTenant(null);
        expect(FilamentTimezone::get())->toBe(config('app.timezone'));
    });

    test('shows the international dial code for foreign company phones', function (): void {
        $company = createCompany(['phone' => '+442079460018']);
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        Livewire::test(ListCompanies::class)->assertSee('+44 20 7946 0018');
    });
});
