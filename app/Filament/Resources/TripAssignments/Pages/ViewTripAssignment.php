<?php

namespace App\Filament\Resources\TripAssignments\Pages;

use App\Filament\Resources\TripAssignments\TripAssignmentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTripAssignment extends ViewRecord
{
    protected static string $resource = TripAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
