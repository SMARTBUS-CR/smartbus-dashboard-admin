<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

it('uses manual cross-database company-user queries instead of a direct belongsToMany relationship', function () {
    $company = new Company(['id' => 1]);
    $user = new User(['id' => 2]);

    $companyUsers = $company->users();
    $userCompanies = $user->companies();

    expect($companyUsers)
        ->toBeInstanceOf(Builder::class)
        ->and($userCompanies)
        ->toBeInstanceOf(Builder::class)
        ->and($companyUsers->getQuery()->from)
        ->toBe('users')
        ->and($userCompanies->getQuery()->from)
        ->toBe('companies');
});
