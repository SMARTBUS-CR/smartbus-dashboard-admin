<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Driver;
use App\Models\DriverSchedule;
use App\Services\DriverScheduleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Tests\Support\WithDriverRegistration;

uses(WithDriverRegistration::class);

beforeEach(function (): void {
    $this->company = createCompany(['timezone' => 'America/Costa_Rica']);
    $this->actor = $this->createWorkforceAdministrator($this->company);
    $this->driver = Driver::factory()->for($this->company)->create();
    $this->scheduleData = ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00'];
});

describe('Driver Schedule Service', function (): void {
    test('creates updates and deletes availability without changing transport or employment records', function (): void {
        grantShield($this->actor, ['Create:DriverSchedule', 'Update:DriverSchedule', 'Delete:DriverSchedule'], $this->company);
        $before = $this->driver->fresh()->getRawOriginal();
        $service = app(DriverScheduleService::class);

        $schedule = $service->create($this->actor, $this->company, $this->driver, $this->scheduleData);
        expect($schedule->start_time)->toBe('08:00:00');
        expect($schedule->id)->toBeUuid();
        $updated = $service->update($this->actor, $this->company, $this->driver, $schedule, ['end_time' => '13:30']);
        expect($updated->id)->toBe($schedule->id);
        expect($updated->end_time)->toBe('13:30:00');
        $service->delete($this->actor, $this->company, $this->driver, $updated);

        $this->assertModelMissing($schedule);
        expect($this->driver->fresh()->getRawOriginal())->toBe($before);
        $this->assertDatabaseCount('routes', 0);
        $this->assertDatabaseCount('buses', 0);
    });

    test('requires the operation permission and restores the team after denial or success', function (string $method, string $permission): void {
        $schedule = DriverSchedule::factory()->for($this->driver)->create();
        $previous = createCompany();
        setPermissionsTeamId($previous->id);
        $this->actor->load('roles', 'permissions');
        $service = app(DriverScheduleService::class);
        $operation = fn () => match ($method) {
            'create' => $service->create($this->actor, $this->company, $this->driver, array_replace($this->scheduleData, ['day_of_week' => 2])),
            'update' => $service->update($this->actor, $this->company, $this->driver, $schedule, ['end_time' => '17:00']),
            'delete' => $service->delete($this->actor, $this->company, $this->driver, $schedule),
        };

        expect($operation)->toThrow(AuthorizationException::class);
        expect(getPermissionsTeamId())->toBe($previous->id);
        expect($this->actor->relationLoaded('roles'))->toBeFalse();
        grantShield($this->actor, [$permission], $this->company);
        $operation();
        expect(getPermissionsTeamId())->toBe($previous->id);
        expect($this->actor->relationLoaded('permissions'))->toBeFalse();
    })->with([['create', 'Create:DriverSchedule'], ['update', 'Update:DriverSchedule'], ['delete', 'Delete:DriverSchedule']]);

    test('rejects reassignment and unknown payload fields', function (string $field): void {
        grantShield($this->actor, ['Create:DriverSchedule', 'Update:DriverSchedule'], $this->company);
        $schedule = DriverSchedule::factory()->for($this->driver)->create();
        $before = $schedule->fresh()->getRawOriginal();
        $data = [$field => null];
        $service = app(DriverScheduleService::class);

        expect(fn () => $service->create($this->actor, $this->company, $this->driver, array_replace($this->scheduleData, $data)))->toThrow(ValidationException::class);
        expect(fn () => $service->update($this->actor, $this->company, $this->driver, $schedule, $data))->toThrow(ValidationException::class);
        expect($schedule->fresh()->getRawOriginal())->toBe($before);
    })->with(['id', 'driver_id', 'company_id', 'route_id', 'route_pattern_id', 'bus_id', 'created_at', 'unknown']);

    test('rejects foreign drivers and schedules even for global administrators', function (string $method): void {
        $actor = createUserWithRole(UserRole::SuperAdmin);
        $other = createCompany();
        $foreign = Driver::factory()->for($other)->create();
        $schedule = DriverSchedule::factory()->for($foreign)->create();
        $service = app(DriverScheduleService::class);

        $operation = fn () => match ($method) {
            'create' => $service->create($actor, $this->company, $foreign, $this->scheduleData),
            'update' => $service->update($actor, $this->company, $this->driver, $schedule, ['end_time' => '17:00']),
            'delete' => $service->delete($actor, $this->company, $this->driver, $schedule),
        };
        expect($operation)->toThrow(ModelNotFoundException::class);
        $this->assertModelExists($schedule);
    })->with(['create', 'update', 'delete']);

    test('excludes the edited row from conflict checks but rejects conflicting changes atomically', function (): void {
        grantShield($this->actor, ['Update:DriverSchedule'], $this->company);
        $first = DriverSchedule::factory()->for($this->driver)->create(['start_time' => '08:00', 'end_time' => '12:00']);
        $second = DriverSchedule::factory()->for($this->driver)->create(['start_time' => '13:00', 'end_time' => '17:00']);
        $before = $second->fresh()->getRawOriginal();
        $service = app(DriverScheduleService::class);

        expect($service->update($this->actor, $this->company, $this->driver, $first, ['end_time' => '12:00'])->id)->toBe($first->id);
        expect(fn () => $service->update($this->actor, $this->company, $this->driver, $second, ['start_time' => '11:00']))->toThrow(ValidationException::class);
        expect($second->fresh()->getRawOriginal())->toBe($before);
    });

    test('uses the company local calendar independently of the application timezone', function (): void {
        config(['app.timezone' => 'Pacific/Auckland']);
        grantShield($this->actor, ['Create:DriverSchedule'], $this->company);
        $service = app(DriverScheduleService::class);
        $data = array_replace($this->scheduleData, ['valid_from' => '2026-10-19', 'valid_until' => '2026-10-19']);
        $service->create($this->actor, $this->company, $this->driver, $data);

        expect(fn () => $service->create($this->actor, $this->company, $this->driver, $data))->toThrow(ValidationException::class);
    });

    test('allows the same availability for a different driver but rejects a mismatched schedule context', function (): void {
        grantShield($this->actor, ['Create:DriverSchedule', 'Update:DriverSchedule'], $this->company);
        $other = Driver::factory()->for($this->company)->create();
        $service = app(DriverScheduleService::class);
        $first = $service->create($this->actor, $this->company, $this->driver, $this->scheduleData);
        $this->assertModelExists($service->create($this->actor, $this->company, $other, $this->scheduleData));

        expect(fn () => $service->update($this->actor, $this->company, $other, $first, ['end_time' => '14:00']))->toThrow(ModelNotFoundException::class);
    });

    test('rejects mutations to archived employment without deleting historical availability', function (string $method): void {
        grantShield($this->actor, ['Create:DriverSchedule', 'Update:DriverSchedule', 'Delete:DriverSchedule'], $this->company);
        $schedule = DriverSchedule::factory()->for($this->driver)->create();
        $this->driver->delete();
        $service = app(DriverScheduleService::class);

        expect(fn () => match ($method) {
            'create' => $service->create($this->actor, $this->company, $this->driver, $this->scheduleData),
            'update' => $service->update($this->actor, $this->company, $this->driver, $schedule, ['end_time' => '17:00']),
            'delete' => $service->delete($this->actor, $this->company, $this->driver, $schedule),
        })->toThrow(ValidationException::class);
        $this->assertModelExists($schedule);
    })->with(['create', 'update', 'delete']);

    test('returns the translated overlap message', function (string $locale, string $message): void {
        app()->setLocale($locale);
        grantShield($this->actor, ['Create:DriverSchedule'], $this->company);
        DriverSchedule::factory()->for($this->driver)->create();

        expect(fn () => app(DriverScheduleService::class)->create($this->actor, $this->company, $this->driver, $this->scheduleData))
            ->toThrow(function (ValidationException $exception) use ($message): void {
                expect($exception->errors())->toBe(['schedule' => [$message]]);
            });
    })->with([
        ['en', 'This availability overlaps an existing schedule for the driver.'],
        ['es', 'Esta disponibilidad se superpone con un horario existente del conductor.'],
    ]);

    test('rejects validity expansion into an occupied effective starting day', function (): void {
        grantShield($this->actor, ['Update:DriverSchedule'], $this->company);
        $first = DriverSchedule::factory()->for($this->driver)->create(['valid_until' => '2026-10-19']);
        DriverSchedule::factory()->for($this->driver)->create(['valid_from' => '2026-10-26']);
        $before = $first->fresh()->getRawOriginal();

        expect(fn () => app(DriverScheduleService::class)->update($this->actor, $this->company, $this->driver, $first, ['valid_until' => '2026-10-26']))->toThrow(ValidationException::class);
        expect($first->fresh()->getRawOriginal())->toBe($before);
    });

    test('rolls back observer failures and rejects observer cancellations', function (string $method, bool $cancel): void {
        grantShield($this->actor, ['Create:DriverSchedule', 'Update:DriverSchedule', 'Delete:DriverSchedule'], $this->company);
        $schedule = DriverSchedule::factory()->for($this->driver)->create();
        $before = $schedule->fresh()->getRawOriginal();
        $originalDispatcher = DriverSchedule::getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        DriverSchedule::setEventDispatcher($dispatcher);
        $event = $cancel ? ($method === 'delete' ? 'deleting' : 'saving') : match ($method) {
            'create' => 'created', 'update' => 'updated', 'delete' => 'deleted',
        };
        $dispatcher->listen('eloquent.'.$event.': '.DriverSchedule::class, function () use ($cancel): bool {
            if (! $cancel) {
                throw new RuntimeException('Injected availability persistence failure');
            }

            return false;
        });

        try {
            $service = app(DriverScheduleService::class);
            expect(fn () => match ($method) {
                'create' => $service->create($this->actor, $this->company, $this->driver, array_replace($this->scheduleData, ['day_of_week' => 2])),
                'update' => $service->update($this->actor, $this->company, $this->driver, $schedule, ['end_time' => '17:00']),
                'delete' => $service->delete($this->actor, $this->company, $this->driver, $schedule),
            })->toThrow($cancel ? ValidationException::class : RuntimeException::class);
            expect($schedule->fresh()->getRawOriginal())->toBe($before);
            $this->assertDatabaseCount('driver_schedules', 1);
        } finally {
            DriverSchedule::setEventDispatcher($originalDispatcher);
        }
    })->with(['create', 'update', 'delete'])->with([false, true]);

    test('exposes the Trip extension point inside the locked transaction', function (string $method): void {
        grantShield($this->actor, ['Create:DriverSchedule', 'Update:DriverSchedule', 'Delete:DriverSchedule'], $this->company);
        $schedule = DriverSchedule::factory()->for($this->driver)->create();
        $service = new class extends DriverScheduleService
        {
            public ?string $observedOperation = null;

            protected function ensureScheduleOperationAllowed(Company $company, Driver $driver, DriverSchedule $schedule, string $operation): void
            {
                expect($driver->getConnection()->transactionLevel())->toBeGreaterThan(0);
                $this->observedOperation = $operation;
                throw ValidationException::withMessages(['schedule' => 'Injected future guard rejection']);
            }
        };
        $before = $schedule->fresh()->getRawOriginal();

        expect(fn () => match ($method) {
            'create' => $service->create($this->actor, $this->company, $this->driver, array_replace($this->scheduleData, ['day_of_week' => 2])),
            'update' => $service->update($this->actor, $this->company, $this->driver, $schedule, ['end_time' => '17:00']),
            'delete' => $service->delete($this->actor, $this->company, $this->driver, $schedule),
        })->toThrow(ValidationException::class);
        expect($service->observedOperation)->toBe($method);
        expect($schedule->fresh()->getRawOriginal())->toBe($before);
        $this->assertDatabaseCount('driver_schedules', 1);
    })->with(['create', 'update', 'delete']);
});
