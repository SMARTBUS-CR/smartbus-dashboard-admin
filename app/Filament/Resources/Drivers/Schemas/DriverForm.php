<?php

namespace App\Filament\Resources\Drivers\Schemas;

use App\Models\Driver;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DriverForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Identity Provisioning'))
                ->description(__('Create a new account or associate an existing active identity.'))
                ->schema([
                    Select::make('registration_type')
                        ->label(__('Identity Type'))
                        ->options([
                            'new' => __('New Identity'),
                            'existing' => __('Existing Identity'),
                        ])
                        ->default('new')
                        ->required()
                        ->live()
                        ->visible(fn (string $operation): bool => $operation === 'create'),

                    Select::make('user_id')
                        ->label(__('Existing User'))
                        ->searchable()
                        ->getSearchResultsUsing(static::searchUsers(...))
                        ->getOptionLabelUsing(static::userLabel(...))
                        ->required(fn (Get $get): bool => $get('registration_type') === 'existing')
                        ->visible(fn (string $operation, Get $get): bool => $operation === 'create'
                            && $get('registration_type') === 'existing'),

                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required(fn (Get $get): bool => $get('registration_type') === 'new')
                        ->maxLength(100)
                        ->visible(fn (string $operation, Get $get): bool => $operation === 'create'
                            && $get('registration_type') === 'new'),

                    TextInput::make('email')
                        ->label(__('Email'))
                        ->email()
                        ->required(fn (Get $get): bool => $get('registration_type') === 'new')
                        ->maxLength(100)
                        ->visible(fn (string $operation, Get $get): bool => $operation === 'create'
                            && $get('registration_type') === 'new'),

                    TextInput::make('password')
                        ->label(__('Password'))
                        ->password()
                        ->revealable()
                        ->confirmed()
                        ->autocomplete('new-password')
                        ->required(fn (Get $get): bool => $get('registration_type') === 'new')
                        ->visible(fn (string $operation, Get $get): bool => $operation === 'create'
                            && $get('registration_type') === 'new'),

                    TextInput::make('password_confirmation')
                        ->label(__('Confirm Password'))
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->required(fn (Get $get): bool => $get('registration_type') === 'new')
                        ->visible(fn (string $operation, Get $get): bool => $operation === 'create'
                            && $get('registration_type') === 'new'),
                ])
                ->columns(2)
                ->columnSpanFull()
                ->visible(fn (string $operation): bool => $operation === 'create'),

            Section::make(__('Employment Details'))
                ->schema([
                    TextInput::make('license')
                        ->label(__('License'))
                        ->required()
                        ->maxLength(50),

                    DatePicker::make('license_expires_at')
                        ->label(__('License Expiration'))
                        ->required(),

                    DatePicker::make('employment_starts_on')
                        ->label(__('Employment Start'))
                        ->required(),

                    DatePicker::make('employment_ends_on')
                        ->label(__('Employment End'))
                        ->rule('after_or_equal:employment_starts_on'),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make(__('Initial Monthly Salary'))
                ->description(__('Salary is required to create employment and remains confidential after registration.'))
                ->schema([
                    TextInput::make('salary_amount')
                        ->label(__('Amount'))
                        ->inputMode('decimal')
                        ->required()
                        ->rule('decimal:0,2')
                        ->minValue(0)
                        ->rule('max:9999999999.99'),

                    Select::make('salary_currency')
                        ->label(__('Currency'))
                        ->options(array_combine(Driver::SUPPORTED_CURRENCIES, Driver::SUPPORTED_CURRENCIES))
                        ->required(),

                    Hidden::make('salary_period')->default('monthly'),
                ])
                ->columns(2)
                ->columnSpanFull()
                ->visible(fn (string $operation): bool => $operation === 'create'),
        ]);
    }

    /** @return array<string, string> */
    private static function searchUsers(string $search): array
    {
        return User::query()
            ->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(25)
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $user): array => [
                $user->getKey() => static::formatUserLabel($user),
            ])
            ->all();
    }

    private static function userLabel(?string $value): ?string
    {
        $user = filled($value) ? User::query()->find($value, ['id', 'name', 'email']) : null;

        return $user instanceof User ? static::formatUserLabel($user) : null;
    }

    private static function formatUserLabel(User $user): string
    {
        return "{$user->name} ({$user->email})";
    }
}
