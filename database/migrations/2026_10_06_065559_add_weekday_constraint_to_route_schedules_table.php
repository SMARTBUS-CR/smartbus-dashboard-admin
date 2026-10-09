<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE route_schedules
                ADD CONSTRAINT route_schedules_weekday_range_check
                    CHECK (day_of_week BETWEEN 0 AND 6)
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE route_schedules
                DROP CONSTRAINT route_schedules_weekday_range_check
            SQL);
    }
};
