<?php

namespace App\Filament\Resources\Drivers\Pages;

use App\Filament\Resources\Drivers\DriverResource;
use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use App\Services\DriverManagementService;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditDriver extends EditRecord
{
    protected static string $resource = DriverResource::class;

    protected ?bool $hasDatabaseTransactions = false;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();

        abort_unless(
            $actor instanceof User && $company instanceof Company && $record instanceof Driver,
            403,
        );

        try {
            return app(DriverManagementService::class)->updateEmployment(
                $actor,
                $company,
                $record,
                Arr::only($data, [
                    'license', 'license_expires_at', 'employment_starts_on', 'employment_ends_on',
                ]),
            );
        } catch (ValidationException $exception) {
            $statePath = $this->form->getStatePath();
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $errors[filled($statePath) ? "{$statePath}.{$field}" : $field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
