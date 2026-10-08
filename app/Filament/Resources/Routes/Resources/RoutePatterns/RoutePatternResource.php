<?php

namespace App\Filament\Resources\Routes\Resources\RoutePatterns;

use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Pages\ViewRoutePattern;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RelationManagers\SchedulesRelationManager;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RelationManagers\StopOccurrencesRelationManager;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Schemas\RoutePatternForm;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Schemas\RoutePatternInfolist;
use App\Filament\Resources\Routes\RouteResource;
use App\Models\Company;
use App\Models\RoutePattern;
use Filament\Facades\Filament;
use Filament\Resources\ParentResourceRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class RoutePatternResource extends Resource
{
    protected static ?string $model = RoutePattern::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isScopedToTenant = false;

    public static function getParentResourceRegistration(): ?ParentResourceRegistration
    {
        return RouteResource::asParent(childResource: static::class)
            ->relationship('patterns')
            ->inverseRelationship('route');
    }

    public static function form(Schema $schema): Schema
    {
        return RoutePatternForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RoutePatternInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewRoutePattern::route('/{record}'),
            'edit' => EditRoutePattern::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            'stops' => StopOccurrencesRelationManager::class,
            'schedules' => SchedulesRelationManager::class,
        ];
    }

    public static function getModelLabel(): string
    {
        return __('Pattern');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Patterns');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'route',
            fn (Builder $query): Builder => $query
                ->withTrashed()
                ->where('company_id', $company->getKey()),
        );
    }
}
