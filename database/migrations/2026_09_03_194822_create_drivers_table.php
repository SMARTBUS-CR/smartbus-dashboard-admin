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
        Schema::create('drivers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('user_id')
                ->unique();

            $table->foreignUuidFor(Company::class)
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('license', 30);
            $table->string('status', 20)
                ->default('active');

            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
