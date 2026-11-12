<?php

namespace App\Filament\Resources\Drivers\Pages;

use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Resources\Drivers\Schemas\DriverForm;
use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use App\Services\DriverReadService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewDriver extends ViewRecord
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('viewSalary')
                ->label(__('View Salary'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->authorize(fn (): bool => DriverResource::canViewSalary($this->getRecord()))
                ->schema(DriverForm::salaryFields(readOnly: true))
                ->fillForm(fn (): array => $this->salaryProjection())
                ->modalHeading(__('Confidential Salary'))
                ->modalDescription(__('Salary information is restricted to explicitly authorized company administrators.'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Close')),
        ];
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
}
