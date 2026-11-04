<?php

use App\Enums\DriverStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Services\DriverManagementService;
use App\Services\DriverReadService;
use App\Services\UserManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class);

beforeEach(function (): void {
    $this->company = createCompany();
    $this->actor = $this->createWorkforceAdministrator($this->company);
    grantShield($this->actor, ['Update:Driver', 'Delete:Driver', 'Restore:Driver', 'View:Driver'], $this->company);
    $this->driver = Driver::factory()->for($this->company)->create();
});

describe('Driver Employment Lifecycle', function (): void {
    test('changes status explicitly and permits repeated requests without changing identity', function (): void {
        $before = $this->driver->only(['id', 'company_id', 'user_id']);
        $service = app(DriverManagementService::class);
        foreach ([false, false, true, true] as $active) {
            $result = $service->setActive($this->actor, $this->company, $this->driver, $active);
            expect($result->fresh()->status)->toBe($active ? DriverStatus::ACTIVE : DriverStatus::INACTIVE);
            expect($result->only(['id', 'company_id', 'user_id']))->toBe($before);
        }
    });

    test('archives and restores the same UUID with historical schedule references intact', function (): void {
        $service = app(DriverManagementService::class);
        $service->setActive($this->actor, $this->company, $this->driver, false);
        $schedule = DriverSchedule::factory()->for($this->driver)->create();
        $before = $this->driver->fresh()->getRawOriginal();

        $service->archive($this->actor, $this->company, $this->driver);
        $this->assertSoftDeleted($this->driver);
        expect($schedule->fresh()->driver->id)->toBe($this->driver->id);
        expect(app(DriverReadService::class)->findArchivedFor($this->actor, $this->company, $this->driver->id)['id'])->toBe($this->driver->id);
        $service->archive($this->actor, $this->company, $this->driver);
        $restored = $service->restore($this->actor, $this->company, $this->driver);

        expect($restored->id)->toBe($before['id']);
        expect($restored->status)->toBe(DriverStatus::INACTIVE);
        expect($restored->salary_amount)->toBe('650000.00');
        expect($restored->trashed())->toBeFalse();
        expect($schedule->fresh()->driver->id)->toBe($restored->id);
        expect($service->restore($this->actor, $this->company, $this->driver)->id)->toBe($restored->id);
        $this->assertDatabaseCount('drivers', 1);
    });

    test('preserves identities roles memberships and other employments throughout the lifecycle', function (): void {
        $user = $this->driver->user;
        $other = createCompany();
        $unrelated = Driver::factory()->for($other)->for($user)->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $user->id]);
        CompanyUser::create(['company_id' => $other->id, 'user_id' => $user->id]);
        grantShield($user, ['View:Driver'], $this->company);
        grantShield($user, ['ViewAny:Driver'], $other);
        $identityBefore = DB::connection('mysql')->table('users')->orderBy('id')->get()->toJson();
        $rolesBefore = DB::connection('mysql')->table('model_has_roles')->orderBy('role_id')->get()->toJson();
        $membershipsBefore = DB::table('company_users')->orderBy('id')->get()->toJson();
        $employmentBefore = $unrelated->fresh()->getRawOriginal();
        $service = app(DriverManagementService::class);

        $service->setActive($this->actor, $this->company, $this->driver, false);
        $service->archive($this->actor, $this->company, $this->driver);
        $service->restore($this->actor, $this->company, $this->driver);

        expect(DB::connection('mysql')->table('users')->orderBy('id')->get()->toJson())->toBe($identityBefore);
        expect(DB::connection('mysql')->table('model_has_roles')->orderBy('role_id')->get()->toJson())->toBe($rolesBefore);
        expect(DB::table('company_users')->orderBy('id')->get()->toJson())->toBe($membershipsBefore);
        expect($unrelated->fresh()->getRawOriginal())->toBe($employmentBefore);
    });

    test('does not restore archived identities or memberships when restoring employment', function (): void {
        $user = $this->driver->user;
        $membership = CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $user->id]);
        $membership->delete();
        $user->delete();
        $this->driver->delete();

        $restored = app(DriverManagementService::class)->restore($this->actor, $this->company, $this->driver);

        expect($restored->trashed())->toBeFalse();
        $this->assertSoftDeleted($user, connection: 'mysql');
        $this->assertSoftDeleted($membership);
    });

    test('rejects changes to archived employment until explicitly restored', function (string $method): void {
        $this->driver->delete();
        $before = Driver::withTrashed()->findOrFail($this->driver->id)->getRawOriginal();
        $argument = $method === 'setActive' ? true : ['license' => 'REJECTED'];

        expect(fn () => app(DriverManagementService::class)->{$method}($this->actor, $this->company, $this->driver, $argument))
            ->toThrow(function (ValidationException $exception): void {
                expect($exception->errors())->toHaveKey('driver');
            });
        expect(Driver::withTrashed()->findOrFail($this->driver->id)->getRawOriginal())->toBe($before);
    })->with(['updateEmployment', 'setActive']);

    test('rejects physical deletion including the quiet model path', function (bool $quiet): void {
        expect(fn () => $quiet ? $this->driver->forceDeleteQuietly() : $this->driver->forceDelete())->toThrow(ValidationException::class);
        $this->assertModelExists($this->driver);
    })->with([false, true]);

    test('rolls back partial persistence when a lifecycle model event fails', function (string $method, string $event): void {
        if ($method === 'restore') {
            $this->driver->delete();
        }
        $before = Driver::withTrashed()->findOrFail($this->driver->id)->getRawOriginal();
        $originalDispatcher = Driver::getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        Driver::setEventDispatcher($dispatcher);
        $dispatcher->listen('eloquent.'.$event.': '.Driver::class, function (): void {
            throw new RuntimeException('Injected lifecycle persistence failure');
        });

        try {
            $argument = $method === 'setActive' ? false : ['license' => 'TRANSIENT'];
            expect(fn () => app(DriverManagementService::class)->{$method}($this->actor, $this->company, $this->driver, $argument))
                ->toThrow(RuntimeException::class, 'Injected lifecycle persistence failure');
            expect(Driver::withTrashed()->findOrFail($this->driver->id)->getRawOriginal())->toBe($before);
        } finally {
            Driver::setEventDispatcher($originalDispatcher);
        }
    })->with([
        ['updateEmployment', 'updated'], ['setActive', 'updated'], ['archive', 'trashed'], ['restore', 'restored'],
    ]);

    test('does not report success when a model observer cancels the operation', function (string $method, string $event): void {
        if ($method === 'restore') {
            $this->driver->delete();
        }
        $before = Driver::withTrashed()->findOrFail($this->driver->id)->getRawOriginal();
        $originalDispatcher = Driver::getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        Driver::setEventDispatcher($dispatcher);
        $dispatcher->listen('eloquent.'.$event.': '.Driver::class, fn (): bool => false);

        try {
            $argument = $method === 'setActive' ? false : ['license' => 'CANCELLED'];
            expect(fn () => app(DriverManagementService::class)->{$method}($this->actor, $this->company, $this->driver, $argument))
                ->toThrow(function (ValidationException $exception): void {
                    expect($exception->errors())->toBe(['driver' => [__('The employment operation was cancelled.')]]);
                });
            expect(Driver::withTrashed()->findOrFail($this->driver->id)->getRawOriginal())->toBe($before);
        } finally {
            Driver::setEventDispatcher($originalDispatcher);
        }
    })->with([
        ['updateEmployment', 'saving'], ['setActive', 'saving'], ['archive', 'deleting'], ['restore', 'restoring'],
    ]);

    test('exposes a transactional extension point before lifecycle writes', function (string $method): void {
        if ($method === 'restore') {
            $this->driver->delete();
        }
        $service = new class(app(UserManagementService::class), app(PermissionRegistrar::class)) extends DriverManagementService
        {
            public ?string $observedOperation = null;

            protected function ensureLifecycleOperationAllowed(Company $company, Driver $driver, string $operation): void
            {
                expect($driver->getConnection()->transactionLevel())->toBeGreaterThan(0);
                $this->observedOperation = $operation;
                throw ValidationException::withMessages(['driver' => 'Injected lifecycle guard rejection']);
            }
        };
        $before = Driver::withTrashed()->findOrFail($this->driver->id)->getRawOriginal();
        $argument = $method === 'setActive' ? false : ['license' => 'GUARDED'];

        expect(fn () => $service->{$method}($this->actor, $this->company, $this->driver, $argument))->toThrow(ValidationException::class);
        expect($service->observedOperation)->toBe($method);
        expect(Driver::withTrashed()->findOrFail($this->driver->id)->getRawOriginal())->toBe($before);
    })->with(['updateEmployment', 'setActive', 'archive', 'restore']);
});
