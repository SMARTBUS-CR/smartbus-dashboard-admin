<?php

namespace App\Filament\Resources\TripAssignments\Pages;

use App\Filament\Resources\TripAssignments\TripAssignmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTripAssignments extends ListRecords
{
    protected static string $resource = TripAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
