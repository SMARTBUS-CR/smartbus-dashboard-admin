<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum CompanyStatus: string implements HasColor, HasLabel
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    /**
     * {@inheritDoc}
     */
    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::ACTIVE => __('Active'),
            self::INACTIVE => __('Inactive'),
        };
    }

    /**
     * Get the plural label for the enum case.
     */
    public function getPluralLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::ACTIVE => __('Active Companies'),
            self::INACTIVE => __('Inactive Companies'),
        };
    }

    /**
     * {@inheritDoc}
     */
    public function getColor(): string|array|null
    {
        return match ($this) {
            self::ACTIVE => 'success',
            self::INACTIVE => 'danger',
        };
    }
}
