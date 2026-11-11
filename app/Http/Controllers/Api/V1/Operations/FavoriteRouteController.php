<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\StoreFavoriteRouteRequest;
use App\Http\Requests\Api\V1\Operations\UpdateFavoriteRouteRequest;
use App\Http\Resources\Api\V1\Operations\FavoriteRouteResource;
use App\Models\FavoriteRoute;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Group(name: 'Operations - Passenger Favorites', description: 'Endpoints for passengers to manage their bookmarked routes.')]
class FavoriteRouteController extends Controller
{
    /**
     * List Passenger Favorite Routes
     *
     * Returns a collection of all routes bookmarked as favorites by the authenticated passenger.
     */
    #[OpenApiResponse(status: HttpStatus::HTTP_OK, description: 'List of favorite routes.')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->id;

        $favorites = FavoriteRoute::query()
            ->where('user_id', $userId)
            ->with(['route.company'])
            ->orderBy('created_at', 'desc')
            ->get();

        return FavoriteRouteResource::collection($favorites);
    }

    /**
     * Bookmark a Route as Favorite
     *
     * Adds an active operational route to the authenticated passenger\'s favorites with an optional label.
     */
    #[OpenApiResponse(status: HttpStatus::HTTP_CREATED, description: 'Route successfully added to favorites.')]
    #[OpenApiResponse(status: HttpStatus::HTTP_CONFLICT, description: 'Route is already in favorites.')]
    public function store(StoreFavoriteRouteRequest $request): JsonResponse
    {
        $userId = $request->user()->id;
        $routeId = $request->validated('route_id');

        $exists = FavoriteRoute::query()
            ->where('user_id', $userId)
            ->where('route_id', $routeId)
            ->exists();

        if ($exists) {
            return response()->json([
                'errors' => [
                    [
                        'status' => '409',
                        'title' => 'Conflict',
                        'detail' => __('This route is already in your favorites.'),
                    ],
                ],
            ], HttpStatus::HTTP_CONFLICT, ['Content-Type' => 'application/vnd.api+json']);
        }

        $favorite = FavoriteRoute::query()->create([
            'user_id' => $userId,
            'route_id' => $routeId,
            'label' => $request->validated('label'),
        ]);

        return (new FavoriteRouteResource($favorite->load('route.company')))
            ->response()
            ->setStatusCode(HttpStatus::HTTP_CREATED)
            ->header('Content-Type', 'application/vnd.api+json');
    }

    /**
     * Update a Favorite Route Bookmark
     *
     * Updates the custom label of an existing favorite route bookmark.
     */
    #[OpenApiResponse(status: HttpStatus::HTTP_OK, description: 'Favorite route label updated successfully.')]
    public function update(UpdateFavoriteRouteRequest $request, string $routeId): FavoriteRouteResource
    {
        $userId = $request->user()->id;

        $favorite = FavoriteRoute::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($routeId): void {
                $query->where('route_id', $routeId)
                    ->orWhere('id', $routeId);
            })
            ->first();

        if (! $favorite) {
            throw new NotFoundHttpException(__('Favorite route not found.'));
        }

        $favorite->update([
            'label' => $request->validated('label'),
        ]);

        return new FavoriteRouteResource($favorite->load('route.company'));
    }

    /**
     * Remove a Route from Favorites
     *
     * Deletes a route from the authenticated passenger\'s favorites.
     */
    #[OpenApiResponse(status: HttpStatus::HTTP_NO_CONTENT, description: 'Route removed from favorites.')]
    public function destroy(Request $request, string $routeId): HttpResponse
    {
        $userId = $request->user()->id;

        $favorite = FavoriteRoute::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($routeId): void {
                $query->where('route_id', $routeId)
                    ->orWhere('id', $routeId);
            })
            ->first();

        if (! $favorite) {
            throw new NotFoundHttpException(__('Favorite route not found.'));
        }

        $favorite->delete();

        return response()->noContent();
    }
}
