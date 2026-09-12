<?php

use App\Models\Company;
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
        Schema::create('routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuidFor(Company::class)
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->string('code')->index();
            $table->string('name');
            // $table->text('description')->nullable();
            $table->string('origin');
            $table->string('destination');
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->unsignedSmallInteger('estimated_duration_minutes')->nullable();
            // $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
