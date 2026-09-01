<?php

namespace App\Filament\Resources\Companies\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Nombre de la Empresa'))
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')
                    ->label(__('Slug / Identificador'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                TextInput::make('legal_id')
                    ->label(__('RUT / Identificación Fiscal'))
                    ->maxLength(100),
                TextInput::make('phone')
                    ->label(__('Teléfono'))
                    ->tel()
                    ->maxLength(50),
                TextInput::make('email')
                    ->label(__('Correo Electrónico'))
                    ->email()
                    ->maxLength(255),
                Textarea::make('address')
                    ->label(__('Dirección'))
                    ->rows(3)
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label(__('Activa'))
                    ->default(true),
            ]);
    }
}
