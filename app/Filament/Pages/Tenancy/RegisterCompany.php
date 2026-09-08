<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Company;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;

class RegisterCompany extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Registrar Empresa';
    }

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

    protected function handleRegistration(array $data): Company
    {
        $company = Company::create($data);

        $company->attachUser(auth()->user());

        return $company;
    }
}
