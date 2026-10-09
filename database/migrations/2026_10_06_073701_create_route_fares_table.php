<?php

use App\Models\Route;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_fares', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuidFor(Route::class)
                ->constrained('routes')
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);

            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();

            $table->timestamps();

            $table->index('route_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_fares');
    }
};
