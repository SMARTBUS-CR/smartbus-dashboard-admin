<?php

namespace App\Models;

use Database\Factories\DriverScheduleFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Fillable(['day_of_week', 'start_time', 'end_time', 'ends_next_day', 'valid_from', 'valid_until'])]
#[Hidden(['driver'])]
class DriverSchedule extends Model
{
    /** @use HasFactory<DriverScheduleFactory> */
    use HasFactory, HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = ['ends_next_day' => false];

    protected static function booted(): void
    {
        static::saving(function (DriverSchedule $schedule): void {
            $schedule->validateValues();
        });
    }

    public function validateValues(): void
    {
        $values = $this->getAttributes();
        $rules = [
            'driver_id' => ['required', 'uuid'],
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'end_time' => ['required', 'date_format:H:i:s'],
            'ends_next_day' => ['required', 'boolean'],
            'valid_from' => ['nullable', 'date', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date', 'date_format:Y-m-d'],
        ];
        if (isset($values['valid_from'])) {
            $rules['valid_until'][] = 'after_or_equal:valid_from';
        }
        Validator::make($values, $rules)->validate();

        if (($this->ends_next_day && $this->end_time > $this->start_time)
            || (! $this->ends_next_day && $this->end_time <= $this->start_time)) {
            throw ValidationException::withMessages(['end_time' => __('The availability interval does not match its overnight flag.')]);
        }
        foreach (['id', 'driver_id'] as $field) {
            if ($this->exists && $this->isDirty($field)) {
                throw ValidationException::withMessages([$field => __('A driver schedule cannot be reassigned or given a different identity.')]);
            }
        }
    }

    protected function startTime(): Attribute
    {
        return Attribute::make(set: fn (mixed $value): ?string => $this->normalizedTime('start_time', $value));
    }

    protected function endTime(): Attribute
    {
        return Attribute::make(set: fn (mixed $value): ?string => $this->normalizedTime('end_time', $value));
    }

    private function normalizedTime(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        Validator::make([$field => $value], [$field => ['bail', 'required', 'string', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/']])->validate();

        return strlen($value) === 5 ? $value.':00' : $value;
    }

    protected function validFrom(): Attribute
    {
        return Attribute::make(set: fn (mixed $value): ?string => $this->validatedDate('valid_from', $value));
    }

    protected function validUntil(): Attribute
    {
        return Attribute::make(set: fn (mixed $value): ?string => $this->validatedDate('valid_until', $value));
    }

    private function validatedDate(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }
        Validator::make([$field => $value], [$field => ['required', 'date', 'date_format:Y-m-d']])->validate();

        return $value;
    }

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'ends_next_day' => 'boolean',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }
}
