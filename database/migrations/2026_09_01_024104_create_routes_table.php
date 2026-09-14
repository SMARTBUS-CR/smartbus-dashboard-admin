<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis;');

        Schema::create('routes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuidFor(Company::class)
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('code')->unique();

            // Origin and destination coordinates stored as decimal for precision
            $table->decimal('origin_lat', 10, 8);
            $table->decimal('origin_lng', 11, 8);
            $table->decimal('destination_lat', 10, 8);
            $table->decimal('destination_lng', 11, 8);

            // Codified polyline (Encoded Polyline Algorithm) string representing the route path,
            // useful for rendering on maps without heavy data transfer
            $table->text('overview_polyline')->nullable();

            // PostGIS LineString for native spatial queries in the database
            $table->geography('path', subtype: 'linestring', srid: 4326)->nullable();

            // Intermediate stops/waypoints details stored in JSONB format
            $table->jsonb('waypoints')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
