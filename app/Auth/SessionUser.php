<?php

namespace App\Auth;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;

class SessionUser implements Authenticatable, FilamentUser
{
    public function __construct(protected array $attributes)
    {
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->attributes['id'] ?? null;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function __get($key)
    {
        return $this->getAttributeValue($key);
    }

    public function __isset($key)
    {
        return isset($this->attributes[$key]);
    }

    public function getAttributeValue($key)
    {
        if ($key === 'name' && empty($this->attributes['name'])) {
            return $this->attributes['username'] ?? null;
        }

        return $this->attributes[$key] ?? null;
    }

    public function getAttribute($key)
    {
        return $this->getAttributeValue($key);
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function getEmail(): string
    {
        return $this->attributes['email'] ?? '';
    }

    public function getName(): string
    {
        return $this->attributes['name'] ?? $this->attributes['username'] ?? '';
    }

    public function getFilamentName(): string
    {
        return $this->getName();
    }

    public function getKey()
    {
        return $this->getAuthIdentifier();
    }
}