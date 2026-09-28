<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum NavigationGroup implements HasLabel
{
    case Transport;

    public function getLabel(): string
    {
        return match ($this) {
            self::Transport => __('Transport'),
        };
    }
}
