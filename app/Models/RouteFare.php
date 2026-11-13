<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'route_id',
    'amount',
    'currency',
    'valid_from',
    'valid_until',
])]
class RouteFare extends Model
{
    use HasFactory, HasUuids;

    public const SUPPORTED_CURRENCIES = [
        'BZD',
        'CRC',
        'GTQ',
        'HNL',
        'NIO',
        'PAB',
        'USD',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (RouteFare $fare): void {
            $attributes = $fare->getAttributes();

            $rules = [
                'amount' => [
                    'bail',
                    'required',
                    'numeric',
                    'decimal:0,2',
                    'min:0',
                    'max:9999999999.99',
                ],
                'currency' => [
                    'required',
                    'string',
                    Rule::in(self::SUPPORTED_CURRENCIES),
                ],
                'valid_from' => ['nullable', 'date'],
                'valid_until' => ['nullable', 'date'],
            ];

            if (filled($attributes['valid_from'] ?? null)) {
                $rules['valid_until'][] = 'after_or_equal:valid_from';
            }

            Validator::make([
                'amount' => $attributes['amount'] ?? null,
                'currency' => $attributes['currency'] ?? null,
                'valid_from' => $attributes['valid_from'] ?? null,
                'valid_until' => $attributes['valid_until'] ?? null,
            ], $rules)->validate();

            if (! Route::query()->whereKey($fare->route_id)->exists()) {
                throw ValidationException::withMessages([
                    'route_id' => __('The selected route is unavailable.'),
                ]);
            }
        });
    }

    /**
     * @param  Builder<RouteFare>  $query
     */
    #[Scope]
    protected function applicableOn(Builder $query, CarbonInterface $date, string $currency): void
    {
        $localDate = $date->format('Y-m-d');

        $query
            ->where('currency', $currency)
            ->where(function (Builder $query) use ($localDate): void {
                $query
                    ->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $localDate);
            })
            ->where(function (Builder $query) use ($localDate): void {
                $query
                    ->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $localDate);
            });
    }

    /**
     * @return BelongsTo<Route, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }
}
