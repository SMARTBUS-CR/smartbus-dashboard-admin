<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Filament\Resources\Trips\Schemas\TripInfolist;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Company;
use App\Models\User;
use App\Services\TripReadService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewTrip extends ViewRecord
{
    protected static string $resource = TripResource::class;

    /** @var array<string, mixed> */
    public array $history = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();

        $actor = Filament::auth()->user();
        $company = Filament::getTenant();

        abort_unless($actor instanceof User && $company instanceof Company, 403);

        $this->history = $this->withPresentation(
            app(TripReadService::class)->findFor($actor, $company, (string) $this->record->getKey()),
        );
    }

    public function infolist(Schema $schema): Schema
    {
        return TripInfolist::configure($schema)->constantState($this->history);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    private function withPresentation(array $history): array
    {
        $snapshot = $history['planned_route_snapshot'];
        $timezone = $snapshot['company_timezone'];
        $occurrences = $snapshot['occurrences'];

        $history['scheduled_departure_display'] = $this->timestamp($history['scheduled_departure_at'], $timezone);
        $history['scheduled_arrival_display'] = $this->timestamp($history['scheduled_arrival_at'], $timezone);
        $history['actual_departure_display'] = $this->timestamp($history['actual_departure_at'], $timezone);
        $history['actual_arrival_display'] = $this->timestamp($history['actual_arrival_at'], $timezone);
        $history['cancelled_display'] = $this->timestamp($history['cancelled_at'], $timezone);
        $history['route_geometry_display'] = json_encode(
            $snapshot['route_geometry'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
        $history['planned_route_snapshot']['origin'] = $occurrences[0]['name'] ?? null;
        $lastOccurrence = $occurrences[array_key_last($occurrences)] ?? null;
        $history['planned_route_snapshot']['destination'] = $lastOccurrence['name'] ?? null;
        $history['planned_route_snapshot']['occurrences'] = array_map(
            fn (array $occurrence): array => [
                ...$occurrence,
                'coordinates' => "{$occurrence['lat']}, {$occurrence['lng']}",
            ],
            $occurrences,
        );

        return $history;
    }

    private function timestamp(?string $instant, string $timezone): ?string
    {
        if ($instant === null) {
            return null;
        }

        $local = CarbonImmutable::parse($instant)->setTimezone($timezone)->format('Y-m-d H:i:s T');

        return "{$local} ({$instant})";
    }
}
