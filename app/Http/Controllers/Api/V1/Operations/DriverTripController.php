<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Operations\TripResource;
use App\Models\Driver;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class DriverTripController extends Controller
{
    /**
     * List trips assigned to the authenticated driver.
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

        $driverIds = Driver::query()->where('user_id', $userId)->pluck('id');

        $trips = QueryBuilder::for(Trip::query()->whereIn('driver_id', $driverIds))
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('service_date'),
                AllowedFilter::exact('company_id'),
                AllowedFilter::exact('bus_id'),
                AllowedFilter::exact('route_pattern_id'),
            )
            ->allowedIncludes('company', 'bus', 'pattern', 'schedule')
            ->defaultSort('scheduled_departure_at')
            ->allowedSorts('scheduled_departure_at', 'service_date', 'status')
            ->paginate($pageSize, ['*'], 'page[number]', $pageNumber);

        return TripResource::collection($trips);
    }
}
