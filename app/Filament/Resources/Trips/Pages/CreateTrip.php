<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Filament\Resources\Trips\TripResource;
use App\Models\Company;
use App\Models\User;
use App\Services\TripManagementService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CreateTrip extends CreateRecord
{
    protected static string $resource = TripResource::class;

    protected ?bool $hasDatabaseTransactions = false;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();

        abort_unless($actor instanceof User && $company instanceof Company, 403);

        try {
            return app(TripManagementService::class)->schedule(
                $actor,
                $company,
                Arr::only($data, [
                    'route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id',
                    'service_date', 'arrival_at', 'departure_utc_offset',
                ]),
            );
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
        $statePath = $this->form->getStatePath();
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            $errors[filled($statePath) ? "{$statePath}.{$field}" : $field] = $messages;
        }

        return ValidationException::withMessages($errors);
    }
}
