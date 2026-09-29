<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Override;

use function in_array;

class EditRole extends EditRecord
{
    public Collection $permissions;

    protected static string $resource = RoleResource::class;

    protected function getActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (Role $record): bool => in_array($record->name, UserRole::protectedRoles(), true))
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
        ];
    }

    #[Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->permissions = collect($data)
            ->filter(fn (mixed $permission, string $key): bool => ! in_array($key, ['name', 'display_name', 'guard_name', 'select_all', Utils::getTenantModelForeignKey()], true))
            ->values()
            ->flatten()
            ->unique();

        RoleResource::authorizePermissionAssignment($this->permissions, $this->record);
        $data['guard_name'] = $this->record->guard_name;

        if (Utils::isTenancyEnabled() && Arr::has($data, Utils::getTenantModelForeignKey()) && filled($data[Utils::getTenantModelForeignKey()])) {
            return Arr::only($data, ['name', 'display_name', 'guard_name', Utils::getTenantModelForeignKey()]);
        }

        return Arr::only($data, ['name', 'display_name', 'guard_name']);
    }

    protected function afterSave(): void
    {
        $permissionModels = collect();
        $this->permissions->each(function (string $permission) use ($permissionModels): void {
            $permissionModels->push(Utils::getPermissionModel()::firstOrCreate([
                'name' => $permission,
                'guard_name' => $this->record->guard_name,
            ]));
        });

        // @phpstan-ignore-next-line
        $this->record->syncPermissions($permissionModels);
    }
}
