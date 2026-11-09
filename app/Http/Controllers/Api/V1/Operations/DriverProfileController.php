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
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page.size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page.number' => ['nullable', 'integer', 'min:1'],
        ]);

        $userId = $request->user()?->id;
        $pageSize = (int) ($request->input('page.size') ?? $request->input('per_page', 15));
        $pageNumber = (int) ($request->input('page.number') ?? 1);

        $profiles = QueryBuilder::for(Driver::query()->where('user_id', $userId))
            ->allowedFilters(
                AllowedFilter::exact('company_id'),
                AllowedFilter::exact('status'),
            )
            ->allowedIncludes('company')
            ->defaultSort('-created_at')
            ->allowedSorts('employment_starts_on', 'status', 'created_at')
            ->paginate($pageSize, ['*'], 'page[number]', $pageNumber);

        return DriverProfileResource::collection($profiles);
    }
}
