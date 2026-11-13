<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class RouteDetourAnalyzer
{
    /**
     * @param  list<array{lat: float|int|string, lng: float|int|string}>  $points
     * @param  list<float|int>  $legDistances
     * @param  list<array{from_point_index: int, to_point_index: int}>  $segments
     * @return list<array{
     *     segment_index: int,
     *     distance_meters: float,
     *     reference_distance_meters: float
     * }>
     */
    public function analyze(
        array $points,
        array $legDistances,
        array $segments,
    ): array {
        Validator::make([
            'points' => $points,
            'legs' => $legDistances,
            'segments' => $segments,
        ], [
            'points' => ['required', 'array', 'list', 'min:2'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'legs' => [
                'required',
                'array',
                'list',
                'size:'.(count($points) - 1),
            ],
            'legs.*' => ['required', 'numeric', 'min:0'],
            'segments' => ['required', 'array', 'list', 'min:1'],
            'segments.*.from_point_index' => [
                'required',
                'integer',
                'min:0',
                'max:'.(count($points) - 2),
            ],
            'segments.*.to_point_index' => [
                'required',
                'integer',
                'min:1',
                'max:'.(count($points) - 1),
            ],
        ])->validate();

        $previousEnd = 0;

        foreach ($segments as $segment) {
            if (
                $segment['from_point_index'] !== $previousEnd
                || $segment['to_point_index'] <= $previousEnd
            ) {
                throw new InvalidArgumentException(
                    'Route segments must cover consecutive points in travel order.',
                );
            }

            $previousEnd = $segment['to_point_index'];
        }

        if ($previousEnd !== count($points) - 1) {
            throw new InvalidArgumentException(
                'Route segments must cover all routing points.',
            );
        }

        foreach ($legDistances as $distance) {
            if (! is_finite((float) $distance)) {
                throw new InvalidArgumentException(
                    'Route leg distances must be finite.',
                );
            }
        }

        $minimumRatio = (float) config(
            'services.osrm.detour.minimum_ratio',
            3,
        );

        $minimumExcess = (float) config(
            'services.osrm.detour.minimum_excess_meters',
            10000,
        );

        if (
            ! is_finite($minimumRatio)
            || $minimumRatio <= 1
            || ! is_finite($minimumExcess)
            || $minimumExcess <= 0
        ) {
            throw new InvalidArgumentException(
                'Route detour thresholds must be finite and positive, with a ratio greater than one.',
            );
        }

        $warnings = [];

        foreach ($segments as $index => $segment) {
            $distance = 0.0;
            $referenceDistance = 0.0;

            for (
                $pointIndex = $segment['from_point_index'];
                $pointIndex < $segment['to_point_index'];
                $pointIndex++
            ) {
                $distance += (float) $legDistances[$pointIndex];

                $referenceDistance += $this->distanceBetween(
                    $points[$pointIndex],
                    $points[$pointIndex + 1],
                );
            }

            if (
                $distance >= $referenceDistance * $minimumRatio
                && $distance - $referenceDistance >= $minimumExcess
            ) {
                $warnings[] = [
                    'segment_index' => $index,
                    'distance_meters' => $distance,
                    'reference_distance_meters' => $referenceDistance,
                ];
            }
        }

        return $warnings;
    }

    /**
     * @param  array{lat: float|int|string, lng: float|int|string}  $from
     * @param  array{lat: float|int|string, lng: float|int|string}  $to
     */
    private function distanceBetween(array $from, array $to): float
    {
        $fromLatitude = deg2rad((float) $from['lat']);
        $toLatitude = deg2rad((float) $to['lat']);

        $latitudeDifference = $toLatitude - $fromLatitude;
        $longitudeDifference = deg2rad(
            (float) $to['lng'] - (float) $from['lng'],
        );

        $value = sin($latitudeDifference / 2) ** 2
            + cos($fromLatitude)
            * cos($toLatitude)
            * sin($longitudeDifference / 2) ** 2;

        return 6371000 * 2 * asin(
            sqrt(max(0.0, min(1.0, $value))),
        );
    }
}
