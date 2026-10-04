<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\UserManagementService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected ?bool $hasDatabaseTransactions = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(UserManagementService::class)->createCompanyAdmin(
                Filament::auth()->user(),
                $data,
            );
        } catch (ValidationException $exception) {
            $statePath = $this->form->getStatePath();

            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $key = filled($statePath)
                    ? "{$statePath}.{$field}"
                    : $field;

                $errors[$key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
