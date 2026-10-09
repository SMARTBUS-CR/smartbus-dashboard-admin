<?php

use App\Enums\UserRole;
use App\Filament\Resources\Stops\Pages\EditStop;
use App\Models\CompanyUser;
use App\Models\Stop;
use Livewire\Livewire;

describe('Stop Resource Update', function (): void {
    beforeEach(function (): void {
        $this->company = createCompany();

        $this->stop = Stop::factory()->for($this->company)->create([
            'name' => 'Central Terminal',
            'description' => 'Main entrance.',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);
    });

    test('updates a company stop for authorized users', function (string $role): void {
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
                'Update:Stop',
            ], $this->company);
        }

        actingAsInCompany($actor, $this->company);

        Livewire::test(EditStop::class, [
            'record' => $this->stop->getRouteKey(),
        ])
            ->assertSchemaStateSet([
                'name' => 'Central Terminal',
                'description' => 'Main entrance.',
                'latitude' => '10.4523456',
                'longitude' => '-84.0123456',
            ])
            ->fillForm([
                'name' => 'North Terminal',
                'description' => 'Boarding area.',
                'latitude' => '10.4623456',
                'longitude' => '-84.0223456',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Stop::class, [
            'id' => $this->stop->getKey(),
            'company_id' => $this->company->getKey(),
            'name' => 'North Terminal',
            'description' => 'Boarding area.',
            'latitude' => '10.4623456',
            'longitude' => '-84.0223456',
        ]);
    })->with([
        'system admin' => [UserRole::SuperAdmin->value],
        'company admin' => [UserRole::Admin->value],
        'custom role' => ['stop-editor'],
    ]);

    test('rejects invalid changes and preserves the stop', function (array $overrides, string $field, string $rule): void {
        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(EditStop::class, [
            'record' => $this->stop->getRouteKey(),
        ])
            ->fillForm(array_replace([
                'name' => 'Central Terminal',
                'description' => 'Main entrance.',
                'latitude' => '10.4523456',
                'longitude' => '-84.0123456',
            ], $overrides))
            ->call('save')
            ->assertHasFormErrors([$field => $rule]);

        $this->assertDatabaseHas(Stop::class, [
            'id' => $this->stop->getKey(),
            'company_id' => $this->company->getKey(),
            'name' => 'Central Terminal',
            'description' => 'Main entrance.',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);
    })->with([
        'missing name' => [
            ['name' => null], 'name', 'required',
        ],
        'latitude outside its range' => [
            ['latitude' => '90.1'], 'latitude', 'between',
        ],
        'longitude outside its range' => [
            ['longitude' => '-180.1'], 'longitude', 'between',
        ],
    ]);

    test('preserves company ownership despite a forged assignment', function (bool $shared): void {
        $otherCompany = createCompany();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(EditStop::class, [
            'record' => $this->stop->getRouteKey(),
        ])
            ->fillForm([
                'name' => 'North Terminal',
                'description' => 'Boarding area.',
                'latitude' => '10.4623456',
                'longitude' => '-84.0223456',
            ])
            ->set('data.company_id', $shared ? null : $otherCompany->getKey())
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Stop::class, [
            'id' => $this->stop->getKey(),
            'company_id' => $this->company->getKey(),
            'name' => 'North Terminal',
        ]);
    })->with([
        'another company' => [false],
        'shared stop' => [true],
    ]);

    test('rejects editing without permission to update stops', function (): void {
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

        Livewire::test(EditStop::class, [
            'record' => $this->stop->getRouteKey(),
        ])
            ->assertForbidden();

        $this->assertDatabaseHas(Stop::class, [
            'id' => $this->stop->getKey(),
            'name' => 'Central Terminal',
            'company_id' => $this->company->getKey(),
        ]);
    });

    test('rejects editing shared stops for company users even with update permission', function (string $role): void {
        $sharedStop = Stop::factory()->create([
            'company_id' => null,
            'name' => 'Shared Terminal',
        ]);

        $actor = createUserWithRole($role, $this->company);

        CompanyUser::create([
            'company_id' => $this->company->getKey(),
            'user_id' => $actor->getKey(),
        ]);

        grantShield($actor, [
            'ViewAny:Stop',
            'View:Stop',
            'Update:Stop',
        ], $this->company);

        actingAsInCompany($actor, $this->company);

        Livewire::test(EditStop::class, [
            'record' => $sharedStop->getRouteKey(),
        ])
            ->assertForbidden();

        $this->assertDatabaseHas(Stop::class, [
            'id' => $sharedStop->getKey(),
            'company_id' => null,
            'name' => 'Shared Terminal',
        ]);
    })->with([
        'company admin' => [UserRole::Admin->value],
        'custom role' => ['stop-editor'],
    ]);

    test('does not resolve stops belonging to another company even for a system admin', function (): void {
        $otherCompany = createCompany();
        $foreignStop = Stop::factory()->for($otherCompany)->create();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(EditStop::class, [
            'record' => $foreignStop->getRouteKey(),
        ])
            ->assertNotFound();
    });

    test('does not resolve archived stops for editing', function (): void {
        $this->stop->delete();

        actingAsInCompany(
            createUserWithRole(UserRole::SuperAdmin),
            $this->company,
        );

        Livewire::test(EditStop::class, [
            'record' => $this->stop->getRouteKey(),
        ])
            ->assertNotFound();

        $this->assertSoftDeleted($this->stop);
    });

    test('allows a system admin to update a shared stop without changing its ownership', function (): void {
        $sharedStop = Stop::factory()->create([
            'company_id' => null,
            'name' => 'Shared Terminal',
            'description' => 'Main entrance.',
            'latitude' => '10.4523456',
            'longitude' => '-84.0123456',
        ]);

        $actor = createUserWithRole(UserRole::SuperAdmin);

        actingAsInCompany($actor, $this->company);

        Livewire::test(EditStop::class, [
            'record' => $sharedStop->getRouteKey(),
        ])
            ->fillForm([
                'name' => 'Shared Boarding Point',
                'description' => 'Updated boarding area.',
                'latitude' => '10.4623456',
                'longitude' => '-84.0223456',
            ])
            ->set('data.company_id', $this->company->getKey())
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Stop::class, [
            'id' => $sharedStop->getKey(),
            'company_id' => null,
            'name' => 'Shared Boarding Point',
            'description' => 'Updated boarding area.',
            'latitude' => '10.4623456',
            'longitude' => '-84.0223456',
        ]);

        expect(
            CompanyUser::withTrashed()
                ->where('user_id', $actor->getKey())
                ->exists(),
        )->toBeFalse();
    });
});
