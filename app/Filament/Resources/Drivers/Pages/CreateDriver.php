<?php

namespace App\Filament\Resources\Drivers\Pages;

use App\Filament\Resources\Drivers\DriverResource;
use App\Models\Company;
use App\Models\User;
use App\Services\DriverManagementService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CreateDriver extends CreateRecord
{
    protected static string $resource = DriverResource::class;

    protected ?bool $hasDatabaseTransactions = false;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();

        abort_unless($actor instanceof User && $company instanceof Company, 403);

        $employment = Arr::only($data, [
            'license', 'license_expires_at', 'employment_starts_on', 'employment_ends_on',
            'salary_amount', 'salary_currency', 'salary_period',
        ]);

        try {
            if (($data['registration_type'] ?? null) === 'existing') {
                $user = User::query()->find($data['user_id'] ?? null);

                if (! $user instanceof User) {
                    throw ValidationException::withMessages([
                        'user_id' => __('The selected user is unavailable.'),
                    ]);
                }

                return app(DriverManagementService::class)
                    ->createForExistingUser($actor, $company, $user, $employment);
            }

            return app(DriverManagementService::class)->register(
                $actor,
                $company,
                Arr::only($data, ['name', 'email', 'password', 'password_confirmation']),
                $employment,
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
