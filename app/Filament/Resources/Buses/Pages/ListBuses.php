<?php

namespace App\Filament\Resources\Buses\Pages;

use App\Filament\Resources\Buses\BusResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBuses extends ListRecords
{
    protected static string $resource = BusResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All Fleet Records'))->badge(fn (): int => array_sum($this->statusCounts())),
            'active' => Tab::make(__('Active Fleet Records'))->badge(fn (): int => $this->statusCounts()[1] ?? 0)
                ->badgeColor('success')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', true)),
            'inactive' => Tab::make(__('Inactive Fleet Records'))->badge(fn (): int => $this->statusCounts()[0] ?? 0)
                ->badgeColor('gray')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', false)),
        ];
    }

    /** @return array<int, int> */
    private function statusCounts(): array
    {
        return once(function (): array {
            $query = BusResource::getEloquentQuery();
            $archive = $this->getTable()->getFilter('trashed');
            $state = $this->getTableFilterState('trashed') ?? [];
            $archive->applyToBaseQuery($query, $state);
            $archive->apply($query, $state);

            return $query->toBase()->selectRaw('CAST(is_active AS integer) AS active_key, COUNT(*) AS total')
                ->groupBy('is_active')->pluck('total', 'active_key')->map(fn ($count): int => (int) $count)->all();
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
