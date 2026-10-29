<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

final class DriverRegistrationOperation
{
    private bool $compensated = false;

    public ?string $newUserId = null;

    public ?string $userId = null;

    public ?string $modelType = null;

    public ?string $driverId = null;

    public ?string $newMembershipId = null;

    /** @var array{id: string, deleted_at: string, updated_at: string|null}|null */
    public ?array $restoredMembership = null;

    public ?int $roleId = null;

    public bool $roleCreated = false;

    public bool $roleAssigned = false;

    public function __construct(public readonly string $companyId, private readonly PermissionRegistrar $permissions) {}

    /** Undo only this operation's writes; repeating recovery is safe. */
    public function compensate(): void
    {
        if ($this->compensated) {
            return;
        }

        try {
            DB::connection('mysql')->transaction(function (): void {
                DB::connection('mysql')->table('users')->where('id', $this->userId ?? $this->newUserId)->lockForUpdate()->first();
                $this->compensateEmployment();
                $this->compensateIdentity();
            });
            $this->compensated = true;
        } finally {
            $this->permissions->forgetCachedPermissions();
        }
    }

    private function compensateEmployment(): void
    {
        if ($this->driverId === null && $this->newMembershipId === null && $this->restoredMembership === null) {
            return;
        }

        DB::connection('pgsql')->transaction(function (): void {
            if ($this->driverId !== null) {
                DB::connection('pgsql')->table('drivers')->where('id', $this->driverId)
                    ->where('company_id', $this->companyId)->where('user_id', $this->userId)->delete();
            }

            if ($this->newMembershipId !== null) {
                DB::connection('pgsql')->table('company_users')->where('id', $this->newMembershipId)
                    ->where('company_id', $this->companyId)->where('user_id', $this->userId)->delete();
            }

            if ($this->restoredMembership !== null) {
                DB::connection('pgsql')->table('company_users')->where('id', $this->restoredMembership['id'])
                    ->where('company_id', $this->companyId)->where('user_id', $this->userId)
                    ->whereNull('deleted_at')->update([
                        'deleted_at' => $this->restoredMembership['deleted_at'],
                        'updated_at' => $this->restoredMembership['updated_at'],
                    ]);
            }
        });
    }

    /** Keep identity data if employment cleanup fails, avoiding dangling references. */
    private function compensateIdentity(): void
    {
        if ($this->newUserId === null && ! $this->roleAssigned && ! $this->roleCreated) {
            return;
        }

        DB::connection('mysql')->transaction(function (): void {
            if ($this->roleAssigned) {
                DB::connection('mysql')->table('model_has_roles')->where('role_id', $this->roleId)
                    ->where('company_id', $this->companyId)->where('model_uuid', $this->userId)
                    ->where('model_type', $this->modelType)->delete();
            }

            if ($this->newUserId !== null) {
                DB::connection('mysql')->table('users')->where('id', $this->newUserId)->delete();
            }

            if ($this->roleCreated) {
                DB::connection('mysql')->table('roles')->where('id', $this->roleId)
                    ->where('company_id', $this->companyId)->where('name', 'driver')->where('guard_name', 'web')
                    ->whereNotExists(fn (Builder $query): Builder => $query->selectRaw('1')->from('model_has_roles')->whereColumn('model_has_roles.role_id', 'roles.id'))
                    ->whereNotExists(fn (Builder $query): Builder => $query->selectRaw('1')->from('role_has_permissions')->whereColumn('role_has_permissions.role_id', 'roles.id'))
                    ->delete();
            }
        });
    }

    /** @return array<string, string|int|bool|null> */
    public function context(): array
    {
        return [
            'company_id' => $this->companyId,
            'user_id' => $this->userId,
            'new_user_id' => $this->newUserId,
            'driver_id' => $this->driverId,
            'new_membership_id' => $this->newMembershipId,
            'restored_membership_id' => $this->restoredMembership['id'] ?? null,
            'role_id' => $this->roleId,
            'role_created' => $this->roleCreated,
            'role_assigned' => $this->roleAssigned,
        ];
    }
}
