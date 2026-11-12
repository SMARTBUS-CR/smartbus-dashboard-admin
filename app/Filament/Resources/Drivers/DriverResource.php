<?php

namespace App\Filament\Resources\Drivers;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Drivers\Pages\CreateDriver;
use App\Filament\Resources\Drivers\Pages\DriverActivity;
use App\Filament\Resources\Drivers\Pages\EditDriver;
use App\Filament\Resources\Drivers\Pages\ListDrivers;
use App\Filament\Resources\Drivers\Pages\ViewDriver;
use App\Filament\Resources\Drivers\RelationManagers\SchedulesRelationManager;
use App\Filament\Resources\Drivers\Schemas\DriverForm;
use App\Filament\Resources\Drivers\Schemas\DriverInfolist;
use App\Filament\Resources\Drivers\Tables\DriversTable;
use App\Models\Company;
use App\Models\Driver;
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

class DriverResource extends Resource
{
    protected static ?string $model = Driver::class;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Transport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Identification;

    protected static ?string $recordTitleAttribute = 'license';

    public static function form(Schema $schema): Schema
    {
        return DriverForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DriverInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DriversTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            'schedules' => SchedulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDrivers::route('/'),
            'create' => CreateDriver::route('/create'),
            'view' => ViewDriver::route('/{record}'),
            'edit' => EditDriver::route('/{record}/edit'),
            'activity' => DriverActivity::route('/{record}/activity'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Driver Employment');
    }

    public static function getModelLabel(): string
    {
        return __('Driver');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Drivers');
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
        return $record instanceof Driver && static::allows('view', $record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Driver && ! $record->trashed() && static::allows('update', $record);
    }

    public static function canViewSalary(Model $record): bool
    {
        return $record instanceof Driver && static::allows('viewSalary', $record);
    }

    public static function canViewAttendance(Model $record): bool
    {
        return $record instanceof Driver && static::allows('viewAttendance', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Driver && ! $record->trashed() && static::allows('delete', $record);
    }

    public static function canRestore(Model $record): bool
    {
        return $record instanceof Driver && $record->trashed() && static::allows('restore', $record);
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

        return $query
            ->select([
                'id', 'company_id', 'user_id', 'license', 'license_expires_at',
                'employment_starts_on', 'employment_ends_on', 'status',
                'created_at', 'updated_at', 'deleted_at',
            ])
            ->where('company_id', $company->getKey())
            ->with('user');
    }

    private static function allows(string $ability, ?Driver $driver = null): bool
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();

        if (! $actor instanceof User || ! $company instanceof Company) {
            return false;
        }

        $arguments = [Driver::class, $company];

        if ($driver instanceof Driver) {
            $arguments[] = $driver;
        }

        return Gate::forUser($actor)->allows($ability, $arguments);
    }
}
