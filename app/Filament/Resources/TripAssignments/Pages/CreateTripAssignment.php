<?php

namespace App\Filament\Resources\TripAssignments\Pages;

use App\Filament\Resources\TripAssignments\TripAssignmentResource;
use App\Models\Company;
use App\Models\User;
use App\Services\TripAssignmentManagementService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CreateTripAssignment extends CreateRecord
{
    protected static string $resource = TripAssignmentResource::class;

    protected ?bool $hasDatabaseTransactions = false;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();
        abort_unless($actor instanceof User && $company instanceof Company, 403);

        try {
            return app(TripAssignmentManagementService::class)->createMany(
                $actor,
                $company,
                array_values($data['route_schedule_ids'] ?? []),
                Arr::only($data, ['driver_id', 'bus_id', 'planned_duration_minutes', 'valid_from', 'valid_until']),
            )->firstOrFail();
        } catch (ValidationException $exception) {
            throw $this->forForm($exception);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl('index');
    }

    private function forForm(ValidationException $exception): ValidationException
    {
        $path = $this->form->getStatePath();

        return ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
            function (array $messages, string $field) use ($path): array {
                $field = match ($field) {
                    'route_schedule_id' => 'route_schedule_ids',
                    'availability' => 'driver_id',
                    default => $field,
                };

                return [filled($path) ? "{$path}.{$field}" : $field => $messages];
            },
        )->all());
    }
}
