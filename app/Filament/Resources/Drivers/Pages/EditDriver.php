<?php

namespace App\Filament\Resources\Drivers\Pages;

use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Resources\Drivers\Schemas\DriverForm;
use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use App\Services\DriverManagementService;
use App\Services\DriverReadService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
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
            Action::make('manageSalary')
                ->label(__('Manage Salary'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->authorize(fn (): bool => DriverResource::canEdit($this->getRecord())
                    && DriverResource::canViewSalary($this->getRecord()))
                ->schema(DriverForm::salaryFields())
                ->fillForm(fn (): array => $this->salaryProjection())
                ->modalHeading(__('Confidential Salary'))
                ->modalDescription(__('Salary information is restricted to explicitly authorized company administrators.'))
                ->action(function (array $data): void {
                    $actor = Filament::auth()->user();
                    $company = Filament::getTenant();
                    $driver = $this->getRecord();

                    abort_unless(
                        $actor instanceof User && $company instanceof Company && $driver instanceof Driver,
                        403,
                    );

                    try {
                        app(DriverManagementService::class)->updateEmployment(
                            $actor,
                            $company,
                            $driver,
                            Arr::only($data, ['salary_amount', 'salary_currency', 'salary_period']),
                        );
                    } catch (ValidationException $exception) {
                        throw $this->forForm(
                            $exception,
                            $this->getMountedActionSchema()?->getStatePath(),
                        );
                    }

                    Notification::make()
                        ->title(__('Salary Updated'))
                        ->success()
                        ->send();
                }),
        ];
    }

    public function dehydrate(): void
    {
        $this->mountedActions = array_map(
            fn ($action) => is_array($action) ? [...$action] : $action,
            $this->mountedActions,
        );
    }

    /** @return array{salary_amount: string, salary_currency: string, salary_period: string} */
    private function salaryProjection(): array
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();
        $driver = $this->getRecord();

        abort_unless(
            $actor instanceof User && $company instanceof Company && $driver instanceof Driver,
            403,
        );

        return app(DriverReadService::class)->salaryFor(
            $actor,
            $company,
            $driver->getKey(),
        );
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
            throw $this->forForm($exception);
        }
    }

    private function forForm(ValidationException $exception, ?string $statePath = null): ValidationException
    {
        $statePath ??= $this->form->getStatePath();
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            $errors[filled($statePath) ? "{$statePath}.{$field}" : $field] = $messages;
        }

        return ValidationException::withMessages($errors);
    }
}
