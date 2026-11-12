<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Enums\TripStatus;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\TextSize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListTrips extends ListRecords
{
    protected static string $resource = TripResource::class;

    public function applyTableFilters(): void
    {
        $this->getTableFiltersForm()->validate();

        parent::applyTableFilters();
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make(__('All Trips'))
                ->badge(fn (): int => (int) $this->todayCounts()->sum()),
        ];

        foreach (TripStatus::cases() as $status) {
            $label = match ($status) {
                TripStatus::SCHEDULED => __('Scheduled Trips'),
                TripStatus::IN_PROGRESS => __('Trips In Progress'),
                TripStatus::COMPLETED => __('Completed Trips'),
                TripStatus::CANCELLED => __('Cancelled Trips'),
            };

            $tabs[$status->value] = Tab::make($label)
                ->badge(fn (): int => (int) $this->todayCounts()->get($status->value, 0))
                ->badgeColor($status->getColor())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status->value));
        }

        return $tabs;
    }

    public function getTabsContentComponent(): Component
    {
        return Group::make([
            Text::make(__('Tab counts show trips for today'))->size(TextSize::Small),
            parent::getTabsContentComponent(),
        ]);
    }

    /** @return Collection<string, int> */
    private function todayCounts(): Collection
    {
        return once(function (): Collection {
            $company = Filament::getTenant();
            if (! $company instanceof Company) {
                return collect();
            }

            return TripResource::getEloquentQuery()
                ->where('service_date', CarbonImmutable::today($company->timezone)->toDateString())
                ->toBase()
                ->select('status')
                ->selectRaw('COUNT(*) AS total')
                ->groupBy('status')
                ->pluck('total', 'status');
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
