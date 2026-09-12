<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

enum UserRole: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case SuperAdmin = 'super-admin';
    case CompanyAdmin = 'company-admin';
    case Driver = 'driver';
    case Passenger = 'passenger';

    /**
     * Get the icon associated with the role.
     */
    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::SuperAdmin => Heroicon::OutlinedShieldCheck,
            self::CompanyAdmin => Heroicon::OutlinedBuildingOffice,
            self::Driver => Heroicon::OutlinedTruck,
            self::Passenger => Heroicon::OutlinedUserGroup,
        };
    }

    /**
     * Get human-readable label for the role.
     */
    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::SuperAdmin => __('roles.label.super-admin'),
            self::CompanyAdmin => __('roles.label.company-admin'),
            self::Driver => __('roles.label.driver'),
            self::Passenger => __('roles.label.passenger'),
        };
    }

    /**
     * Get human-readable description for the role.
     */
    public function getDescription(): string|Htmlable|null
    {
        return match ($this) {
            self::SuperAdmin => __('roles.description.super-admin'),
            self::CompanyAdmin => __('roles.description.company-admin'),
            self::Driver => __('roles.description.driver'),
            self::Passenger => __('roles.description.passenger'),
        };
    }

    /**
     * Get color associated with the role for UI representation.
     */
    public function getColor(): string|array|null
    {
        return match ($this) {
            self::SuperAdmin => 'danger',
            self::CompanyAdmin => 'primary',
            self::Driver => 'success',
            self::Passenger => 'secondary',
        };
    }

    /**
     * Determine if the role can access the Filament administration panel.
     */
    public function hasAdminAccess(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::CompanyAdmin => true,
            self::Driver, self::Passenger => false,
        };
    }

    /**
     * Get array of all role values allowed in the admin dashboard.
     *
     * @return array<string>
     */
    public static function adminRoles(): array
    {
        return [
            self::SuperAdmin->value,
            self::CompanyAdmin->value,
        ];
    }
}
