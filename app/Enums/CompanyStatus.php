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
            self::ACTIVE => trans_choice('Active', 2),
            self::INACTIVE => trans_choice('Inactive', 2),
        };
    }

    /**
     * Get the plural label for the enum case.
     */
    public function getPluralLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::ACTIVE => trans_choice('Active', 4),
            self::INACTIVE => trans_choice('Inactive', 4),
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
