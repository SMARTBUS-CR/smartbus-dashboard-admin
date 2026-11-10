<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Operations\RoutePatternResource;
use App\Http\Resources\Api\V1\Operations\RouteResource;
use App\Models\Route;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class RouteController extends Controller
{
    /**
     * List active routes in the public catalogue.
     *
     * @response AnonymousResourceCollection<RouteResource>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'page.size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page.number' => ['nullable', 'integer', 'min:1'],
        ]);

        $pageSize = (int) $request->input('page.size', 20);
        $pageNumber = (int) $request->input('page.number', 1);

        $routes = QueryBuilder::for(Route::query()->with('company'))
            ->inPublicCatalogue()
            ->allowedFilters(
                AllowedFilter::exact('company_id'),
                AllowedFilter::partial('name'),
                AllowedFilter::exact('code'),
            )
            ->allowedSorts('name', 'code', 'created_at')
            ->allowedIncludes('company', 'patterns')
            ->defaultSort('code')
            ->paginate($pageSize, ['*'], 'page[number]', $pageNumber);

        return RouteResource::collection($routes);
    }

    /**
     * Get details of a single route.
     */
    public function show(string $id): RouteResource
    {
        $route = Route::query()
            ->inPublicCatalogue()
            ->with(['company', 'patterns'])
            ->find($id);

        if (! $route) {
            abort(404, __('The requested route does not exist or has been archived.'));
        }

        return new RouteResource($route);
    }

    /**
     * List patterns belonging to a route.
     *
     * @response AnonymousResourceCollection<RoutePatternResource>
     */
    public function patterns(string $id): AnonymousResourceCollection
    {
        $route = Route::query()->inPublicCatalogue()->find($id);

        if (! $route) {
            abort(404, __('The requested route does not exist or has been archived.'));
        }

        $patterns = $route->patterns()
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get();

        return RoutePatternResource::collection($patterns);
    }
}
