<?php

namespace App\Http\Resources\Api\V1\Operations;

use App\Http\Resources\JsonApi\JsonApiResource;
use App\Models\RouteSchedule;
use Illuminate\Http\Request;

/**
 * @mixin RouteSchedule
 */
class StopDepartureResource extends JsonApiResource
{
    /**
     * The `type` of the resource object.
     */
    public function toType(Request $request): string
    {
        return 'stop-departures';
    }

    /**
     * Get the resource identifier.
     */
    public function toId(Request $request): string
    {
        return (string) ($this->resource->id ?? $this->resource['id'] ?? '');
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'route_id' => $this->resource->route_id ?? $this->resource['route_id'],
            'route_code' => $this->resource->route_code ?? $this->resource['route_code'],
            'route_name' => $this->resource->route_name ?? $this->resource['route_name'],
            'pattern_id' => $this->resource->pattern_id ?? $this->resource['pattern_id'],
            'headsign' => $this->resource->headsign ?? $this->resource['headsign'],
            'stop_id' => $this->resource->stop_id ?? $this->resource['stop_id'],
            'stop_name' => $this->resource->stop_name ?? $this->resource['stop_name'],
            'stop_sequence' => (int) ($this->resource->stop_sequence ?? $this->resource['stop_sequence']),
            'scheduled_stop_departure_at' => $this->resource->scheduled_stop_departure_at ?? $this->resource['scheduled_stop_departure_at'],
            'fare' => $this->resource->fare ?? $this->resource['fare'],
        ];
    }

    /**
     * Get the relationships that should be included with the resource.
     *
     * @return array<string, mixed>
     */
    public function toRelationships(Request $request): array
    {
        return [];
    }
}
