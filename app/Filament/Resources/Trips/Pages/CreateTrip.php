<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Enums\DriverStatus;
use App\Enums\TripStatus;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\RoutePattern;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripManagementService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CreateTrip extends CreateRecord
{
    protected static string $resource = TripResource::class;

    protected ?bool $hasDatabaseTransactions = false;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    public function mount(): void
    {
        parent::mount();

        $replacementId = request()->query('replacement');

        if (! is_string($replacementId) || $replacementId === '') {
            return;
        }

        $company = Filament::getTenant();

        abort_unless($company instanceof Company, 403);

        $cancelled = TripResource::getEloquentQuery()
            ->where('status', TripStatus::CANCELLED)
            ->findOrFail($replacementId);

        $this->form->fill($this->replacementDefaults($company, $cancelled));
    }

    /** @return array<string, string|null> */
    private function replacementDefaults(Company $company, Trip $cancelled): array
    {
        $patternAvailable = RoutePattern::query()
            ->whereKey($cancelled->route_pattern_id)
            ->whereHas('route', fn (Builder $query): Builder => $query->where('company_id', $company->getKey()))
            ->exists();
        $busAvailable = Bus::query()
            ->whereKey($cancelled->bus_id)
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->exists();
        $driverAvailable = Driver::query()
            ->whereKey($cancelled->driver_id)
            ->where('company_id', $company->getKey())
            ->where('status', DriverStatus::ACTIVE)
            ->exists();

        return [
            'route_pattern_id' => $patternAvailable ? $cancelled->route_pattern_id : null,
            'route_schedule_id' => $patternAvailable ? $cancelled->route_schedule_id : null,
            'service_date' => $cancelled->service_date->toDateString(),
            'departure_utc_offset' => $cancelled->scheduled_departure_at
                ->setTimezone($company->timezone)
                ->format('P'),
            'arrival_at' => $cancelled->scheduled_arrival_at
                ->utc()
                ->format('Y-m-d H:i:s'),
            'bus_id' => $busAvailable ? $cancelled->bus_id : null,
            'driver_id' => $driverAvailable ? $cancelled->driver_id : null,
        ];
    }

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
