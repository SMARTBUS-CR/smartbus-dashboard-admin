<?php

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

function companyFormData(array $overrides = []): array
{
    return array_replace(Company::factory()->make()->only([
        'legal_name', 'trade_name', 'legal_id', 'operator_number', 'phone',
        'email', 'address', 'country_code', 'timezone',
    ]), ['status' => 'active'], $overrides);
}

describe('Company Resource', function (): void {
    test('creates a company with normalized country, slug and default roles', function () {
        $tenant = createCompany();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $tenant);
        $data = companyFormData(['trade_name' => 'Transportes del Norte', 'country_code' => 'cr']);

        Livewire::test(CreateCompany::class)->fillForm($data)->call('create')->assertHasNoFormErrors();

        $company = Company::where('email', $data['email'])->sole();
        $this->assertDatabaseHas(Company::class, array_replace($data, ['country_code' => 'CR', 'slug' => 'transportes-del-norte']));
        expect($company->status)->toBe(CompanyStatus::ACTIVE)
            ->and($company->roles()->withoutGlobalScopes()->pluck('name')->sort()->values()->all())->toBe(['admin', 'driver'])
            ->and(getPermissionsTeamId())->toBe($tenant->id);
    });

    test('edits its own unique identifiers and changes status without changing the tenant slug', function () {
        $company = createCompany();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $company);

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->assertSchemaStateSet(['legal_name' => $company->legal_name, 'email' => $company->email])
            ->fillForm(['legal_name' => 'Updated Company', 'trade_name' => 'Updated Trade', 'status' => 'inactive'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseHas(Company::class, ['id' => $company->id, 'legal_name' => 'Updated Company', 'slug' => $company->slug, 'status' => 'inactive']);
        expect($company->fresh()->status)->toBe(CompanyStatus::INACTIVE);
    });

    test('rejects missing required company fields without persisting a company', function () {
        $tenant = createCompany();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $tenant);

        Livewire::test(CreateCompany::class)->fillForm([
            'legal_name' => null, 'phone' => null, 'email' => null, 'address' => null,
            'country_code' => null, 'timezone' => null, 'status' => null,
        ])->call('create')->assertHasFormErrors([
            'legal_name' => 'required', 'phone' => 'required', 'email' => 'required',
            'address' => 'required', 'country_code' => 'required', 'timezone' => 'required', 'status' => 'required',
        ]);

        $this->assertDatabaseCount(Company::class, 1);
    });

    test('rejects invalid company input without writing', function (string $field, mixed $value, ?string $rule) {
        $tenant = createCompany();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $tenant);

        Livewire::test(CreateCompany::class)->fillForm(companyFormData([$field => $value]))
            ->call('create')->assertHasFormErrors([$field => $rule]);

        $this->assertDatabaseCount(Company::class, 1);
    })->with([
        ['legal_name', 'ABCD', 'min'],
        ['legal_name', str_repeat('a', 256), 'max'],
        ['trade_name', str_repeat('a', 256), 'max'],
        ['legal_id', str_repeat('1', 256), 'max'],
        ['operator_number', str_repeat('1', 256), 'max'],
        ['phone', str_repeat('1', 51), 'max'],
        ['email', 'invalid-email', 'email'],
        ['address', str_repeat('a', 256), 'max'],
        ['country_code', 'CRI', 'size'],
        ['timezone', str_repeat('a', 65), 'max'],
        ['status', 'unknown', null],
    ]);

    test('rejects identifiers reserved by another company in the same country including trashed companies', function (string $field, bool $trashed) {
        $existing = createCompany(['country_code' => 'CR']);
        $tenant = createCompany();
        if ($trashed) {
            $existing->delete();
        }
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $tenant);

        Livewire::test(CreateCompany::class)->fillForm(companyFormData(['country_code' => 'cr', $field => $existing->$field]))
            ->call('create')->assertHasFormErrors([$field => 'unique']);

        $this->assertDatabaseCount(Company::class, 2);
    })->with(['legal_id', 'operator_number', 'email'])->with([false, true]);

    test('allows the same legal and operator identifiers in a different country', function () {
        $existing = createCompany(['country_code' => 'CR']);
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $existing);
        $data = companyFormData(['country_code' => 'PA', 'legal_id' => $existing->legal_id, 'operator_number' => $existing->operator_number]);

        Livewire::test(CreateCompany::class)->fillForm($data)->call('create')->assertHasNoFormErrors();

        $this->assertDatabaseHas(Company::class, $data);
    });

    test('soft deletes restores and permanently deletes through the edit page', function () {
        $tenant = createCompany();
        $company = createCompany();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $tenant);

        Livewire::test(EditCompany::class, ['record' => $company->id])->callAction('delete');
        $this->assertSoftDeleted($company);
        Livewire::test(EditCompany::class, ['record' => $company->id])->callAction('restore');
        $this->assertNotSoftDeleted($company);
        Livewire::test(EditCompany::class, ['record' => $company->id])->callAction('delete');
        Livewire::test(EditCompany::class, ['record' => $company->id])->callAction('forceDelete');
        $this->assertModelMissing($company);
        $this->assertModelExists($tenant);
    });

    test('bulk lifecycle only changes the selected companies', function () {
        $tenant = createCompany();
        $selected = Company::factory()->count(2)->create();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $tenant);

        Livewire::test(ListCompanies::class)->selectTableRecords($selected->modelKeys())
            ->callAction(TestAction::make('delete')->table()->bulk());
        foreach ($selected as $company) {
            $this->assertSoftDeleted($company);
        }
        Livewire::test(ListCompanies::class)->filterTable('trashed', false)->selectTableRecords($selected->modelKeys())
            ->callAction(TestAction::make('restore')->table()->bulk());
        foreach ($selected as $company) {
            $this->assertNotSoftDeleted($company);
            $company->delete();
        }
        Livewire::test(ListCompanies::class)->filterTable('trashed', false)->selectTableRecords($selected->modelKeys())
            ->callAction(TestAction::make('forceDelete')->table()->bulk());
        foreach ($selected as $company) {
            $this->assertModelMissing($company);
        }
        $this->assertNotSoftDeleted($tenant);
    });

    test('lists companies globally and filters active inactive and trashed records', function () {
        $active = createCompany();
        $inactive = Company::factory()->inactive()->create();
        $trashed = createCompany();
        $trashed->delete();
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $active);

        Livewire::test(ListCompanies::class)->assertCanSeeTableRecords([$active, $inactive])->assertCanNotSeeTableRecords([$trashed])
            ->set('activeTab', 'active')->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$inactive])
            ->set('activeTab', 'inactive')->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$active])
            ->set('activeTab', 'all')->filterTable('trashed', false)->assertCanSeeTableRecords([$trashed])->assertCanNotSeeTableRecords([$active, $inactive])
            ->filterTable('trashed', true)->assertCanSeeTableRecords([$active, $inactive, $trashed]);
    });

    test('searches the configured company columns', function (string $column, string $first, string $second) {
        $matching = createCompany([$column => $first]);
        $other = createCompany(array_replace([
            'legal_id' => 'LEGAL-ZULU',
            'legal_name' => 'Zulu Transit',
            'operator_number' => 'OP-ZULU',
            'email' => 'zulu@example.test',
            'country_code' => 'PA',
            'phone' => '99999999',
        ], [$column => $second]));
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $matching);

        Livewire::test(ListCompanies::class)->searchTable($first)
            ->assertCanSeeTableRecords([$matching])->assertCanNotSeeTableRecords([$other]);
    })->with([
        ['legal_id', 'LEGAL-ALPHA', 'LEGAL-ZULU'], ['legal_name', 'Alpha Transit', 'Zulu Transit'],
        ['operator_number', 'OP-ALPHA', 'OP-ZULU'], ['email', 'alpha@example.test', 'zulu@example.test'],
        ['country_code', 'CR', 'PA'], ['phone', '11111111', '99999999'],
    ]);

    test('sorts the configured company columns in both directions', function (string $column, string $first, string $second) {
        $last = createCompany([$column => $second]);
        $firstCompany = createCompany([$column => $first]);
        actingAsInCompany(createUserWithRole(UserRole::SuperAdmin), $firstCompany);

        Livewire::test(ListCompanies::class)->sortTable($column)->assertCanSeeTableRecords([$firstCompany, $last], inOrder: true)
            ->sortTable($column, 'desc')->assertCanSeeTableRecords([$last, $firstCompany], inOrder: true);
    })->with([
        ['legal_id', '100', '900'], ['legal_name', 'Alpha Transit', 'Zulu Transit'],
        ['operator_number', '100', '900'], ['email', 'alpha@example.test', 'zulu@example.test'],
        ['country_code', 'CR', 'PA'], ['phone', '11111111', '99999999'],
        ['created_at', '2025-01-01 00:00:00', '2025-02-01 00:00:00'],
    ]);

    test('denies a company administrator the companies pages even with company permissions', function () {
        config([
            'services.smartbus.gateway.url' => 'https://gateway.test',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'https://gateway.test/auth/token/validate' => Http::response([
                'meta' => ['valid' => true],
            ]),
        ]);

        $this->withSession([
            'external_auth_token' => 'test-token',
        ]);

        $own = createCompany();
        $foreign = createCompany();
        $admin = createUserWithRole(UserRole::Admin, $own);
        $admin->companies()->syncWithoutDetaching([$own->id]);
        grantShield($admin, ['ViewAny:Company', 'Create:Company', 'Update:Company', 'Delete:Company'], $own);
        actingAsInCompany($admin, $own);

        $this->get(CompanyResource::getUrl('index', tenant: $own))->assertForbidden();
        $this->get(CompanyResource::getUrl('create', tenant: $own))->assertForbidden();
        $this->get(CompanyResource::getUrl('edit', ['record' => $foreign], tenant: $own))->assertForbidden();
        $this->assertModelExists($foreign);
    });

    test('redirects guests to the panel login', function () {
        $company = createCompany();

        $this->get(CompanyResource::getUrl('index', tenant: $company))->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    });
});
