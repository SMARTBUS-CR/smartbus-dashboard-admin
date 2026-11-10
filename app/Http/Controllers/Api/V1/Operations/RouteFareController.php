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
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $route = Route::query()->inPublicCatalogue()->find($routeId);

        if (! $route) {
            abort(404, __('The requested route does not exist or has been archived.'));
        }

        $query = $route->fares();

        if ($request->filled('date')) {
            $date = $request->input('date');
            $query->where(function ($q) use ($date): void {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date);
            })->where(function ($q) use ($date): void {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date);
            });
        }

        $fares = $query
            ->orderBy('amount')
            ->get();

        return RouteFareResource::collection($fares);
    }
}
