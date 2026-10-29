<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

#[Connection('mysql')]
#[Fillable(['name', 'display_name', 'guard_name', 'company_id', 'color'])]
class Role extends SpatieRole
{
    protected static function booted(): void
    {
        static::creating(function (Role $role): void {
            $role->color = static::generateColor();
        });
    }

    public static function generateColor(): string
    {
        do {
            $color = sprintf('#%06x', random_int(0, 0xFFFFFF));
        } while (static::withoutGlobalScopes()->where('color', $color)->exists());

        return $color;
    }

    /**
     * Get the company that owns the role.
     *
     * @return BelongsTo<Company, Role> The relationship instance.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function hasAssignedUsers(): bool
    {
        $connection = $this->getConnection();
        $request = app('request');
        $attribute = 'dashboard.role_assignment_checks';

        $key = $connection->getName().'|'.$this->getKey();
        $checks = $request->attributes->get($attribute, []);
        $canReuse = $connection->transactionLevel() === 0;

        if ($canReuse && array_key_exists($key, $checks)) {
            return $checks[$key];
        }

        $result = $this->users()->withoutGlobalScopes()->exists();

        if ($canReuse) {
            $checks[$key] = $result;
            $request->attributes->set($attribute, $checks);
        }

        return $result;
    }
}
