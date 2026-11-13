<?php

namespace App\Filament\Resources\Drivers\Pages;

use App\Filament\Resources\Drivers\DriverResource;
use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use App\Services\DriverAttendanceService;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class DriverActivity extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DriverResource::class;

    protected string $view = 'filament.resources.drivers.pages.driver-activity';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(DriverResource::canViewAttendance($this->getRecord()), 403);

        $today = CarbonImmutable::now($this->company()->timezone)->toDateString();
        $this->form->fill([
            'from_date' => $today,
            'until_date' => $today,
        ]);
    }

    public function hydrate(): void
    {
        abort_unless(DriverResource::canViewAttendance($this->getRecord()), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Recorded Driver Activity');
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label(__('View Driver')),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                DatePicker::make('from_date')
                    ->label(__('From Date'))
                    ->required(),
                DatePicker::make('until_date')
                    ->label(__('Until Date'))
                    ->required()
                    ->afterOrEqual('from_date'),
            ])
            ->statePath('data');
    }

    public function generateReport(): void
    {
        $data = $this->form->getState();
        $actor = Filament::auth()->user();
        $driver = $this->getRecord();

        abort_unless($actor instanceof User && $driver instanceof Driver, 403);

        $company = $this->company();
        $this->report = app(DriverAttendanceService::class)->summarize(
            $actor,
            $company,
            $driver,
            CarbonImmutable::parse($data['from_date'], $company->timezone),
            CarbonImmutable::parse($data['until_date'], $company->timezone),
        );
    }

    private function company(): Company
    {
        $company = Filament::getTenant();

        abort_unless($company instanceof Company, 403);

        return $company;
    }
}
