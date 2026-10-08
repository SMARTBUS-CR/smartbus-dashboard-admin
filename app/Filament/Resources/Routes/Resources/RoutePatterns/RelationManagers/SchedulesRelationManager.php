<?php

namespace App\Filament\Resources\Routes\Resources\RoutePatterns\RelationManagers;

use App\Filament\Resources\Routes\Resources\RoutePatterns\RoutePatternResource;
use App\Models\Company;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use App\Models\RouteScheduleException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'schedules';

    public static function getTitle(
        Model $ownerRecord,
        string $pageClass,
    ): string {
        return __('Schedules');
    }

    public static function canViewForRecord(
        Model $ownerRecord,
        string $pageClass,
    ): bool {
        $company = Filament::getTenant();

        if (
            ! $ownerRecord instanceof RoutePattern
            || ! $company instanceof Company
        ) {
            return false;
        }

        $route = $ownerRecord->route()->withTrashed()->first();

        if (
            ! $route
            || (string) $route->company_id !== (string) $company->getKey()
        ) {
            return false;
        }

        return RoutePatternResource::canView($ownerRecord);
    }

    public function mount(): void
    {
        abort_unless(
            static::canViewForRecord(
                $this->getOwnerRecord(),
                $this->getPageClass(),
            ),
            403,
        );

        parent::mount();
    }

    public function dehydrate(): void
    {
        // Detach action state references before Livewire serializes the component.
        $this->mountedActions = array_map(
            static fn (array $action): array => [...$action],
            $this->mountedActions,
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('day_of_week')
                ->label(__('Day'))
                ->options([
                    1 => __('Monday'),
                    2 => __('Tuesday'),
                    3 => __('Wednesday'),
                    4 => __('Thursday'),
                    5 => __('Friday'),
                    6 => __('Saturday'),
                    0 => __('Sunday'),
                ])
                ->required(),

            TimePicker::make('departure_time')
                ->label(__('Departure Time'))
                ->seconds(false)
                ->format('H:i:s')
                ->timezone(config('app.timezone'))
                ->required()
                ->rules(['date_format:H:i,H:i:s']),

            DatePicker::make('valid_from')
                ->label(__('Valid From'))
                ->helperText(__('Leave blank if there is no start date.'))
                ->live()
                ->rules(['nullable', 'date']),

            DatePicker::make('valid_until')
                ->label(__('Valid Until'))
                ->helperText(__('Leave blank if there is no end date.'))
                ->rules(['nullable', 'date'])
                ->afterOrEqual(
                    fn (Get $get): string => filled($get('valid_from'))
                        ? 'valid_from'
                        : '',
                ),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(fn (): string => __(
                'Departure times use the company timezone: :timezone.',
                [
                    'timezone' => $this->getOwnerRecord()
                        ->route()
                        ->withTrashed()
                        ->firstOrFail()
                        ->company
                        ->timezone,
                ],
            ))
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query
                    ->orderBy('day_of_week')
                    ->orderBy('departure_time'),
            )
            ->columns([
                TextColumn::make('day_of_week')
                    ->label(__('Day'))
                    ->formatStateUsing(
                        fn (int $state): string => match ($state) {
                            0 => __('Sunday'),
                            1 => __('Monday'),
                            2 => __('Tuesday'),
                            3 => __('Wednesday'),
                            4 => __('Thursday'),
                            5 => __('Friday'),
                            6 => __('Saturday'),
                        },
                    ),

                TextColumn::make('departure_time')
                    ->label(__('Departure Time'))
                    ->formatStateUsing(
                        fn (string $state): string => substr($state, 0, 5),
                    ),

                TextColumn::make('valid_from')
                    ->label(__('Valid From'))
                    ->date()
                    ->placeholder(__('No Start Date')),

                TextColumn::make('valid_until')
                    ->label(__('Valid Until'))
                    ->date()
                    ->placeholder(__('No End Date')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add Departure'))
                    ->modalHeading(__('Add Departure'))
                    ->modalDescription(__(
                        'This departure repeats every week on the selected day during its validity period.',
                    ))
                    ->modalSubmitActionLabel(__('Add Departure'))
                    ->authorize(fn (): bool => $this->canManageSchedules())
                    ->using(
                        fn (array $data): RouteSchedule => $this->createSchedule($data),
                    )
                    ->successNotificationTitle(__('Departure Added')),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('Edit Departure'))
                    ->modalHeading(__('Edit Departure'))
                    ->modalSubmitActionLabel(__('Save Changes'))
                    ->authorize(fn (): bool => $this->canManageSchedules())
                    ->using(
                        fn (RouteSchedule $record, array $data): RouteSchedule => $this->updateSchedule($record, $data),
                    )
                    ->successNotificationTitle(__('Departure Updated')),
                Action::make('viewSuspensions')
                    ->label(__('View Suspensions'))
                    ->modalHeading(__('Suspensions'))
                    ->modalDescription(__(
                        'This departure will not operate on the listed dates.',
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->authorize(fn (): bool => static::canViewForRecord(
                        $this->getOwnerRecord(),
                        $this->getPageClass(),
                    ))
                    ->schema(fn (RouteSchedule $record): array => [
                        TextEntry::make('suspended_dates')
                            ->label(__('Suspended Dates'))
                            ->state(
                                fn (): array => $record->exceptions()
                                    ->orderBy('service_date')
                                    ->get()
                                    ->map(
                                        fn (RouteScheduleException $exception): string =>
                                            $exception->service_date
                                                ->locale(app()->getLocale())
                                                ->isoFormat('LL'),
                                    )
                                    ->all(),
                            )
                            ->listWithLineBreaks()
                            ->placeholder(__('No Suspensions')),
                    ]),
                Action::make('suspend')
                    ->label(__('Suspend Departure'))
                    ->modalHeading(__('Suspend Departure'))
                    ->modalDescription(__(
                        'Only the selected date will be suspended. The weekly schedule will remain unchanged.',
                    ))
                    ->modalSubmitActionLabel(__('Suspend Departure'))
                    ->color('warning')
                    ->authorize(fn (): bool => $this->canManageSchedules())
                    ->schema(fn (RouteSchedule $record): array => [
                        DatePicker::make('service_date')
                            ->label(__('Suspension Date'))
                            ->helperText(__(
                                'Choose a date when this departure is scheduled to operate.',
                            ))
                            ->required()
                            ->rules([
                                'date',
                                Rule::unique(RouteScheduleException::class, 'service_date')
                                    ->where('route_schedule_id', $record->getKey()),
                            ]),
                    ])
                    ->action(function (RouteSchedule $record, array $data): void {
                        $this->createSuspension($record, $data);

                        Notification::make()
                            ->success()
                            ->title(__('Departure Suspended'))
                            ->send();
                    }),
                Action::make('resume')
                    ->label(__('Resume Departure'))
                    ->modalHeading(__('Resume Departure'))
                    ->modalDescription(__(
                        'Remove the suspension for the selected date. Other suspensions will remain unchanged.',
                    ))
                    ->modalSubmitActionLabel(__('Resume Departure'))
                    ->color('success')
                    ->authorize(fn (): bool => $this->canManageSchedules())
                    ->disabled(
                        fn (RouteSchedule $record): bool => ! $record->exceptions()->exists(),
                    )
                    ->schema(fn (RouteSchedule $record): array => [
                        Select::make('exception_id')
                            ->label(__('Suspended Date'))
                            ->options(
                                fn (): array => $record->exceptions()
                                    ->orderBy('service_date')
                                    ->get()
                                    ->mapWithKeys(
                                        fn (RouteScheduleException $exception): array => [
                                            $exception->getKey() => $exception->service_date
                                                ->locale(app()->getLocale())
                                                ->isoFormat('LL'),
                                        ],
                                    )
                                    ->all(),
                            )
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (RouteSchedule $record, array $data): void {
                        $this->resumeDeparture($record, $data);

                        Notification::make()
                            ->success()
                            ->title(__('Departure Resumed'))
                            ->send();
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateHeading(__('No Schedules Configured'))
            ->emptyStateDescription(__(
                'Add weekly departures to define when this pattern operates.',
            ));
    }

    private function canManageSchedules(): bool
    {
        $pattern = $this->getOwnerRecord();

        return ! $this->isReadOnly()
            && ! $pattern->trashed()
            && $pattern->route()->exists()
            && RoutePatternResource::canEdit($pattern);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createSchedule(array $data): RouteSchedule
    {
        try {
            return DB::transaction(function () use ($data): RouteSchedule {
                $pattern = RoutePattern::query()
                    ->lockForUpdate()
                    ->findOrFail($this->getOwnerRecord()->getKey());

                $this->validateScheduleOverlap($pattern, $data);

                return $pattern->schedules()->create([
                    'day_of_week' => $data['day_of_week'],
                    'departure_time' => $data['departure_time'],
                    'valid_from' => $data['valid_from'] ?? null,
                    'valid_until' => $data['valid_until'] ?? null,
                ]);
            });
        } catch (ValidationException $exception) {
            $this->throwFormValidationException($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function updateSchedule(
        RouteSchedule $record,
        array $data,
    ): RouteSchedule {
        try {
            return DB::transaction(function () use ($record, $data): RouteSchedule {
                $pattern = RoutePattern::query()
                    ->lockForUpdate()
                    ->findOrFail($this->getOwnerRecord()->getKey());

                $schedule = $pattern->schedules()
                    ->lockForUpdate()
                    ->findOrFail($record->getKey());

                $this->validateScheduleOverlap($pattern, $data, $schedule);

                $schedule->update([
                    'day_of_week' => $data['day_of_week'],
                    'departure_time' => $data['departure_time'],
                    'valid_from' => $data['valid_from'] ?? null,
                    'valid_until' => $data['valid_until'] ?? null,
                ]);

                return $schedule;
            });
        } catch (ValidationException $exception) {
            $this->throwFormValidationException($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createSuspension(
        RouteSchedule $record,
        array $data,
    ): RouteScheduleException {
        try {
            return DB::transaction(function () use ($record, $data): RouteScheduleException {
                $pattern = RoutePattern::query()
                    ->lockForUpdate()
                    ->findOrFail($this->getOwnerRecord()->getKey());

                $schedule = $pattern->schedules()
                    ->lockForUpdate()
                    ->findOrFail($record->getKey());

                Validator::make([
                    'service_date' => $data['service_date'] ?? null,
                ], [
                    'service_date' => [
                        'required',
                        'date',
                        Rule::unique(RouteScheduleException::class, 'service_date')
                            ->where('route_schedule_id', $schedule->getKey()),
                    ],
                ])->validate();

                return $schedule->exceptions()->create([
                    'service_date' => $data['service_date'],
                ]);
            });
        } catch (ValidationException $exception) {
            $this->throwFormValidationException($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resumeDeparture(
        RouteSchedule $record,
        array $data,
    ): void {
        try {
            DB::transaction(function () use ($record, $data): void {
                $pattern = RoutePattern::query()
                    ->lockForUpdate()
                    ->findOrFail($this->getOwnerRecord()->getKey());

                $schedule = $pattern->schedules()
                    ->lockForUpdate()
                    ->findOrFail($record->getKey());

                Validator::make([
                    'exception_id' => $data['exception_id'] ?? null,
                ], [
                    'exception_id' => [
                        'required',
                        'uuid',
                        Rule::exists(RouteScheduleException::class, 'id')
                            ->where('route_schedule_id', $schedule->getKey()),
                    ],
                ])->validate();

                $suspension = $schedule->exceptions()
                    ->lockForUpdate()
                    ->findOrFail($data['exception_id']);

                $suspension->delete();
            });
        } catch (ValidationException $exception) {
            $this->throwFormValidationException($exception);
        }
    }

    protected function throwFormValidationException(
        ValidationException $exception,
    ): never {
        $statePath = $this->getMountedActionSchema()?->getStatePath();
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            $field = $field === 'route_schedule_id'
                ? 'day_of_week'
                : $field;

            $key = filled($statePath)
                ? "{$statePath}.{$field}"
                : $field;

            $errors[$key] = $messages;
        }

        throw ValidationException::withMessages($errors);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateScheduleOverlap(
        RoutePattern $pattern,
        array $data,
        ?RouteSchedule $record = null,
    ): void {
        $validFrom = $data['valid_from'] ?? null;
        $validUntil = $data['valid_until'] ?? null;

        $conflictingSchedules = $pattern->schedules()
            ->where('day_of_week', $data['day_of_week'])
            ->where('departure_time', $data['departure_time']);

        if ($record !== null) {
            $conflictingSchedules->whereKeyNot($record->getKey());
        }

        if (filled($validFrom)) {
            $conflictingSchedules->where(function (Builder $query) use ($validFrom): void {
                $query
                    ->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $validFrom);
            });
        }

        if (filled($validUntil)) {
            $conflictingSchedules->where(function (Builder $query) use ($validUntil): void {
                $query
                    ->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $validUntil);
            });
        }

        if ($conflictingSchedules->exists()) {
            throw ValidationException::withMessages([
                'departure_time' => __(
                    'A departure already exists for this day and time during the selected validity period.',
                ),
            ]);
        }
    }
}
