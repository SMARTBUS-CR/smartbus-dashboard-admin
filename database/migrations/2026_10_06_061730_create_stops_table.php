<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stops', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuidFor(Company::class)
                ->nullable()
                ->constrained('companies')
                ->restrictOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            $table->decimal('latitude', 9, 7);
            $table->decimal('longitude', 10, 7);

            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stops');
    }
};
