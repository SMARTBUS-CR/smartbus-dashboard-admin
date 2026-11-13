<?php

namespace App\Filament\Resources\SuperAdmins\Pages;

use App\Filament\Resources\SuperAdmins\SuperAdminResource;
use App\Services\UserManagementService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSuperAdmin extends EditRecord
{
    protected static string $resource = SuperAdminResource::class;

    protected ?bool $hasDatabaseTransactions = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['password'] = null;
        $data['password_confirmation'] = null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(UserManagementService::class)->updateSuperAdmin(
                Filament::auth()->user(),
                $record,
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
