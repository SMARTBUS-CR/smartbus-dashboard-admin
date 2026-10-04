<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Services\UserManagementService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected ?bool $hasDatabaseTransactions = false;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role'] = UserRole::Admin->value;
        $data['password'] = null;
        $data['password_confirmation'] = null;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            app(UserManagementService::class)->updateCompanyAdmin(
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

        return $record->refresh();
    }
}
