<?php

namespace App\Filament\Resources\Routes\Resources\RoutePatterns\RelationManagers;

use App\Enums\LucideIcon;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RoutePatternResource;
use App\Filament\Resources\Stops\Schemas\StopForm;
use App\Filament\Resources\Stops\StopResource;
use App\Filament\Support\TableSectionHeader;
use App\Models\Company;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use App\Services\RoutePatternStopService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class StopOccurrencesRelationManager extends RelationManager
{
    protected static string $relationship = 'stopOccurrences';

    protected static string|\BackedEnum|null $icon = LucideIcon::BusFront;

    public static function getTitle(
        Model $ownerRecord,
        string $pageClass,
    ): string {
        return __('Stops');
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

    #[On('route-pattern-stops-updated.{ownerRecord.id}')]
    public function refreshStops(): void
    {
        $this->getOwnerRecord()->refresh();

        $this->flushCachedTableRecords();
    }

    public function form(Schema $schema): Schema
    {
        $companyId = Filament::getTenant()?->getKey();

        return $schema
            ->columns(1)
            ->components([
                Select::make('stop_id')
                    ->label(__('Stop'))
                    ->prefixIcon(LucideIcon::BusFront)
                    ->helperText(__('Choose a company stop or a shared stop.'))
                    ->relationship(
                        name: 'stop',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query
                            ->where(function (Builder $query) use ($companyId): void {
                                $query
                                    ->where('company_id', $companyId)
                                    ->orWhereNull('company_id');
                            }),
                    )
                    ->searchable()
                    ->createOptionForm(
                        fn (Schema $schema): Schema => StopForm::configure(
                            $schema->operation('create'),
                            compact: true,
                        ),
                    )
                    ->createOptionModalHeading(__('Create Stop'))
                    ->createOptionAction(
                        fn (Action $action): Action => $action
                            ->label(__('Create Stop'))
                            ->color('success')
                            ->modalDescription(__(
                                'Enter the stop details and select its boarding point. The stop will be available in the company catalog.',
                            ))
                            ->modalIcon(LucideIcon::BusFront)
                            ->modalIconColor('success')
                            ->modalAlignment(Alignment::Start)
                            ->modalWidth(Width::ThreeExtraLarge)
                            ->authorize(
                                fn (): bool => $this->canManageStops()
                                    && StopResource::canCreate(),
                            ),
                    )
                    ->createOptionUsing(
                        fn (array $data): string => $this->createCatalogStop($data),
                    )
                    ->required()
                    ->rules([
                        Rule::exists(Stop::class, 'id')
                            ->whereNull('deleted_at')
                            ->where(function (QueryBuilder $query) use ($companyId): void {
                                $query
                                    ->where('company_id', $companyId)
                                    ->orWhereNull('company_id');
                            }),
                    ]),

                TextInput::make('minutes_from_start')
                    ->label(__('Minutes From Departure'))
                    ->prefixIcon(Heroicon::OutlinedClock)
                    ->helperText(__('Leave blank if the estimate is unknown.'))
                    ->numeric()
                    ->suffix(' '.__('min'))
                    ->rules([
                        'nullable',
                        'integer',
                        'min:0',
                        'max:2147483647',
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(TableSectionHeader::heading(
                __('Stops'),
                LucideIcon::BusFront,
            ))
            ->description(fn () => TableSectionHeader::description(
                $this->canManageStops()
                ? __('Reordering stops clears all estimated minutes from departure and invalidates the calculated route. Arrange the stops first, then enter their estimated times and calculate the route again.')
                : null,
            ))
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query->with('stop'),
            )
            ->columns([
                TextColumn::make('stop_sequence')
                    ->label(__('Position')),

                TextColumn::make('stop.name')
                    ->label(__('Stop')),

                TextColumn::make('minutes_from_start')
                    ->label(__('Minutes From Departure'))
                    ->suffix(' '.__('min'))
                    ->placeholder(__('Not Set')),
            ])
            ->defaultSort('stop_sequence')
            ->reorderable('stop_sequence')
            ->authorizeReorder(fn (): bool => $this->canManageStops())
            ->paginatedWhileReordering(false)
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add Stop'))
                    ->modalHeading(__('Add Stop'))
                    ->modalDescription(__(
                        'The stop will be added at the end of this pattern.'
                    ))
                    ->modalIcon(LucideIcon::BusFront)
                    ->modalIconColor('success')
                    ->modalAlignment(Alignment::Start)
                    ->modalWidth(Width::Large)
                    ->modalSubmitActionLabel(__('Add Stop'))
                    ->after(fn () => $this->notifyPatternStopsChanged())
                    ->authorize(fn (): bool => $this->canManageStops())
                    ->using(fn (array $data): RoutePatternStop => $this->createOccurrence($data)
                    )
                    ->successNotificationTitle(__('Stop Added')),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('Edit Stop'))
                    ->modalDescription(__(
                        'Update the stop and its estimated minutes from departure.',
                    ))
                    ->modalIcon(Heroicon::OutlinedPencilSquare)
                    ->modalIconColor('primary')
                    ->modalAlignment(Alignment::Start)
                    ->modalWidth(Width::Large)
                    ->modalHeading(__('Edit Stop'))
                    ->authorize(fn (): bool => $this->canManageStops())
                    ->using(fn (RoutePatternStop $record, array $data): RoutePatternStop => $this->updateOccurrence($record, $data)
                    )
                    ->successNotificationTitle(__('Stop Updated')),
                DeleteAction::make()
                    ->label(__('Remove Stop'))
                    ->modalHeading(__('Remove Stop'))
                    ->modalDescription(__(
                        'Only this occurrence will be removed. The stop will remain in the catalog.'
                    ))
                    ->after(fn () => $this->notifyPatternStopsChanged())
                    ->modalSubmitActionLabel(__('Remove Stop'))
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => $this->canManageStops())
                    ->using(fn (RoutePatternStop $record): bool => $this->removeOccurrence($record)
                    )
                    ->successNotificationTitle(__('Stop Removed')),
            ])
            ->emptyStateHeading(__('No Stops Configured'))
            ->emptyStateDescription(__(
                'Stops will appear here in their travel order.'
            ));
    }

    /**
     * @param  array<int|string>  $order
     */
    public function reorderTable(
        array $order,
        int|string|null $draggedRecordKey = null,
    ): void {
        abort_unless($this->canManageStops(), 403);

        try {
            app(RoutePatternStopService::class)->reorder(
                $this->getOwnerRecord(),
                $order,
            );
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title(__('Could Not Reorder Stops'))
                ->body(
                    collect($exception->errors())
                        ->flatten()
                        ->first()
                    ?? __('The stops could not be reordered. Please try again.')
                )
                ->send();

            return;
        }

        $this->notifyPatternStopsChanged();

        Notification::make()
            ->success()
            ->title(__('Stops Reordered'))
            ->send();
    }

    private function canManageStops(): bool
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
    protected function createOccurrence(array $data): RoutePatternStop
    {
        try {
            return DB::transaction(function () use ($data): RoutePatternStop {
                $pattern = RoutePattern::query()
                    ->lockForUpdate()
                    ->findOrFail($this->getOwnerRecord()->getKey());

                $nextSequence = (
                    $pattern->stopOccurrences()->max('stop_sequence') ?? 0
                ) + 1;

                return $pattern->stopOccurrences()->create([
                    'stop_id' => $data['stop_id'],
                    'stop_sequence' => $nextSequence,
                    'minutes_from_start' => $data['minutes_from_start'] ?? null,
                ]);
            });
        } catch (ValidationException $exception) {
            $this->throwFormValidationException($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function updateOccurrence(
        RoutePatternStop $record,
        array $data,
    ): RoutePatternStop {
        try {
            $occurrence = DB::transaction(function () use ($record, $data): RoutePatternStop {
                $pattern = RoutePattern::query()
                    ->lockForUpdate()
                    ->findOrFail($this->getOwnerRecord()->getKey());

                $occurrence = $pattern->stopOccurrences()
                    ->findOrFail($record->getKey());

                $occurrence->update([
                    'stop_id' => $data['stop_id'],
                    'minutes_from_start' => $data['minutes_from_start'] ?? null,
                ]);

                return $occurrence;
            });

            if ($occurrence->wasChanged('stop_id')) {
                $this->notifyPatternStopsChanged();
            }

            return $occurrence;
        } catch (ValidationException $exception) {
            $this->throwFormValidationException($exception);
        }
    }

    protected function removeOccurrence(RoutePatternStop $record): bool
    {
        return DB::transaction(function () use ($record): bool {
            $pattern = RoutePattern::query()
                ->lockForUpdate()
                ->findOrFail($this->getOwnerRecord()->getKey());

            $occurrence = $pattern->stopOccurrences()
                ->findOrFail($record->getKey());

            return (bool) $occurrence->delete();
        });
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

    private function notifyPatternStopsChanged(): void
    {
        $this->dispatch(
            'route-pattern-stops-changed.'.$this->getOwnerRecord()->getKey(),
        )->to(EditRoutePattern::class);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createCatalogStop(array $data): string
    {
        abort_unless(
            $this->canManageStops() && StopResource::canCreate(),
            403,
        );

        $company = Filament::getTenant();
        $actor = Filament::auth()->user();

        abort_unless(
            $company instanceof Company
            && $actor?->canAccessTenant($company),
            403,
        );

        $isShared = ($actor->isSuperAdmin())
            && (bool) ($data['is_shared'] ?? false);

        $stop = Stop::create([
            'company_id' => $isShared ? null : $company->getKey(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
        ]);

        return (string) $stop->getKey();
    }
}
