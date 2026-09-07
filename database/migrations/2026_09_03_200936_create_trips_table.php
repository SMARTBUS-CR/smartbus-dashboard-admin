<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::connection($this->connection)->create('trips', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('route_id')
                ->constrained('routes')
                ->cascadeOnDelete();

            $table->foreignUuid('bus_id')
                ->constrained('buses')
                ->cascadeOnDelete();

            $table->foreignUuid('driver_id')
                ->constrained('drivers')
                ->cascadeOnDelete();

            $table->string('status', 20)
                ->default('scheduled');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('driver_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('trips');
    }
};
