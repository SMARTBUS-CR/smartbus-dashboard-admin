<?php

namespace App\Filament\Resources\Drivers\RelationManagers;

use App\Filament\Resources\Drivers\DriverResource;
use App\Models\Company;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Models\User;
use App\Services\DriverScheduleService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'schedules';

    /** @var array<int, string> */
    private const WEEKDAYS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Working Availability');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();

        return $ownerRecord instanceof Driver
            && $actor instanceof User
            && $company instanceof Company
            && (string) $ownerRecord->company_id === (string) $company->getKey()
            && DriverResource::canView($ownerRecord)
            && Gate::forUser($actor)->allows('viewAny', [DriverSchedule::class, $company]);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);

        parent::mount();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('day_of_week')
                    ->label(__('Weekday'))
                    ->options(collect(self::WEEKDAYS)->mapWithKeys(
                        fn (string $day, int $value): array => [$value => __($day)],
                    )->all())
                    ->required(),
                Toggle::make('ends_next_day')
                    ->label(__('Ends Next Day'))
                    ->helperText(__('Enable this for overnight or complete local-day availability.'))
                    ->live(),
                TimePicker::make('start_time')
                    ->label(__('Start Time'))
                    ->timezone(config('app.timezone'))
                    ->seconds()
                    ->required(),
                TimePicker::make('end_time')
                    ->label(__('End Time'))
                    ->timezone(config('app.timezone'))
                    ->seconds()
                    ->required()
                    ->helperText(fn (Get $get): string => $get('ends_next_day')
                        ? __('The end time may be earlier than or equal to the start time.')
                        : __('The end time must be later than the start time.')),
                DatePicker::make('valid_from')
                    ->label(__('Valid From'))
                    ->helperText(__('Leave blank when there is no starting boundary.')),
                DatePicker::make('valid_until')
                    ->label(__('Valid Until'))
                    ->helperText(__('Leave blank when there is no ending boundary.'))
                    ->afterOrEqual(fn (Get $get): string => filled($get('valid_from')) ? 'valid_from' : ''),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('day_of_week')
                    ->label(__('Weekday'))
                    ->formatStateUsing(fn (int $state): string => __(self::WEEKDAYS[$state])),
                TextColumn::make('start_time')
                    ->label(__('Start Time')),
                TextColumn::make('end_time')
                    ->label(__('End Time')),
                IconColumn::make('ends_next_day')
                    ->label(__('Overnight'))
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('valid_from')
                    ->label(__('Valid From'))
                    ->date()
                    ->placeholder(__('Unbounded'))
                    ->toggleable(),
                TextColumn::make('valid_until')
                    ->label(__('Valid Until'))
                    ->date()
                    ->placeholder(__('Unbounded'))
                    ->toggleable(),
            ])
            ->defaultSort('day_of_week')
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add Availability'))
                    ->authorize(fn (): bool => $this->canCreateSchedule())
                    ->using(fn (array $data): DriverSchedule => $this->createSchedule($data))
                    ->successNotificationTitle(__('Availability Added')),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('Edit Availability'))
                    ->authorize(fn (DriverSchedule $record): bool => $this->canUpdateSchedule($record))
                    ->using(fn (DriverSchedule $record, array $data): DriverSchedule => $this->updateSchedule($record, $data))
                    ->successNotificationTitle(__('Availability Updated')),
                DeleteAction::make()
                    ->label(__('Delete Availability'))
                    ->authorize(fn (DriverSchedule $record): bool => $this->canDeleteSchedule($record))
                    ->using(fn (DriverSchedule $record, DeleteAction $action): bool => $this->deleteSchedule($record, $action))
                    ->successNotificationTitle(__('Availability Deleted')),
            ])
            ->toolbarActions([])
            ->emptyStateHeading(__('No Working Availability'))
            ->emptyStateDescription(__('Add the local weekly intervals when this driver may be assigned to Trips.'));
    }

    private function canCreateSchedule(): bool
    {
        [$actor, $company, $driver] = $this->context();

        return ! $this->isReadOnly()
            && ! $driver->trashed()
            && Gate::forUser($actor)->allows('create', [DriverSchedule::class, $company, $driver]);
    }

    private function canUpdateSchedule(DriverSchedule $schedule): bool
    {
        [$actor, $company, $driver] = $this->context();

        return ! $this->isReadOnly()
            && ! $driver->trashed()
            && Gate::forUser($actor)->allows('update', [DriverSchedule::class, $company, $schedule]);
    }

    private function canDeleteSchedule(DriverSchedule $schedule): bool
    {
        [$actor, $company, $driver] = $this->context();

        return ! $this->isReadOnly()
            && ! $driver->trashed()
            && Gate::forUser($actor)->allows('delete', [DriverSchedule::class, $company, $schedule]);
    }

    /** @param array<string, mixed> $data */
    private function createSchedule(array $data): DriverSchedule
    {
        [$actor, $company, $driver] = $this->context();

        try {
            return app(DriverScheduleService::class)->create($actor, $company, $driver, $data);
        } catch (ValidationException $exception) {
            $this->throwFormValidationException($exception);
        }
    }

    /** @param array<string, mixed> $data */
    private function updateSchedule(DriverSchedule $schedule, array $data): DriverSchedule
    {
        [$actor, $company, $driver] = $this->context();

        try {
            return app(DriverScheduleService::class)->update($actor, $company, $driver, $schedule, $data);
        } catch (ValidationException $exception) {
            $this->throwFormValidationException($exception);
        }
    }

    private function deleteSchedule(DriverSchedule $schedule, Action $action): bool
    {
        [$actor, $company, $driver] = $this->context();

        try {
            app(DriverScheduleService::class)->delete($actor, $company, $driver, $schedule);

            return true;
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title(__('Availability Change Rejected'))
                ->body(collect($exception->errors())->flatten()->first())
                ->send();
            $action->halt();

            return false;
        }
    }

    /** @return array{User, Company, Driver} */
    private function context(): array
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();
        $driver = $this->getOwnerRecord();

        abort_unless($actor instanceof User && $company instanceof Company && $driver instanceof Driver, 403);

        return [$actor, $company, $driver];
    }

    private function throwFormValidationException(ValidationException $exception): never
    {
        $statePath = $this->getMountedActionSchema()?->getStatePath();
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            $errors[filled($statePath) ? "{$statePath}.{$field}" : $field] = $messages;
        }

        throw ValidationException::withMessages($errors);
    }
}
