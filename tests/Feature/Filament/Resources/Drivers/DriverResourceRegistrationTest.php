<?php

use App\Enums\UserRole;
use App\Filament\Resources\Drivers\Pages\CreateDriver;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class);

describe('Driver Resource Registration', function (): void {
    beforeEach(function (): void {
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturnTrue();
        $this->company = createCompany();
        $this->actor = createUserWithRole(UserRole::Admin, $this->company);
        CompanyUser::create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->actor->getKey(),
        ]);
        grantShield($this->actor, ['ViewAny:Driver', 'View:Driver', 'Create:Driver'], $this->company);
        actingAsInCompany($this->actor, $this->company);
    });

    test('registers a new identity and employment through the canonical workflow', function (): void {
        Livewire::test(CreateDriver::class)
            ->fillForm([
                'registration_type' => 'new',
                'name' => 'Demo Driver',
                'email' => 'demo.driver@example.test',
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
                ...$this->validDriverEmployment([
                    'license' => ' demo-license ',
                    'salary_amount' => '650000.25',
                ]),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertRedirect();

        $user = User::query()->where('email', 'demo.driver@example.test')->sole();
        expect(Hash::check('N7v!qL2#rX9@kP4', $user->password))->toBeTrue();
        $this->assertDatabaseHas(Driver::class, [
            'company_id' => $this->company->getKey(),
            'user_id' => $user->getKey(),
            'license' => 'DEMO-LICENSE',
            'salary_amount' => '650000.25',
        ]);
        $this->assertDatabaseHas(CompanyUser::class, [
            'company_id' => $this->company->getKey(),
            'user_id' => $user->getKey(),
            'deleted_at' => null,
        ]);
    });

    test('associates an existing identity without changing its credentials', function (): void {
        $user = User::factory()->create(['password' => 'ExistingPassword123!']);
        $password = $user->getRawOriginal('password');

        Livewire::test(CreateDriver::class)
            ->fillForm([
                'registration_type' => 'existing',
                'user_id' => $user->getKey(),
                ...$this->validDriverEmployment(['license' => 'EXISTING-IDENTITY']),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        expect($user->fresh()->getRawOriginal('password'))->toBe($password);
        $this->assertDatabaseHas(Driver::class, [
            'company_id' => $this->company->getKey(),
            'user_id' => $user->getKey(),
            'license' => 'EXISTING-IDENTITY',
        ]);
    });

    test('rejects duplicate employment without restoring the archived profile', function (): void {
        $driver = Driver::factory()->for($this->company)->archived()->create();

        Livewire::test(CreateDriver::class)
            ->fillForm([
                'registration_type' => 'existing',
                'user_id' => $driver->user_id,
                ...$this->validDriverEmployment(['license' => 'ANOTHER-LICENSE']),
            ])
            ->call('create')
            ->assertHasFormErrors(['user_id'])
            ->assertNotNotified();

        $this->assertSoftDeleted($driver);
        $this->assertDatabaseCount('drivers', 1);
    });
});
