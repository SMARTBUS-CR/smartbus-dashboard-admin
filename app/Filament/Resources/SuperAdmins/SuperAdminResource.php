<?php

namespace App\Filament\Resources\SuperAdmins;

use App\Enums\NavigationGroup;
use App\Enums\UserRole;
use App\Filament\Resources\SuperAdmins\Pages\CreateSuperAdmin;
use App\Filament\Resources\SuperAdmins\Pages\EditSuperAdmin;
use App\Filament\Resources\SuperAdmins\Pages\ListSuperAdmins;
use App\Filament\Resources\SuperAdmins\Schemas\SuperAdminForm;
use App\Filament\Resources\SuperAdmins\Tables\SuperAdminsTable;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class SuperAdminResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::AccessManagement;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ShieldCheck;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isScopedToTenant = false;

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return SuperAdminForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuperAdminsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuperAdmins::route('/'),
            'create' => CreateSuperAdmin::route('/create'),
            'edit' => EditSuperAdmin::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('System Admins');
    }

    public static function getModelLabel(): string
    {
        return __('System Admin');
    }

    public static function getPluralModelLabel(): string
    {
        return __('System Admins');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = new User;

        $globalSuperAdminIds = DB::connection('mysql')
            ->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_type', $user->getMorphClass())
            ->whereNull('assignments.company_id')
            ->whereNull('roles.company_id')
            ->where('roles.guard_name', 'web')
            ->where('roles.name', UserRole::SuperAdmin->value)
            ->select('assignments.model_uuid');

        return parent::getEloquentQuery()
            ->whereIn('users.id', $globalSuperAdminIds);
    }

    public static function getAuthorizationResponse(
        string|UnitEnum $action,
        ?Model $record = null,
    ): Response {
        $actor = Filament::auth()->user();

        if (
            ! $actor instanceof User
            || ! static::isActiveGlobalSuperAdmin($actor)
        ) {
            return Response::deny(
                __('Only global system admins may access this resource.')
            );
        }

        if (
            $record !== null
            && (
                ! $record instanceof User
                || ! static::isActiveGlobalSuperAdmin($record)
            )
        ) {
            return Response::deny(
                __('This resource manages only active global system admin accounts.')
            );
        }

        return parent::getAuthorizationResponse($action, $record);
    }

    private static function isActiveGlobalSuperAdmin(User $user): bool
    {
        return static::getEloquentQuery()
            ->whereKey($user->getKey())
            ->exists();
    }
}
