<?php

namespace App\Http\Resources\Api\V1\Operations;

use App\Http\Resources\JsonApi\JsonApiResource;
use App\Models\Trip;
use Illuminate\Http\Request;

/**
 * @mixin Trip
 */
class TripResource extends JsonApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'company_id' => (string) $this->company_id,
            'bus_id' => (string) $this->bus_id,
            'driver_id' => (string) $this->driver_id,
            'route_pattern_id' => (string) $this->route_pattern_id,
            'route_schedule_id' => (string) $this->route_schedule_id,
            'service_date' => $this->service_date?->toDateString(),
            'status' => $this->status?->value ?? (string) $this->status,
            'scheduled_departure_at' => $this->scheduled_departure_at?->toISOString(),
            'scheduled_arrival_at' => $this->scheduled_arrival_at?->toISOString(),
            'actual_departure_at' => $this->actual_departure_at?->toISOString(),
            'actual_arrival_at' => $this->actual_arrival_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancellation_reason' => $this->cancellation_reason,
            'planned_route_snapshot' => $this->planned_route_snapshot,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Get the relationships that should be included with the resource.
     *
     * @return array<string, mixed>
     */
    public function toRelationships(Request $request): array
    {
        return [
            'company' => fn () => $this->company,
            'bus' => fn () => $this->bus,
            'pattern' => fn () => $this->pattern,
            'schedule' => fn () => $this->schedule,
            'driver' => fn () => $this->driver,
        ];
    }
}
