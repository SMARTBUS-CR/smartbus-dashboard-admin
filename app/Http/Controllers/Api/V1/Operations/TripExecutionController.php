<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Operations\TripResource;
use App\Models\Trip;
use App\Services\TripManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TripExecutionController extends Controller
{
    /**
     * Retrieve trip details.
     */
    public function show(Request $request, string $id): TripResource
    {
        $trip = Trip::with(['company', 'bus', 'pattern', 'schedule', 'driver'])->find($id);

        if (! $trip) {
            throw new NotFoundHttpException(__('The requested trip was not found.'));
        }

        $user = $request->user();
        if (! $user) {
            abort(401, __('Authentication required.'));
        }

        $previousTeam = getPermissionsTeamId();
        try {
            setPermissionsTeamId($trip->company_id);
            Gate::forUser($user)->authorize('view', [Trip::class, $trip->company, $trip]);
        } finally {
            setPermissionsTeamId($previousTeam);
        }

        return TripResource::make($trip);
    }

    /**
     * Start an eligible trip assignment.
     */
    public function start(Request $request, string $id, TripManagementService $service): TripResource|JsonResponse
    {
        $trip = Trip::with(['company', 'driver'])->find($id);

        if (! $trip) {
            throw new NotFoundHttpException(__('The requested trip was not found.'));
        }

        $actor = $request->user();
        if (! $actor) {
            abort(401, __('Authentication required.'));
        }

        try {
            $started = $service->start($actor, $trip->company, $trip->id);

            return TripResource::make($started);
        } catch (ValidationException $exception) {
            if ($exception->errors()['status'] ?? false) {
                return $this->conflictResponse($exception);
            }

            throw $exception;
        }
    }

    /**
     * Complete an in-progress trip.
     */
    public function complete(Request $request, string $id, TripManagementService $service): TripResource|JsonResponse
    {
        $trip = Trip::with(['company', 'driver'])->find($id);

        if (! $trip) {
            throw new NotFoundHttpException(__('The requested trip was not found.'));
        }

        $actor = $request->user();
        if (! $actor) {
            abort(401, __('Authentication required.'));
        }

        try {
            $completed = $service->complete($actor, $trip->company, $trip->id);

            return TripResource::make($completed);
        } catch (ValidationException $exception) {
            if ($exception->errors()['status'] ?? false) {
                return $this->conflictResponse($exception);
            }

            throw $exception;
        }
    }

    private function conflictResponse(ValidationException $exception): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'status' => '409',
                'title' => 'Conflict',
                'detail' => (string) $exception->errors()['status'][0],
            ]],
        ], 409, ['Content-Type' => 'application/vnd.api+json']);
    }
}
