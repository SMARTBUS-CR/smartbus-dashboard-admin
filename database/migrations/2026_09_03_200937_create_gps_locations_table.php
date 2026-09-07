<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operational data resides in PostgreSQL.
     */
    protected $connection = 'pgsql';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::connection($this->connection)->statement('CREATE EXTENSION IF NOT EXISTS postgis');

        Schema::connection($this->connection)->create('gps_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('trip_id')
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
        Schema::connection($this->connection)->dropIfExists('gps_locations');
    }
};
