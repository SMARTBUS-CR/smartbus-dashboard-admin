<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Operations\RouteFareResource;
use App\Models\Route;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RouteFareController extends Controller
{
    /**
     * List active and valid fares for a route.
     *
     * @response AnonymousResourceCollection<RouteFareResource>
     */
    public function index(string $routeId, Request $request): AnonymousResourceCollection
    {
        $route = Route::query()->whereNull('deleted_at')->find($routeId);

        if (! $route) {
            abort(404, __('The requested route does not exist or has been archived.'));
        }

        $fares = $route->fares()
            ->orderBy('amount')
            ->get();

        return RouteFareResource::collection($fares);
    }
}
