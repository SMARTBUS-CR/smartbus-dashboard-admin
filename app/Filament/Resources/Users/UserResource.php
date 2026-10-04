<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static bool $isScopedToTenant = false;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
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
        $query = parent::getEloquentQuery();
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return $query->whereRaw('1 = 0');
        }

        $memberIds = CompanyUser::query()
            ->where('company_id', $tenant->getKey())
            ->pluck('user_id');

        $user = new User;

        $adminIds = DB::connection('mysql')
            ->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_type', $user->getMorphClass())
            ->where('assignments.company_id', $tenant->getKey())
            ->where('roles.company_id', $tenant->getKey())
            ->where('roles.guard_name', 'web')
            ->where('roles.name', UserRole::Admin->value)
            ->select('assignments.model_uuid');

        $superAdminIds = DB::connection('mysql')
            ->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_type', $user->getMorphClass())
            ->where('roles.guard_name', 'web')
            ->where('roles.name', UserRole::SuperAdmin->value)
            ->select('assignments.model_uuid');

        return $query
            ->whereIn('users.id', $memberIds)
            ->whereIn('users.id', $adminIds)
            ->whereNotIn('users.id', $superAdminIds);
    }
}
