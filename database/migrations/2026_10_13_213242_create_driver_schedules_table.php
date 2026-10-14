<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('driver_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('driver_id')
                ->constrained('drivers')
                ->restrictOnDelete();
            
            $table->smallInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');

            $table->boolean('ends_next_day')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            
            $table->timestamps();

            $table->index(['driver_id', 'day_of_week']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE driver_schedules
                ADD CONSTRAINT driver_schedules_weekday_check CHECK (day_of_week BETWEEN 0 AND 6),
                ADD CONSTRAINT driver_schedules_time_check CHECK (
                    start_time < time '24:00:00' AND end_time < time '24:00:00'
                    AND (
                        (NOT ends_next_day AND end_time > start_time)
                        OR (ends_next_day AND end_time <= start_time)
                    )
                ),
                ADD CONSTRAINT driver_schedules_validity_check CHECK (
                    valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_schedules');
    }
};
