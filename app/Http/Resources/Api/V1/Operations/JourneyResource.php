<?php

namespace App\Http\Resources\Api\V1\Operations;

use App\Http\Resources\JsonApi\JsonApiResource;
use App\Models\RoutePattern;
use Illuminate\Http\Request;

/**
 * @mixin RoutePattern
 */
class JourneyResource extends JsonApiResource
{
    /**
     * The `type` of the resource object.
     */
    public function toType(Request $request): string
    {
        return 'journeys';
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
            'pattern_name' => $this->resource->pattern_name ?? $this->resource['pattern_name'],
            'headsign' => $this->resource->headsign ?? $this->resource['headsign'],
            'company_id' => $this->resource->company_id ?? $this->resource['company_id'],
            'company_name' => $this->resource->company_name ?? $this->resource['company_name'],
            'boarding_stop' => $this->resource->boarding_stop ?? $this->resource['boarding_stop'],
            'alighting_stop' => $this->resource->alighting_stop ?? $this->resource['alighting_stop'],
            'scheduled_departure_at' => $this->resource->scheduled_departure_at ?? $this->resource['scheduled_departure_at'],
            'estimated_arrival_at' => $this->resource->estimated_arrival_at ?? $this->resource['estimated_arrival_at'],
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
