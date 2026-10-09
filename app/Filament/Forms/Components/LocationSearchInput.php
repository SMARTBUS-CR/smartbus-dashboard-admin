<?php

namespace App\Filament\Forms\Components;

use App\Services\PhotonGeocodingService;
use EduardoRibeiroDev\FilamentLeaflet\Fields\GeoSearchInput;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\GeoSearchResult;
use Filament\Notifications\Notification;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Livewire\Attributes\Renderless;
use RuntimeException;

class LocationSearchInput extends GeoSearchInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->minSearchLength(3);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function getSearchResults(string $search): array
    {
        if (mb_strlen(trim($search)) < $this->getMinSearchLength()) {
            return [];
        }

        try {
            $results = app(PhotonGeocodingService::class)->search($search);
        } catch (RuntimeException $exception) {
            Notification::make()
                ->warning()
                ->title(__('Location Search Unavailable'))
                ->body($exception->getMessage())
                ->send();

            return [];
        }

        return array_map(
            static fn (GeoSearchResult $result): array => [
                'label' => $result->displayName,
                'value' => json_encode(
                    $result->toArray(),
                    JSON_THROW_ON_ERROR,
                ),
            ],
            $results,
        );
    }
}
