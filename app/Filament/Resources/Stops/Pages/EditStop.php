<?php

namespace App\Filament\Resources\Stops\Pages;

use App\Filament\Resources\Stops\StopResource;
use Filament\Resources\Pages\EditRecord;

class EditStop extends EditRecord
{
    protected static string $resource = StopResource::class;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
        ];
    }
}
