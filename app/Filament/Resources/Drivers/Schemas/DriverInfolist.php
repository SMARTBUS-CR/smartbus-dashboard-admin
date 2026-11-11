<?php

namespace App\Filament\Resources\Drivers\Schemas;

use App\Enums\DriverStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DriverInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Identity'))
                ->schema([
                    TextEntry::make('user.name')
                        ->label(__('Name'))
                        ->placeholder(__('Unavailable Identity')),
                    TextEntry::make('user.email')
                        ->label(__('Email'))
                        ->placeholder(__('Unavailable Identity')),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make(__('Employment Details'))
                ->schema([
                    TextEntry::make('license')->label(__('License')),
                    TextEntry::make('license_expires_at')->label(__('License Expiration'))->date(),
                    TextEntry::make('employment_starts_on')->label(__('Employment Start'))->date(),
                    TextEntry::make('employment_ends_on')
                        ->label(__('Employment End'))
                        ->date()
                        ->placeholder(__('Open Ended')),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (DriverStatus $state): string => __(ucfirst($state->value))),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
