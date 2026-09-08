<?php

use App\Models\Trip;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        Schema::create('gps_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuidFor(Trip::class)
                ->constrained('trips')
                ->cascadeOnDelete();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->decimal('speed_kmh', 8, 2)
                ->nullable();

            $table->timestamp('recorded_at');

            $table->geography(
                'location',
                subtype: 'point',
                srid: 4326
            );

            $table->timestamps();

            $table->index('trip_id');
            $table->index('recorded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gps_locations');
    }
};
