<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\CatalogueIndexRequest;
use App\Http\Resources\Api\V1\Operations\PublicTripResource;
use App\Models\RoutePattern;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicTripController extends Controller
{
    /**
     * List ongoing trips for a published route pattern, without employee data.
     *
     * @response AnonymousResourceCollection<PublicTripResource>
     */
    public function index(string $id, CatalogueIndexRequest $request): AnonymousResourceCollection
    {
        if (! RoutePattern::query()->inPublicCatalogue()->whereKey($id)->exists()) {
            abort(404, __('The requested route pattern does not exist or has been archived.'));
        }

        $trips = $this->publicTrips()->where('route_pattern_id', $id)
            ->orderBy('actual_departure_at')->orderBy('id')
            ->paginate((int) $request->input('page.size', 20), ['*'], 'page[number]', (int) $request->input('page.number', 1))
            ->withQueryString();

        return PublicTripResource::collection($trips);
    }

    /** Retrieve the public projection of an ongoing trip. */
    public function show(string $id): PublicTripResource
    {
        $trip = $this->publicTrips()->find($id);
        if (! $trip) {
            abort(404, __('The requested trip was not found.'));
        }

        return new PublicTripResource($trip);
    }

    /** @return Builder<Trip> */
    private function publicTrips(): Builder
    {
        return Trip::query()->select([
            'id', 'route_pattern_id', 'bus_id', 'status', 'scheduled_departure_at', 'scheduled_arrival_at',
            'actual_departure_at', 'actual_arrival_at', 'planned_route_snapshot->headsign as headsign',
        ])->where('status', TripStatus::IN_PROGRESS)
            ->whereHas('pattern', fn (Builder $pattern): Builder => $pattern->inPublicCatalogue()->whereNull('route_patterns.deleted_at'))
            ->with('bus:id,plate_number,unit_number');
    }
}
