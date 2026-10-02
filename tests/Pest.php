<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WithMysqlFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| RefreshDatabase migrates/refreshes the DEFAULT connection only (pgsql in
| phpunit.xml). The secondary mysql tables are created per test by
| WithMysqlFixture because DDL cannot be rolled back in a transaction.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class, WithMysqlFixture::class)
    ->in('Feature', 'Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| Each test still creates its own records; these helpers only remove
| duplication in how cross-database fixtures are wired.
|
*/

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

/**
 * Create a company on the pgsql connection.
 *
 * @param  array<string, mixed>  $overrides
 */
function createCompany(array $overrides = []): Company
{
    return Company::factory()->create($overrides);
}

/**
 * Create two companies with distinct slugs for tenant-isolation tests.
 *
 * @return array{0: Company, 1: Company}
 */
function createTenantPair(): array
{
    return [createCompany(), createCompany()];
}

/**
 * Create a mysql user and assign the given role, optionally scoped to a team.
 */
function createUserWithRole(string|UserRole $role, ?Company $team = null): User
{
    $roleName = $role instanceof UserRole ? $role->value : $role;
    $previousTeam = getPermissionsTeamId();
    setPermissionsTeamId($team?->getKey());

    try {
        $spatieRole = Role::findOrCreate($roleName, 'web');
        $user = User::factory()->create();
        $user->assignRole($spatieRole);
    } finally {
        setPermissionsTeamId($previousTeam);
    }

    return $user->unsetRelation('roles')->unsetRelation('permissions');
}

/**
 * Create Shield-style permissions and grant them to the user under the team.
 *
 * @param  array<int, string>  $permissions
 */
function grantShield(User $user, array $permissions, Company $team): void
{
    $previousTeam = getPermissionsTeamId();
    setPermissionsTeamId($team->getKey());
    try {
        $role = Role::create([
            'name' => 'test-grant-'.Str::uuid(),
            'guard_name' => 'web',
            'company_id' => $team->getKey(),
        ]);
        foreach ($permissions as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $user->assignRole($role);
    } finally {
        setPermissionsTeamId($previousTeam);
    }

    $user->unsetRelation('roles')->unsetRelation('permissions');
}

function actingAsInCompany(User $user, Company $company): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company, isQuiet: true);
    setPermissionsTeamId($company->getKey());
    $user->unsetRelation('roles')->unsetRelation('permissions');
    Filament::bootCurrentPanel();
}
