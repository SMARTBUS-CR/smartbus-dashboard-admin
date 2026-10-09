<?php

namespace App\Filament\Resources\Routes;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Routes\Pages\CreateRoute;
use App\Filament\Resources\Routes\Pages\EditRoute;
use App\Filament\Resources\Routes\Pages\ListRoutes;
use App\Filament\Resources\Routes\Pages\ViewRoute;
use App\Filament\Resources\Routes\RelationManagers\FaresRelationManager;
use App\Filament\Resources\Routes\RelationManagers\PatternsRelationManager;
use App\Filament\Resources\Routes\Schemas\RouteForm;
use App\Filament\Resources\Routes\Schemas\RouteInfolist;
use App\Filament\Resources\Routes\Tables\RoutesTable;
use App\Models\Company;
use App\Models\Route;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class RouteResource extends Resource
{
    protected static ?string $model = Route::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Transport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Map;

    public static function form(Schema $schema): Schema
    {
        return RouteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoutesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RouteInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            'patterns' => PatternsRelationManager::class,
            'fares' => FaresRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoutes::route('/'),
            'create' => CreateRoute::route('/create'),
            'view' => ViewRoute::route('/{record}'),
            'edit' => EditRoute::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Routes');
    }

    public static function getModelLabel(): string
    {
        return __('Route');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Routes');
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

        if (! Filament::getTenant() instanceof Company) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
    }
}
