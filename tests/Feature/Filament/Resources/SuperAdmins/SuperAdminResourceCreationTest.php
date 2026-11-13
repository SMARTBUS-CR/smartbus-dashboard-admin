<?php

use App\Enums\UserRole;
use App\Filament\Resources\SuperAdmins\Pages\CreateSuperAdmin;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

describe('Global SuperAdmin Resource Creation', function (): void {
    beforeEach(function (): void {
        $this->mock(UncompromisedVerifier::class)
            ->shouldReceive('verify')
            ->andReturnTrue();
    });

    test('creates a verified global account through the form without company membership', function (bool $useFirstCompany): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);

        [$company, $foreign] = createTenantPair();

        actingAsInCompany(
            $actor,
            $useFirstCompany ? $company : $foreign,
        );

        $previousTeam = getPermissionsTeamId();
        $membershipsBefore = CompanyUser::withTrashed()->count();

        Livewire::test(CreateSuperAdmin::class)
            ->fillForm([
                'name' => 'Created Global Administrator',
                'email' => 'form-super-admin@example.test',
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $created = User::where('email', 'form-super-admin@example.test')
            ->sole();

        expect($created->name)->toBe('Created Global Administrator')
            ->and($created->hasVerifiedEmail())->toBeTrue()
            ->and(Hash::check('N7v!qL2#rX9@kP4', $created->password))
            ->toBeTrue()
            ->and(getPermissionsTeamId())->toBe($previousTeam)
            ->and(CompanyUser::withTrashed()->count())->toBe($membershipsBefore)
            ->and(CompanyUser::withTrashed()
                ->where('user_id', $created->getKey())->exists())->toBeFalse();

        $assignments = DB::connection('mysql')
            ->table('model_has_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_type', $created->getMorphClass())
            ->where('assignments.model_uuid', $created->getKey())
            ->select([
                'assignments.company_id as assignment_company_id',
                'roles.company_id as role_company_id',
                'roles.name',
                'roles.guard_name',
            ])
            ->get();

        expect($assignments)->toHaveCount(1);

        $assignment = $assignments->sole();

        expect($assignment->name)->toBe(UserRole::SuperAdmin->value)
            ->and($assignment->guard_name)->toBe('web')
            ->and($assignment->assignment_company_id)->toBeNull()
            ->and($assignment->role_company_id)->toBeNull();
    })->with([
        'from the first company' => [true],
        'from another company' => [false],
    ]);

    test('shows compromised password errors without creating an account', function (): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        actingAsInCompany($actor, createCompany());

        $usersBefore = User::withTrashed()->count();
        $membershipsBefore = CompanyUser::withTrashed()->count();

        $this->mock(UncompromisedVerifier::class)
            ->shouldReceive('verify')
            ->andReturnFalse();

        Livewire::test(CreateSuperAdmin::class)
            ->fillForm([
                'name' => 'Rejected SuperAdmin',
                'email' => 'rejected-form-super-admin@example.test',
                'password' => 'N7v!qL2#rX9@kP4',
                'password_confirmation' => 'N7v!qL2#rX9@kP4',
            ])
            ->call('create')
            ->assertHasFormErrors(['password'])
            ->assertNotNotified();

        expect(User::withTrashed()->count())->toBe($usersBefore)
            ->and(CompanyUser::withTrashed()->count())->toBe($membershipsBefore)
            ->and(User::withTrashed()
                ->where('email', 'rejected-form-super-admin@example.test')->exists())->toBeFalse();
    });
});
