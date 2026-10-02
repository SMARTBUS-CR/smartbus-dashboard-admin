<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

#[Connection('mysql')]
#[Fillable(['name', 'display_name', 'guard_name', 'company_id'])]
class Role extends SpatieRole
{
    /**
     * Get the company that owns the role.
     *
     * @return BelongsTo<Company, Role> The relationship instance.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
