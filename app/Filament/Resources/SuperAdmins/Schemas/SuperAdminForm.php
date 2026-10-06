<?php

namespace App\Filament\Resources\SuperAdmins\Schemas;

use App\Enums\LucideIcon;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;

class SuperAdminForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Account Information'))
                ->icon(Heroicon::OutlinedUserCircle)
                ->description(__('The account information of the user.'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->prefixIcon(LucideIcon::UserRound)
                        ->required()
                        ->maxLength(100),

                    TextInput::make('email')
                        ->label(__('Email'))
                        ->prefixIcon(LucideIcon::Mail)
                        ->email()
                        ->required()
                        ->maxLength(100),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make(__('Access Credentials'))
                ->icon(Heroicon::OutlinedKey)
                ->description(__('The access credentials of the user.'))
                ->schema([
                    Callout::make(__('Leave both password fields blank to keep the current password.'))
                        ->info()
                        ->visible(fn (string $operation): bool => $operation === 'edit')
                        ->columnSpanFull(),

                    TextInput::make('password')
                        ->label(__('Password'))
                        ->prefixIcon(Heroicon::OutlinedLockClosed)
                        ->belowContent([
                            Icon::make(Heroicon::OutlinedInformationCircle)
                                ->color(Color::Gray)
                                ->size(IconSize::Small),
                            __('Minimum 8 characters. Includes uppercase, numbers, and symbols.'),
                        ])
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->confirmed(fn (Get $get): bool => filled($get('password')))
                        ->autocomplete('new-password'),

                    TextInput::make('password_confirmation')
                        ->label(__('Confirm Password'))
                        ->prefixIcon(Heroicon::LockClosed)
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
