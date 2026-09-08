<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Company;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class RegisterCompany extends RegisterTenant
{
    /**
     * Get the label for the RegisterCompany page.
     *
     * @return string The label for the page.
     */
    public static function getLabel(): string
    {
        return __('form.register-tenant.title');
    }

    /**
     * Get the subheading for the RegisterCompany page.
     *
     * @return string|null The subheading for the page, or null if not defined.
     */
    public function getSubheading(): ?string
    {
        return __('form.register-tenant.description');
    }

    /**
     * Define the form schema for the company registration process.
     *
     * @param  Schema  $schema  The schema instance to define the form components.
     * @return Schema The modified schema with the company registration form components.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->live(onBlur: true)
                    ->required()
                    ->unique(Company::class, 'name')
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state))),
                TextInput::make('slug')
                    ->disabled()
                    ->required()
                    ->unique(Company::class, 'slug')
                    ->visible(false),
                TextInput::make('legal_id'),
                TextInput::make('phone'),
                TextInput::make('email'),
                TextInput::make('address'),
            ]);
    }

    /**
     * Handle the company registration process.
     *
     * @param  array  $data  The data submitted from the registration form.
     * @return Company The newly created company instance.
     */
    protected function handleRegistration(array $data): Company
    {
        $company = Company::create($data);

        $company->attachUser(auth()->user());

        return $company;
    }

    /**
     * Get the actions for the company registration form.
     *
     * @return array<Action | ActionGroup> The form actions.
     */
    protected function getFormActions(): array
    {
        return [
            $this->getRegisterFormAction(),
            $this->getBackFormAction(),
        ];
    }

    /**
     * Get the action to navigate back from the registration page.
     *
     * @return Action The back action.
     */
    public function getBackFormAction(): Action
    {
        return Action::make('back')
            ->label(__('form.register-tenant.actions.back.label'))
            ->color('gray')
            ->url(fn (): string => url()->previous() !== url()->current() ? url()->previous() : Filament::getUrl());
    }

    /**
     * Determine if the form actions should be displayed in full width.
     *
     * @return bool True if the form actions should be full width, false otherwise.
     */
    protected function hasFullWidthFormActions(): bool
    {
        return false;
    }
}
