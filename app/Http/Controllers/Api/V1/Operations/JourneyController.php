<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\JourneySearchRequest;
use App\Http\Resources\Api\V1\Operations\JourneyResource;
use App\Services\Operations\DirectJourneySearchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

        return JourneyResource::collection($journeys);
    }
}
