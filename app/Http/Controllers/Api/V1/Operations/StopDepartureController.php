<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\StopDepartureRequest;
use App\Http\Resources\Api\V1\Operations\StopDepartureResource;
use App\Models\Stop;
use App\Services\Operations\StopDepartureService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class StopDepartureController extends Controller
{
    /**
     * List upcoming planned departures from a stop within a time window.
     *
     * @response AnonymousResourceCollection<StopDepartureResource>
     */
    public function index(string $id, StopDepartureRequest $request, StopDepartureService $departureService): AnonymousResourceCollection
    {
        $stop = Stop::query()->inPublicCatalogue()->find($id);

        if (! $stop) {
            abort(404, __('The requested stop does not exist or has been archived.'));
        }

        $departures = $departureService->getUpcomingDepartures(
            $stop,
            $request->input('departure_at'),
            (int) $request->input('window_minutes', 120),
        );

        $pageSize = (int) ($request->input('page.size') ?? $request->input('per_page', 20));
        $pageNumber = (int) ($request->input('page.number') ?? 1);

        $paginated = new LengthAwarePaginator(
            $departures->forPage($pageNumber, $pageSize)->values(),
            $departures->count(),
            $pageSize,
            $pageNumber,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return StopDepartureResource::collection($paginated);
    }
}
