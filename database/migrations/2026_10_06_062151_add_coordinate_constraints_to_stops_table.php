<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE stops
                ADD CONSTRAINT stops_latitude_range_check
                    CHECK (latitude BETWEEN -90 AND 90),
                ADD CONSTRAINT stops_longitude_range_check
                    CHECK (longitude BETWEEN -180 AND 180)
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE stops
                DROP CONSTRAINT stops_latitude_range_check,
                DROP CONSTRAINT stops_longitude_range_check
            SQL);
    }
};
