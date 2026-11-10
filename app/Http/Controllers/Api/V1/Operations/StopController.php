<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\StopIndexRequest;
use App\Http\Resources\Api\V1\Operations\StopResource;
use App\Models\Stop;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class StopController extends Controller
{
    /**
     * List geographic bus stops.
     *
     * @response AnonymousResourceCollection<StopResource>
     */
    public function index(StopIndexRequest $request): AnonymousResourceCollection
    {
        $pageSize = (int) $request->input('page.size', 20);
        $pageNumber = (int) $request->input('page.number', 1);

        $stops = QueryBuilder::for(Stop::query())
            ->inPublicCatalogue()
            ->allowedFilters(
                AllowedFilter::scope('company_id', 'forCatalogueCompanies'),
                AllowedFilter::partial('name'),
                AllowedFilter::scope('bbox', 'withinBoundingBox'),
                AllowedFilter::scope('near', 'withinRadius'),
            )
            ->allowedSorts('name', 'created_at')
            ->defaultSort('name')
            ->orderBy('id')
            ->paginate($pageSize, ['*'], 'page[number]', $pageNumber)
            ->withQueryString();

        return StopResource::collection($stops);
    }

    /**
     * Get details of a single bus stop.
     */
    public function show(string $id): StopResource
    {
        $stop = Stop::query()->inPublicCatalogue()->find($id);

        if (! $stop) {
            abort(404, __('The requested stop does not exist or has been archived.'));
        }

        return new StopResource($stop);
    }
}
