<?php

use App\Models\Route;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_patterns', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuidFor(Route::class)
                ->constrained('routes')
                ->restrictOnDelete();

            $table->string('code', 50);
            $table->string('name');
            $table->string('headsign');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['route_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_patterns');
    }
};
