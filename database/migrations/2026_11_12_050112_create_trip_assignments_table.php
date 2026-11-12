<?php

use App\Models\Company;
use App\Models\RouteSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuidFor(Company::class)
                ->constrained('companies')
                ->restrictOnDelete();

            $table->foreignUuidFor(RouteSchedule::class)
                ->constrained('route_schedules')
                ->restrictOnDelete();

            $table->uuid('driver_id');
            $table->uuid('bus_id');

            $table->time('planned_arrival_time');
            $table->boolean('ends_next_day')->default(false);

            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['driver_id', 'company_id'], 'trip_assignments_driver_id_company_foreign')
                ->references(['id', 'company_id'])
                ->on('drivers')
                ->restrictOnDelete();

            $table->foreign(['bus_id', 'company_id'], 'trip_assignments_bus_id_company_foreign')
                ->references(['id', 'company_id'])
                ->on('buses')
                ->restrictOnDelete();

            $table->index(['company_id', 'status']);
            $table->index(['route_schedule_id', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index(['bus_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE trip_assignments
                ADD CONSTRAINT trip_assignments_status_check CHECK (
                    status IN ('active', 'inactive')
                ),
                ADD CONSTRAINT trip_assignments_validity_check CHECK (
                    valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_assignments');
    }
};
