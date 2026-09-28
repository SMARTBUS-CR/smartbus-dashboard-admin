<?php

namespace App\Models;

use Database\Factories\CompanyUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('company_users')]
#[Fillable(['company_id', 'user_id'])]
class CompanyUser extends Pivot
{
    /** @use HasFactory<CompanyUserFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Keep pivot queries on PostgreSQL even when Eloquent assigns the parent's MySQL connection.
     */
    public function getConnectionName(): string
    {
        return 'pgsql';
    }

    /**
     * Get the company that this pivot belongs to.
     *
     * @return BelongsTo<Company, CompanyUser>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the user that this pivot belongs to.
     *
     * @return BelongsTo<User, CompanyUser>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
