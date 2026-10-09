<?php

namespace App\Filament\Resources\Routes\Resources\RoutePatterns\Pages;

use App\Filament\Resources\Routes\Resources\RoutePatterns\RelationManagers\StopOccurrencesRelationManager;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RoutePatternResource;
use App\Models\RoutePattern;
use App\Models\User;
use App\Services\OsrmRoutingService;
use App\Services\RoutePatternStopService;
use App\Services\RouteRoutingPointsService;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\GeoSearchResult;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\On;

class EditRoutePattern extends EditRecord
{
    protected static string $resource = RoutePatternResource::class;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof RoutePattern, 404);

        $origin = $data['origin_search'] ?? null;
        $destination = $data['destination_search'] ?? null;
        $hasCalculatedRoute = filled(
            $this->data['calculated_geometry'] ?? null,
        );

        unset(
            $data['origin_search'],
            $data['destination_search'],
            $data['calculated_geometry'],
            $data['route_geometry'],
            $data['distance_meters'],
            $data['driving_duration_seconds'],
            $data['routing_points_hash'],
            $data['routing_leg_distances'],
            $data['route_detour_warnings'],
            $data['route_detour_analysis_available'],
        );

        try {
            return DB::transaction(function () use ($record, $data, $origin, $destination, $hasCalculatedRoute): Model {
                $updatedRecord = parent::handleRecordUpdate($record, $data);

                if (filled($origin) || filled($destination)) {
                    if (! $origin instanceof GeoSearchResult) {
                        throw ValidationException::withMessages([
                            'origin_search' => __(
                                'Choose a location from the search results.',
                            ),
                        ]);
                    }

                    if (! $destination instanceof GeoSearchResult) {
                        throw ValidationException::withMessages([
                            'destination_search' => __(
                                'Choose a location from the search results.',
                            ),
                        ]);
                    }

                    $actor = Filament::auth()->user();

                    abort_unless($actor instanceof User, 403);

                    app(RoutePatternStopService::class)->setEndpoints(
                        $record,
                        $actor,
                        $origin,
                        $destination,
                    );
                }

                if ($hasCalculatedRoute) {
                    $this->saveCalculatedRoute($record);
                }

                return $updatedRecord;
            });
        } catch (ValidationException $exception) {
            $record->refresh();

            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $errors["data.{$field}"] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    protected function afterSave(): void
    {
        $this->dispatch(
            'route-pattern-stops-updated.'.$this->getRecord()->getKey(),
        )->to(StopOccurrencesRelationManager::class);
    }

    #[On('route-pattern-stops-changed.{record.id}')]
    public function refreshRoutingData(): void
    {
        $record = $this->getRecord()->refresh();

        $this->data['routing_adjustments'] = $record->routing_adjustments ?? [];
        $this->data['calculated_geometry'] = $record->route_geometry;
        $this->data['distance_meters'] = $record->distance_meters;
        $this->data['driving_duration_seconds'] = $record->driving_duration_seconds;
        $this->data['distance_preview'] = $record->distance_meters !== null
            ? number_format(
                (float) $record->distance_meters / 1000,
                2,
                '.',
                '',
            )
            : null;

        $this->data['driving_duration_preview'] =
            $record->driving_duration_seconds !== null
            ? (int) ceil(
                (float) $record->driving_duration_seconds / 60,
            )
            : null;
        $this->data['adjustment_segment'] = null;
        $this->data['route_detour_warnings'] = [];
        $this->data['route_detour_analysis_available'] =
            $record->route_geometry !== null ? false : null;

        $occurrences = $record->stopOccurrences()
            ->with('stop')
            ->orderBy('stop_sequence')
            ->get();

        $endpoints = [
            'origin_search' => $occurrences->first()?->stop,
            'destination_search' => $occurrences->last()?->stop,
        ];

        foreach ($endpoints as $field => $stop) {
            $this->data[$field] = $stop === null
                ? null
                : json_encode([
                    'coordinate' => [
                        'lat' => (float) $stop->latitude,
                        'lng' => (float) $stop->longitude,
                    ],
                    'name' => $stop->name,
                    'display_name' => $stop->name,
                ], JSON_THROW_ON_ERROR);
        }

        $this->form
            ->getComponentByStatePath(
                'route_detour_analysis_available',
                withHidden: true,
            )
            ?->callAfterStateHydrated();
    }

    private function saveCalculatedRoute(RoutePattern $pattern): void
    {
        $pattern->refresh();

        $occurrences = $pattern->stopOccurrences()
            ->with('stop')
            ->orderBy('stop_sequence')
            ->get();

        if (
            $occurrences->count() < 2
            || $occurrences->contains(
                fn ($occurrence): bool => $occurrence->stop === null,
            )
        ) {
            throw ValidationException::withMessages([
                'origin_search' => __(
                    'The route must have at least two available stops.',
                ),
            ]);
        }

        $stops = $occurrences
            ->map(fn ($occurrence): array => [
                'occurrence_id' => $occurrence->getKey(),
                'lat' => (float) $occurrence->stop->latitude,
                'lng' => (float) $occurrence->stop->longitude,
            ])
            ->all();

        try {
            $points = app(RouteRoutingPointsService::class)->build(
                $stops,
                $pattern->routing_adjustments ?? [],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'routing_adjustments' => __(
                    'Review the route adjustment points and available stops before calculating.',
                ),
            ]);
        }

        $coordinates = implode(';', array_map(
            static fn (array $point): string => sprintf(
                '%.7F,%.7F',
                $point['lng'],
                $point['lat'],
            ),
            $points,
        ));

        $pointsHash = hash('sha256', $coordinates);

        $calculation = app(OsrmRoutingService::class)
            ->getCachedRoute($points);

        if (
            $pattern->route_geometry !== null
            && $pattern->distance_meters !== null
            && $pattern->driving_duration_seconds !== null
            && $pattern->routing_points_hash === $pointsHash
            && $calculation === null
        ) {
            return;
        }

        if ($calculation === null) {
            throw ValidationException::withMessages([
                'origin_search' => __(
                    'Calculate the route again before saving.',
                ),
            ]);
        }

        $pattern->update([
            'route_geometry' => $calculation['geometry'],
            'distance_meters' => $calculation['distance_meters'],
            'driving_duration_seconds' => $calculation['duration_seconds'],
            'routing_points_hash' => $pointsHash,
            'routing_leg_distances' => filled(
                $calculation['leg_distances_meters'] ?? [],
            )
                ? $calculation['leg_distances_meters']
                : null,
        ]);
    }
}
