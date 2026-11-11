<?php

namespace App\Http\Resources\Api\V1\Operations;

use App\Http\Resources\JsonApi\JsonApiResource;
use App\Models\Trip;
use Illuminate\Http\Request;

/** @mixin Trip */
class PublicTripResource extends JsonApiResource
{
    protected bool $includesPreviouslyLoadedRelationships = false;

    public function toType(Request $request): string
    {
        return 'trips';
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'route_pattern_id' => (string) $this->route_pattern_id,
            'status' => $this->status->value,
            'scheduled_departure_at' => $this->scheduled_departure_at?->toISOString(),
            'scheduled_arrival_at' => $this->scheduled_arrival_at?->toISOString(),
            'actual_departure_at' => $this->actual_departure_at?->toISOString(),
            'actual_arrival_at' => $this->actual_arrival_at?->toISOString(),
            'headsign' => $this->headsign === null ? null : (string) $this->headsign,
            'bus' => [
                'id' => (string) $this->bus->id,
                'plate_number' => $this->bus->plate_number,
                'unit_number' => $this->bus->unit_number,
            ],
        ];
    }
}
