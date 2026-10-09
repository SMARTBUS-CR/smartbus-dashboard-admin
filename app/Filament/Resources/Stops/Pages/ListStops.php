<?php

namespace App\Filament\Resources\Stops\Pages;

use App\Filament\Resources\Stops\StopResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;

class ListStops extends ListRecords
{
    protected static string $resource = StopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $schema = parent::content($schema);

        $components = $schema->getComponents(withHidden: true);

        return $schema->components([
            Callout::make(__('Shared Stops'))
                ->key('shared_stops_notice')
                ->description(
                    fn (): string => Filament::auth()->user()?->isSuperAdmin()
                        ? __('Shared stops can be used by multiple companies.')
                        : __('Shared stops are created by a System Admin. They can be used to create patterns across multiple companies.'),
                )
                ->info()
                ->columnSpanFull(),

            ...$components,
        ]);
    }
}
