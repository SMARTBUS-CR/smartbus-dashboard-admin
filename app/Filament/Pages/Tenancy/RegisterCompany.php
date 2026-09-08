<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Company;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;

class RegisterCompany extends RegisterTenant
{
    /**
     * Get the label for the RegisterCompany page.
     *
     * @return string The label for the page.
     */
    public static function getLabel(): string
    {
        return 'Registrar nueva empresa';
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
                TextInput::make('name'),
                TextInput::make('slug'),
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
}
