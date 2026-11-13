<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\Company;
use App\Services\UserManagementService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
        $company = Filament::getTenant();
        $record = $this->getRecord();

        $data['roles'] = $company instanceof Company && ! $company->trashed()
            ? DB::connection('mysql')
                ->table('model_has_roles as assignments')
                ->join('roles', 'roles.id', '=', 'assignments.role_id')
                ->where('assignments.model_type', $record->getMorphClass())
                ->where('assignments.model_uuid', $record->getKey())
                ->where('assignments.company_id', $company->getKey())
                ->where('roles.company_id', $company->getKey())
                ->where('roles.guard_name', 'web')
                ->whereNotIn('roles.name', [
                    UserRole::SuperAdmin->value,
                    UserRole::Driver->value,
                    UserRole::Passenger->value,
                ])
                ->orderBy('roles.id')
                ->pluck('roles.id')
                ->map(fn ($id): int => (int) $id)
                ->all()
            : [];

        unset($data['role']);

        $data['password'] = null;
        $data['password_confirmation'] = null;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            app(UserManagementService::class)->updateCompanyUser(
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
