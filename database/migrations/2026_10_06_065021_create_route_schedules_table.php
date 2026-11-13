<?php

use App\Models\RoutePattern;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuidFor(RoutePattern::class)
                ->constrained('route_patterns')
                ->restrictOnDelete();

            $table->smallInteger('day_of_week');
            $table->time('departure_time');

            $table->timestamps();

            $table->unique([
                'route_pattern_id',
                'day_of_week',
                'departure_time',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_schedules');
    }
};
