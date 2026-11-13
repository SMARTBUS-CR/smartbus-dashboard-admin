<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Filament\Resources\Trips\TripResource;
use App\Models\Company;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripManagementService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditTrip extends EditRecord
{
    protected static string $resource = TripResource::class;

    protected ?bool $hasDatabaseTransactions = false;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $company = Filament::getTenant();
        $trip = $this->getRecord();

        if ($company instanceof Company && $trip instanceof Trip) {
            $data['arrival_at'] = $trip->scheduled_arrival_at;
            $data['departure_utc_offset'] = $trip->scheduled_departure_at
                ->setTimezone($company->timezone)
                ->format('P');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();

        abort_unless(
            $actor instanceof User && $company instanceof Company && $record instanceof Trip,
            403,
        );

        try {
            return app(TripManagementService::class)->replan(
                $actor,
                $company,
                $record->getKey(),
                Arr::only($data, [
                    'route_pattern_id', 'route_schedule_id', 'bus_id', 'driver_id',
                    'service_date', 'arrival_at', 'departure_utc_offset',
                ]),
            );
        } catch (ValidationException $exception) {
            throw $this->forForm($exception);
        }
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
