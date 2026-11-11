<?php

namespace App\Http\Resources\Api\V1\Operations;

use App\Http\Resources\JsonApi\JsonApiResource;
use App\Models\FavoriteRoute;
use Illuminate\Http\Request;

/**
 * @mixin FavoriteRoute
 */
class FavoriteRouteResource extends JsonApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'user_id' => (string) $this->user_id,
            'route_id' => (string) $this->route_id,
            'label' => $this->label,
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
            'route' => RouteResource::class,
        ];
    }
}
