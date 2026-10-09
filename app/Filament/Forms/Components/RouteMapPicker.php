<?php

namespace App\Filament\Forms\Components;

use Closure;
use EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\BaseLayer;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;

class RouteMapPicker extends MapPicker
{
    protected ?Closure $onAdjustmentPointMoveCallback = null;

    public function onAdjustmentPointMove(?Closure $callback): static
    {
        $this->onAdjustmentPointMoveCallback = $callback;

        return $this;
    }

    #[ExposedLivewireMethod]
    public function handleAdjustmentPointMove(
        string $layerId,
        float $latitude,
        float $longitude,
    ): void {
        $this->evaluate($this->onAdjustmentPointMoveCallback, [
            'layerId' => $layerId,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    #[ExposedLivewireMethod]
    public function handleLayerClick(string $layerId): void
    {
        $layer = null;

        foreach ($this->getLayers() as $currentLayer) {
            if (
                $currentLayer instanceof BaseLayer
                && $currentLayer->getId() === $layerId
            ) {
                $layer = $currentLayer;

                break;
            }
        }

        $this->evaluate($this->onLayerClickCallback, [
            'layer' => $layer,
        ]);
    }
}
