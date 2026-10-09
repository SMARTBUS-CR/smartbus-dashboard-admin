<?php

namespace App\Filament\Resources\Routes\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RouteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('General Information'))
                ->schema([
                    TextEntry::make('code')
                        ->label(__('Code')),

                    TextEntry::make('name')
                        ->label(__('Route Name')),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
