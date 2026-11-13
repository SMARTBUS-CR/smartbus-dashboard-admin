<?php

namespace App\Filament\Resources\Drivers\Pages;

use App\Enums\DriverStatus;
use App\Filament\Resources\Drivers\DriverResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDrivers extends ListRecords
{
    protected static string $resource = DriverResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make(__('All Driver Records'))->badge(fn (): int => array_sum($this->statusCounts())),
        ];

        foreach (DriverStatus::cases() as $status) {
            $active = $status === DriverStatus::ACTIVE;
            $tabs[$status->value] = Tab::make($active ? __('Active Driver Records') : __('Inactive Driver Records'))
                ->badge(fn (): int => $this->statusCounts()[$status->value] ?? 0)
                ->badgeColor($active ? 'success' : 'gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status->value));
        }

        return $tabs;
    }

    /** @return array<string, int> */
    private function statusCounts(): array
    {
        return once(function (): array {
            $query = DriverResource::getEloquentQuery();
            $archive = $this->getTable()->getFilter('trashed');
            $state = $this->getTableFilterState('trashed') ?? [];
            $archive->applyToBaseQuery($query, $state);
            $archive->apply($query, $state);

            return $query->toBase()->select('status')->selectRaw('COUNT(*) AS total')
                ->groupBy('status')->pluck('total', 'status')->map(fn ($count): int => (int) $count)->all();
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
