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

        Schema::create('buses', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuidFor(Company::class)
                ->constrained('companies')
                ->restrictOnDelete();

            $table->char('country_code', 2);
            $table->string('plate_number', 30);
            $table->string('unit_number', 50);

            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->smallInteger('year')->nullable();
            $table->smallInteger('capacity');

            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['country_code', 'plate_number']);
            $table->unique(['company_id', 'unit_number']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'is_active']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE buses
                ADD CONSTRAINT buses_capacity_check CHECK (capacity BETWEEN 1 AND 32767),
                ADD CONSTRAINT buses_year_check CHECK (
                    year IS NULL OR year BETWEEN 1900 AND extract(year FROM CURRENT_DATE)::integer + 1
                ),
                ADD CONSTRAINT buses_identifiers_check CHECK (
                    country_code ~ '^[A-Z]{2}$'
                    AND plate_number = upper(btrim(plate_number)) AND plate_number <> ''
                    AND unit_number = btrim(unit_number) AND unit_number <> ''
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('buses');
    }
};
