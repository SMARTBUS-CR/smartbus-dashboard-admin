<?php

namespace App\Services;

use App\Models\RoutePattern;
use App\Models\Stop;
use App\Models\User;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\GeoSearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RoutePatternStopService
{
    /**
     * @param  list<string>  $orderedIds
     */
    public function reorder(RoutePattern $pattern, array $orderedIds): void
    {
        Validator::make([
            'stop_order' => $orderedIds,
        ], [
            'stop_order' => ['array', 'list'],
            'stop_order.*' => ['required', 'string', 'uuid', 'distinct:strict'],
        ])->validate();

        DB::transaction(function () use ($pattern, $orderedIds): void {
            $storedPattern = RoutePattern::query()
                ->with('route')
                ->lockForUpdate()
                ->find($pattern->getKey());

            if (! $storedPattern || ! $storedPattern->route) {
                throw ValidationException::withMessages([
                    'stop_order' => __('The selected route pattern is unavailable.'),
                ]);
            }

            $occurrences = $storedPattern->stopOccurrences()
                ->lockForUpdate()
                ->get();

            $currentIds = $occurrences->modelKeys();

            $requestedIds = $orderedIds;
            $availableIds = $currentIds;

            sort($requestedIds);
            sort($availableIds);

            if ($requestedIds !== $availableIds) {
                throw ValidationException::withMessages([
                    'stop_order' => __('The stop order must include every occurrence exactly once.'),
                ]);
            }

            if ($orderedIds === $currentIds) {
                return;
            }

            $temporaryStart = (
                (int) $occurrences->max('stop_sequence')
            ) + 1;

            $temporaryEnd = $temporaryStart + $occurrences->count() - 1;

            if ($temporaryEnd > 2147483647) {
                throw ValidationException::withMessages([
                    'stop_order' => __('The stop positions exceed the supported range.'),
                ]);
            }

            // Free the final positions without violating the unique constraint.
            foreach ($occurrences as $index => $occurrence) {
                $occurrence->update([
                    'stop_sequence' => $temporaryStart + $index,
                    'minutes_from_start' => null,
                ]);
            }

            $occurrencesById = $occurrences->keyBy('id');

            foreach ($orderedIds as $index => $id) {
                $occurrence = $occurrencesById->get($id);

                $occurrence->update([
                    'stop_sequence' => $index + 1,
                ]);
            }

            $storedPattern->removeObsoleteRoutingAdjustments();
        });
    }

    public function setEndpoints(
        RoutePattern $pattern,
        User $actor,
        GeoSearchResult $origin,
        GeoSearchResult $destination,
    ): void {
        $this->validateEndpoint($origin, 'origin_search');
        $this->validateEndpoint($destination, 'destination_search');

        DB::transaction(function () use ($pattern, $actor, $origin, $destination): void {
            $storedPattern = RoutePattern::query()
                ->with('route')
                ->lockForUpdate()
                ->find($pattern->getKey());

            if (! $storedPattern || ! $storedPattern->route) {
                throw ValidationException::withMessages([
                    'origin_search' => __('The selected route pattern is unavailable.'),
                ]);
            }

            Gate::forUser($actor)->authorize('update', $storedPattern);

            $companyId = (string) $storedPattern->route->company_id;

            $originStop = $this->resolveEndpointStop(
                $origin,
                $companyId,
                $actor,
            );

            $destinationStop = $this->resolveEndpointStop(
                $destination,
                $companyId,
                $actor,
            );

            $occurrences = $storedPattern->stopOccurrences()
                ->lockForUpdate()
                ->get();

            if (
                $occurrences->count() >= 2
                && (string) $occurrences->first()->stop_id === (string) $originStop->getKey()
                && (string) $occurrences->last()->stop_id === (string) $destinationStop->getKey()
            ) {
                return;
            }

            if ($occurrences->isEmpty()) {
                $storedPattern->stopOccurrences()->create([
                    'stop_id' => $originStop->getKey(),
                    'stop_sequence' => 1,
                    'minutes_from_start' => 0,
                ]);

                $storedPattern->stopOccurrences()->create([
                    'stop_id' => $destinationStop->getKey(),
                    'stop_sequence' => 2,
                    'minutes_from_start' => null,
                ]);

                return;
            }

            // Endpoint changes invalidate the existing travel time estimates.
            foreach ($occurrences as $occurrence) {
                $occurrence->update(['minutes_from_start' => null]);
            }

            $first = $occurrences->first();

            $first->update([
                'stop_id' => $originStop->getKey(),
                'minutes_from_start' => 0,
            ]);

            if ($occurrences->count() === 1) {
                if ($first->stop_sequence >= 2147483647) {
                    throw ValidationException::withMessages([
                        'destination_search' => __('The stop positions exceed the supported range.'),
                    ]);
                }

                $storedPattern->stopOccurrences()->create([
                    'stop_id' => $destinationStop->getKey(),
                    'stop_sequence' => $first->stop_sequence + 1,
                    'minutes_from_start' => null,
                ]);

                return;
            }

            $occurrences->last()->update([
                'stop_id' => $destinationStop->getKey(),
            ]);
        });
    }

    private function validateEndpoint(
        GeoSearchResult $location,
        string $field,
    ): void {
        $validator = Validator::make([
            'name' => $location->name,
            'latitude' => $location->coordinate?->lat,
            'longitude' => $location->coordinate?->lng,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                $field => $validator->errors()->all(),
            ]);
        }
    }

    private function resolveEndpointStop(
        GeoSearchResult $location,
        string $companyId,
        User $actor,
    ): Stop {
        $latitude = sprintf('%.7f', $location->coordinate->lat);
        $longitude = sprintf('%.7f', $location->coordinate->lng);

        $stop = Stop::query()
            ->where(function (Builder $query) use ($companyId): void {
                $query
                    ->where('company_id', $companyId)
                    ->orWhereNull('company_id');
            })
            ->where('name', $location->name)
            ->where('latitude', $latitude)
            ->where('longitude', $longitude)
            ->orderByRaw('company_id IS NULL')
            ->orderBy('id')
            ->first();

        if ($stop !== null) {
            return $stop;
        }

        Gate::forUser($actor)->authorize('create', Stop::class);

        return Stop::create([
            'company_id' => $companyId,
            'name' => $location->name,
            'description' => null,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }
}
