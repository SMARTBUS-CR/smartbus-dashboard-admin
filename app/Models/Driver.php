<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\DriverStatus;
use Closure;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'company_id', 'user_id', 'license', 'license_expires_at',
    'employment_starts_on', 'employment_ends_on', 'salary_amount',
    'salary_currency', 'salary_period', 'status',
])]
#[Hidden(['salary_amount', 'salary_currency', 'salary_period', 'user'])]
class Driver extends Model
{
    /** @use HasFactory<DriverFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const SUPPORTED_CURRENCIES = ['BZD', 'CRC', 'GTQ', 'HNL', 'NIO', 'PAB', 'USD'];

    /** @var array<string, string> */
    protected $attributes = [
        'salary_period' => 'monthly',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'license_expires_at' => 'immutable_date',
            'employment_starts_on' => 'immutable_date',
            'employment_ends_on' => 'immutable_date',
            'salary_amount' => 'decimal:2',
            'status' => DriverStatus::class,
        ];
    }

    protected function license(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): mixed => is_string($value) ? Str::upper(trim($value)) : $value,
        );
    }

    protected function salaryCurrency(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): mixed => is_string($value) ? Str::upper(trim($value)) : $value,
        );
    }

    protected static function booted(): void
    {
        static::saving(function (Driver $driver): void {
            if ($driver->exists) {
                foreach (['company_id', 'user_id'] as $field) {
                    if ($driver->isDirty($field)) {
                        throw ValidationException::withMessages([
                            $field => __('A driver employment cannot be reassigned to another company or user.'),
                        ]);
                    }
                }
            }

            Validator::make($driver->getAttributes(), [
                'company_id' => ['required', 'uuid'],
                'user_id' => ['required', 'uuid'],
                'license' => ['required', 'string', 'max:50'],
                'license_expires_at' => ['required', 'date'],
                'employment_starts_on' => ['required', 'date'],
                'employment_ends_on' => ['nullable', 'date', 'after_or_equal:employment_starts_on'],
                'salary_amount' => [
                    'bail', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99',
                    static function (string $attribute, mixed $value, Closure $fail): void {
                        if (is_float($value)) {
                            $fail(__('Salary amounts must be provided as decimal strings or integers.'));
                        }
                    },
                ],
                'salary_currency' => ['required', Rule::in(self::SUPPORTED_CURRENCIES)],
                'salary_period' => ['required', Rule::in(['monthly'])],
                'status' => ['required', Rule::enum(DriverStatus::class)],
            ])->validate();

            if (! $driver->exists) {
                if (! Company::query()->whereKey($driver->company_id)->where('status', CompanyStatus::ACTIVE)->exists()) {
                    throw ValidationException::withMessages([
                        'company_id' => __('The selected company is unavailable.'),
                    ]);
                }

                if (! User::query()->whereKey($driver->user_id)->exists()) {
                    throw ValidationException::withMessages([
                        'user_id' => __('The selected user is unavailable.'),
                    ]);
                }
            }
        });
    }

    public function forceDelete(): bool
    {
        throw ValidationException::withMessages([
            'driver' => __('Permanent driver deletion is disabled. Archive the driver instead.'),
        ]);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
