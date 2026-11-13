<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuidFor(Company::class)
                ->constrained('companies')
                ->restrictOnDelete();

            $table->string('code', 50);
            $table->string('name');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
