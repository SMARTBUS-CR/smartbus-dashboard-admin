<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Enums\CompanyStatus;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $counts = $this->getModel()::query()
            ->select('status')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $tabs = [
            'all' => Tab::make(__('All Companies'))
                ->icon(Heroicon::ListBullet),
        ];

        foreach (CompanyStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->getPluralLabel())
                ->icon(Heroicon::UserGroup)
                ->badgeColor($status->getColor())
                ->badge((int) ($counts[$status->value] ?? 0))
                ->modifyQueryUsing(fn ($query) => $query->where('status', $status->value));
        }

        return $tabs;
    }
}
