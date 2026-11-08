<?php

namespace App\Http\Resources\Api\V1\Operations;

use App\Http\Resources\JsonApi\JsonApiResource;
use App\Models\RouteSchedule;
use Illuminate\Http\Request;

/**
 * @mixin RouteSchedule
 */
class RouteScheduleResource extends JsonApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'day_of_week' => (int) $this->day_of_week,
            'departure_time' => $this->departure_time,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'route_pattern_id' => (string) $this->route_pattern_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
