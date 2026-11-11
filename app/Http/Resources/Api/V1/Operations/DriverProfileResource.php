<?php

namespace App\Http\Resources\Api\V1\Operations;

use App\Http\Resources\JsonApi\JsonApiResource;
use App\Models\Driver;
use Illuminate\Http\Request;

/**
 * @mixin Driver
 */
class DriverProfileResource extends JsonApiResource
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
            'company_id' => (string) $this->company_id,
            'license' => $this->license,
            'license_expires_at' => $this->license_expires_at !== null ? (string) $this->license_expires_at->toDateString() : null,
            'status' => $this->status?->value ?? (string) $this->status,
            'employment_starts_on' => $this->employment_starts_on !== null ? (string) $this->employment_starts_on->toDateString() : null,
            'employment_ends_on' => $this->employment_ends_on !== null ? (string) $this->employment_ends_on->toDateString() : null,
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
        ];
    }
}
