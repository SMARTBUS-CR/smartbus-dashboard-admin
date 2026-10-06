<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Enums\CompanyStatus;
use App\Enums\LucideIcon;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;

class CompanyForm
{
    private const COUNTRIES = [
        'BZ' => 'Belize',
        'CR' => 'Costa Rica',
        'SV' => 'El Salvador',
        'GT' => 'Guatemala',
        'HN' => 'Honduras',
        'NI' => 'Nicaragua',
        'PA' => 'Panama',
    ];

    private const COUNTRY_TIMEZONES = [
        'BZ' => 'America/Belize',
        'CR' => 'America/Costa_Rica',
        'SV' => 'America/El_Salvador',
        'GT' => 'America/Guatemala',
        'HN' => 'America/Tegucigalpa',
        'NI' => 'America/Managua',
        'PA' => 'America/Panama',
    ];

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
                        PhoneInput::make('phone')
                            ->label(__('Phone'))
                            ->helperText(__(
                                'The phone number may belong to a different country than the company.'
                            ))
                            ->required()
                            ->rules(['string', 'max:50'])
                            ->initialCountry('cr')
                            ->defaultCountry('CR')
                            ->disableLookup()
                            ->countryOrder(['cr', 'gt', 'sv', 'hn', 'ni', 'pa', 'bz'])
                            ->validateFor(country: 'INTERNATIONAL')
                            ->inputNumberFormat(PhoneInputNumberType::E164)
                            ->displayNumberFormat(PhoneInputNumberType::INTERNATIONAL),

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
                        Select::make('country_code')
                            ->label(__('Country'))
                            ->prefixIcon(Heroicon::OutlinedGlobeAmericas)
                            ->options(fn (): array => array_map(
                                fn (string $name): string => __($name),
                                self::COUNTRIES,
                            ))
                            ->required()
                            ->searchable()
                            ->live()
                            ->rules([
                                'string',
                                'size:2',
                                Rule::in(array_keys(self::COUNTRIES)),
                            ])
                            ->mutateStateForValidationUsing(
                                fn (?string $state): ?string => $state !== null
                                    ? strtoupper($state)
                                    : null,
                            )
                            ->dehydrateStateUsing(
                                fn (?string $state): ?string => $state !== null
                                    ? strtoupper($state)
                                    : null,
                            )
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                $country = strtoupper($state ?? '');

                                $set('country_code', $state !== null ? $country : null);
                                $set('timezone', self::COUNTRY_TIMEZONES[$country] ?? null);
                            }),

                        Select::make('timezone')
                            ->label(__('Timezone'))
                            ->prefixIcon(Heroicon::OutlinedClock)
                            ->helperText(__(
                                'Used to interpret and display company dates and times.'
                            ))
                            ->options(function (Get $get): array {
                                $country = strtoupper((string) $get('country_code'));
                                $timezone = self::COUNTRY_TIMEZONES[$country] ?? null;

                                return $timezone !== null
                                    ? [$timezone => $timezone]
                                    : [];
                            })
                            ->required()
                            ->rules(function (Get $get): array {
                                $country = strtoupper((string) $get('country_code'));
                                $timezone = self::COUNTRY_TIMEZONES[$country] ?? null;

                                return [
                                    'string',
                                    'max:64',
                                    Rule::in($timezone !== null ? [$timezone] : []),
                                ];
                            }),

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
