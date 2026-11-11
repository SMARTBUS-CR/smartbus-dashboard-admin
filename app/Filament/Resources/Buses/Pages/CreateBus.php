<?php

namespace App\Filament\Resources\Buses\Pages;

use App\Filament\Resources\Buses\BusResource;
use App\Models\Company;
use App\Models\User;
use App\Services\BusManagementService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateBus extends CreateRecord
{
    protected static string $resource = BusResource::class;

    protected ?bool $hasDatabaseTransactions = false;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();

        abort_unless($actor instanceof User && $company instanceof Company, 403);

        try {
            return app(BusManagementService::class)->create($actor, $company, $data);
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
