<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\TripStatusOverview;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm {
        normalizeTableFilterValuesFromQueryString as protected normalizeFilterValues;
        updatedFilters as protected persistFilters;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Home;

    public function content(Schema $schema): Schema
    {
        $widgets = collect($this->getWidgets());
        [$operations, $welcome] = $widgets->partition(fn ($widget): bool => $this->normalizeWidgetClass($widget) === TripStatusOverview::class);

        return $schema->components([
            Grid::make($this->getColumns())
                ->schema(fn (): array => $this->getWidgetsSchemaComponents($welcome->all())),
            $this->getFiltersFormContentComponent()->visible(fn (): bool => TripStatusOverview::canView()),
            Grid::make($this->getColumns())
                ->schema(fn (): array => $this->getWidgetsSchemaComponents($operations->all()))
                ->visible(fn (): bool => TripStatusOverview::canView()),
        ]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label(__('Date Range'))
                ->options([
                    'today' => __('Today'),
                    'last_7_days' => __('Last 7 Days'),
                    'last_30_days' => __('Last 30 Days'),
                    'custom' => __('Custom'),
                ])
                ->default('today')
                ->selectablePlaceholder(false),
            DatePicker::make('from_date')
                ->label(__('From Date'))
                ->visible(fn (Get $get): bool => $get('period') === 'custom')
                ->rule('date_format:Y-m-d')
                ->maxDate(fn (Get $get): ?string => $get('until_date')),
            DatePicker::make('until_date')
                ->label(__('Until Date'))
                ->visible(fn (Get $get): bool => $get('period') === 'custom')
                ->rule('date_format:Y-m-d')
                ->afterOrEqual(fn (Get $get): string => filled($get('from_date')) ? 'from_date' : '')
                ->minDate(fn (Get $get): ?string => $get('from_date')),
        ]);
    }

    public function updatedFilters(): void
    {
        $this->resetValidation(['filters.from_date', 'filters.until_date']);
        $this->getFiltersForm()->validate();
        $this->persistFilters();
    }

    protected function normalizeTableFilterValuesFromQueryString(array &$data): void
    {
        $this->normalizeFilterValues($data);

        $data['period'] ??= filled($data['from_date'] ?? null) || filled($data['until_date'] ?? null) ? 'custom' : 'today';
    }

    public function getTitle(): string|Htmlable
    {
        return __('Home');
    }

    public static function getNavigationLabel(): string
    {
        return __('Home');
    }
}
