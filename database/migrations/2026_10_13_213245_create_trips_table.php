<?php

use App\Models\Company;
use App\Models\RoutePattern;
use App\Models\RouteSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('trips', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuidFor(Company::class)
                ->constrained('companies')
                ->restrictOnDelete();

            $table->foreignUuidFor(RoutePattern::class)
                ->constrained('route_patterns')
                ->restrictOnDelete();

            $table->foreignUuidFor(RouteSchedule::class)
                ->constrained('route_schedules')
                ->restrictOnDelete();

            $table->uuid('bus_id');
            $table->uuid('driver_id');

            $table->date('service_date');
            $table->string('status', 20)->default('scheduled');

            $table->timestampTz('scheduled_departure_at', 6);
            $table->timestampTz('scheduled_arrival_at', 6);

            $table->timestampTz('actual_departure_at', 6)->nullable();
            $table->timestampTz('actual_arrival_at', 6)->nullable();

            $table->jsonb('planned_route_snapshot');
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $table->timestamps();

            $table->foreign(['bus_id', 'company_id'], 'trips_bus_company_foreign')
                ->references(['id', 'company_id'])
                ->on('buses')
                ->restrictOnDelete();
                
            $table->foreign(['driver_id', 'company_id'], 'trips_driver_company_foreign')
                ->references(['id', 'company_id'])
                ->on('drivers')
                ->restrictOnDelete();
                
            $table->index(['company_id', 'service_date', 'status']);
            $table->index(['route_pattern_id', 'scheduled_departure_at']);
            $table->index(['driver_id', 'scheduled_departure_at']);
            $table->index(['bus_id', 'scheduled_departure_at']);
        });

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX trips_schedule_service_date_unique
                ON trips (route_schedule_id, service_date) WHERE status <> 'cancelled'
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE trips
                ADD CONSTRAINT trips_scheduled_dates_check CHECK (
                    scheduled_arrival_at > scheduled_departure_at
                ),
                ADD CONSTRAINT trips_snapshot_check CHECK (
                    jsonb_typeof(planned_route_snapshot) = 'object'
                    AND planned_route_snapshot <> '{}'::jsonb
                ),
                ADD CONSTRAINT trips_execution_check CHECK (
                    (status = 'scheduled' AND actual_departure_at IS NULL
                        AND actual_arrival_at IS NULL AND cancelled_at IS NULL AND cancellation_reason IS NULL)
                    OR (status = 'in_progress' AND actual_departure_at IS NOT NULL
                        AND actual_arrival_at IS NULL AND cancelled_at IS NULL AND cancellation_reason IS NULL)
                    OR (status = 'completed' AND actual_departure_at IS NOT NULL
                        AND actual_arrival_at IS NOT NULL AND actual_arrival_at >= actual_departure_at
                        AND cancelled_at IS NULL AND cancellation_reason IS NULL)
                    OR (status = 'cancelled' AND actual_departure_at IS NULL
                        AND actual_arrival_at IS NULL AND cancelled_at IS NOT NULL
                        AND cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> '')
                ),
                ADD CONSTRAINT trips_no_bus_overlap EXCLUDE USING gist (
                    bus_id WITH =,
                    tstzrange(scheduled_departure_at, scheduled_arrival_at, '[)') WITH &&
                ) WHERE (status IN ('scheduled', 'in_progress')),
                ADD CONSTRAINT trips_no_driver_overlap EXCLUDE USING gist (
                    driver_id WITH =,
                    tstzrange(scheduled_departure_at, scheduled_arrival_at, '[)') WITH &&
                ) WHERE (status IN ('scheduled', 'in_progress'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
