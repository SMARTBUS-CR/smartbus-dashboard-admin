<?php

namespace App\Filament\Resources\Routes\RelationManagers;

use App\Filament\Support\TableSectionHeader;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use App\Filament\Resources\Routes\RouteResource;
use App\Models\Company;
use App\Models\Route;
use App\Models\RouteFare;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FaresRelationManager extends RelationManager
{
    protected static string $relationship = 'fares';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedBanknotes;

    public static function getTitle(
        Model $ownerRecord,
        string $pageClass,
    ): string {
        return __('Fares');
    }

    public static function canViewForRecord(
        Model $ownerRecord,
        string $pageClass,
    ): bool {
        $company = Filament::getTenant();

        if (
            ! $ownerRecord instanceof Route
            || ! $company instanceof Company
            || (string) $ownerRecord->company_id !== (string) $company->getKey()
        ) {
            return false;
        }

        return RouteResource::canView($ownerRecord);
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
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->prefixIcon(Heroicon::OutlinedBanknotes)
                    ->helperText(__('Use zero only when travel is free.'))
                    ->numeric()
                    ->required()
                    ->rules([
                        'bail',
                        'numeric',
                        'decimal:0,2',
                        'min:0',
                        'max:9999999999.99',
                    ]),

                Select::make('currency')
                    ->label(__('Currency'))
                    ->prefixIcon(Heroicon::OutlinedCurrencyDollar)
                    ->options(array_combine(
                        RouteFare::SUPPORTED_CURRENCIES,
                        RouteFare::SUPPORTED_CURRENCIES,
                    ))
                    ->required(),

                DatePicker::make('valid_from')
                    ->label(__('Valid From'))
                    ->prefixIcon(Heroicon::OutlinedCalendarDays)
                    ->helperText(__('Leave blank if there is no start date.'))
                    ->live()
                    ->rules(['nullable', 'date']),

                DatePicker::make('valid_until')
                    ->label(__('Valid Until'))
                    ->prefixIcon(Heroicon::OutlinedCalendarDays)
                    ->helperText(__('Leave blank to keep this fare valid indefinitely.'))
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
            ->heading(TableSectionHeader::heading(
                __('Fares'),
                Heroicon::OutlinedBanknotes,
            ))
            ->description(TableSectionHeader::description(__(
                'A missing fare does not mean the trip is free. Configure a zero amount only when travel is free.',
            )))
            ->columns([
                TextColumn::make('amount')
                    ->label(__('Amount'))
                    ->numeric(decimalPlaces: 2),

                TextColumn::make('currency')
                    ->label(__('Currency'))
                    ->fontFamily(FontFamily::Mono)
                    ->color(Color::Gray)
                    ->badge(),

                TextColumn::make('valid_from')
                    ->label(__('Valid From'))
                    ->date()
                    ->placeholder(__('No Start Date')),

                TextColumn::make('valid_until')
                    ->label(__('Valid Until'))
                    ->date()
                    ->placeholder(__('No End Date')),
            ])
            ->defaultSort('currency')
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add Fare'))
                    ->modalHeading(__('Add Fare'))
                    ->modalIcon(Heroicon::OutlinedBanknotes)
                    ->modalIconColor('success')
                    ->modalAlignment(Alignment::Start)
                    ->modalWidth(Width::Large)
                    ->modalDescription(__(
                        'Only one fare per currency can apply on a given date.',
                    ))
                    ->modalSubmitActionLabel(__('Add Fare'))
                    ->authorize(fn (): bool => $this->canManageFares())
                    ->using(
                        fn (array $data): RouteFare => $this->createFare($data),
                    )
                    ->successNotificationTitle(__('Fare Added')),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('Edit Fare'))
                    ->modalHeading(__('Edit Fare'))
                    ->modalDescription(__(
                        'Only one fare per currency can apply on a given date.',
                    ))
                    ->modalIcon(Heroicon::OutlinedPencilSquare)
                    ->modalIconColor('primary')
                    ->modalAlignment(Alignment::Start)
                    ->modalWidth(Width::Large)
                    ->modalSubmitActionLabel(__('Save Changes'))
                    ->authorize(fn (): bool => $this->canManageFares())
                    ->using(
                        fn (RouteFare $record, array $data): RouteFare => $this->updateFare($record, $data),
                    )
                    ->successNotificationTitle(__('Fare Updated')),
            ])
            ->toolbarActions([])
            ->emptyStateHeading(__('No Fares Configured'))
            ->emptyStateDescription(__(
                'Add fares and their validity periods for this route.',
            ));
    }

    private function canManageFares(): bool
    {
        $route = $this->getOwnerRecord();

        return ! $this->isReadOnly()
            && ! $route->trashed()
            && RouteResource::canEdit($route);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createFare(array $data): RouteFare
    {
        try {
            return DB::transaction(function () use ($data): RouteFare {
                $route = Route::query()
                    ->lockForUpdate()
                    ->findOrFail($this->getOwnerRecord()->getKey());

                $this->validateFareOverlap($route, $data);

                return $route->fares()->create([
                    'amount' => $data['amount'],
                    'currency' => $data['currency'],
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
    protected function updateFare(
        RouteFare $record,
        array $data,
    ): RouteFare {
        try {
            return DB::transaction(function () use ($record, $data): RouteFare {
                $route = Route::query()
                    ->lockForUpdate()
                    ->findOrFail($this->getOwnerRecord()->getKey());

                $fare = $route->fares()
                    ->lockForUpdate()
                    ->findOrFail($record->getKey());

                $this->validateFareOverlap($route, $data, $fare);

                $fare->update([
                    'amount' => $data['amount'],
                    'currency' => $data['currency'],
                    'valid_from' => $data['valid_from'] ?? null,
                    'valid_until' => $data['valid_until'] ?? null,
                ]);

                return $fare;
            });
        } catch (ValidationException $exception) {
            $this->throwFormValidationException($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateFareOverlap(
        Route $route,
        array $data,
        ?RouteFare $record = null,
    ): void {
        $validFrom = $data['valid_from'] ?? null;
        $validUntil = $data['valid_until'] ?? null;

        $conflictingFares = $route->fares()
            ->where('currency', $data['currency']);

        if ($record !== null) {
            $conflictingFares->whereKeyNot($record->getKey());
        }

        if (filled($validFrom)) {
            $conflictingFares->where(function (Builder $query) use ($validFrom): void {
                $query
                    ->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $validFrom);
            });
        }

        if (filled($validUntil)) {
            $conflictingFares->where(function (Builder $query) use ($validUntil): void {
                $query
                    ->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $validUntil);
            });
        }

        $conflictingFare = $conflictingFares
            ->orderBy('valid_from')
            ->orderBy('id')
            ->first();

        if ($conflictingFare !== null) {
            throw ValidationException::withMessages([
                'currency' => $this->fareConflictMessage($conflictingFare),
            ]);
        }
    }

    protected function throwFormValidationException(
        ValidationException $exception,
    ): never {
        $statePath = $this->getMountedActionSchema()?->getStatePath();
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            $key = filled($statePath)
                ? "{$statePath}.{$field}"
                : $field;

            $errors[$key] = $messages;
        }

        throw ValidationException::withMessages($errors);
    }

    private function fareConflictMessage(RouteFare $fare): string
    {
        $locale = app()->getLocale();

        $from = $fare->valid_from
                ?->locale($locale)
            ->isoFormat('LL');

        $until = $fare->valid_until
                ?->locale($locale)
            ->isoFormat('LL');

        $period = match (true) {
            $from !== null && $until !== null => __(
                'from :from to :until',
                ['from' => $from, 'until' => $until],
            ),
            $from !== null => __(
                'from :from, with no end date',
                ['from' => $from],
            ),
            $until !== null => __(
                'with no start date, until :until',
                ['until' => $until],
            ),
            default => __('with no start or end date'),
        };

        return __(
            'A fare of :amount :currency (:period) already applies during the selected validity period.',
            [
                'amount' => number_format((float) $fare->amount, 2),
                'currency' => $fare->currency,
                'period' => $period,
            ],
        );
    }
}
