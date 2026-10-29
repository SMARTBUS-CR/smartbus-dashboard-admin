<?php

namespace App\Services;

use InvalidArgumentException;

use function count;
use function in_array;
use function is_string;

class RouteRoutingPointsService
{
    /**
     * @param list<array{
     *     occurrence_id: string,
     *     lat: float|int|string,
     *     lng: float|int|string
     * }> $stops
     * @param list<array{
     *     from_occurrence_id: string,
     *     to_occurrence_id: string,
     *     points: list<array{
     *         lat: float|int|string,
     *         lng: float|int|string
     *     }>
     * }> $adjustments
     * @return list<array{lat: float, lng: float}>
     */
    public function build(array $stops, array $adjustments): array
    {
        $this->validateOccurrences($stops);
        $this->validateCoordinates($stops, $adjustments);
        $this->validateSegments($stops, $adjustments);

        $points = [];

        foreach ($stops as $index => $stop) {
            $points[] = [
                'lat' => (float) $stop['lat'],
                'lng' => (float) $stop['lng'],
            ];

            $nextStop = $stops[$index + 1] ?? null;

            if ($nextStop === null) {
                continue;
            }

            foreach ($adjustments as $adjustment) {
                if (
                    $adjustment['from_occurrence_id'] !== $stop['occurrence_id']
                    || $adjustment['to_occurrence_id'] !== $nextStop['occurrence_id']
                ) {
                    continue;
                }

                foreach ($adjustment['points'] as $point) {
                    $points[] = [
                        'lat' => (float) $point['lat'],
                        'lng' => (float) $point['lng'],
                    ];
                }
            }
        }

        if (count($points) > 100) {
            throw new InvalidArgumentException(
                'Route calculations may contain at most 100 points, including stops and adjustments.',
            );
        }

        return $points;
    }

    /**
     * @param list<array{
     *     occurrence_id: string,
     *     lat: float|int|string,
     *     lng: float|int|string
     * }> $stops
     * @param list<array{
     *     from_occurrence_id: string,
     *     to_occurrence_id: string,
     *     points: list<array{
     *         lat: float|int|string,
     *         lng: float|int|string
     *     }>
     * }> $adjustments
     */
    private function validateSegments(array $stops, array $adjustments): void
    {
        $occurrenceIds = array_column($stops, 'occurrence_id');
        $usedSegments = [];

        foreach ($adjustments as $adjustment) {
            $fromIndex = array_search(
                $adjustment['from_occurrence_id'],
                $occurrenceIds,
                true,
            );

            if (
                $fromIndex === false
                || ($occurrenceIds[$fromIndex + 1] ?? null)
                !== $adjustment['to_occurrence_id']
            ) {
                throw new InvalidArgumentException(
                    'Route adjustments must connect consecutive stop occurrences in travel order.',
                );
            }

            if (isset($usedSegments[$fromIndex])) {
                throw new InvalidArgumentException(
                    'Each route segment may have only one adjustment point list.',
                );
            }

            $usedSegments[$fromIndex] = true;
        }
    }

    /**
     * @param list<array{
     *     occurrence_id: string,
     *     lat: float|int|string,
     *     lng: float|int|string
     * }> $stops
     * @param list<array{
     *     from_occurrence_id: string,
     *     to_occurrence_id: string,
     *     points: list<array{
     *         lat: float|int|string,
     *         lng: float|int|string
     *     }>
     * }> $adjustments
     */
    private function validateCoordinates(array $stops, array $adjustments): void
    {
        foreach ($stops as $stop) {
            $this->validateCoordinate($stop);
        }

        foreach ($adjustments as $adjustment) {
            foreach ($adjustment['points'] as $point) {
                $this->validateCoordinate($point);
            }
        }
    }

    /**
     * @param  array{lat?: mixed, lng?: mixed}  $point
     */
    private function validateCoordinate(array $point): void
    {
        foreach (['lat' => 90, 'lng' => 180] as $field => $limit) {
            $value = $point[$field] ?? null;

            if (! is_numeric($value)) {
                throw new InvalidArgumentException(
                    'Routing coordinates must be numeric.',
                );
            }

            $coordinate = (float) $value;

            if (
                ! is_finite($coordinate)
                || $coordinate < -$limit
                || $coordinate > $limit
            ) {
                throw new InvalidArgumentException(
                    'Routing coordinates must be finite and within geographic bounds.',
                );
            }
        }
    }

    /**
     * @param list<array{
     *     occurrence_id: string,
     *     lat: float|int|string,
     *     lng: float|int|string
     * }> $stops
     */
    private function validateOccurrences(array $stops): void
    {
        if (count($stops) < 2) {
            throw new InvalidArgumentException(
                'Route calculations require at least two stop occurrences.',
            );
        }

        $identifiers = [];

        foreach ($stops as $stop) {
            $identifier = $stop['occurrence_id'] ?? null;

            if (! is_string($identifier) || trim($identifier) === '') {
                throw new InvalidArgumentException(
                    'Every stop occurrence must have a nonempty string identifier.',
                );
            }

            if (in_array($identifier, $identifiers, true)) {
                throw new InvalidArgumentException(
                    'Stop occurrence identifiers must be unique within the route.',
                );
            }

            $identifiers[] = $identifier;
        }
    }
}
