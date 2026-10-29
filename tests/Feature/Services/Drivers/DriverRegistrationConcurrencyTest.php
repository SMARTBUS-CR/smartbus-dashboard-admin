<?php

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Tests\Support\DriverRegistrationProcess;
use Tests\Support\WithDriverConcurrency;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class, WithDriverConcurrency::class);

afterEach(function (): void {
    $this->finishDriverConcurrency();
});

describe('Driver Registration Concurrency', function (): void {
    test('completes registration competing with company access removal without a cross-database circular wait', function (): void {
        $company = $this->createConcurrentCompany();
        $otherCompany = $this->createConcurrentCompany();
        $actor = $this->createWorkforceAdministrator($company);
        grantShield($actor, ['Delete:User'], $company);
        $target = createUserWithRole(UserRole::Admin, $company);
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $target->id]);
        $otherMembership = CompanyUser::create(['company_id' => $otherCompany->id, 'user_id' => $target->id]);
        setPermissionsTeamId($otherCompany->id);
        $otherRole = Role::withoutGlobalScopes()->where('company_id', $otherCompany->id)->where('name', 'admin')->sole();
        $target->assignRole($otherRole);
        $identityBefore = $target->fresh()->getRawOriginal();
        $input = [
            'company_id' => $company->id, 'actor_id' => $actor->id, 'user_id' => $target->id,
            'employment' => $this->validDriverEmployment(), 'previous_team' => $otherCompany->id,
        ];
        $removal = $this->startDriverProcess([...$input, 'operation' => 'remove', 'barrier_at' => 'removal']);
        $removalReady = $removal->await('ready');
        $removal->await('company_locked');
        $registration = $this->startDriverProcess([...$input, 'operation' => 'existing', 'barrier_at' => 'registration']);
        $registrationReady = $registration->await('ready');
        $registration->await('registration_write_attempt');

        $removal->release();
        $registration->release();
        $removed = $removal->await('result');
        $registered = $registration->await('result');

        expect($removalReady['pgsql_pid'])->not->toBe($registrationReady['pgsql_pid']);
        expect($removed)->toMatchArray(['status' => 'success']);
        expect($registered['status'])->toBe('success');
        foreach ([$removed, $registered] as $result) {
            expect($result['team_restored'])->toBeTrue();
            expect($result['actor_cache_cleared'])->toBeTrue();
            expect($result['target_cache_cleared'])->toBeTrue();
            expect($result['pgsql_level'])->toBe(0);
            expect($result['mysql_level'])->toBe(0);
            expect($result['pgsql_transaction'])->toBeFalse();
            expect($result['mysql_transaction'])->toBeFalse();
        }
        expect($target->fresh()->getRawOriginal())->toBe($identityBefore);
        $this->assertDatabaseCount('drivers', 1);
        $this->assertDatabaseHas('company_users', ['company_id' => $company->id, 'user_id' => $target->id, 'deleted_at' => null]);
        $this->assertModelExists($otherMembership);
        $this->assertNotSoftDeleted($otherMembership);
        $this->assertDatabaseHas('model_has_roles', ['role_id' => $otherRole->id, 'company_id' => $otherCompany->id, 'model_uuid' => $target->id], 'mysql');
        $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'driver')->sole();
        $this->assertDatabaseHas('model_has_roles', ['role_id' => $role->id, 'company_id' => $company->id, 'model_uuid' => $target->id], 'mysql');
    });

    test('returns a localized license error to the concurrent loser and rolls back its provisioning', function (bool $newIdentity, string $locale): void {
        $company = $this->createConcurrentCompany();
        $otherCompany = $this->createConcurrentCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $targets = $newIdentity ? [] : [User::factory()->create(), User::factory()->create()];
        $identitiesBefore = array_map(fn (User $user): array => $user->fresh()->getRawOriginal(), $targets);
        $otherMemberships = [];
        foreach ($targets as $target) {
            $otherMemberships[] = CompanyUser::create(['company_id' => $otherCompany->id, 'user_id' => $target->id]);
        }
        Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'driver')->delete();
        $inputs = [];
        foreach ([0, 1] as $number) {
            $inputs[] = [
                'company_id' => $company->id, 'actor_id' => $actor->id, 'previous_team' => $otherCompany->id,
                'operation' => $newIdentity ? 'register' : 'existing', 'barrier_at' => 'license', 'locale' => $locale,
                'user_id' => $targets[$number]->id ?? null,
                'identity' => $this->validDriverIdentity(['email' => 'racing-driver-'.$number.'@example.test']),
                'employment' => $this->validDriverEmployment(['license' => $number === 0 ? '  race-license  ' : 'RACE-LICENSE']),
            ];
        }
        $first = $this->startDriverProcess($inputs[0]);
        $firstReady = $first->await('ready');
        $first->await('license_checked');
        $second = $this->startDriverProcess($inputs[1]);
        $secondReady = $second->await('ready');
        $second->await('license_checked');

        $first->release();
        $second->release();
        $results = [$first->await('result'), $second->await('result')];

        expect($firstReady['pgsql_pid'])->not->toBe($secondReady['pgsql_pid']);
        $successes = array_filter($results, fn (array $result): bool => $result['status'] === 'success');
        $failures = array_filter($results, fn (array $result): bool => $result['status'] !== 'success');
        expect($successes)->toHaveCount(1);
        expect($failures)->toHaveCount(1);
        $failure = array_values($failures)[0];
        expect($failure['status'])->toBe('validation');
        expect($failure['errors'])->toBe(['license' => [__('This license is already reserved by a driver in this company.', locale: $locale)]]);
        foreach ($results as $result) {
            expect($result['team_restored'])->toBeTrue();
            expect($result['actor_cache_cleared'])->toBeTrue();
            expect($result['target_cache_cleared'])->toBeTrue();
            expect($result['pgsql_level'])->toBe(0);
            expect($result['mysql_level'])->toBe(0);
            expect($result['pgsql_transaction'])->toBeFalse();
            expect($result['mysql_transaction'])->toBeFalse();
        }
        $winner = array_values($successes)[0];
        $this->assertDatabaseCount('drivers', 1);
        $this->assertDatabaseHas('drivers', ['id' => $winner['driver_id'], 'user_id' => $winner['user_id'], 'license' => 'RACE-LICENSE']);
        $this->assertDatabaseCount('users', $newIdentity ? 2 : 3, 'mysql');
        $this->assertDatabaseCount('company_users', $newIdentity ? 2 : 4);
        $this->assertDatabaseHas('company_users', ['company_id' => $company->id, 'user_id' => $winner['user_id'], 'deleted_at' => null]);
        $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'driver')->sole();
        expect(DB::connection('mysql')->table('model_has_roles')->where('role_id', $role->id)->where('company_id', $company->id)->pluck('model_uuid')->all())->toBe([$winner['user_id']]);
        foreach ($targets as $number => $target) {
            expect($target->fresh()->getRawOriginal())->toBe($identitiesBefore[$number]);
            $this->assertNotSoftDeleted($otherMemberships[$number]);
        }
        $loser = $results[0]['status'] === 'success' ? 1 : 0;
        if ($newIdentity) {
            $this->assertDatabaseMissing('users', ['email' => $inputs[$loser]['identity']['email']], 'mysql');
        } else {
            $this->assertDatabaseMissing('company_users', ['company_id' => $company->id, 'user_id' => $targets[$loser]->id]);
        }
    })->with(['new identities' => true, 'existing identities' => false])->with(['en', 'es']);

    test('preserves unrelated database errors and rolls back identity provisioning', function (string $constraint, string $sqlstate): void {
        $company = $this->createConcurrentCompany();
        $otherCompany = $this->createConcurrentCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $existing = Driver::factory()->for($otherCompany)->create(['license' => 'OTHER-CONFLICT']);
        if ($constraint === 'unique') {
            DB::connection('pgsql')->unprepared('ALTER TABLE drivers ADD CONSTRAINT drivers_test_unrelated_unique UNIQUE (license)');
        } else {
            DB::connection('pgsql')->unprepared('ALTER TABLE drivers ADD CONSTRAINT drivers_test_unrelated_check CHECK (salary_amount < 10) NOT VALID');
        }

        try {
            $worker = $this->startDriverProcess([
                'company_id' => $company->id, 'actor_id' => $actor->id, 'previous_team' => $otherCompany->id,
                'operation' => 'register', 'barrier_at' => 'none',
                'identity' => $this->validDriverIdentity(),
                'employment' => $this->validDriverEmployment(['license' => 'OTHER-CONFLICT']),
            ]);
            $worker->await('ready');

            $result = $worker->await('result');

            expect($result)->toMatchArray(['status' => 'database', 'connection' => 'pgsql', 'sqlstate' => $sqlstate, 'team_restored' => true, 'pgsql_level' => 0, 'mysql_level' => 0, 'pgsql_transaction' => false, 'mysql_transaction' => false]);
            $this->assertModelExists($existing);
            $this->assertDatabaseCount('drivers', 1);
            $this->assertDatabaseCount('users', 2, 'mysql');
            $this->assertDatabaseCount('company_users', 1);
            $this->assertDatabaseMissing('users', ['email' => 'new-driver@example.test'], 'mysql');
        } finally {
            DB::connection('pgsql')->unprepared('ALTER TABLE drivers DROP CONSTRAINT IF EXISTS drivers_test_unrelated_unique, DROP CONSTRAINT IF EXISTS drivers_test_unrelated_check');
        }
    })->with(['another unique constraint' => ['unique', '23505'], 'check constraint' => ['check', '23514']]);

    test('rejects a company deactivated between the context lookup and row lock acquisition', function (bool $newIdentity): void {
        $company = $this->createConcurrentCompany();
        $actor = $this->createWorkforceAdministrator($company);
        $target = $newIdentity ? null : User::factory()->create();
        DB::connection('pgsql')->beginTransaction();

        try {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $worker = $this->startDriverProcess([
                'company_id' => $company->id, 'actor_id' => $actor->id, 'previous_team' => null,
                'operation' => $newIdentity ? 'register' : 'existing', 'barrier_at' => 'registration',
                'user_id' => $target?->id, 'identity' => $this->validDriverIdentity(), 'employment' => $this->validDriverEmployment(),
            ]);
            $worker->await('ready');
            $worker->await('registration_write_attempt');
            $company->update(['status' => CompanyStatus::INACTIVE]);
            DB::connection('pgsql')->commit();
            $worker->release();

            $result = $worker->await('result');

            expect($result['status'])->toBe('validation');
            expect($result['errors'])->toHaveKey('company_id');
            expect($result['team_restored'])->toBeTrue();
            $this->assertDatabaseCount('drivers', 0);
            $this->assertDatabaseCount('company_users', 1);
            $this->assertDatabaseCount('users', $newIdentity ? 1 : 2, 'mysql');
        } finally {
            DB::connection('pgsql')->rollBack(0);
        }
    })->with(['new identity' => true, 'existing identity' => false]);

    test('refuses worker connections to development databases before querying them', function (string $unsafeConnection): void {
        $parser = new ConfigurationUrlParser;
        $connections = [
            'pgsql' => $parser->parseConfiguration(config('database.connections.pgsql')),
            'mysql' => $parser->parseConfiguration(config('database.connections.mysql')),
        ];
        $connections[$unsafeConnection]['database'] = 'smartbus';
        $worker = new DriverRegistrationProcess(['connections' => $connections]);

        try {
            $failure = $worker->await('failure');

            expect($failure['class'])->toBe(RuntimeException::class);
            expect($failure['message'])->toBe('Concurrency workers require isolated testing databases.');
        } finally {
            $worker->close();
        }
    })->with(['pgsql', 'mysql']);
});
