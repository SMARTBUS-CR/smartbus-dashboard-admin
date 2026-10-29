<?php

namespace App\Filament\Resources\Routes\Pages;

use App\Filament\Resources\Routes\RouteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRoute extends CreateRecord
{
    protected static string $resource = RouteResource::class;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getRedirectUrl(): string
    {
        if (RouteResource::canEdit($this->getRecord())) {
            return $this->getResourceUrl('edit', [
                'record' => $this->getRecord()->getRouteKey(),
            ]);
        }

        return parent::getRedirectUrl();
    }
}
