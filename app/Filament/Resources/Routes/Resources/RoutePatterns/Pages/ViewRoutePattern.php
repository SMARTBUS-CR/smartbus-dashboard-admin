<?php

namespace App\Filament\Resources\Routes\Resources\RoutePatterns\Pages;

use App\Filament\Resources\Routes\Resources\RoutePatterns\RoutePatternResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRoutePattern extends ViewRecord
{
    protected static string $resource = RoutePatternResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
