<?php

namespace App\Filament\Resources\Stops\Pages;

use App\Filament\Resources\Stops\StopResource;
use Filament\Actions\CreateAction;
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
                ->description(__(
                    'Shared stops can be used by multiple companies.',
                ))
                ->info()
                ->columnSpanFull(),

            ...$components,
        ]);
    }
}
