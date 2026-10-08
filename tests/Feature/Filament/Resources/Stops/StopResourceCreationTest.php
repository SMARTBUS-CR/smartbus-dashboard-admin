<?php

use App\Enums\UserRole;
use App\Filament\Resources\Stops\Pages\CreateStop;
use App\Models\CompanyUser;
use App\Models\Stop;
use Livewire\Livewire;

describe('Stop Resource Creation', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany();
    });

    test('creates a stop in the selected company for authorized users', function (string $role): void {
        if ($role === UserRole::SuperAdmin->value) {
            $actor = createUserWithRole(UserRole::SuperAdmin);
        } else {
            $actor = createUserWithRole($role, $this->company);

            CompanyUser::create([
                'company_id' => $this->company->getKey(),
                'user_id' => $actor->getKey(),
            ]);

            grantShield($actor, [
                'ViewAny:Stop',
                'View:Stop',
                'Create:Stop',
            ], $this->company);
        }

        actingAsInCompany($actor, $this->company);

        Livewire::test(CreateStop::class)
            ->fillForm([
                'name' => 'Central Terminal',
                'description' => 'Main entrance.',
                'latitude' => '10.4523456',
                'longitude' => '-84.0123456',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Stop::class, [
            'company_id' => $this->company->getKey(),
            'name' => 'Central Terminal',
            'description' => 'Main entrance.',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);
    })->with([
        'system admin' => [UserRole::SuperAdmin->value],
        'company admin' => [UserRole::Admin->value],
        'custom role' => ['stop-editor'],
    ]);

    test('rejects invalid stop data', function (array $overrides, string $field, string $rule): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(CreateStop::class)
            ->fillForm(array_replace([
                'name' => 'Central Terminal',
                'description' => null,
                'latitude' => '10.4523456',
                'longitude' => '-84.0123456',
            ], $overrides))
            ->call('create')
            ->assertHasFormErrors([$field => $rule]);

        expect(Stop::query()->count())->toBe(0);
    })->with([
        'missing name' => [
            ['name' => null], 'name', 'required',
        ],
        'missing latitude' => [
            ['latitude' => null], 'latitude', 'required',
        ],
        'non-numeric latitude' => [
            ['latitude' => 'invalid'], 'latitude', 'numeric',
        ],
        'latitude above the maximum' => [
            ['latitude' => '90.1'], 'latitude', 'between',
        ],
        'latitude below the minimum' => [
            ['latitude' => '-90.1'], 'latitude', 'between',
        ],
        'missing longitude' => [
            ['longitude' => null], 'longitude', 'required',
        ],
        'non-numeric longitude' => [
            ['longitude' => 'invalid'], 'longitude', 'numeric',
        ],
        'longitude above the maximum' => [
            ['longitude' => '180.1'], 'longitude', 'between',
        ],
        'longitude below the minimum' => [
            ['longitude' => '-180.1'], 'longitude', 'between',
        ],
    ]);

    test('rejects creation without permission', function (): void {
        $actor = createUserWithRole('stop-viewer', $this->company);

        CompanyUser::create([
            'company_id' => $this->company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Stop',
            'View:Stop',
        ], $this->company);

        actingAsInCompany($actor, $this->company);

        Livewire::test(CreateStop::class)
            ->assertForbidden();

        expect(Stop::query()->count())->toBe(0);
    });

    test('ignores a forged company assignment when creating a company stop', function (bool $shared): void {
        $otherCompany = createCompany();

        $actor = createUserWithRole('stop-editor', $this->company);

        CompanyUser::create([
            'company_id' => $this->company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Stop',
            'View:Stop',
            'Create:Stop',
        ], $this->company);

        actingAsInCompany($actor, $this->company);

        Livewire::test(CreateStop::class)
            ->fillForm([
                'name' => 'Central Terminal',
                'description' => null,
                'latitude' => '10.4523456',
                'longitude' => '-84.0123456',
            ])
            ->set('data.company_id', $shared ? null : $otherCompany->getKey())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Stop::class, [
            'company_id' => $this->company->getKey(),
            'name' => 'Central Terminal',
        ]);

        expect(Stop::query()->count())->toBe(1);
    })->with([
        'another company' => [false],
        'shared stop' => [true],
    ]);

    test('allows a system admin to create a shared stop without company membership', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($actor, $this->company);

        Livewire::test(CreateStop::class)
            ->fillForm([
                'name' => 'Shared Terminal',
                'description' => 'Boarding point used by multiple companies.',
                'latitude' => '10.4523456',
                'longitude' => '-84.0123456',
                'is_shared' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Stop::class, [
            'company_id' => null,
            'name' => 'Shared Terminal',
            'description' => 'Boarding point used by multiple companies.',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        expect(Stop::query()->count())->toBe(1)
            ->and(CompanyUser::withTrashed()
                ->where('user_id', $actor->getKey())->exists())->toBeFalse();
    });

    test('does not create a shared stop from a forged flag sent by a company user', function (string $role): void {
        $actor = createUserWithRole($role, $this->company);

        CompanyUser::create([
            'company_id' => $this->company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Stop',
            'View:Stop',
            'Create:Stop',
        ], $this->company);

        actingAsInCompany($actor, $this->company);

        Livewire::test(CreateStop::class)
            ->fillForm([
                'name' => 'Company Terminal',
                'description' => null,
                'latitude' => '10.4523456',
                'longitude' => '-84.0123456',
            ])
            ->set('data.is_shared', true)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Stop::class, [
            'company_id' => $this->company->getKey(),
            'name' => 'Company Terminal',
        ]);

        expect(Stop::query()->whereNull('company_id')->exists())
            ->toBeFalse();
    })->with([
        'company admin' => [UserRole::Admin->value],
        'custom role' => ['stop-editor'],
    ]);
});
