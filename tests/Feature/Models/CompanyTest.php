<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

describe('Company Slug Lifecycle', function () {
    test('generates a slug from the trade name when none is given', function () {
        $company = createCompany(['trade_name' => 'SmartBus Demo', 'slug' => null]);

        expect($company->slug)->toBe('smartbus-demo');
    });

    test('falls back to the legal name when no trade name exists', function () {
        $company = createCompany([
            'legal_name' => 'Transportes del Norte SA',
            'trade_name' => null,
            'slug' => null,
        ]);

        expect($company->slug)->toBe('transportes-del-norte-sa');
    });

    test('suffixed slug when the base slug is taken', function () {
        createCompany(['slug' => 'smartbus-demo']);
        $replacement = createCompany(['trade_name' => 'SmartBus Demo', 'slug' => null]);

        expect($replacement->slug)->toBe('smartbus-demo-2');
    });

    test('does not regenerate the slug when names change', function () {
        $company = createCompany(['slug' => 'original-slug']);

        $company->update(['legal_name' => 'Completely Different Name', 'trade_name' => 'Other Trade']);

        // Product/design assumption: slug is the tenant URL key and stays immutable.
        expect($company->fresh()->slug)->toBe('original-slug');
    });

    test('keeps soft-deleted slugs reserved', function () {
        $company = createCompany(['slug' => 'smartbus-demo']);
        $company->delete();

        // PostgreSQL aborts the transaction on error: the failing-path
        // assertion (duplicate must not exist live) goes last.
        $replacement = createCompany(['trade_name' => 'SmartBus Demo', 'slug' => null]);

        expect($replacement->slug)->toBe('smartbus-demo-2');
        $this->assertSoftDeleted($company);
    });
});

describe('Cross Database Membership', function () {
    test('returns no users for an empty company and orders its active members by name', function () {
        [$company, $empty] = createTenantPair();
        $last = User::factory()->create(['name' => 'Zulu']);
        $first = User::factory()->create(['name' => 'Alpha']);
        $deleted = User::factory()->create(['name' => 'Deleted']);
        foreach ([$last, $first, $deleted] as $user) {
            $user->companies()->syncWithoutDetaching([$company->id]);
        }
        $deleted->delete();

        expect($empty->users()->pluck('id')->all())->toBeEmpty()
            ->and($company->users()->pluck('id')->all())->toBe([$first->id, $last->id]);
    });

    test('attaches a mysql user to a pgsql company without duplicate memberships', function () {
        $user = User::factory()->create();
        $company = createCompany();

        $user->companies()->syncWithoutDetaching([$company->id]);
        $user->companies()->syncWithoutDetaching([$company->id]);

        expect(CompanyUser::where('company_id', $company->id)->count())->toBe(1)
            ->and($user->companies()->where('companies.id', $company->id)->exists())->toBeTrue();
    });

    test('only returns users attached to that company, not other companies', function () {
        // Would pass with a single record even if filtering were broken.
        [$companyA, $companyB] = createTenantPair();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userA->companies()->syncWithoutDetaching([$companyA->id]);
        $userB->companies()->syncWithoutDetaching([$companyB->id]);

        // Company::users() runs a manual cross-connection query (BelongsToMany
        // cannot span connections): assert it filters, not its return type.
        expect($companyA->users()->pluck('users.id')->all())->toBe([$userA->id])
            ->and($companyB->users()->pluck('users.id')->all())->toBe([$userB->id]);
    });
});

describe('Company Identifier Constraints', function (): void {
    test('the database reserves company identifiers by country even after soft deletion', function (string $field) {
        $company = createCompany(['country_code' => 'CR']);
        $company->delete();
        $duplicate = Company::factory()->make(['country_code' => 'CR', $field => $company->$field]);

        // A constraint violation aborts the PostgreSQL transaction, so it is the final operation.
        expect(fn () => $duplicate->save())->toThrow(UniqueConstraintViolationException::class);
    })->with(['slug', 'legal_id', 'operator_number']);
});

describe('Company Default Roles', function (): void {
    test('creates default admin and driver roles scoped to the new company', function () {
        $company = createCompany();

        setPermissionsTeamId($company->getKey());
        $adminRole = Role::findByName(UserRole::Admin->value, 'web');
        $driverRole = Role::findByName(UserRole::Driver->value, 'web');
        setPermissionsTeamId(null);

        expect((string) $adminRole->company_id)->toBe((string) $company->id)
            ->and((string) $driverRole->company_id)->toBe((string) $company->id);
    });
});
