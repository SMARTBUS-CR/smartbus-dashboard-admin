<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_pattern_stops', function (Blueprint $table): void {
            $table->integer('minutes_from_start')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE route_pattern_stops
                ADD CONSTRAINT route_pattern_stops_non_negative_minutes_check
                    CHECK (
                        minutes_from_start IS NULL
                        OR minutes_from_start >= 0
                    )
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE route_pattern_stops
                DROP CONSTRAINT route_pattern_stops_non_negative_minutes_check
            SQL);

        Schema::table('route_pattern_stops', function (Blueprint $table): void {
            $table->dropColumn('minutes_from_start');
        });
    }
};
