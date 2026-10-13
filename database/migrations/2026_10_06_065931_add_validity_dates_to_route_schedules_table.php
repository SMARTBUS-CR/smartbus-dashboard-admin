<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_schedules', function (Blueprint $table): void {
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE route_schedules
                ADD CONSTRAINT route_schedules_validity_order_check
                    CHECK (
                        valid_from IS NULL
                        OR valid_until IS NULL
                        OR valid_until >= valid_from
                    )
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE route_schedules
                DROP CONSTRAINT route_schedules_validity_order_check
            SQL);

        Schema::table('route_schedules', function (Blueprint $table): void {
            $table->dropColumn(['valid_from', 'valid_until']);
        });
    }
};
