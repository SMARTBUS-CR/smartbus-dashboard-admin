<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\JourneySearchRequest;
use App\Http\Resources\Api\V1\Operations\JourneyResource;
use App\Services\Operations\DirectJourneySearchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class JourneyController extends Controller
{
    /**
     * Search direct journey options between geographical coordinates.
     *
     * @response AnonymousResourceCollection<JourneyResource>
     */
    public function index(JourneySearchRequest $request, DirectJourneySearchService $searchService): AnonymousResourceCollection
    {
        $journeys = $searchService->search($request->validated());

        $pageSize = (int) ($request->input('page.size') ?? $request->input('per_page', 20));
        $pageNumber = (int) ($request->input('page.number') ?? 1);

        $paginated = new LengthAwarePaginator(
            $journeys->forPage($pageNumber, $pageSize)->values(),
            $journeys->count(),
            $pageSize,
            $pageNumber,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return JourneyResource::collection($paginated);
    }
}
