<?php

use App\Models\Bus;
use App\Models\Driver;
use App\Models\Route;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuidFor(Route::class)
                ->constrained('routes')
                ->cascadeOnDelete();

            $table->foreignUuidFor(Bus::class)
                ->constrained('buses')
                ->cascadeOnDelete();

            $table->foreignUuidFor(Driver::class)
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
        Schema::dropIfExists('trips');
    }
};
