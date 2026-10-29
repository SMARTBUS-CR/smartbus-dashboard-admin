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
    case Admin = 'admin';
    case Driver = 'driver';
    case Passenger = 'passenger';

    /**
     * Get the color associated with the user role
     * for UI representation.
     *
     * {@inheritDoc}
     */
    public function getColor(): string|array|null
    {
        return match ($this) {
            self::SuperAdmin => 'danger',
            self::Admin => 'primary',
            self::Driver => 'success',
            self::Passenger => 'secondary',
        };
    }

    /**
     * Get the description associated with the user role.
     *
     * {@inheritDoc}
     */
    public function getDescription(): string|Htmlable|null
    {
        return match ($this) {
            self::SuperAdmin => __('roles.description.super-admin'),
            self::Admin => __('roles.description.admin'),
            self::Driver => __('roles.description.driver'),
            self::Passenger => __('roles.description.passenger'),
        };
    }

    /**
     * Get the icon associated with the user role
     * for UI representation.
     *
     * {@inheritDoc}
     */
    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::SuperAdmin => Heroicon::OutlinedShieldCheck,
            self::Admin => Heroicon::OutlinedBuildingOffice,
            self::Driver => Heroicon::OutlinedTruck,
            self::Passenger => Heroicon::OutlinedUserGroup,
        };
    }

    /**
     * Get the label associated with the user role
     * for UI representation.
     *
     * {@inheritDoc}
     */
    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::SuperAdmin => __('roles.label.super-admin'),
            self::Admin => __('roles.label.admin'),
            self::Driver => __('roles.label.driver'),
            self::Passenger => __('roles.label.passenger'),
        };
    }

    /**
     * Determine if the role can access the Filament administration panel.
     */
    public function hasAdminAccess(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::Admin => true,
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
            self::Admin->value,
        ];
    }

    public static function protectedRoles(): array
    {
        return [
            self::SuperAdmin->value,
            self::Admin->value,
            self::Driver->value,
            self::Passenger->value,
        ];
    }
}
