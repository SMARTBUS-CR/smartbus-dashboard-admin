<?php

namespace App\Models;

use Database\Factories\DriverScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['day_of_week', 'start_time', 'end_time', 'ends_next_day', 'valid_from', 'valid_until'])]
#[Hidden(['driver'])]
class DriverSchedule extends Model
{
    /** @use HasFactory<DriverScheduleFactory> */
    use HasFactory, HasUuids;

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
