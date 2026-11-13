<?php

use App\Models\RoutePattern;
use App\Models\Stop;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_pattern_stops', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuidFor(RoutePattern::class)
                ->constrained('route_patterns')
                ->restrictOnDelete();

            $table->foreignUuidFor(Stop::class)
                ->constrained('stops')
                ->restrictOnDelete();

            $table->integer('stop_sequence');

            $table->timestamps();

            $table->unique(['route_pattern_id', 'stop_sequence']);
            $table->index('stop_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE route_pattern_stops
                ADD CONSTRAINT route_pattern_stops_positive_sequence_check
                    CHECK (stop_sequence > 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('route_pattern_stops');
    }
};
