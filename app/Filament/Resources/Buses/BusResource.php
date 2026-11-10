<?php

namespace App\Filament\Resources\Buses;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Buses\Pages\CreateBus;
use App\Filament\Resources\Buses\Pages\EditBus;
use App\Filament\Resources\Buses\Pages\ListBuses;
use App\Filament\Resources\Buses\Pages\ViewBus;
use App\Filament\Resources\Buses\Schemas\BusForm;
use App\Filament\Resources\Buses\Schemas\BusInfolist;
use App\Filament\Resources\Buses\Tables\BusesTable;
use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class BusResource extends Resource
{
    protected static ?string $model = Bus::class;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Transport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Truck;

    protected static ?string $recordTitleAttribute = 'unit_number';

    public static function form(Schema $schema): Schema
    {
        return BusForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BusInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BusesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBuses::route('/'),
            'create' => CreateBus::route('/create'),
            'view' => ViewBus::route('/{record}'),
            'edit' => EditBus::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Fleet');
    }

    public static function getModelLabel(): string
    {
        return __('Bus');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Buses');
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
        return $record instanceof Bus && static::allows('view', $record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Bus && ! $record->trashed() && static::allows('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Bus && ! $record->trashed() && static::allows('delete', $record);
    }

    public static function canRestore(Model $record): bool
    {
        return $record instanceof Bus && $record->trashed() && static::allows('restore', $record);
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

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('company_id', $company->getKey());
    }

    private static function allows(string $ability, ?Bus $bus = null): bool
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();

        if (! $actor instanceof User || ! $company instanceof Company) {
            return false;
        }

        $arguments = [Bus::class, $company];

        if ($bus instanceof Bus) {
            $arguments[] = $bus;
        }

        return Gate::forUser($actor)->allows($ability, $arguments);
    }
}
