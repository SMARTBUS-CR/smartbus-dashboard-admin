<?php

namespace App\Filament\Resources\Routes\Schemas;

use App\Enums\LucideIcon;
use App\Models\Route;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

class RouteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make(__('Route Setup'))
                ->description(__(
                    'Create the route first. You can add patterns after saving.'
                ))
                ->info()
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->columnSpanFull(),

            Section::make(__('General Information'))
                ->icon(LucideIcon::Route)
                ->description(__('The code and name used to identify this route.'))
                ->schema([
                    TextInput::make('code')
                        ->label(__('Code'))
                        ->prefixIcon(LucideIcon::Barcode)
                        ->required()
                        ->maxLength(50)
                        ->rules(fn (?Route $record): array => [
                            Rule::unique(Route::class, 'code')
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->ignore($record?->getKey()),
                        ]),

                    TextInput::make('name')
                        ->label(__('Route Name'))
                        ->prefixIcon(Heroicon::OutlinedMap)
                        ->required()
                        ->maxLength(255),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
