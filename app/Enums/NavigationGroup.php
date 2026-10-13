<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum NavigationGroup implements HasLabel
{
    case Transport;
    case AccessManagement;

    public function getLabel(): string
    {
        return match ($this) {
            self::Transport => __('Transport'),
            self::AccessManagement => __('Access Management'),
        };
    }
}
