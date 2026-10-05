<?php

namespace App\Filament\Resources\SuperAdmins\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SuperAdminForm
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
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make(__('Access Credentials'))
                ->schema([
                    TextInput::make('password')
                        ->label(__('Password'))
                        ->password()
                        ->revealable()
                        ->required(
                            fn (string $operation): bool => $operation === 'create'
                        )
                        ->confirmed(
                            fn (Get $get): bool => filled($get('password'))
                        )
                        ->autocomplete('new-password'),

                    TextInput::make('password_confirmation')
                        ->label(__('Confirm Password'))
                        ->password()
                        ->revealable()
                        ->required(
                            fn (string $operation, Get $get): bool => $operation === 'create'
                                || filled($get('password'))
                        )
                        ->autocomplete('new-password'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
