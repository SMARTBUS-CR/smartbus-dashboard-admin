<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles;

use App\Enums\NavigationGroup;
use App\Enums\UserRole;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use App\Models\Role;
use BackedEnum;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use BezhanSalleh\FilamentShield\Support\Utils;
use BezhanSalleh\FilamentShield\Traits\HasShieldFormComponents;
use BezhanSalleh\PluginEssentials\Concerns\Resource as Essentials;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Override;
use UnitEnum;

use function in_array;

class RoleResource extends Resource
{
    use Essentials\BelongsToParent;
    use Essentials\BelongsToTenant;
    use Essentials\HasGlobalSearch;
    use Essentials\HasLabels;
    use Essentials\HasNavigation;
    use HasShieldFormComponents;

    protected static ?string $model = Role::class;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'display_name';

    #[Override]
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return NavigationGroup::AccessManagement;
    }

    #[Override]
    public static function getNavigationIcon(): BackedEnum|Htmlable|string|null
    {
        return Heroicon::OutlinedKey;
    }

    #[Override]
    public static function getActiveNavigationIcon(): BackedEnum|Htmlable|string|null
    {
        return Heroicon::Key;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return __('Access Control');
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make()
                    ->schema([
                        Section::make()
                            ->schema([
                                TextInput::make('display_name')
                                    ->label(__('Name'))
                                    ->placeholder(__('i.e.: Administrator, Dispatcher, Supervisor'))
                                    ->helperText(__('Name of the role that will be displayed in the application'))
                                    ->required()
                                    ->maxLength(255)
                                    ->live(debounce: 500)
                                    ->afterStateUpdated(function (string $operation, ?string $state, Set $set, ?Role $record) {
                                        // Generate automatic slug if it's creation or not a protected role
                                        $isProtected = in_array($record?->name, UserRole::protectedRoles(), true);

                                        if ($operation === 'create' || ! $isProtected) {
                                            $set('name', Str::slug($state));
                                        }
                                    }),
                                TextInput::make('name')
                                    ->label(__('System Identifier'))
                                    ->helperText(__('Unique identifier of the role for permission control'))
                                    ->disabled()
                                    ->readOnly()
                                    ->dehydrated()
                                    ->mutateStateForValidationUsing(fn (Get $get, ?Role $record): string => static::getRoleName($get, $record))
                                    ->dehydrateStateUsing(fn (Get $get, ?Role $record): string => static::getRoleName($get, $record))
                                    ->rule(fn (?Role $record) => ! Filament::auth()->user()->isSuperAdmin() && ! in_array($record?->name, UserRole::protectedRoles(), true)
                                        ? Rule::notIn(UserRole::protectedRoles())
                                        : null)
                                    ->unique(
                                        ignoreRecord: true, /** @phpstan-ignore-next-line */
                                        modifyRuleUsing: fn (Unique $rule): Unique => Utils::isTenancyEnabled() ? $rule->where(Utils::getTenantModelForeignKey(), Filament::getTenant()?->id) : $rule
                                    )
                                    ->required()
                                    ->maxLength(255),

                                Hidden::make('guard_name')
                                    ->default(Utils::getFilamentAuthGuard()),

                                Select::make(config('permission.column_names.team_foreign_key'))
                                    ->label(__('filament-shield::filament-shield.field.team'))
                                    ->placeholder(__('filament-shield::filament-shield.field.team.placeholder'))
                                    /** @phpstan-ignore-next-line */
                                    ->default(Filament::getTenant()?->id)
                                    ->options(fn (): array => in_array(Utils::getTenantModel(), [null, '', '0'], true) ? [] : Utils::getTenantModel()::pluck('name', 'id')->toArray())
                                    ->visible(fn (): bool => static::shield()->isCentralApp() && Utils::isTenancyEnabled())
                                    ->dehydrated(fn (): bool => static::shield()->isCentralApp() && Utils::isTenancyEnabled()),
                                static::getSelectAllFormComponent(),

                            ])
                            ->columns([
                                'sm' => 2,
                                'lg' => 3,
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                static::getShieldFormComponents(),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('Name'))
                    ->weight(FontWeight::Medium)
                    ->default(fn (Role $record) => Str::headline($record->display_name))
                    ->searchable(),
                TextColumn::make('name')
                    ->label(__('Identifier'))
                    ->badge()
                    ->color(function (string $state, Role $record): array {
                        $color = $record->color ?: '#'.substr(hash('sha256', (string) $record->getKey()), 0, 6);

                        return Color::hex($color);
                    })
                    ->searchable(),
                TextColumn::make('team.name')
                    ->default('Global')
                    ->badge()
                    ->color(fn (mixed $state): string => str($state)->contains('Global') ? 'gray' : 'primary')
                    ->label(__('filament-shield::filament-shield.column.team'))
                    ->searchable()
                    ->visible(fn (): bool => static::shield()->isCentralApp() && Utils::isTenancyEnabled()),
                TextColumn::make('permissions_count')
                    ->badge()
                    ->label(__('filament-shield::filament-shield.column.permissions'))
                    ->counts('permissions')
                    ->color('primary'),
                TextColumn::make('updated_at')
                    ->label(__('Last Updated'))
                    ->dateTime(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Role $record) => in_array($record->name, UserRole::protectedRoles(), true))
                    ->before(function (DeleteAction $action, Role $record): void {
                        $usersCount = $record->users()->withoutGlobalScopes()->count();

                        if ($usersCount > 0) {
                            Notification::make()
                                ->danger()
                                ->title(__('Cannot be deleted'))
                                ->body(__('This role is assigned to :count user(s). You must reassign or remove the users before deleting this role.', ['count' => $usersCount]))
                                ->send();

                            // Halt the deletion action to prevent the role from being deleted
                            $action->halt();
                        }
                    }),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->authorizeIndividualRecords('delete'),
            ]);
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    protected static function getRoleName(Get $get, ?Role $record): string
    {
        return in_array($record?->name, UserRole::protectedRoles(), true)
            ? $record->name
            : Str::slug((string) $get('display_name'));
    }

    /**
     * @param  Collection<int, string>  $permissions
     */
    public static function authorizePermissionAssignment(Collection $permissions, ?Role $record = null): void
    {
        $user = Filament::auth()->user();

        if ($user->isSuperAdmin()) {
            return;
        }

        $existingPermissions = $record?->permissions()->pluck('name') ?? collect();

        foreach ($permissions->diff($existingPermissions) as $permission) {
            abort_unless($user->can($permission), 403);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getModel(): string
    {
        return Utils::getRoleModel();
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return Utils::getResourceSlug();
    }

    public static function getCluster(): ?string
    {
        return Utils::getResourceCluster();
    }

    public static function getEssentialsPlugin(): ?FilamentShieldPlugin
    {
        return FilamentShieldPlugin::get();
    }
}
