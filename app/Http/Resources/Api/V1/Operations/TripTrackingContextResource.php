<?php

namespace App\Http\Resources\Api\V1\Operations;

use App\Http\Resources\JsonApi\JsonApiResource;
use App\Models\Trip;
use Illuminate\Http\Request;

/**
 * @mixin Trip
 */
class TripTrackingContextResource extends JsonApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'trip_id' => (string) $this->id,
            'company_id' => (string) $this->company_id,
            'bus_id' => (string) $this->bus_id,
            'driver_id' => (string) $this->driver_id,
            'driver_user_id' => (string) $this->driver_user_id,
            'route_pattern_id' => (string) $this->route_pattern_id,
            'status' => $this->status->value,
            'scheduled_departure_at' => $this->scheduled_departure_at?->toISOString(),
            'scheduled_arrival_at' => $this->scheduled_arrival_at?->toISOString(),
            'actual_departure_at' => $this->actual_departure_at?->toISOString(),
            'actual_arrival_at' => $this->actual_arrival_at?->toISOString(),
            'planned_route_snapshot' => $this->planned_route_snapshot,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
