<?php

namespace App\Filament\Resources\TripAssignments;

use App\Enums\NavigationGroup;
use App\Filament\Resources\TripAssignments\Pages\CreateTripAssignment;
use App\Filament\Resources\TripAssignments\Pages\EditTripAssignment;
use App\Filament\Resources\TripAssignments\Pages\ListTripAssignments;
use App\Filament\Resources\TripAssignments\Pages\ViewTripAssignment;
use App\Filament\Resources\TripAssignments\Schemas\TripAssignmentForm;
use App\Filament\Resources\TripAssignments\Schemas\TripAssignmentInfolist;
use App\Filament\Resources\TripAssignments\Tables\TripAssignmentsTable;
use App\Models\Company;
use App\Models\TripAssignment;
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

class TripAssignmentResource extends Resource
{
    protected static ?string $model = TripAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Transport;

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return TripAssignmentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TripAssignmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TripAssignmentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Recurring Assignments');
    }

    public static function getModelLabel(): string
    {
        return __('Recurring Assignment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Recurring Assignments');
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
        return $record instanceof TripAssignment && static::allows('view', $record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof TripAssignment && ! $record->trashed() && static::allows('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof TripAssignment && ! $record->trashed() && static::allows('delete', $record);
    }

    public static function canRestore(Model $record): bool
    {
        return $record instanceof TripAssignment && $record->trashed() && static::allows('restore', $record);
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTripAssignments::route('/'),
            'create' => CreateTripAssignment::route('/create'),
            'view' => ViewTripAssignment::route('/{record}'),
            'edit' => EditTripAssignment::route('/{record}/edit'),
        ];
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
        $company = Filament::getTenant();

        return parent::getEloquentQuery()
            ->when(
                $company instanceof Company,
                fn (Builder $query): Builder => $query->where('company_id', $company->getKey()),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            )
            ->with(['schedule.pattern', 'driver.user', 'bus']);
    }

    private static function allows(string $ability, ?TripAssignment $assignment = null): bool
    {
        $actor = Filament::auth()->user();
        $company = Filament::getTenant();
        if (! $actor instanceof User || ! $company instanceof Company) {
            return false;
        }

        return Gate::forUser($actor)->allows($ability, [
            TripAssignment::class,
            $company,
            ...($assignment instanceof TripAssignment ? [$assignment] : []),
        ]);
    }
}
