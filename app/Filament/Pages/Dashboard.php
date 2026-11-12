<?php

namespace App\Filament\Pages;

use App\Models\Company;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Home;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('from_date')
                ->label(__('From Date'))
                ->default(fn (): ?string => $this->tenantToday()),
            DatePicker::make('until_date')
                ->label(__('Until Date'))
                ->default(fn (): ?string => $this->tenantToday())
                ->minDate(fn (Get $get): ?string => $get('from_date')),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Home');
    }

    public static function getNavigationLabel(): string
    {
        return __('Home');
    }

    private function tenantToday(): ?string
    {
        $company = Filament::getTenant();

        return $company instanceof Company
            ? CarbonImmutable::now($company->timezone)->toDateString()
            : null;
    }
}
