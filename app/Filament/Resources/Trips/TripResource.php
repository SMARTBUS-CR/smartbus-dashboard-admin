<?php

namespace App\Filament\Resources\Trips;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Trips\Pages\CreateTrip;
use App\Filament\Resources\Trips\Pages\EditTrip;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Filament\Resources\Trips\Schemas\TripForm;
use App\Filament\Resources\Trips\Tables\TripsTable;
use App\Models\Company;
use App\Models\Trip;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class TripResource extends Resource
{
    protected static ?string $model = Trip::class;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Transport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return TripForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TripsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Trip Planning');
    }

    public static function getModelLabel(): string
    {
        return __('Trip');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Trips');
    }

    public static function canViewAny(): bool
    {
        return static::allows('viewAny');
    }

    public static function canCreate(): bool
    {
        return static::allows('create');
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Trip && static::allows('view', $record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Trip && static::allows('update', $record);
    }

    public static function canStart(Model $record): bool
    {
        return $record instanceof Trip && static::allows('start', $record);
    }

    public static function canComplete(Model $record): bool
    {
        return $record instanceof Trip && static::allows('complete', $record);
    }

    public static function canCancel(Model $record): bool
    {
        return $record instanceof Trip && static::allows('cancel', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrips::route('/'),
            'create' => CreateTrip::route('/create'),
            'edit' => EditTrip::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('company_id', $company->getKey())
            ->with(['company', 'pattern', 'schedule', 'bus', 'driver']);
    }

    private static function allows(string $ability, ?Trip $trip = null): bool
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();

        if (! $actor instanceof User || ! $company instanceof Company) {
            return false;
        }

        $arguments = [Trip::class, $company];

        if ($trip instanceof Trip) {
            $arguments[] = $trip;
        }

        return Gate::forUser($actor)->allows($ability, $arguments);
    }
}
