<?php

namespace App\Http\Resources\JsonApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource as BaseJsonApiResource;
use Illuminate\Support\Str;

/**
 * Base class for every JSON:API resource in the Operational Dashboard microservice.
 *
 * Ensures resource `type` is serialized as kebab-case plural (e.g., "routes", "route-patterns", "stops")
 * and previously loaded relations are compiled into the relationships object.
 */
abstract class JsonApiResource extends BaseJsonApiResource
{
    /**
     * Include previously loaded relationships in the serialized JSON:API document.
     */
    protected bool $includesPreviouslyLoadedRelationships = true;

    /**
     * The `type` of the resource object.
     */
    public function toType(Request $request): string
    {
        return (string) Str::of(class_basename(static::class))
            ->before('Resource')
            ->kebab()
            ->plural();
    }
}
