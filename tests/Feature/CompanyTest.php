<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

pest()->use(LazilyRefreshDatabase::class);

beforeEach(function () {
    config()->set('database.default', 'pgsql');
    config()->set('database.connections.pgsql', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.connections.mysql', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'foreign_key_constraints' => true,
    ]);
    $this->refreshDatabase();
});

test('companies can be created with an automatic slug after migrations', function () {
    $company = Company::factory()->create([
        'trade_name' => 'SmartBus Demo',
        'slug' => null,
    ]);

    $this->assertDatabaseHas('companies', [
        'id' => $company->id,
        'slug' => 'smartbus-demo',
    ]);
});

test('soft deleted companies keep their slugs reserved', function () {
    $company = Company::factory()->create(['slug' => 'smartbus-demo']);
    $company->delete();

    $replacement = Company::factory()->create([
        'trade_name' => 'SmartBus Demo',
        'slug' => null,
    ]);

    $this->assertSoftDeleted($company);
    $this->assertDatabaseHas('companies', [
        'id' => $replacement->id,
        'slug' => 'smartbus-demo-2',
    ]);
});

test('a mysql user can attach and load companies from the pgsql connection without duplicate memberships', function () {
    $user = (new User)->newFromBuilder(['id' => '01a0c691-4b83-72f5-b21c-8e2a486c086c']);
    $company = Company::factory()->create();

    $user->companies()->syncWithoutDetaching([$company->id]);
    $user->companies()->syncWithoutDetaching([$company->id]);

    $this->assertDatabaseCount('company_users', 1, 'pgsql');
    $this->assertDatabaseHas('company_users', [
        'user_id' => $user->id,
        'company_id' => $company->id,
    ], 'pgsql');
    expect($user->companies->modelKeys())->toBe([$company->id]);
});
