<?php

namespace App\Http\Resources\Api\V1\Operations;

use App\Http\Resources\JsonApi\JsonApiResource;
use App\Models\RoutePattern;
use Illuminate\Http\Request;

/**
 * @mixin RoutePattern
 */
class RoutePatternResource extends JsonApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        $orderedStops = [];
        if ($this->relationLoaded('stopOccurrences')) {
            $orderedStops = $this->stopOccurrences->map(function ($occurrence) {
                return [
                    'occurrence_id' => (string) $occurrence->id,
                    'stop_id' => (string) $occurrence->stop_id,
                    'stop_name' => $occurrence->stop?->name,
                    'stop_sequence' => (int) $occurrence->stop_sequence,
                    'minutes_from_start' => $occurrence->minutes_from_start !== null ? (int) $occurrence->minutes_from_start : null,
                    'latitude' => $occurrence->stop?->latitude !== null ? (float) $occurrence->stop->latitude : null,
                    'longitude' => $occurrence->stop?->longitude !== null ? (float) $occurrence->stop->longitude : null,
                ];
            })->all();
        }

        return [
            'code' => $this->code,
            'name' => $this->name,
            'headsign' => $this->headsign,
            'route_id' => (string) $this->route_id,
            'route_geometry' => $this->route_geometry,
            'distance_meters' => $this->distance_meters !== null ? (float) $this->distance_meters : null,
            'driving_duration_seconds' => $this->driving_duration_seconds !== null ? (float) $this->driving_duration_seconds : null,
            'stops' => $orderedStops,
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
            'route' => fn () => [
                'data' => [
                    'type' => 'routes',
                    'id' => (string) $this->route_id,
                ],
            ],
            'schedules' => fn () => RouteScheduleResource::collection($this->whenLoaded('schedules')),
        ];
    }
}
