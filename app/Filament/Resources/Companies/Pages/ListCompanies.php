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
        $tabs = [
            'all' => Tab::make(trans_choice('All', 2))
                ->icon(Heroicon::ListBullet),
        ];

        foreach (CompanyStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->getPluralLabel())
                ->icon(Heroicon::UserGroup)
                ->badgeColor($status->getColor())
                ->badge($this->getModel()::where('status', $status->value)->count())
                ->modifyQueryUsing(fn ($query) => $query->where('status', $status->value));
        }

        return $tabs;
    }
}
