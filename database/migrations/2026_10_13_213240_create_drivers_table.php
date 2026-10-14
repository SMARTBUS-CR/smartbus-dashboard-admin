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

        Schema::create('drivers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuidFor(Company::class)
                ->constrained('companies')
                ->restrictOnDelete();
            
            $table->uuid('user_id');

            $table->string('license', 50);
            $table->date('license_expires_at');
            
            $table->date('employment_starts_on');
            $table->date('employment_ends_on')->nullable();
            
            $table->decimal('salary_amount', 12, 2);
            $table->char('salary_currency', 3);
            $table->string('salary_period', 20)->default('monthly');
            
            $table->string('status', 20)->default('active');
            
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'user_id']);
            $table->unique(['company_id', 'license']);
            $table->unique(['id', 'company_id']);
            $table->index(['user_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE drivers
                ADD CONSTRAINT drivers_salary_check CHECK (
                    salary_amount >= 0 AND salary_amount <= 9999999999.99
                    AND salary_period = 'monthly' AND salary_currency ~ '^[A-Z]{3}$'
                ),
                ADD CONSTRAINT drivers_status_check CHECK (status IN ('active', 'inactive')),
                ADD CONSTRAINT drivers_employment_dates_check CHECK (
                    employment_ends_on IS NULL OR employment_ends_on >= employment_starts_on
                ),
                ADD CONSTRAINT drivers_license_check CHECK (
                    license = upper(btrim(license)) AND license <> ''
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
