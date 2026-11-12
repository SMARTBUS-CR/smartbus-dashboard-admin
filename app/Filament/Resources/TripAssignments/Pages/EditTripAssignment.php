<?php

namespace App\Filament\Resources\TripAssignments\Pages;

use App\Filament\Resources\TripAssignments\TripAssignmentResource;
use App\Models\Company;
use App\Models\TripAssignment;
use App\Models\User;
use App\Services\TripAssignmentManagementService;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditTripAssignment extends EditRecord
{
    protected static string $resource = TripAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();
        abort_unless($actor instanceof User && $company instanceof Company && $record instanceof TripAssignment, 403);

        try {
            return app(TripAssignmentManagementService::class)->update(
                $actor,
                $company,
                $record,
                Arr::only($data, ['route_schedule_id', 'driver_id', 'bus_id', 'planned_arrival_time', 'ends_next_day', 'valid_from', 'valid_until']),
            );
        } catch (ValidationException $exception) {
            $path = $this->form->getStatePath();
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
                function (array $messages, string $field) use ($path): array {
                    $field = $field === 'availability' ? 'driver_id' : $field;

                    return [filled($path) ? "{$path}.{$field}" : $field => $messages];
                },
            )->all());
        }
    }
}
