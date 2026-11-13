<?php

use App\Enums\TripAssignmentStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\RouteSchedule;
use App\Models\TripAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

describe('Trip Assignment', function (): void {
    test('persists a recurring assignment with typed values and relationships', function (): void {
        $assignment = TripAssignment::factory()->bounded('2026-11-01', '2027-03-31')->create([
            'planned_arrival_time' => '07:45:00',
            'ends_next_day' => false,
            'status' => TripAssignmentStatus::INACTIVE,
        ])->fresh();

        expect($assignment->id)->toBeUuid()
            ->and($assignment->planned_arrival_time)->toBe('07:45:00')
            ->and($assignment->ends_next_day)->toBeFalse()
            ->and($assignment->valid_from->toDateString())->toBe('2026-11-01')
            ->and($assignment->valid_until->toDateString())->toBe('2027-03-31')
            ->and($assignment->status)->toBe(TripAssignmentStatus::INACTIVE)
            ->and($assignment->company)->toBeInstanceOf(Company::class)
            ->and($assignment->schedule)->toBeInstanceOf(RouteSchedule::class)
            ->and($assignment->driver)->toBeInstanceOf(Driver::class)
            ->and($assignment->bus)->toBeInstanceOf(Bus::class)
            ->and($assignment->driver->company_id)->toBe($assignment->company_id)
            ->and($assignment->bus->company_id)->toBe($assignment->company_id)
            ->and($assignment->schedule->pattern->route->company_id)->toBe($assignment->company_id)
            ->and($assignment->company->tripAssignments()->whereKey($assignment->id)->exists())->toBeTrue()
            ->and($assignment->schedule->tripAssignments()->whereKey($assignment->id)->exists())->toBeTrue()
            ->and($assignment->driver->tripAssignments()->whereKey($assignment->id)->exists())->toBeTrue()
            ->and($assignment->bus->tripAssignments()->whereKey($assignment->id)->exists())->toBeTrue();
    });

    test('keeps archived resource references available for recurring history', function (): void {
        $assignment = TripAssignment::factory()->create();

        $assignment->driver->delete();
        $assignment->bus->delete();
        $assignment->company->delete();

        $historical = $assignment->fresh();

        expect($historical->driver)->not->toBeNull()
            ->and($historical->bus)->not->toBeNull()
            ->and($historical->company)->not->toBeNull();
    });

    test('rejects invalid validity ranges and unsupported states at the database boundary', function (array $changes, string $constraint): void {
        $assignment = TripAssignment::factory()->create();
        $attributes = array_merge([
            'id' => (string) Str::uuid(),
            'company_id' => $assignment->company_id,
            'route_schedule_id' => $assignment->route_schedule_id,
            'driver_id' => $assignment->driver_id,
            'bus_id' => $assignment->bus_id,
            'planned_arrival_time' => '07:30:00',
            'ends_next_day' => false,
            'valid_from' => null,
            'valid_until' => null,
            'status' => TripAssignmentStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ], $changes);

        expect(fn () => DB::table('trip_assignments')->insert($attributes))
            ->toThrow(function (QueryException $exception) use ($constraint): void {
                expect($exception->errorInfo[0])->toBe('23514')
                    ->and($exception->errorInfo[2])->toContain($constraint);
            });
    })->with([
        'reversed validity' => [[
            'valid_from' => '2026-11-02',
            'valid_until' => '2026-11-01',
        ], 'trip_assignments_validity_check'],
        'unsupported status' => [[
            'status' => 'paused',
        ], 'trip_assignments_status_check'],
    ]);

    test('rejects driver and bus ownership from another company at the database boundary', function (string $field): void {
        $assignment = TripAssignment::factory()->create();
        $otherCompany = Company::factory()->create();
        $foreignResource = $field === 'driver_id'
            ? Driver::factory()->for($otherCompany)->create()
            : Bus::factory()->for($otherCompany)->create();

        expect(fn () => DB::table('trip_assignments')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $assignment->company_id,
            'route_schedule_id' => $assignment->route_schedule_id,
            'driver_id' => $field === 'driver_id' ? $foreignResource->id : $assignment->driver_id,
            'bus_id' => $field === 'bus_id' ? $foreignResource->id : $assignment->bus_id,
            'planned_arrival_time' => '07:30:00',
            'ends_next_day' => false,
            'status' => TripAssignmentStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(function (QueryException $exception) use ($field): void {
            expect($exception->errorInfo[0])->toBe('23503')
                ->and($exception->errorInfo[2])->toContain("trip_assignments_{$field}_company_foreign");
        });
    })->with(['driver_id', 'bus_id']);

    test('prevents permanent deletion while preserving the recurring assignment', function (): void {
        $assignment = TripAssignment::factory()->create();

        expect(fn () => $assignment->forceDelete())->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toHaveKey('assignment');
        });
        $this->assertModelExists($assignment);
    });
});
