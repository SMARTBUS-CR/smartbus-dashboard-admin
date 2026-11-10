<?php

namespace App\Filament\Resources\Buses\Pages;

use App\Filament\Resources\Buses\BusResource;
use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use App\Services\BusManagementService;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditBus extends EditRecord
{
    protected static string $resource = BusResource::class;

    protected ?bool $hasDatabaseTransactions = false;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();

        abort_unless(
            $actor instanceof User && $company instanceof Company && $record instanceof Bus,
            403,
        );

        try {
            return app(BusManagementService::class)->update(
                $actor,
                $company,
                $record->getKey(),
                collect($data)->only([
                    'country_code', 'plate_number', 'unit_number', 'brand', 'model', 'year', 'capacity',
                ])->all(),
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
