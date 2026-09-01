<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super-admin';
    case CompanyAdmin = 'company-admin';
    case Driver = 'driver';
    case Passenger = 'passenger';

    /**
     * Get human-readable label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Administrador',
            self::CompanyAdmin => 'Administrador de Empresa',
            self::Driver => 'Conductor',
            self::Passenger => 'Pasajero',
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


