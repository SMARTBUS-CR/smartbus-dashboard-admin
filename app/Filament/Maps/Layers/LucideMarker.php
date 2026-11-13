<?php

namespace App\Filament\Maps\Layers;

use App\Enums\LucideIcon;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;

class LucideMarker extends Marker
{
    protected ?LucideIcon $lucideIcon = null;

    public function lucideIcon(LucideIcon $icon): static
    {
        $this->lucideIcon = $icon;

        return $this;
    }

    public function getIconOptions(): array
    {
        $options = parent::getIconOptions();

        if ($this->lucideIcon !== null) {
            $options['heroicon'] = svg(
                $this->lucideIcon->value,
            )->toHtml();
        }

        return $options;
    }
}
