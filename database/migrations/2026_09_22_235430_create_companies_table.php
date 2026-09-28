<?php

use App\Enums\CompanyStatus;
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
        // Create the companies table
        Schema::create('companies', function (Blueprint $table) {
            // Use UUIDs for the primary key
            $table->uuid('id')->primary();

            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('slug')->unique();

            // Store the country code as a 2-character string
            $table->char('country_code', 2);

            $table->string('legal_id')->nullable();
            $table->string('operator_number')->nullable();

            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            $table->string('address')->nullable();

            $table->string('timezone', 64);

            $table
                ->string('status')
                ->default(CompanyStatus::ACTIVE);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['country_code', 'legal_id']);
            $table->unique(['country_code', 'operator_number']);
        });

        // Create the pivot table for the many-to-many relationship between companies and users
        Schema::create('company_users', function (Blueprint $table) {
            // Use UUIDs for the primary key
            $table->uuid('id')->primary();

            // Foreign key to the companies table
            $table->foreignUuidFor(Company::class)
                ->constrained('companies')
                ->cascadeOnDelete();

            // External foreign key to the users table
            $table->uuid('user_id')->index();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_users');
        Schema::dropIfExists('companies');
    }
};
