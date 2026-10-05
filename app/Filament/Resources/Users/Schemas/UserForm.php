<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Role;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Account Information'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(100),

                    TextInput::make('email')
                        ->label(__('Email'))
                        ->email()
                        ->required()
                        ->maxLength(100),

                    Select::make('roles')
                        ->label(__('Roles'))
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => self::getRoleOptions())
                        ->default([])
                        ->required()
                        ->minItems(1),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make(__('Access Credentials'))
                ->schema([
                    TextInput::make('password')
                        ->label(__('Password'))
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->confirmed(fn (Get $get): bool => filled($get('password')))
                        ->autocomplete('new-password'),

                    TextInput::make('password_confirmation')
                        ->label(__('Confirm Password'))
                        ->password()
                        ->revealable()
                        ->required(
                            fn (string $operation, Get $get): bool => $operation === 'create' || filled($get('password'))
                        )
                        ->autocomplete('new-password'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return array<int|string, string>
     */
    private static function getRoleOptions(): array
    {
        $company = Filament::getTenant();

        if (! $company instanceof Company || $company->trashed()) {
            return [];
        }

        return Role::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('guard_name', 'web')
            ->whereNotIn('name', [
                UserRole::SuperAdmin->value,
                UserRole::Driver->value,
                UserRole::Passenger->value,
            ])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Role $role): array => [
                $role->getKey() => filled($role->display_name)
                    ? UserRole::tryFrom($role->name)?->getLabel() 
                    ?: $role->display_name 
                    : $role->name,
            ])
            ->all();
    }
}
