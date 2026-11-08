<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Operations\DriverProfileResource;
use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class DriverProfileController extends Controller
{
    /**
     * List driver profiles for the authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()?->id;

        $profiles = QueryBuilder::for(Driver::query()->where('user_id', $userId))
            ->allowedFilters(
                AllowedFilter::exact('company_id'),
                AllowedFilter::exact('status'),
            )
            ->allowedIncludes('company')
            ->defaultSort('-created_at')
            ->allowedSorts('employment_starts_on', 'status', 'created_at')
            ->paginate($request->integer('per_page', 15));

        return DriverProfileResource::collection($profiles);
    }
}
