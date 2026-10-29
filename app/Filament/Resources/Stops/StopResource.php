<?php

namespace App\Filament\Resources\Stops;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Stops\Pages\CreateStop;
use App\Filament\Resources\Stops\Pages\EditStop;
use App\Filament\Resources\Stops\Pages\ListStops;
use App\Filament\Resources\Stops\Pages\ViewStop;
use App\Filament\Resources\Stops\Schemas\StopForm;
use App\Filament\Resources\Stops\Schemas\StopInfolist;
use App\Filament\Resources\Stops\Tables\StopsTable;
use App\Models\Company;
use App\Models\Stop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StopResource extends Resource
{
    protected static ?string $model = Stop::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Transport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::MapPin;

    protected static bool $isScopedToTenant = false;

    public static function form(Schema $schema): Schema
    {
        return StopForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StopsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StopInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStops::route('/'),
            'create' => CreateStop::route('/create'),
            'view' => ViewStop::route('/{record}'),
            'edit' => EditStop::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Stops');
    }

    public static function getModelLabel(): string
    {
        return __('Stop');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stops');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($company): void {
            $query
                ->where('company_id', $company->getKey())
                ->orWhereNull('company_id');
        });
    }
}
