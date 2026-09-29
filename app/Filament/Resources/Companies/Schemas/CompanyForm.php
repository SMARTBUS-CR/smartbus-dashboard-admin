<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Enums\CompanyStatus;
use App\Enums\LucideIcon;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Unique;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Legal Information'))
                    ->description(__('The legal information of the company.'))
                    ->icon(Heroicon::OutlinedBuildingOffice)
                    ->collapsible()
                    ->schema([
                        TextInput::make('legal_name')
                            ->label(__('Legal Name'))
                            ->prefixIcon(Heroicon::OutlinedBuildingOffice2)
                            ->required()
                            ->live(onBlur: true)
                            ->minLength(5)
                            ->maxLength(255),

                        TextInput::make('trade_name')
                            ->label(__('Trade Name'))
                            ->prefixIcon(Heroicon::OutlinedBuildingOffice)
                            ->maxLength(255),

                        TextInput::make('legal_id')
                            ->label(__('Legal Identification'))
                            ->prefixIcon(Heroicon::OutlinedIdentification)
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('country_code', strtoupper((string) $get('country_code'))))
                            ->maxLength(255),

                        TextInput::make('operator_number')
                            ->label(__('Operator Number'))
                            ->prefixIcon(LucideIcon::IDCardLanyard)
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('country_code', strtoupper((string) $get('country_code'))))
                            ->maxLength(255),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make(__('Contact Information'))
                    ->description(__('The contact information of the company.'))
                    ->icon(Heroicon::OutlinedEnvelopeOpen)
                    ->collapsible()
                    ->schema([
                        TextInput::make('phone')
                            ->label(__('Phone'))
                            ->prefixIcon(Heroicon::OutlinedPhone)
                            ->tel()
                            ->required()
                            ->maxLength(50),

                        TextInput::make('email')
                            ->label(__('Email'))
                            ->prefixIcon(Heroicon::OutlinedEnvelope)
                            ->email()
                            ->required()
                            ->unique(column: 'email')
                            ->maxLength(255),

                        Textarea::make('address')
                            ->label(__('Address'))
                            ->required()
                            ->maxLength(255)
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make(__('Configuration'))
                    ->description(__('The configuration of the company.'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->collapsible()
                    ->schema([
                        TextInput::make('country_code')
                            ->label(__('Country'))
                            ->required()
                            ->prefixIcon(Heroicon::OutlinedGlobeAmericas)
                            ->length(2)
                            ->maxLength(2)
                            ->dehydrateStateUsing(
                                fn (?string $state): ?string => $state
                                    ? strtoupper($state)
                                    : null
                            ),

                        TextInput::make('timezone')
                            ->label(__('Timezone'))
                            ->prefixIcon(Heroicon::OutlinedClock)
                            ->required()
                            ->maxLength(64),

                        Select::make('status')
                            ->label(__('Status'))
                            ->prefixIcon(Heroicon::OutlinedCheckCircle)
                            ->options(CompanyStatus::class)
                            ->required(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
